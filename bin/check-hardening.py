#!/usr/bin/env python3
"""Assert that no executable payload survives a Divi -> Elementor conversion.

Usage:
    php bin/convert.php fixtures/divi-hardening-sample.json out/
    python3 bin/check-hardening.py out/divi-hardening-sample-elementor.json

Judges each payload in the *settings field it landed in* rather than grepping the
whole file. That distinction matters: sanitize_html_class() legitimately turns
`a' onmouseover='alert(1)` into the inert identifier `aonmouseoveralert1`, which
a naive grep for "onmouseover" reports as a leak.

Exit status is 0 when clean, 1 when anything executable survived.
"""

import json
import re
import sys

# (label, pattern, keys) — pattern must not appear in the listed settings keys.
# `keys=None` means every key.
#
# The patterns are deliberately anchored to where a payload would have to sit to
# actually execute. A bare "javascript:" or "alert(" in a value is not a finding:
# core's wp_kses_post() strips the *scheme* from href/src and leaves the rest as
# an inert relative URL, so `href="alert('x')"` is the correct, safe output — and
# the same words appearing in an anchor's visible text are just text.
CHECKS = [
    ("executable tag",     r"<\s*(script|iframe|style|object|embed|form)\b", None),
    ("event handler attr", r"\son[a-z]+\s*=", None),
    ("script protocol",    r"(?:href|src|action|formaction)\s*=\s*[\"']?\s*(?:javascript|vbscript|data)\s*:", None),
    ("css @import",        r"@import", None),
    ("style breakout",     r"</\s*style", None),
    ("remote host",        r"evil\.example", None),
    # Script bodies left behind in a stylesheet, which is a CSS-only concern.
    ("script text in css", r"alert\s*\(", {"custom_css"}),
]

# Content the conversion must NOT destroy — a sanitizer that eats everything
# would pass every check above while making the plugin useless.
MUST_SURVIVE = [
    "Safe markup from a Code module is kept.",
    "<strong>formatting</strong>",
]


def collect(node, out):
    """Gather every (widget_type, key, value) string under any `settings` dict."""
    if isinstance(node, list):
        for item in node:
            collect(item, out)
        return
    if not isinstance(node, dict):
        return

    settings = node.get("settings")
    if isinstance(settings, dict):
        label = node.get("widgetType") or node.get("elType") or "?"
        for key, value in settings.items():
            if isinstance(value, str):
                out.append((label, key, value))

    for value in node.values():
        if isinstance(value, (dict, list)):
            collect(value, out)


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__, file=sys.stderr)
        return 2

    raw = open(sys.argv[1], encoding="utf-8").read()
    values = []
    collect(json.loads(raw), values)

    if not values:
        print("FAIL: no widget settings found — the walker did not reach the tree.")
        return 1

    print(f"Scanned {len(values)} settings values.\n")

    failed = False
    for label, pattern, keys in CHECKS:
        hits = [(w, k, v) for w, k, v in values
                if (keys is None or k in keys) and re.search(pattern, v, re.I)]
        if hits:
            failed = True
            print(f"LEAKED  {label}")
            for widget, key, value in hits:
                print(f"          [{widget}] {key} = {value!r}")
        else:
            print(f"blocked {label}")

    nested = [(w, k, v) for w, k, v in values
              if k == "custom_css" and re.search(r"\{[^{}]*\{", v)]
    if nested:
        failed = True
        print("\nLEAKED  nested braces in custom_css (rule may escape its selector scope)")
        for widget, key, value in nested:
            print(f"          [{widget}] {key} = {value!r}")
    else:
        print("blocked nested braces in custom_css")

    print()
    for needle in MUST_SURVIVE:
        if any(needle in v for _, _, v in values):
            print(f"preserved {needle!r}")
        else:
            failed = True
            print(f"DESTROYED {needle!r} — sanitizing is too aggressive")

    print("\nRESULT:", "FAIL" if failed else "PASS")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
