<?php
// ============================================================
// core/Auth.php
// Login state, role resolution, route guards
// ============================================================

class Auth
{
    // -- Login / logout ------------------------------------------
    public static function login(array $user, bool $remember = false): void
    {
        session_regenerate_id(true);
        Session::set('user_id',   (int)  $user['id']);
        Session::set('user_name',        $user['name']);
        Session::set('user_email',       $user['email']);
        Session::set('user_role',        $user['role']);
        if ($remember) {
            self::rememberIssue((int) $user['id']);
        }
    }

    public static function logout(): void
    {
        self::rememberForget();
        Session::destroy();
        header('Location: ' . url('/login'));
        exit;
    }

    // -- Remember me ---------------------------------------------
    // Long-lived cookie "selector:validator". Only sha256(validator) is stored.
    private static function rememberSetCookie(string $value, int $expires): void
    {
        setcookie(REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS'])
                          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($expires > time()) { $_COOKIE[REMEMBER_COOKIE] = $value; } else { unset($_COOKIE[REMEMBER_COOKIE]); }
    }

    public static function rememberIssue(int $userId): void
    {
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires   = time() + REMEMBER_DAYS * 86400;
        try {
            db()->prepare('DELETE FROM remember_tokens WHERE user_id = ? AND expires_at < NOW()')->execute([$userId]);
            db()->prepare('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)')
               ->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires)]);
        } catch (\Throwable $e) {
            error_log('[remember] issue failed: ' . $e->getMessage());
            return; // login still works, just not remembered
        }
        self::rememberSetCookie($selector . ':' . $validator, $expires);
    }

    public static function rememberRestore(): void
    {
        if (self::check() || empty($_COOKIE[REMEMBER_COOKIE])) {
            return;
        }
        $parts = remember_cookie_parse((string) $_COOKIE[REMEMBER_COOKIE]);
        if (!$parts) {
            self::rememberSetCookie('', time() - 3600);
            return;
        }
        [$selector, $validator] = $parts;

        try {
            $stmt = db()->prepare('SELECT id, user_id, token_hash, (expires_at > NOW()) AS live FROM remember_tokens WHERE selector = ? LIMIT 1');
            $stmt->execute([$selector]);
            $row = $stmt->fetch();
            if (!$row || !$row['live']) {
                if ($row) { db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]); }
                self::rememberSetCookie('', time() - 3600);
                return;
            }
            if (!remember_hash_ok($validator, (string) $row['token_hash'])) {
                // Known selector, wrong validator: assume the cookie was stolen.
                self::rememberRevokeAll((int) $row['user_id']);
                self::rememberSetCookie('', time() - 3600);
                return;
            }

            $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([(int) $row['user_id']]);
            $user = $stmt->fetch();
            if (!$user || ($user['role'] === 'student' && empty($user['email_verified']))) {
                db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
                self::rememberSetCookie('', time() - 3600);
                return;
            }

            // Rotate the validator on every use.
            // ponytail: two requests racing with the same old cookie look like theft and sign the user out everywhere. Upgrade: keep the previous hash for a few seconds.
            $newValidator = bin2hex(random_bytes(32));
            $expires      = time() + REMEMBER_DAYS * 86400;
            db()->prepare('UPDATE remember_tokens SET token_hash = ?, expires_at = ? WHERE id = ?')
               ->execute([hash('sha256', $newValidator), date('Y-m-d H:i:s', $expires), $row['id']]);
            self::rememberSetCookie($selector . ':' . $newValidator, $expires);

            self::login($user);
            // The idle timeout just ended the old session; do not tell a restored user it expired.
            Session::getFlash('timeout');
        } catch (\Throwable $e) {
            error_log('[remember] restore failed: ' . $e->getMessage());
        }
    }

    public static function rememberForget(): void
    {
        $parts = remember_cookie_parse((string) ($_COOKIE[REMEMBER_COOKIE] ?? ''));
        if ($parts) {
            try {
                db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$parts[0]]);
            } catch (\Throwable $e) {
                error_log('[remember] forget failed: ' . $e->getMessage());
            }
        }
        if (isset($_COOKIE[REMEMBER_COOKIE])) {
            self::rememberSetCookie('', time() - 3600);
        }
    }

    public static function rememberRevokeAll(int $userId): void
    {
        try {
            db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
        } catch (\Throwable $e) {
            error_log('[remember] revoke failed: ' . $e->getMessage());
        }
    }

    // -- State checks --------------------------------------------
    public static function check(): bool
    {
        return Session::has('user_id');
    }

    public static function id(): ?int
    {
        return Session::get('user_id');
    }

    public static function role(): ?string
    {
        return Session::get('user_role');
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return [
            'id'    => Session::get('user_id'),
            'name'  => Session::get('user_name'),
            'email' => Session::get('user_email'),
            'role'  => Session::get('user_role'),
        ];
    }

    public static function isStudent():   bool { return self::role() === ROLE_STUDENT;  }
    public static function isStaff():     bool { return self::role() === ROLE_STAFF;    }
    public static function isProfessor(): bool { return self::role() === ROLE_STAFF;    } // alias
    public static function isProctor():   bool { return self::role() === ROLE_PROCTOR;  }
    public static function isSSO():       bool { return self::role() === ROLE_SSO;      }
    public static function isDean():      bool { return self::role() === ROLE_DEAN;     }
    public static function isAdmin():     bool { return self::role() === ROLE_ADMIN;    }

    /**
     * Any non-student authenticated user (Professor / Proctor / SSO / Dean / Admin).
     * Use for pages that everyone with admin-side access can view (e.g.
     * shared dashboards, the interview queue, user-profile settings).
     */
    public static function isStaffPlus(): bool
    {
        return in_array(self::role(), [ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN], true);
    }

    /**
     * SSO / Dean / Admin — the "oversight" tier.
     * Use for pages that read across applicants but where Professors
     * and Proctors have no business (results review, courses & strands).
     */
    public static function isOversight(): bool
    {
        return in_array(self::role(), [ROLE_SSO, ROLE_DEAN, ROLE_ADMIN], true);
    }

    /** Legacy helper kept for back-compat (Staff / Proctor OR Admin only). */
    public static function isStaffOrAdmin(): bool
    {
        return in_array(self::role(), [ROLE_STAFF, ROLE_PROCTOR, ROLE_ADMIN], true);
    }

    // -- Role labels --------------------------------------------
    // Map a DB enum value to its UI label. The DB enum keeps 'staff'
    // for back-compat, but everywhere the UI shows it we want
    // "Professor" — call this helper instead of `ucfirst($u['role'])`.
    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            ROLE_STUDENT  => 'Student',
            ROLE_STAFF    => 'Professor',
            ROLE_PROCTOR  => 'Proctor',
            ROLE_SSO      => 'SSO',
            ROLE_DEAN     => 'Dean',
            ROLE_ADMIN    => 'Admin',
            default       => ucfirst((string)$role),
        };
    }

    // -- Guards --------------------------------------------------
    // Call at the top of any page that requires authentication
    public static function requireLogin(): void
    {
        if (!self::check()) {
            Session::flash('error', 'Please log in to continue.');
            header('Location: ' . url('/login'));
            exit;
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            http_response_code(403);
            include VIEWS_PATH . '/403.php';
            exit;
        }
    }

    /** Convenience: any non-student authenticated user. */
    public static function requireStaffPlus(): void
    {
        self::requireRole(ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);
    }

    /** Convenience: SSO / Dean / Admin (oversight tier). */
    public static function requireOversight(): void
    {
        self::requireRole(ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);
    }

    // -- Default redirect after login by role --------------------
    public static function homeUrl(): string
    {
        return match (self::role()) {
            ROLE_ADMIN   => url('/admin/dashboard'),
            ROLE_SSO     => url('/admin/dashboard'),
            ROLE_DEAN    => url('/admin/dashboard'),
            ROLE_STAFF   => url('/staff/interviews/queue'),
            // Proctors go straight to Exam Slots — no dashboard.
            ROLE_PROCTOR => url('/staff/exam/slots'),
            default      => url('/student/documents'),
        };
    }
}

// -- Pure helpers (no DB, used by tests/remember_check.php) -------
/** Returns [selector, validator] for a well-formed cookie value, else null. */
function remember_cookie_parse(string $raw): ?array
{
    if (preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        return [$m[1], $m[2]];
    }
    return null;
}

function remember_hash_ok(string $validator, string $storedHash): bool
{
    return hash_equals($storedHash, hash('sha256', $validator));
}
