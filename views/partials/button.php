<?php
// ============================================================
// views/partials/button.php
// Shared button component. One renderer for every button that sits
// in a page heading (and any other place that wants the standard
// look), so size, spacing and icon placement never drift per page.
// Mirrors lakbay-pasig's <Button variant size> (components/ui/button.tsx).
//
//   ui_button('Add Session', [
//       'variant' => 'primary',          // primary|secondary|ghost|success|danger|outline-danger|'' (plain)
//       'size'    => '',                 // ''=default 36px, 'sm'=32px, 'lg'=40px
//       'icon'    => 'plus',             // any icon() name
//       'icon_pos'=> 'before',           // before|after
//       'href'    => url('/somewhere'),  // set => <a>, omitted => <button>
//       'type'    => 'button',           // <button> only
//       'attrs'   => ['id' => 'x', 'onclick' => 'go()', 'title' => '...'],
//   ]);
//
// Label and attribute values are escaped. Returns an HTML string.
// Sizes live in app.css (.btn / .btn-sm / .btn-lg); this file only
// picks the classes, it never hard-codes pixel sizes.
// ============================================================

if (!function_exists('ui_button')) {
    function ui_button(string $label, array $o = []): string
    {
        $variant = (string)($o['variant'] ?? '');
        $size    = (string)($o['size'] ?? '');
        $iconPos = (string)($o['icon_pos'] ?? 'before');

        $cls = 'btn';
        if ($variant !== '') $cls .= ' btn-' . preg_replace('/[^a-z-]/', '', $variant);
        if ($size    !== '') $cls .= ' btn-' . preg_replace('/[^a-z]/', '', $size);

        $attrs = ' class="' . e($cls) . '"';
        $isLink = isset($o['href']);
        if ($isLink) {
            $attrs .= ' href="' . e((string)$o['href']) . '"';
        } else {
            $attrs .= ' type="' . e((string)($o['type'] ?? 'button')) . '"';
        }
        foreach ((array)($o['attrs'] ?? []) as $name => $value) {
            if (!preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', (string)$name)) continue;
            $attrs .= ' ' . $name . '="' . e((string)$value) . '"';
        }

        $ico  = !empty($o['icon']) ? icon((string)$o['icon'], 16) : '';
        $text = e($label);
        $body = ($iconPos === 'after') ? $text . $ico : $ico . $text;
        $tag  = $isLink ? 'a' : 'button';

        return '<' . $tag . $attrs . '>' . $body . '</' . $tag . '>';
    }
}
