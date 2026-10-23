<?php
// ============================================================
// views/partials/applicant_drawer.php
//
// Slide-in drawer container + JS opener. Include this once per
// page that wants to show applicant detail panels. Then trigger
// the drawer with:
//
//     <a href="#" onclick="openApplicantPanel(123); return false">…</a>
//     ui_button('Open', ['attrs' => ['onclick' => 'openApplicantPanel(123)']])
//
// or by adding a `data-applicant-panel="123"` attribute to any
// element — the IIFE below auto-wires those click handlers too.
//
// The drawer fetches /api/applicant-panel?id=… (returns the
// rendered applicant_panel partial as HTML).
// ============================================================
?>
<div class="ap-drawer-backdrop" id="apDrawerBackdrop"
     onclick="closeApplicantPanel()"></div>

<aside class="ap-drawer" id="apDrawer" aria-hidden="true" role="dialog"
       aria-labelledby="apDrawerTitle">
    <div class="ap-drawer-head">
        <span id="apDrawerTitle" class="ap-drawer-title">Applicant detail</span>
        <div>
        <?php if (Auth::isSSO() || Auth::isAdmin()): ?>
        <a id="apDrawerPrint" class="ap-drawer-close" href="#" target="_blank" rel="noopener"
           title="Print admission" aria-label="Print admission">
            <?= icon('printer', 16) ?>
        </a>
        <?php endif; ?>
        <button type="button" class="ap-drawer-close"
                onclick="closeApplicantPanel()" aria-label="Close panel">
            <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
        </button>
        </div>
    </div>
    <div class="ap-drawer-body" id="apDrawerBody">
        <div class="ap-drawer-loading">Loading…</div>
    </div>
</aside>

<script>
(function () {
    var drawer  = document.getElementById('apDrawer');
    var backdrop= document.getElementById('apDrawerBackdrop');
    var body    = document.getElementById('apDrawerBody');

    // Public open / close API ------------------------------------
    window.openApplicantPanel = function (id) {
        if (!id) return;
        drawer.classList.add('is-open');
        backdrop.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ap-drawer-locked');
        body.innerHTML = '<div class="ap-drawer-loading">Loading…</div>';
        // Runs on click, after jQuery has loaded. Link exists for SSO/Admin only.
        $('#apDrawerPrint').attr('href', '<?= e(url('/print/admission?id=')) ?>' + encodeURIComponent(id));

        fetch('<?= e(url('/api/applicant-panel')) ?>?id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html' }
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) { body.innerHTML = html; })
            .catch(function (err) {
                body.innerHTML =
                    '<div class="ap-drawer-error">'
                    + 'Could not load applicant detail. '
                    + '<button type="button" class="btn btn-secondary btn-sm" '
                    + 'onclick="openApplicantPanel(' + (id|0) + ')">Retry</button>'
                    + '</div>';
            });
    };

    window.closeApplicantPanel = function () {
        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ap-drawer-locked');
    };

    // Auto-wire data-applicant-panel triggers --------------------
    document.addEventListener('click', function (ev) {
        var t = ev.target.closest('[data-applicant-panel]');
        if (!t) return;
        ev.preventDefault();
        window.openApplicantPanel(parseInt(t.getAttribute('data-applicant-panel'), 10));
    });

    // ESC closes
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && drawer.classList.contains('is-open')) {
            window.closeApplicantPanel();
        }
    });

    // Confirm helper used by the inline Pass/Reject form -----------
    // Dialogs are async: block this submit, ask, then submit again with a flag.
    window.apEvalConfirm = function (form) {
        if (form.dataset.apOk) { delete form.dataset.apOk; return true; }
        var r = (form.querySelector('.ap-eval-input') || {}).value || '';
        if (!r) {
            ui.alert('Please choose Pass or Decline.');
            return false;
        }
        if (r === 'pass' || r === 'reject') {
            ui.confirm('Submit this evaluation as ' + r.toUpperCase() + '?', { danger: r === 'reject', ok: 'Submit' })
                .then(function (ok) { if (ok) { form.dataset.apOk = '1'; form.requestSubmit(); } });
            return false;
        }
        return true;
    };
})();
</script>
