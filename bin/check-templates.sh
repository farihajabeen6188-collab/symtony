#!/bin/sh
# Design-system guard: templates must use tokens, not raw hex colors or arbitrary pixel values.
# SVG geometry (viewBox coordinates, stroke widths) is unitless and therefore allowed.
set -eu
cd "$(dirname "$0")/.."

fail=0
if grep -rnE '#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?\b' templates --include='*.twig' | grep -vE '^\S+:[0-9]+:\s*\{#|href="#|"#"|#[a-z-]+\b' ; then
    echo "✗ Raw hex colors found in templates. Use tokens (text-err, bg-surface, var(--accent))." >&2
    fail=1
fi
if grep -rnE '[0-9]+px' templates --include='*.twig' ; then
    echo "✗ Raw px values found in templates. Use the spacing scale or a token." >&2
    fail=1
fi
[ "$fail" -eq 0 ] && echo "✓ Templates use design tokens only."
exit "$fail"
