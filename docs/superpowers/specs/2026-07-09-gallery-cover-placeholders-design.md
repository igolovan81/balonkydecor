# Gallery/product cover placeholders — design

Date: 2026-07-09

## Problem

Album cards on the gallery index (`/{lang}/services/archive`) show a flat gray box whenever
`gallery_albums.cover_image` is empty — even when the album is full of photos/videos.
Product cards and the product detail page show the same flat gray box when a product has
no images.

## Solution

### 1. Auto-cover fallback (model + template)

`GalleryModel::albums()` returns two computed columns:

- `cover_file` — the admin-set `cover_image` if non-empty; otherwise the album's first
  item, preferring photos over videos, ordered by `sort_order, id`. NULL if the album is
  empty.
- `cover_is_video` — `1` only when the fallback item is a video (an explicit admin cover
  is always treated as an image).

Implemented with correlated subqueries (no LATERAL join) so it runs on the shared-hosting
MySQL version.

`templates/public/gallery/index.twig` renders:

- image cover → `<img>` (as today)
- video cover → `<video preload="metadata" muted playsinline>` — the browser shows the
  first frame; no controls; still wrapped in the album link
- no items → the branded placeholder div

### 2. Branded placeholder (CSS only)

`.gallery-cover-placeholder` and `.product-img-placeholder` share one look: soft beige
gradient (`#f5f1ea → var(--border)`) with a centered line-art balloon-bunch SVG in the
brand bronze (`var(--accent)`), embedded as a data-URI background. No template changes
needed on the shop pages — they already emit those classes.

### 3. Out of scope

- No schema changes, no admin UI changes. An explicit admin-set cover always wins.
- `GalleryModel::album()` (detail page) is unchanged.

## Testing

Extend the gallery model tests (real MySQL via Docker, per project convention):

1. Album with images but empty `cover_image` → `cover_file` = first image, `cover_is_video` = 0.
2. Album whose only items are videos → `cover_file` = first video, `cover_is_video` = 1.
3. Album with an explicit `cover_image` → that value wins, `cover_is_video` = 0.
4. Empty album → `cover_file` is NULL.
