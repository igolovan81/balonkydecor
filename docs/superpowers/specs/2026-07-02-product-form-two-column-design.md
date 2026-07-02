# Product Edit Form — Two-Column Layout Design

**Date:** 2026-07-02
**Scope:** Admin product form only (`templates/admin/products/form.twig`) — visual layout restructuring, no behavior change
**Status:** Approved

---

## Overview

The admin product form uses `.admin-form { max-width: 800px; }`, rendered as a single narrow card regardless of viewport width. On a wide monitor this leaves the entire right half of the screen empty (screenshot in conversation: sidebar + ~830px card, ~1050px of blank space beyond it).

This restructures the product form only into two columns: a wider **main column** (SKU, price, category, translations) and a narrower **side column** (stock status, active flag, image upload + existing images) — mirroring the "status panel" pattern approved via the visual companion (Option B). Below ~900px viewport width, the layout collapses back to a single column (main content stacked above the side panel), since the admin panel currently has no responsive handling at all and a fixed two-column layout would break on narrow/tablet screens.

No other admin form is touched. Categories, gallery, blog, pages, users, and settings keep today's single-column `.admin-form` (max-width 800px) — confirmed with the user that only Products has enough distinct "status" content (image + stock + active) to justify a second column; the others would have a mostly-empty right panel.

This is a pure template/CSS restructuring: no field is added, removed, or renamed, no controller or model changes, no new translation keys. Field names, POST body shape, and all existing JS (`#stock-type-select`/`#stock-qty-group` toggle, lang-tab switching, translate button, delete-image buttons) are unchanged — only their position in the DOM moves.

---

## Layout

**Column split:**
```
┌─────────────────────────────────────────────────────────┐
│  Main column (flex: 2)         │  Side column (flex: 1)  │
│  ─────────────────────         │  ─────────────────────  │
│  SKU                           │  Stock status (dropdown)│
│  Price                         │  Quantity (conditional) │
│  Category                      │  Active (checkbox)      │
│  Translations                  │  Add image               │
│    (lang tabs + name/desc/     │  Existing images         │
│     meta_title/meta_desc,      │                          │
│     translate button)          │                          │
├─────────────────────────────────────────────────────────┤
│  Save / Cancel (full width, below both columns)           │
└─────────────────────────────────────────────────────────┘
```

Field order within each column is unchanged from today's top-to-bottom order — only which column each field lands in changes. This means the stock dropdown/quantity fields, which were between Category and Active in the old single column, move to the side column; Category (which stays in the main column) becomes directly followed by the Translations section.

**Widths:** the form card's `max-width` increases from 800px to 1100px, via a new `.admin-form--wide` modifier class added only to the product form's `<form>` tag (the base `.admin-form { max-width: 800px; }` rule is untouched, so every other admin form keeps its current width). Main:side column ratio is 2:1 (`flex: 2` / `flex: 1`).

**Responsive collapse:** below 900px viewport width, `.product-form-columns` switches from `flex-direction: row` to `column` — the side column (stock/active/image) drops below the main column (SKU/price/category/translations) in source order. This is the first responsive breakpoint anywhere in `admin.css`; it's scoped to this one new layout, not a general admin-panel responsiveness pass.

---

## Implementation approach

`templates/admin/products/form.twig` is restructured so the form fields render inside two sibling `<div>`s (`.product-form-main`, `.product-form-side`) wrapped in a `.product-form-columns` flex container, in place of the current flat sequence of `.form-group` divs. The `<form>` tag gains the `admin-form--wide` class alongside the existing `admin-form` class. `.form-actions` (Save/Cancel) stays outside `.product-form-columns`, directly inside `<form>`, so it always spans the full card width regardless of column state.

New CSS added to `www/assets/css/admin.css`:
```css
.admin-form--wide { max-width: 1100px; }
.product-form-columns { display: flex; gap: 2rem; }
.product-form-main { flex: 2; min-width: 0; }
.product-form-side { flex: 1; min-width: 0; }
@media (max-width: 900px) {
  .product-form-columns { flex-direction: column; }
}
```
(`min-width: 0` on both flex children prevents long unbroken content — e.g. a long SKU value — from forcing the row wider than the card.)

No changes to `ProductController`, `ProductModel`, translation files, or any JS logic — the existing `#stock-type-select` / `#stock-qty-group` IDs and the `.delete-image-btn` / `.translate-btn` / `.lang-tab` classes and their event listeners are unaffected by moving their containing markup into a different column `<div>`.

---

## Testing

No automated template/CSS tests exist in this repo (confirmed pattern throughout this project). Verified by:
1. Full PHPUnit suite (sanity — this change touches no PHP).
2. Manual verification against the running local app: fetch the rendered edit-form HTML and confirm the expected fields land in the expected column `<div>`, confirm `.admin-form--wide` is present only on the product form's `<form>` tag and not on category/gallery/other forms, and visually confirm (screenshot or direct rendering check) the two-column layout at a wide viewport and single-column collapse at a narrow one.

---

## Out of Scope

- Any change to categories, gallery, blog, pages, users, or settings admin forms.
- Any new field, renamed field, or behavior change to the product form.
- A general responsive-design pass across the rest of the admin panel.
- Changes to the public-facing site (unrelated to this admin-only layout change).
