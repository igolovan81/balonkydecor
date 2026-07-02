# Services Archive (formerly Gallery) + Video Support Design

**Date:** 2026-07-02
**Scope:** Move "Gallery" into the public nav as a submenu of "Services", rename it to reflect a portfolio of completed work, move its URLs under `/services/archive`, and add MP4 video support alongside photos in albums.
**Status:** Approved

---

## Overview

Today "Gallery" (`/{lang}/gallery`, `/{lang}/gallery/{slug}`) is a standalone top-level nav item, unrelated in the URL/nav structure to "Services" (`/{lang}/services`) even though conceptually it exists to showcase completed service work. This change:

1. Nests the gallery under "Services" in the public nav as a dropdown, and moves its routes to `/{lang}/services/archive` and `/{lang}/services/archive/{slug}`.
2. Renames it across all public and admin translations from "Gallery"/"Галерея" to a "completed projects / realizace" framing (admin keeps the same `/admin/gallery` URL and page — only labels change, no admin nav restructuring).
3. Adds video support: albums can contain MP4 videos alongside photos, uploaded and deleted the same way, displayed via native `<video>` playback with no server-side thumbnail generation (no ffmpeg on WEDOS shared hosting).

No changes to `ProductModel`, `OrderModel`, `BlogModel`, or any other subsystem — this is scoped entirely to gallery/services routing, nav, and the gallery data model.

---

## Components

### 1. Routing — `src/routes.php`

- `/{lang}/services` — unchanged.
- `/{lang}/gallery` → renamed to `/{lang}/services/archive`.
- `/{lang}/gallery/{slug}` → renamed to `/{lang}/services/archive/{slug}`.
- Old `/gallery` routes are removed outright — no redirect. This is a low-traffic dev-stage site with no external backlinks to preserve.
- `GalleryController` (public) is unchanged internally — `index()`/`album()` don't reference their own route path, so only the `routes.php` registration changes.
- Admin gallery routes (`/admin/gallery`, `/admin/gallery/new`, etc.) are unchanged.

### 2. Public nav — `templates/layout/base.twig`, `www/assets/css/style.css`

"Services" becomes a dropdown parent. Structure:

```twig
<div class="nav-item-dropdown">
    <a href="/{{ lang }}/services">{{ t('nav.services') }}</a>
    <div class="nav-dropdown-menu">
        <a href="/{{ lang }}/services/archive">{{ t('nav.gallery') }}</a>
    </div>
</div>
```

CSS (new rules in `style.css`, near the existing `.main-nav` block):

```css
.nav-item-dropdown { position: relative; }
.nav-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid var(--border); min-width: 200px; z-index: 10; padding: .5rem 0; }
.nav-dropdown-menu a { display: block; padding: .5rem 1rem; }
.nav-item-dropdown:hover .nav-dropdown-menu { display: block; }
```

At the existing `@media (max-width: 768px)` breakpoint, since `.main-nav` already collapses into a static vertical list toggled by `.header-inner.is-open`, the dropdown is made always-visible/inline (no hover on touch devices):

```css
@media (max-width: 768px) {
    .nav-dropdown-menu { position: static; display: block; border: none; padding-left: 1rem; }
}
```

No JS changes — the existing `nav.js` hamburger toggle already shows/hides the whole `.main-nav` block, and the dropdown submenu rides along with it on mobile.

### 3. Admin nav — `templates/layout/admin-base.twig`

No structural change. The `<a href="/admin/gallery">{{ t('nav.gallery') }}</a>` link stays exactly where it is; only the translated label text changes (Section 6).

### 4. Database — new migration `database/migrations/V008__gallery_video_support.sql`

```sql
ALTER TABLE gallery_images
  ADD COLUMN media_type ENUM('image','video') NOT NULL DEFAULT 'image' AFTER filename;
```

`gallery_albums.cover_image` is unchanged — it remains photo-only (no admin UI exists to set it today; out of scope).

### 5. `VideoUploader` service — new `src/Services/VideoUploader.php`

Mirrors `ImageUploader`'s upload contract, without resizing or thumbnailing:

```php
namespace App\Services;

class VideoUploader
{
    public static function upload(array $file, string $dir): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }
        $mime = mime_content_type($file['tmp_name']);
        if ($mime !== 'video/mp4') {
            throw new \RuntimeException('Unsupported video type: ' . $mime);
        }
        $filename = bin2hex(random_bytes(16)) . '.mp4';
        $destDir  = rtrim($dir, '/');
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename);
        return $filename;
    }
}
```

Note: `move_uploaded_file()` requires the real `$_FILES` tmp path, not `getStream()->getMetadata('uri')` if that ever returns a non-uploaded-file stream — this matches how `ImageUploader` is already invoked from `Admin\GalleryController::handleImageUploads()` today (same `tmp_name` extraction pattern), so no new risk is introduced.

### 6. Upload size limits — `www/.htaccess`

Add (or extend the existing `.htaccess`):

```apache
php_value upload_max_filesize 50M
php_value post_max_size 55M
```

This requires WEDOS to permit per-directory PHP value overrides via `.htaccess`, which is typical for their shared hosting but unconfirmed until deployed. If the override doesn't take effect, video uploads above the current 2MB/8MB PHP defaults fail cleanly with a standard PHP upload error (no partial writes, no data corruption) — `/verify` should check actual effective limits post-deploy (e.g. via a small `phpinfo()`-based check or attempting a >8MB test upload) and this is called out explicitly so it isn't silently assumed to work.

### 7. `GalleryModel` — `src/Models/GalleryModel.php`

- **`addImage()`** gains a `$mediaType` param:
  ```php
  public static function addImage(int $albumId, string $filename, string $mediaType = 'image'): void
  {
      $pdo = Database::getConnection();
      $pdo->prepare('INSERT INTO gallery_images (album_id, filename, media_type, sort_order) VALUES (?, ?, ?, 0)')
          ->execute([$albumId, $filename, $mediaType]);
  }
  ```
  Default keeps all existing photo-upload call sites working unchanged.

- **`album()`** and **`findAlbumById()`**: the images sub-query changes from `SELECT filename ... ` + `FETCH_COLUMN` to full rows:
  ```php
  $imgs = $pdo->prepare('SELECT id, filename, media_type FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
  $imgs->execute([$album['id']]);
  $album['images'] = $imgs->fetchAll();
  ```
  (`findAlbumById()` already does `SELECT *`, so it just needs `media_type` implicitly included — no query change there beyond what the `ALTER TABLE` already provides.)

- **`deleteImage()`** returns the row instead of a bare filename, so the controller knows whether a `thumb_` file exists to clean up:
  ```php
  public static function deleteImage(int $imageId): ?array
  {
      $pdo  = Database::getConnection();
      $stmt = $pdo->prepare('SELECT filename, media_type FROM gallery_images WHERE id = ?');
      $stmt->execute([$imageId]);
      $row = $stmt->fetch();
      if (!$row) return null;
      $pdo->prepare('DELETE FROM gallery_images WHERE id = ?')->execute([$imageId]);
      return $row;
  }
  ```

### 8. Admin `GalleryController` — `src/Controllers/Admin/GalleryController.php`

- `handleImageUploads()` (existing, reads `images[]`) is renamed to read from a `photos[]` field for clarity, unchanged otherwise.
- New `handleVideoUploads()`, same shape, reads `videos[]`, calls `VideoUploader::upload()`, then `GalleryModel::addImage($albumId, $filename, 'video')`.
- `createSubmit()`/`editSubmit()` call both `handleImageUploads()` and `handleVideoUploads()`.
- `deleteImage()` / `delete()`: only `@unlink` the `thumb_` variant when `$row['media_type'] === 'image'`; videos have no thumb file to clean up. `filename` unlink happens for both types either way.

### 9. Admin form template — `templates/admin/gallery/form.twig`

- Existing photo file input renamed from `images[]` to `photos[]` (matches controller field rename), unchanged `accept="image/*"`.
- New file input: `<input type="file" name="videos[]" multiple accept="video/mp4">` with label `{{ t('gallery.form.add_videos') }}`.
- Existing media list splits into two labeled sections:
  - "Fotky v albu" (existing photo thumbnails, unchanged)
  - New "Videa v albu" section — each row renders `<video controls preload="metadata" style="max-width:200px;"><source src="/assets/uploads/gallery/{{ v.filename }}" type="video/mp4"></video>` instead of an `<img>`, with the same delete-button pattern posting to the existing `deleteImage` route (now type-aware server-side per Component 8).

### 10. Public templates — `templates/public/gallery/album.twig`

The `{% for img in album.images %}` loop changes from treating `img` as a filename string to an object with `filename`/`media_type`:

```twig
{% for img in album.images %}
    {% if img.media_type == 'video' %}
    <div class="photo-item">
        <video controls preload="metadata"><source src="/assets/uploads/gallery/{{ img.filename }}" type="video/mp4"></video>
    </div>
    {% else %}
    <a href="/assets/uploads/gallery/{{ img.filename }}" class="photo-item" target="_blank">
        <img src="/assets/uploads/gallery/{{ img.filename }}" alt="{{ album.name }}">
    </a>
    {% endif %}
{% endfor %}
```

The `back-link` and breadcrumb JSON-LD both update from `/{{ lang }}/gallery` to `/{{ lang }}/services/archive`, and the breadcrumb gains a "Services" level ahead of it:

```twig
{'@type': 'ListItem', 'position': 2, 'name': t('nav.services'), 'item': base_url ~ '/' ~ lang ~ '/services'},
{'@type': 'ListItem', 'position': 3, 'name': t('nav.gallery'), 'item': base_url ~ '/' ~ lang ~ '/services/archive'},
{'@type': 'ListItem', 'position': 4, 'name': album.name, 'item': canonical_url}
```

`templates/public/gallery/index.twig` (album listing) is unchanged in structure — `cover_image` is still always a plain image, so no media-type branching needed there.

Template files stay physically under `templates/public/gallery/` and `templates/admin/gallery/` — directory names are internal organization, independent of the public URL, so no file moves are needed.

### 11. Translations

**Public `lang/{cs,en,ru,uk,sk}.json`** — reworded (not new keys, existing keys repurposed):

| Key | cs | en | ru | uk | sk |
|---|---|---|---|---|---|
| `nav.gallery` | Naše realizace | Completed Projects | Архив оказанных услуг | Архів наданих послуг | Naše realizácie |
| `gallery.title` | Naše realizace | Completed Projects | Архив оказанных услуг | Архів наданих послуг | Naše realizácie |
| `gallery.back` | ← Zpět na realizace | ← Back to projects | ← Назад к архиву | ← Назад до архіву | ← Späť na realizácie |
| `gallery.no_albums` | Zatím žádné realizace. | No completed projects yet. | Пока нет проектов. | Поки немає проєктів. | Zatiaľ žiadne realizácie. |

**Admin `lang/admin/{cs,en,ru,uk,sk}.json`**:

- `nav.gallery` and `gallery.title` reworded to match the public table above (same 5 values).
- Three new keys, mirroring the existing photo equivalents:

| Key | cs | en | ru | uk | sk |
|---|---|---|---|---|---|
| `gallery.form.add_videos` | Přidat videa | Add videos | Добавить видео | Додати відео | Pridať videá |
| `gallery.form.existing_videos` | Videa v albu | Videos in album | Видео в альбоме | Відео в альбомі | Videá v albe |
| `gallery.form.delete_video` | Smazat | Delete | Удалить | Видалити | Zmazať |

All 5 files in each set keep identical key sets per existing CLAUDE.md convention.

---

## Data Flow

```
Public: GET /{lang}/services/archive
  → GalleryController::index → GalleryModel::albums(lang) → index.twig (unchanged, photo-only covers)

Public: GET /{lang}/services/archive/{slug}
  → GalleryController::album → GalleryModel::album(slug, lang)
      → images: [{id, filename, media_type}, ...] ordered by sort_order, id
  → album.twig renders <img> or <video> per item based on media_type

Admin: POST /admin/gallery/{id}/edit with photos[] and videos[] files
  → GalleryController::editSubmit
      → handleImageUploads() → ImageUploader::upload() per photo → GalleryModel::addImage(id, fn, 'image')
      → handleVideoUploads() → VideoUploader::upload() per video → GalleryModel::addImage(id, fn, 'video')

Admin: POST /admin/gallery/{id}/image/{image_id}/delete
  → GalleryModel::deleteImage(image_id) → {filename, media_type}
  → if media_type === 'image': unlink filename + thumb_filename
  → else: unlink filename only
```

---

## Error Handling

| Scenario | Behavior |
|---|---|
| Uploaded video is not `video/mp4` | `VideoUploader::upload()` throws `RuntimeException`, same failure mode as `ImageUploader` for unsupported image types today (uncaught — surfaces as a 500, consistent with existing upload error handling) |
| Video file exceeds effective `upload_max_filesize`/`post_max_size` | Standard PHP upload error (`UPLOAD_ERR_INI_SIZE` etc.), caught by the existing `if ($file->getError() === UPLOAD_ERR_NO_FILE) continue;` pattern being extended — non-`NO_FILE` errors still propagate into `ImageUploader`/`VideoUploader`'s `UPLOAD_ERR_OK` check and throw, same as today |
| Deleting a video via `deleteImage` | No `thumb_` unlink attempted (would otherwise silently no-op via `@unlink` anyway, but the type check avoids the wasted filesystem call and keeps intent explicit) |
| Old `/gallery` URLs visited post-deploy | 404 (FastRoute — no route registered), no redirect |

---

## Testing

`tests/Unit/Models/GalleryModelTest.php`:

- Update `test_album_returns_data`: assert `images` is an array of rows with `filename`/`media_type` keys (once at least one image exists on `test-album`), not bare strings.
- New `test_add_image_defaults_to_image_media_type`: call `addImage()` without the third arg, confirm the stored row has `media_type = 'image'`.
- New `test_add_image_stores_video_media_type`: call `addImage($id, 'x.mp4', 'video')`, confirm stored `media_type = 'video'`.
- New `test_delete_image_returns_media_type`: add an image, call `deleteImage()`, assert the returned array has `filename` and `media_type` keys and the row is gone.

No changes needed to `OrderModelTest`, `ProductModelTest`, etc.

Manual verification post-implementation (per CLAUDE.md UI-change guidance): serve locally, upload a small MP4 through `/admin/gallery/{id}/edit`, confirm it plays on both the album edit page and the public `/{lang}/services/archive/{slug}` page, confirm the nav dropdown works on desktop (hover) and mobile (tap-to-open hamburger, inline submenu).

---

## Out of Scope

- Server-side video thumbnail/poster generation (no ffmpeg on WEDOS).
- Video formats other than MP4 (no WebM support).
- `gallery_albums.cover_image` becoming a video, or any admin UI for setting `cover_image` at all (doesn't exist today, unrelated to this change).
- 301 redirects from old `/gallery` URLs.
- Admin nav restructuring (submenu) — only label text changes.
- Video compression/validation of duration or resolution.
- Drag-and-drop reordering of mixed photo/video sort order (existing `sort_order` field and admin UI behavior are unchanged — new uploads still default to `sort_order = 0`, same as today's photo behavior).
