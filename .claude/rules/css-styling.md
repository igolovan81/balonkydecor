---
description: CSS/styling conventions — design tokens in :root, 768px/480px breakpoints, flat kebab-case naming with --modifier variants, focus/keyboard accessibility, inline SVG assets.
globs: ["www/assets/css/**/*.css", "templates/**/*.twig"]
alwaysApply: false
---

# CSS / Styling Conventions

Applies to `www/assets/css/style.css` (public) and `www/assets/css/admin.css` (admin).
There is no build step — plain CSS only. Do not introduce Sass, PostCSS, Tailwind, or
frameworks.

## Design tokens

All colors, fonts, and the layout width live as custom properties in `:root` at the top
of `style.css`:

| Token | Use for |
|-------|---------|
| `--bg` | page background |
| `--surface` | card/header/panel backgrounds (white) |
| `--surface-warm` | warm tint for gradients/placeholders |
| `--text` / `--text-inverse` | body text / text on accent backgrounds |
| `--muted` | secondary text |
| `--accent` / `--accent-dark` | brand bronze / its hover shade |
| `--border` | borders and subtle fills |
| `--font` / `--ui-font` | serif headings-and-body / system UI chrome |

Rules:
- Any color used **more than once** must be a token. One-off colors (status badges,
  third-party brand colors like Facebook blue) stay literal.
- Never hardcode `#fff`, the bronze `#b8967a`, or its hover `#a0806a` — use the tokens.

## Breakpoints

Two breakpoints only, desktop-first with `max-width`:
- **768px** — tablet/phone: nav collapses to hamburger, grids tighten, layouts stack
- **480px** — phone: single columns, stacked cart table, full-width buttons

Custom properties cannot be used inside `@media` conditions, so these stay literal —
do not invent new breakpoint values. Keep responsive rules in a small `@media` block
**next to the component they modify** (there are intentionally several blocks per
breakpoint), not in one giant media query at the end.

## Naming

- Flat, single-class, kebab-case selectors (`.gallery-album-card`). No IDs, no
  `!important`, no deep nesting.
- **Variants** use a BEM-style `--modifier` suffix: `.order-status--paid`.
- **States** (toggled by JS or router) use bare classes: `.active`, `.is-open`, `.large`.

## Accessibility

- Interactive elements must be reachable by keyboard: real `<a>`/`<button>` elements,
  never `<span>`/`<div>` with hover-only behavior.
- Anything shown on `:hover` must also show on `:focus-within` (see `.nav-item-dropdown`).
- The global `:focus-visible` outline at the top of `style.css` covers links, buttons,
  and form fields — don't remove outlines without a replacement.
- Form inputs keep `font-size: 16px` at the 480px breakpoint to prevent iOS zoom.

## Assets

- Small decorative graphics are inlined as `data:image/svg+xml` URIs in the CSS
  (encode `#` as `%23`), not separate files — e.g. the balloon media placeholder.
- CSS is cache-busted at deploy time; no manual versioning needed.
