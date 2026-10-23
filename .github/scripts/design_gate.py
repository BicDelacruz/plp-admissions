#!/usr/bin/env python3
"""Design gate: UI consistency checks across the whole app.

Usage:
  python3 -I .github/scripts/design_gate.py <project_root>            check
  python3 -I .github/scripts/design_gate.py <project_root> --update   rewrite baseline

Two kinds of rules:

  STRICT   must have zero violations, always.
  RATCHET  the app already has legacy violations. They are recorded per file in
           design_gate_baseline.json. The gate fails when any file gets WORSE
           than its baseline, so nothing new slips in. When you clean a file up,
           run with --update to lock in the lower number.
"""
import json
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1]).resolve()
update = "--update" in sys.argv[2:]
baseline_path = root / ".github/scripts/design_gate_baseline.json"

SKIP_DIRS = {".git", "lib", "vendor", "node_modules", "uploads"}
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


def has_class(tag, wanted):
    cls = attr(tag, "class") or ""
    return any(w in cls for w in wanted)


strict = []          # [(file, line, message)]
counts = {}          # rule -> file -> count


def ratchet(rule, rel):
    counts.setdefault(rule, {})
    counts[rule][rel] = counts[rule].get(rel, 0) + 1


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

    # ── RATCHET: em dashes in anything that is not a comment line ─
    for line in text.splitlines():
        if EM_DASH in line and not line.lstrip().startswith(COMMENT_START):
            ratchet("no-em-dash", rel)

    # ── RATCHET: form fields without the shared classes ─────────
    for _, t in find_tags(text, "input"):
        if (attr(t, "type") or "text").lower() in NON_TEXT_INPUT:
            continue
        if not has_class(t, FIELD_CLASSES):
            ratchet("shared-field-class", rel)
    for tagname in ("select", "textarea"):
        for _, t in find_tags(text, tagname):
            if not has_class(t, FIELD_CLASSES):
                ratchet("shared-field-class", rel)

    # ── RATCHET: buttons without the shared button classes ──────
    for _, t in find_tags(text, "button"):
        if not has_class(t, ("btn",)):
            ratchet("shared-button-class", rel)

    # ── RATCHET: hardcoded colors and px font sizes in inline styles
    for m in RE_STYLE_ATTR.finditer(text):
        style = m.group(1)
        if RE_HEX.search(style):
            ratchet("inline-hex-color", rel)
        if RE_FONT_PX.search(style):
            ratchet("inline-px-font-size", rel)

# ── STRICT: app.css must keep the shared date field rules ───────
css_path = root / "public/assets/css/app.css"
css = css_path.read_text(encoding="utf-8")
for sel in ('.form-input[type="date"]', '.form-control[type="date"]'):
    if sel not in css:
        strict.append(("public/assets/css/app.css", 0, "missing shared date field rule: " + sel))

# ── RATCHET: hardcoded hex colors in app.css outside the :root token block
outside_root, depth, in_root = 0, 0, False
for line in css.splitlines():
    s = line.strip()
    if s.startswith(":root") or s.startswith("html[data-theme") or s.startswith('[data-theme'):
        in_root = True
    if in_root:
        depth += line.count("{") - line.count("}")
        if depth <= 0:
            in_root, depth = False, 0
        continue
    if RE_HEX.search(line) and not s.startswith(("/*", "*", "//")):
        outside_root += 1
if outside_root:
    counts.setdefault("css-hex-outside-tokens", {})["public/assets/css/app.css"] = outside_root

# ── Baseline handling ───────────────────────────────────────────
counts = {r: dict(sorted(v.items())) for r, v in sorted(counts.items())}

if update:
    baseline_path.write_text(json.dumps(counts, indent=2) + "\n", encoding="utf-8")
    total = sum(sum(v.values()) for v in counts.values())
    print("Baseline written: %d legacy violations across %d rules." % (total, len(counts)))
    sys.exit(1 if strict else 0)

baseline = json.loads(baseline_path.read_text(encoding="utf-8")) if baseline_path.exists() else {}
regressions, improved = [], []
for rule in sorted(set(counts) | set(baseline)):
    cur, base = counts.get(rule, {}), baseline.get(rule, {})
    for rel in sorted(set(cur) | set(base)):
        c, b = cur.get(rel, 0), base.get(rel, 0)
        if c > b:
            regressions.append("%s: %s has %d (baseline %d)" % (rule, rel, c, b))
        elif c < b:
            improved.append("%s: %s dropped %d -> %d" % (rule, rel, b, c))

for rel, ln, msg in strict:
    print("FAIL %s%s  %s" % (rel, (":%d" % ln) if ln else "", msg))
for r in regressions:
    print("FAIL (new violation) " + r)
if improved:
    print("\nCleaner than baseline. Run with --update to lock it in:")
    for i in improved:
        print("  " + i)

if strict or regressions:
    print("\nDesign gate failed. Use the shared classes and var(--token) values, no em dashes in UI text.")
    sys.exit(1)
print("Design gate passed")
