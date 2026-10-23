#!/usr/bin/env python3
"""Design gate: UI consistency checks across the whole app.

Usage:
  python3 -I .github/scripts/design_gate.py <project_root>

Every rule is strict: any violation fails the gate. There is no baseline file.
The only exemption is core/email_html.php for inline color and size rules, because
email clients ignore CSS variables.
"""
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1]).resolve()

SKIP_DIRS = {".git", "lib", "vendor", "node_modules", "uploads"}
# Email clients ignore CSS variables, so HTML email markup needs literal hex colors and
# px sizes. That markup lives in this one file. Exempt from the inline color/size rules only.
EMAIL_HTML_FILE = "core/email_html.php"
COMMENT_START = ("//", "/*", "*", "#", "<!--", "-->", "--")

EM_DASH = "—"
def find_tags(text, name):
    """Yield (start, tag_text) for every <name ...> tag. Scans linearly and skips
    inline PHP blocks, so a "?>" inside an attribute does not end the tag early."""
    for m in re.finditer(r"<%s\b" % name, text, re.I):
        i, n = m.end(), len(text)
        while i < n:
            if text.startswith("<?", i):
                j = text.find("?>", i + 2)
                i = n if j == -1 else j + 2
            elif text[i] == ">":
                yield m.start(), text[m.start():i + 1]
                break
            else:
                i += 1


RE_STYLE_ATTR = re.compile(r'style="([^"]*)"', re.S)
RE_HEX = re.compile(r"#[0-9a-fA-F]{3,8}\b")
RE_FONT_PX = re.compile(r"font-size:\s*\d+px")
FIELD_CLASSES = ("form-input", "form-control", "form-select", "form-textarea", "dp-date-in")
# Distinct controls that own their CSS and are deliberately not .btn or .form-*.
# A class is matched as a whole token. Add to these lists only for a real,
# styled component, never to silence one violation.
COMPONENT_BUTTON_CLASSES = {
    "dp-item", "seg-control-item", "drag-handle", "iq-add-choice", "rm",
    "student-header-avatar", "auth-pw-eye", "ap-drawer-close", "nav-item",
    "sidebar-logo-toggle",
}
COMPONENT_FIELD_CLASSES = {
    "toolbar-select", "choice-input", "inline-edit-input", "exam-inline",
    "short-answer-input", "paragraph-input",
}
NON_TEXT_INPUT = {"hidden", "checkbox", "radio", "file", "submit", "button", "range", "color", "image"}


def php_files():
    for f in sorted(root.rglob("*.php")):
        rel = f.relative_to(root)
        if SKIP_DIRS & set(rel.parts):
            continue
        yield f, rel.as_posix()


def line_of(text, pos):
    return text.count("\n", 0, pos) + 1


def attr(tag, name):
    m = re.search(r'\b%s="([^"]*)"' % name, tag)
    return m.group(1) if m else None


def has_component(tag, allowed):
    cls = re.sub(r"<\?.*?\?>", " ", attr(tag, "class") or "", flags=re.S)  # drop inline PHP
    return bool(set(cls.split()) & allowed)


def has_class(tag, wanted):
    cls = attr(tag, "class") or ""
    return any(w in cls for w in wanted)


strict = []          # [(file, line, message)]


for f, rel in php_files():
    text = f.read_text(encoding="utf-8", errors="ignore")

    # ── STRICT: date and time fields ────────────────────────────
    for pos, t in find_tags(text, "input"):
        if not re.search(r'\btype="(?:date|time|datetime-local)"', t):
            continue
        ln = line_of(text, pos)
        if not has_class(t, FIELD_CLASSES):
            strict.append((rel, ln, "date/time input lacks a shared field class"))
        w = re.search(r"width:\s*(\d+)px", t)
        if w and int(w.group(1)) < 150:
            strict.append((rel, ln, "date/time input is %spx wide, minimum is 150px" % w.group(1)))

    # ── STRICT: em dashes in anything that is not a comment line ─
    for n, line in enumerate(text.splitlines(), 1):
        if EM_DASH in line and not line.lstrip().startswith(COMMENT_START):
            strict.append((rel, n, "em dash in UI text, use a hyphen or rewrite"))

    # ── STRICT: form fields without the shared classes ──────────
    for pos, t in find_tags(text, "input"):
        if (attr(t, "type") or "text").lower() in NON_TEXT_INPUT:
            continue
        if not has_class(t, FIELD_CLASSES) and not has_component(t, COMPONENT_FIELD_CLASSES):
            strict.append((rel, line_of(text, pos), "field lacks a shared field class"))
    for tagname in ("select", "textarea"):
        for pos, t in find_tags(text, tagname):
            if not has_class(t, FIELD_CLASSES) and not has_component(t, COMPONENT_FIELD_CLASSES):
                strict.append((rel, line_of(text, pos), "%s lacks a shared field class" % tagname))

    # ── STRICT: buttons without the shared button classes ───────
    for pos, t in find_tags(text, "button"):
        if not has_class(t, ("btn",)) and not has_component(t, COMPONENT_BUTTON_CLASSES):
            strict.append((rel, line_of(text, pos), "button lacks a btn class (use ui_button or btn variants)"))

    # ── STRICT: hardcoded colors and px font sizes in inline styles
    for m in ([] if rel == EMAIL_HTML_FILE else RE_STYLE_ATTR.finditer(text)):
        style = m.group(1)
        ln = line_of(text, m.start())
        if RE_HEX.search(style):
            strict.append((rel, ln, "inline hex color, use a var(--token)"))
        if RE_FONT_PX.search(style):
            strict.append((rel, ln, "inline px font size, use a --text-* token"))

# ── STRICT: app.css must keep the shared date field rules ───────
css_path = root / "public/assets/css/app.css"
css = css_path.read_text(encoding="utf-8")
for sel in ('.form-input[type="date"]', '.form-control[type="date"]'):
    if sel not in css:
        strict.append(("public/assets/css/app.css", 0, "missing shared date field rule: " + sel))

# ── STRICT: hardcoded hex colors in app.css outside the :root token block
depth, in_root = 0, False
for n, line in enumerate(css.splitlines(), 1):
    s = line.strip()
    if s.startswith(":root") or s.startswith("html[data-theme") or s.startswith('[data-theme'):
        in_root = True
    if in_root:
        depth += line.count("{") - line.count("}")
        if depth <= 0:
            in_root, depth = False, 0
        continue
    if RE_HEX.search(line) and not s.startswith(("/*", "*", "//")):
        strict.append(("public/assets/css/app.css", n, "hex color outside the :root tokens, add a token"))

# ── Report ──────────────────────────────────────────────────────
for rel, ln, msg in strict:
    print("FAIL %s%s  %s" % (rel, (":%d" % ln) if ln else "", msg))
if strict:
    print("\nDesign gate failed: %d violation(s). Use the shared classes and var(--token) values, no em dashes in UI text." % len(strict))
    sys.exit(1)
print("Design gate passed")
