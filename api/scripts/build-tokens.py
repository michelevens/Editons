#!/usr/bin/env python3
"""Regenerate resources/css/tokens.css from the handoff's tokens.json.

The handoff requires components to carry no ad-hoc hexes. Transcribing sixty
values by hand is how a palette silently drifts from its source, so this is
generated. Edit tokens.json and re-run; never edit tokens.css directly.

Two sources feed this, in order:

  1. publishing-studio-handoff/tokens.json -- the full UI system, 47 colours
     plus type, radii, shadows and layout metrics.
  2. ecrivain-brand-assets/brand/tokens.json -- the product's own brand
     palette, which is authoritative for the handful of colours that carry
     the identity.

The brand file deliberately does not replace the UI system: it names seven
colours, and swapping all 47 for them would be a redesign rather than a
rebrand. BRAND_OVERRIDES below is the explicit map of which UI tokens the
brand owns, so the two cannot drift silently and nobody has to guess which
blue is the real one.

    python scripts/build-tokens.py
"""

import json
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[1]
SOURCE = ROOT.parent / 'publishing-studio-handoff' / 'tokens.json'
BRAND = ROOT.parent / 'ecrivain-brand-assets' / 'brand' / 'tokens.json'
TARGET = ROOT / 'resources' / 'css' / 'tokens.css'

# UI token -> brand colour. Only the tokens that carry the identity: the
# primary action colour, the darkest text, the success green and the colour
# that means "this came from the AI". Everything else stays as the handoff
# designed it, because it is a UI system rather than a logo.
BRAND_OVERRIDES = {
    'brand.primary': 'blue',
    'text.primary': 'navy',
    'status.success': 'success',
    'ai.dot': 'ai-purple',
}

# Text colours that failed WCAG AA (4.5:1) on the surfaces they sit on. The
# handoff's warning amber was 1.99:1 on its own tint and the secondary grey
# 4.37:1 on the app background, and axe flagged one or the other on every
# screen. Each value below is the nearest colour of the same hue that clears
# 4.5:1 on white, on the app background and on its own -bg/-tile tint.
#
# Applied after the brand: brand.primary is the brand blue, which is kept for
# fills (brand.fill below) but is 4.16:1 as text on its own subtle tint.
ACCESSIBILITY_OVERRIDES = {
    'text.secondary': '#5B6478',
    'text.muted': '#5F6880',
    'brand.primary': '#1A56DB',
    'status.danger': '#C42B30',
    'status.warning': '#8A5A00',
    'status.success': '#137A52',
}

# Tokens the handoff does not have.
#
# A colour used as text and the same colour used as a fill under white text
# cannot both pass in dark mode: text has to get lighter, a fill under white
# text has to stay dark. The *.fill tokens are the second use, so the
# components say which one they mean. bg.inverse / text.inverse are the dark
# chip under light text (toasts, the primary dark button), which flips too.
EXTRA = {
    'brand.fill': '#2166F5',
    'status.danger.fill': '#C42B30',
    'status.warning.fill': '#8A5A00',
    'status.success.fill': '#137A52',
    'status.info.fill': '#1F56E0',
    'bg.inverse': '#10223D',
    'text.inverse': '#FFFFFF',
}

# The dark palette. Only colours: type, radii and metrics do not change with
# the theme. Every text/surface pair here clears 4.5:1; the sidebar was
# already dark and keeps its colours. Applied under html.dark — see
# resources/views/app.blade.php for how the class is chosen.
DARK = {
    'bg.app': '#0E1422',
    'bg.surface': '#161E2E',
    'bg.subtle': '#1C2638',
    'bg.sidebar': '#0A101C',
    'bg.sidebar.active': '#22314D',
    'border.default': '#2A3449',
    'border.input': '#3A4560',
    'border.divider': '#222C3F',
    'border.sidebar': '#1F2A40',
    'text.primary': '#EEF2F8',
    'text.body': '#D3D9E4',
    'text.secondary': '#A4AEC2',
    'text.muted': '#9AA5BA',
    'brand.primary': '#7DA6FF',
    'brand.primary.hover': '#1D4ED8',
    'brand.primary.subtle': '#1D2B4A',
    'brand.primary.border': '#3B5694',
    'ai.dot': '#9B7CF5',
    'status.danger': '#FF8A8D',
    'status.danger.bg': '#3A1C20',
    'status.danger.tile': '#45202A',
    'status.info': '#8CB0FF',
    'status.info.bg': '#1A2742',
    'status.warning': '#F2B84B',
    'status.warning.bg': '#33270F',
    'status.warning.tile': '#3D2E10',
    'status.success': '#4FD1A0',
    'status.success.bg': '#12301F',
    'status.success.tile': '#153826',
    'highlight.ai.bg': '#24365C',
    'callout.bg': '#1A2540',
    'callout.border': '#2E4170',
    'outline.active.bg': '#22325A',
    'outline.active.text': '#B9CCFF',
    'brand.fill': '#2563EB',
    'bg.inverse': '#E4E9F3',
    'text.inverse': '#10223D',
}


def var(name: str) -> str:
    return name.replace('.', '-')


def main() -> None:
    tokens = json.loads(SOURCE.read_text(encoding='utf-8'))
    brand = json.loads(BRAND.read_text(encoding='utf-8'))['colors']

    applied = []

    for ui_token, brand_colour in BRAND_OVERRIDES.items():
        if brand_colour not in brand:
            raise SystemExit(f'Brand tokens have no colour named [{brand_colour}].')

        if ui_token not in tokens['color']:
            raise SystemExit(f'The UI palette has no token named [{ui_token}].')

        applied.append((ui_token, tokens['color'][ui_token], brand[brand_colour]))
        tokens['color'][ui_token] = brand[brand_colour]

    for ui_token, value in ACCESSIBILITY_OVERRIDES.items():
        if ui_token not in tokens['color']:
            raise SystemExit(f'The UI palette has no token named [{ui_token}].')

        applied.append((ui_token, tokens['color'][ui_token], value))
        tokens['color'][ui_token] = value

    tokens['color'].update(EXTRA)

    for ui_token in DARK:
        if ui_token not in tokens['color']:
            raise SystemExit(f'The dark palette names [{ui_token}], which is not a token.')

    out = [
        '/* Design tokens from publishing-studio-handoff/tokens.json,',
        '   with the identity colours taken from ecrivain-brand-assets.',
        '   GENERATED by scripts/build-tokens.py -- do not edit by hand.',
        '   Edit either tokens.json and re-run the script instead. */',
        '@theme {',
        '  /* Colours */',
    ]

    for key, value in tokens['color'].items():
        out.append(f'  --color-{var(key)}: {value};')

    out += ['', '  /* Fonts */']
    for key, value in tokens['font'].items():
        out.append(f'  --font-{var(key)}: {value};')

    out += ['', '  /* Radii */']
    for key, value in tokens['radius'].items():
        out.append(f'  --radius-{var(key)}: {value}px;')

    out += ['', '  /* Shadows */']
    for key, value in tokens['shadow'].items():
        out.append(f'  --shadow-{var(key)}: {value};')

    out += ['', '  /* Layout metrics (px, from the 1536x1024 frame) */']

    def flatten(prefix: str, obj: dict) -> None:
        for key, value in obj.items():
            name = f'{prefix}-{var(key)}' if prefix else var(key)

            if isinstance(value, dict):
                flatten(name, value)
            elif isinstance(value, list):
                for index, item in enumerate(value):
                    out.append(f'  --spacing-{name}-{index}: {item}px;')
            else:
                out.append(f'  --spacing-{name}: {value}px;')

    flatten('', tokens['layout'])

    out += ['', '  /* Type scale */']
    for key, value in tokens['type'].items():
        if 'size' in value:
            out.append(f'  --text-{var(key)}: {value["size"]}px;')
        if 'lineHeight' in value:
            out.append(f'  --text-{var(key)}--line-height: {value["lineHeight"]}px;')
        if 'weight' in value:
            out.append(f'  --font-weight-{var(key)}: {value["weight"]};')

    out.append('}')

    # Dark mode re-points the same variables, so every utility built on a
    # token (bg-bg-surface, text-text-primary...) flips without a dark:
    # variant on each element. color-scheme flips form controls and
    # scrollbars with it.
    out += ['', '/* Dark theme: the same tokens, re-pointed. */', 'html.dark {', '  color-scheme: dark;']
    for key, value in DARK.items():
        out.append(f'  --color-{var(key)}: {value};')
    out.append('}')

    TARGET.write_text('\n'.join(out) + '\n', encoding='utf-8')
    print(f'wrote {TARGET.relative_to(ROOT)} ({len(out)} lines)')

    # Printed so the override is visible rather than silent: somebody reading
    # tokens.json and then the stylesheet should be told why four colours
    # differ, instead of discovering it.
    for ui_token, was, now in applied:
        changed = '' if was.upper() != now.upper() else '  (already matched)'
        print(f'  override: --color-{var(ui_token)}  {was} -> {now}{changed}')


if __name__ == '__main__':
    main()
