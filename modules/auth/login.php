<?php
// ============================================================
// modules/auth/login.php
// M2 — Authentication: Login
//
// Standalone page (does not use views/layouts/auth.php).
// Images expected in public/assets/img/:
//   plp logo.png   — school seal
//   PLP PICTURE.jpg   — campus photo for the hero background
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

// Already logged in — redirect home
if (Auth::check()) {
    header("Location: " . Auth::homeUrl()); exit;
}

$errors     = [];
$didTimeout = Session::getFlash('timeout');
$resetDone  = Session::getFlash('reset_done');
$googleErr  = Session::getFlash('google_error');

$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if (!$email)    $errors['email']    = 'Email is required.';
    if (!$password) $errors['password'] = 'Password is required.';

    if (empty($errors)) {
        // Turnstile verification
        if (!captcha_verify()) {
            $errors['captcha'] = 'Please complete the check.';
        }
    }

    // Rate limiting check
    if (empty($errors) && is_login_locked($email)) {
        $errors['general'] = 'Too many failed attempts. Please try again in 15 minutes.';
    }

    if (empty($errors)) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Check email verification for students
            if ($user['role'] === 'student' && empty($user['email_verified'])) {
                // Don't lock the user out — send them to the verify page where they
                // can enter a fresh code or request a resend.
                clear_login_attempts($email);
                Session::set('verify_pending_email', $user['email']);
                Session::flash('error', 'Verify your email first. Enter the code we sent, or request a new one.');
                redirect('/verify-pending');
            } else {
                clear_login_attempts($email);
                Auth::login($user, !empty($_POST['remember']));
                audit_log('login', "Successful login: {$user['email']}", 'user', $user['id']);
                header("Location: " . Auth::homeUrl()); exit;
            }
        } else {
            record_failed_login($email);
            audit_log('login_failed', "Failed login attempt for: {$email}");
            $errors['general'] = 'Incorrect email or password.';
        }
    }
}

// -- View --------------------------------------------------------
$logoUrl  = asset('img/' . rawurlencode('plp logo.png'));
$photoUrl = asset('img/' . rawurlencode('schol blg.jpg'));

header("Content-Security-Policy: " . auth_csp());
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — PLP Admissions</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?= phosphor_head() ?>

    <style>
    :root {
        --lg-green-950: #0c2a1a;
        --lg-green-800: #14532d;
        --lg-green-700: #1b6b3a;
        --lg-green-600: #1f7a3f;
        --lg-ink: #1a2420;
        --lg-muted: #5d6b63;
        --lg-line: #cfd8d2;
        --lg-link: #1d6b3b;
        --lg-danger: #b3261e;
        --radius: 8px;
        --lg-font-body: "Figtree", system-ui, -apple-system, "Segoe UI", sans-serif;
        color-scheme: light;
    }
    * { box-sizing: border-box; }
    :where(*) { border-radius: var(--radius) !important; }
    html, body { margin: 0; }
    html { background: #fff; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
    body { background: #fff; color: var(--lg-ink); font-family: var(--lg-font-body); }
    [hidden] { display: none !important; }
    h1, h2, h3 { text-wrap: balance; }
    p { text-wrap: pretty; }

    /* Split layout, light only: photo panel left, plain form right */
    .lg-page { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }

    .lg-aside {
        position: relative; isolation: isolate;
        display: flex; flex-direction: column; justify-content: space-between; gap: 32px;
        padding: clamp(32px, 5vw, 64px);
        color: #fff;
        background: url("<?= e($photoUrl) ?>") center / cover no-repeat, var(--lg-green-950);
        position: sticky; top: 0; height: 100vh; align-self: start;
    }
    /* One flat green wash over the photo (no gradient) */
    .lg-aside::before { content: ''; position: absolute; inset: 0; background: rgba(10,38,22,.8); z-index: -1; }

    .lg-brand { display: flex; align-items: center; gap: 16px; }
    .lg-logo { width: 48px; height: 48px; object-fit: contain; flex: none; display: block; background: #fff; padding: 4px; }
    .lg-brand-title { margin: 0; font-size: 1rem; font-weight: 600; line-height: 1.2; }
    .lg-brand-sub { margin: 2px 0 0; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: rgba(255,255,255,.72); }

    .lg-intro { max-width: 480px; }
    .lg-big { margin: 0 0 24px; font-weight: 600; font-size: clamp(2rem, 3.4vw, 2.9rem); line-height: 1.08; letter-spacing: -0.02em; }

    .lg-tabs { display: flex; gap: 24px; border-bottom: 1px solid rgba(255,255,255,.24); margin-bottom: 16px; }
    .lg-tab { background: none; border: 0; padding: 0 0 10px; margin-bottom: -1px; font: inherit; font-size: .95rem; font-weight: 600; color: rgba(255,255,255,.65); cursor: pointer; border-bottom: 2px solid transparent; transition-property: color, border-color; transition-duration: 150ms; transition-timing-function: ease-out; }
    .lg-tab:hover { color: #fff; }
    .lg-tab[aria-selected="true"] { color: #fff; border-bottom-color: #6fcf97; }
    .lg-tab:focus-visible { outline: 2px solid #fff; outline-offset: 4px; }

    .lg-notice { margin: 0 0 16px; font-size: .92rem; line-height: 1.55; color: rgba(255,255,255,.88); }
    .lg-cards { display: flex; flex-direction: column; gap: 12px; }
    .lg-info { display: flex; gap: 14px; align-items: flex-start; padding: 14px 16px; background: rgba(255,255,255,.08); box-shadow: 0 0 0 1px rgba(255,255,255,.14); min-width: 0; }
    .lg-info i { font-size: 22px; line-height: 1; flex: none; margin-top: 2px; color: #6fcf97; }
    .lg-info h3 { margin: 0 0 4px; font-size: .95rem; font-weight: 600; }
    .lg-info p { margin: 0; font-size: .82rem; line-height: 1.5; color: rgba(255,255,255,.78); }
    .lg-aside-foot { margin: 0; font-size: .75rem; color: rgba(255,255,255,.6); }

    /* Form pane */
    .lg-pane { display: flex; align-items: center; justify-content: center; padding: clamp(24px, 5vw, 64px); background: #fff; }
    .lg-card { width: 100%; max-width: 400px; }
    .lg-lead { margin: 0 0 24px; font-size: 1.5rem; font-weight: 600; letter-spacing: -0.02em; line-height: 1.2; color: var(--lg-ink); }

    .lg-alert { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 16px; padding: 10px 12px; font-size: .86rem; line-height: 1.4; }
    .lg-alert-error { background: #fdecea; color: var(--lg-danger); box-shadow: 0 0 0 1px #f3c1bc; }
    .lg-alert-info  { background: #e8f3ec; color: var(--lg-green-800); box-shadow: 0 0 0 1px #bfdcc9; }

    .lg-field { margin-bottom: 16px; }
    .lg-field label { display: block; margin-bottom: 6px; font-size: .82rem; font-weight: 600; }
    .lg-input-wrap { position: relative; }
    .lg-field input { width: 100%; height: 46px; padding: 0 14px; border: 1px solid var(--lg-line); background: #fff; font: inherit; font-size: .95rem; color: var(--lg-ink); transition-property: border-color, box-shadow; transition-duration: 150ms; transition-timing-function: ease-out; }
    .lg-field input::placeholder { color: #8b97a0; }
    .lg-field input:hover { border-color: #a9b6ae; }
    .lg-field input:focus { outline: none; border-color: var(--lg-green-600); box-shadow: 0 0 0 3px rgba(31,122,63,.18); }
    .lg-field input.lg-bad { border-color: var(--lg-danger); background: #fdecea; }
    .lg-err { display: block; margin-top: 5px; font-size: .78rem; color: var(--lg-danger); }
    .lg-has-eye input { padding-right: 48px; }
    .lg-eye { position: absolute; right: 5px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border: 0; background: none; cursor: pointer; display: grid; place-items: center; color: var(--lg-muted); }
    .lg-eye::before { content: ""; position: absolute; inset: -2px; }
    .lg-eye:hover { background: rgba(0,0,0,.06); }
    .lg-eye:focus-visible { outline: 2px solid var(--lg-green-600); }
    .lg-eye i { font-size: 20px; line-height: 1; display: block; }
    .lg-eye.on { color: var(--lg-green-700); }

    .lg-opts { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: -4px 0 20px; }
    .lg-check { display: inline-flex; align-items: center; gap: 8px; font-size: .86rem; font-weight: 600; cursor: pointer; }
    .lg-check input { width: 18px; height: 18px; margin: 0; accent-color: var(--lg-green-700); cursor: pointer; }
    .lg-check input:focus-visible { outline: 2px solid var(--lg-green-600); outline-offset: 2px; }
    .lg-forgot { text-align: right; }
    .lg-forgot a, .lg-signup a { color: var(--lg-link); font-size: .86rem; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
    .lg-go { width: 100%; height: 46px; border: 0; background: var(--lg-green-700); color: #fff; font: inherit; font-size: .95rem; font-weight: 600; cursor: pointer; transition-property: background-color, scale; transition-duration: 150ms; transition-timing-function: ease-out; }
    .lg-go:hover { background: var(--lg-green-800); }
    .lg-go:active:not(:disabled) { scale: .96; }
    .lg-go:disabled { opacity: .7; cursor: wait; }
    .lg-go:focus-visible { outline: 3px solid rgba(31,122,63,.4); outline-offset: 2px; }
    .lg-or { display: flex; align-items: center; gap: 12px; margin: 18px 0; font-size: .8rem; color: var(--lg-muted); }
    .lg-or::before, .lg-or::after { content: ''; flex: 1; height: 1px; background: #d5dbd7; }
    .lg-google { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; height: 46px; border: 1px solid var(--lg-line); background: #fff; color: var(--lg-ink); font: inherit; font-size: .95rem; font-weight: 600; text-decoration: none; transition-property: background-color, scale; transition-duration: 150ms; transition-timing-function: ease-out; }
    .lg-google:hover { background: #f4f7f5; }
    .lg-google:active { scale: .96; }
    .lg-google:focus-visible { outline: 3px solid rgba(31,122,63,.4); outline-offset: 2px; }
    .lg-signup { margin: 18px 0 0; text-align: center; font-size: .86rem; color: var(--lg-muted); }

    @media (max-width: 900px) {
        .lg-page { grid-template-columns: minmax(0, 1fr); }
        .lg-aside { position: static; height: auto; padding: 16px 24px; gap: 0; }
        .lg-intro, .lg-aside-foot { display: none; }
        .lg-pane { padding: 32px 24px; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { transition: none !important; }
        .lg-go:active, .lg-google:active { scale: none; }
    }
    </style>
</head>
<body>

<div class="lg-page">

    <aside class="lg-aside">
        <div class="lg-brand">
            <img class="lg-logo" src="<?= e($logoUrl) ?>" alt="PLP seal">
            <div>
                <p class="lg-brand-title">PLP Admissions</p>
                <p class="lg-brand-sub">Pamantasan ng Lungsod ng Pasig</p>
            </div>
        </div>

        <section class="lg-intro" aria-labelledby="lg-hero-title">
            <h1 class="lg-big" id="lg-hero-title">Admissions<br>Office</h1>

            <div class="lg-tabs" role="tablist" aria-label="Admissions information">
                <button type="button" class="lg-tab" role="tab" id="lg-t1" aria-selected="true"  aria-controls="lg-p1">Apply Online</button>
                <button type="button" class="lg-tab" role="tab" id="lg-t2" aria-selected="false" aria-controls="lg-p2">Advisory</button>
            </div>

            <div id="lg-p1" role="tabpanel" aria-labelledby="lg-t1">
                <p class="lg-notice">Create an account, upload your requirements, and track your application from one place.</p>
                <div class="lg-cards">
                    <article class="lg-info">
                        <i class="ph-fill ph-file-text" aria-hidden="true"></i>
                        <div>
                            <h3>Submit Documents</h3>
                            <p>Upload your PSA birth certificate, Form 137 or 138, IDs, and other requirements.</p>
                        </div>
                    </article>
                    <article class="lg-info">
                        <i class="ph-fill ph-calendar-check" aria-hidden="true"></i>
                        <div>
                            <h3>Exam &amp; Interview</h3>
                            <p>See your entrance exam schedule, interview slot, and final result as they are released.</p>
                        </div>
                    </article>
                </div>
            </div>

            <div id="lg-p2" role="tabpanel" aria-labelledby="lg-t2" hidden>
                <p class="lg-notice">Advisory: documents must be uploaded as PDF, JPG, PNG or WEBP, 4 MB or smaller.</p>
                <div class="lg-cards">
                    <article class="lg-info">
                        <i class="ph-fill ph-envelope-simple" aria-hidden="true"></i>
                        <div>
                            <h3>Check your email</h3>
                            <p>New accounts must verify their email with the code we send before signing in.</p>
                        </div>
                    </article>
                    <article class="lg-info">
                        <i class="ph-fill ph-lock" aria-hidden="true"></i>
                        <div>
                            <h3>Account lock</h3>
                            <p>Too many failed sign-ins lock your account for 15 minutes.</p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <p class="lg-aside-foot">Admissions Office</p>
    </aside>

    <main class="lg-pane">
        <!-- Right: login form -->
        <section class="lg-card animate-fade-in" aria-labelledby="lg-login-h">
            <p class="lg-lead" id="lg-login-h">Sign in to continue your application</p>

            <?php if ($didTimeout): ?>
                <div class="lg-alert lg-alert-info" role="status">Your session expired. Please sign in again.</div>
            <?php endif; ?>

            <?php if ($resetDone): ?>
                <div class="lg-alert lg-alert-info" role="status">Password updated. Sign in with your new password.</div>
            <?php endif; ?>

            <?php if ($googleErr): ?>
                <div class="lg-alert lg-alert-error" role="alert"><?= e($googleErr) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors['general'])): ?>
                <div class="lg-alert lg-alert-error" role="alert"><?= e($errors['general']) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= url('/login') ?>" data-once novalidate>
                <?= csrf_field() ?>

                <div class="lg-field">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="<?= isset($errors['email']) ? 'lg-bad' : '' ?>"
                        value="<?= e($email) ?>"
                        placeholder="you@example.com"
                        autocomplete="email"
                        required
                    >
                    <?php if (!empty($errors['email'])): ?>
                        <span class="lg-err"><?= e($errors['email']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="lg-field">
                    <label for="password">Password</label>
                    <div class="lg-input-wrap lg-has-eye">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="<?= isset($errors['password']) ? 'lg-bad' : '' ?>"
                            placeholder="Password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="lg-eye" id="lg-eye" aria-label="Show password">
                            <i class="ph-bold ph-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php if (!empty($errors['password'])): ?>
                        <span class="lg-err"><?= e($errors['password']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="lg-opts">
                    <label class="lg-check" for="remember">
                        <input type="checkbox" id="remember" name="remember" value="1">
                        Remember me
                    </label>
                    <span class="lg-forgot"><a href="<?= url('/forgot-password') ?>">Forgot password?</a></span>
                </div>

                <?php if (!empty($errors['captcha'])): ?>
                    <div class="lg-alert lg-alert-error" role="alert"><?= e($errors['captcha']) ?></div>
                <?php endif; ?>

                <?php if (CAPTCHA_ENABLED): ?>
                    <div class="cf-turnstile" data-sitekey="<?= e(TURNSTILE_SITE_KEY) ?>" data-theme="auto" style="margin-bottom:16px"></div>
                <?php endif; ?>

                <button type="submit" class="lg-go">Login</button>
            </form>

            <?php if (GOOGLE_ENABLED): ?>
                <div class="lg-or"><span>or</span></div>
                <a class="lg-google" id="lg-google" href="<?= url('/auth/google') ?>">
                    <?= google_logo_svg() ?>
                    Continue with Google
                </a>
            <?php endif; ?>

            <p class="lg-signup">
                New applicant?
                <a href="<?= url('/register') ?>">Create an account</a>
            </p>
            <p class="lg-signup" style="margin-top:10px"><a href="<?= url('/privacy') ?>">Privacy Policy</a></p>
        </section>

    </main>
</div>

<script src="<?= asset('js/jquery.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if (CAPTCHA_ENABLED): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script>
(function () {
    // Info tabs
    var tabs = document.querySelectorAll('.lg-tab');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (o) {
                var on = o === t;
                o.setAttribute('aria-selected', on ? 'true' : 'false');
                document.getElementById(o.getAttribute('aria-controls')).hidden = !on;
            });
        });
    });

    // Live email check on blur
    if (window.jQuery) {
        var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        jQuery('#email').on('blur input', function (ev) {
            var $in = jQuery(this), v = jQuery.trim($in.val());
            if (ev.type === 'input' && !$in.hasClass('lg-bad')) return;
            var bad = v !== '' && !emailRe.test(v);
            $in.siblings('.lg-err').remove();
            $in.toggleClass('lg-bad', bad);
            if (bad) jQuery('<span class="lg-err">Enter a valid email address.</span>').insertAfter($in);
        });
    }

    // Carry the Remember me tick through the Google redirect
    var g = document.getElementById('lg-google'), rem = document.getElementById('remember');
    if (g && rem) {
        g.addEventListener('click', function () {
            g.href = g.href.split('?')[0] + (rem.checked ? '?remember=1' : '');
        });
    }

    // Show / hide password
    var pw  = document.getElementById('password');
    var eye = document.getElementById('lg-eye');
    eye.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.classList.toggle('on', show);
        eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>

</body>
</html>