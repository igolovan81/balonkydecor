# Mobile homepage hero layout fixes

## Problem

A screenshot from a real phone showed the homepage hero carousel and header
breaking down below the 768px breakpoint:

1. **Carousel arrows float in the wrong place.** `.hero-carousel-arrow` is
   `position: absolute; top: 50%` relative to `.hero-carousel-inner`, which
   holds the entire slide (image + heading + subtitle + button). That's
   correct on desktop, where image and copy sit side by side and 50% lands on
   the image's center. On mobile, `.hero-slide` stacks the image above the
   copy (`grid-template-columns: 1fr`, `.hero-slide-media { order: -1 }`), so
   the block is much taller and 50% lands near the bottom of the image
   instead of centering on it — the arrows appear to float in dead space
   between the image and the heading.
2. **Header row wraps awkwardly.** `.cart-link, .wishlist-link,
   .compare-link { order: 2; margin-left: auto }` all sit on the same flex
   line as the logo below 768px. When the combined width doesn't fit, the
   line breaks mid-group (e.g. wishlist+compare end up on one line, cart +
   hamburger wrap to a second line, each independently right-aligned by its
   own `margin-left: auto`), producing a lopsided two-row header with an
   oversized gap between the rows.
3. **General excess whitespace** around the hero copy/dots on mobile from
   unreduced gaps.

## Fix

All changes are CSS (`www/assets/css/style.css`) plus one markup wrapper
(`templates/public/home.twig`), scoped to the existing 768px breakpoint. No
new breakpoints, no JS changes, no new dependencies.

### 1. Carousel arrows

Wrap the two arrow buttons in `home.twig` with a new `.hero-carousel-nav`
div:

```twig
<div class="hero-carousel-nav">
    <button ... data-hero-prev>‹</button>
    <button ... data-hero-next>›</button>
</div>
```

CSS:

```css
.hero-carousel-nav { display: contents; }

@media (max-width: 768px) {
    .hero-carousel-nav {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 1rem;
    }
    .hero-carousel-arrow { position: static; transform: none; }
}
```

`display: contents` makes the wrapper invisible to layout above 768px, so
desktop keeps its current absolute-positioned overlay behavior unchanged.
Below 768px the wrapper becomes a centered flex row and the buttons drop out
of absolute positioning, rendering as a normal "‹ ›" control row under the
CTA button — a predictable position instead of an accidental one.
`hero-carousel.js` selects buttons by `[data-hero-prev]`/`[data-hero-next]`
attribute, which stay on the same elements, so no JS change is needed.

### 2. Header row

```css
@media (max-width: 768px) {
    .header-inner { gap: 1rem; }   /* was inheriting the base 2rem */
    .logo { flex-basis: 100%; }
}
```

Forcing the logo onto its own full-width line (only takes effect under
`flex-wrap: wrap`, which is itself only enabled inside this same media
query, so desktop is unaffected) means the wishlist/compare/cart/hamburger
group is the entirety of the second line and wraps together instead of
splitting. The reduced gap tightens the icon row.

### 3. Spacing trim (mobile only)

```css
@media (max-width: 768px) {
    .hero-slide { gap: 1.25rem; }        /* was 2rem */
    .hero-carousel-dots { margin-top: 1rem; }  /* was 1.5rem */
}
```

## Testing

CSS/Twig changes aren't unit-tested per `.claude/rules/unit-testing.md` —
verified by rendering `http://localhost:8080/cs/` locally and checking the
header and hero carousel at mobile widths.

## Addendum: "Go to shop" CTA between carousel and recently-viewed

Added a static button in `home.twig` between the `.hero-carousel` section
and `public/partials/recently-viewed-row.twig`, independent of the
per-slide, admin-editable hero CTA (`slide.cta_label`/`slide.cta_url`)
which is left untouched. New key `home.go_to_shop` (all 5 `lang/*.json`
files), reusing the wording already established for this action in
`cart.empty_cta`. Links to `/{{ lang }}/shop`, styled with the existing
`.btn.btn-primary`; new `.home-shop-cta` rule just adds centering/padding.
