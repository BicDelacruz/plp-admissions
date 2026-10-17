<?php
// ============================================================
// views/partials/dark_mode_setting.php
// Dark Mode row for Settings > Appearance (student, staff, admin).
// Sits under the text-size slider, so it carries its own divider.
// Uses window.Theme from app.js (saves to localStorage 'plp_theme'); the
// settings modal's parent page follows through the 'storage' event.
// Include once per page.
// ============================================================
?>
<div data-dark-mode-setting
     style="display:flex;align-items:center;justify-content:space-between;margin-top:var(--space-5);padding-top:var(--space-5);border-top:1px solid var(--border)">
    <div>
        <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Dark Mode</div>
        <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Saved to this device</div>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" data-dark-mode-btn aria-pressed="false">
        <span data-dark-mode-label>Switch to Dark</span>
    </button>
</div>
<script>
(function () {
    var btn   = document.querySelector('[data-dark-mode-btn]');
    var label = document.querySelector('[data-dark-mode-label]');
    if (!btn || !label) return;

    function sync() {
        var dark = document.documentElement.dataset.theme === 'dark';
        label.textContent = dark ? 'Switch to Light' : 'Switch to Dark';
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }

    btn.addEventListener('click', function () {
        window.Theme.toggle();
        var t = document.documentElement.dataset.theme;
        // keep the profile menu's dark mode pill in step
        document.querySelectorAll('.theme-pill').forEach(function (p) { p.dataset.theme = t; });
    });

    // Follows any change to the theme, including the one from the other tab / the page behind.
    new MutationObserver(sync).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    sync();
})();
</script>
