<?php
// ============================================================
// core/email_html.php
// HTML email building blocks.
// Email clients ignore CSS variables and external stylesheets, so
// this is the ONE place where literal hex colors and px sizes are
// allowed. The design gate exempts this file only. Every email
// should use these helpers instead of writing its own inline styles.
// ============================================================

/**
 * Build a styled HTML email body using school branding.
 */
function email_template(string $title, string $body): string
{
    $schoolName  = school_setting('school_name', 'PLP Admissions');
    $accentColor = school_setting('accent_color', '#2d6a4f');

    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 0">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" '
        . 'style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
    <tr><td style="background:' . e($accentColor) . ';padding:24px 32px">
        <h1 style="margin:0;color:#ffffff;font-size:20px">' . e($schoolName) . '</h1>
    </td></tr>
    <tr><td style="padding:32px">
        <h2 style="margin:0 0 16px;color:#1a1a1a;font-size:18px">' . $title . '</h2>
        <div style="color:#374151;font-size:14px;line-height:1.6">' . $body . '</div>
    </td></tr>
    <tr><td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:center">
        <p style="margin:0;font-size:12px;color:#9ca3af">'
        . 'This is an automated email from ' . e($schoolName) . '. Please do not reply.</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
}

/** Call-to-action button paragraph. $large gives the bigger verify-email size. */
function email_button(string $url, string $label, ?string $accent = null, bool $large = false): string
{
    $accent = $accent ?? (string) school_setting('accent_color', '#2d6a4f');
    $pad    = $large ? '12px 32px' : '10px 24px';
    $size   = $large ? ';font-size:16px' : '';
    return '<p style="margin-top:16px"><a href="' . e($url) . '" '
        . 'style="display:inline-block;padding:' . $pad . ';background:' . e($accent) . ';color:#fff;'
        . 'text-decoration:none;border-radius:8px;font-weight:bold' . $size . '">' . e($label) . '</a></p>';
}

/** Small grey paragraph. $html is trusted markup, escape before passing.
 *  $extra is a CSS prefix such as 'margin-top:16px;'. */
function email_muted(string $html, string $extra = ''): string
{
    return '<p style="' . $extra . 'color:#6b7280;font-size:13px">' . $html . '</p>';
}

/** Plain text link in the accent color. */
function email_link(string $url, ?string $accent = null): string
{
    $accent = $accent ?? (string) school_setting('accent_color', '#2d6a4f');
    return '<a href="' . e($url) . '" style="color:' . e($accent) . '">' . e($url) . '</a>';
}

/** Large spaced one-time code in the accent color. */
function email_code(string $code, ?string $accent = null): string
{
    $accent = $accent ?? (string) school_setting('accent_color', '#2d6a4f');
    return '<p style="margin:0 0 16px 0;font-family:monospace;font-size:28px;font-weight:bold;'
        . 'letter-spacing:.4em;color:' . e($accent) . '">' . e($code) . '</p>';
}
