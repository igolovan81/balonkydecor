# Services Archive (formerly Gallery) + Video Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the public "Gallery" nav item into a dropdown submenu under "Services" at `/{lang}/services/archive`, rename it to a "completed projects" framing across all 5 languages, and add MP4 video support alongside photos in gallery albums.

**Architecture:** Add a `media_type` enum column to `gallery_images`, a `VideoUploader` service mirroring `ImageUploader`'s contract (no transcoding, no thumbnails — native `<video>` playback), extend `GalleryModel`/`Admin\GalleryController` to be type-aware, move the public routes under `/services/archive`, and add a CSS-only nav dropdown.

**Tech Stack:** PHP 8 / Slim 4 / Twig 3 / PDO MySQL / vanilla JS / no build step.

## Global Constraints

- No server-side video thumbnail/poster generation (no ffmpeg on WEDOS shared hosting) — browsers show the video's first frame natively via `<video preload="metadata">`.
- Video uploads accept `video/mp4` only.
- `gallery_albums.cover_image` stays photo-only; no admin UI for it exists today and none is added.
- Old `/{lang}/gallery` and `/{lang}/gallery/{slug}` routes are removed outright — no redirect.
- Admin nav (`templates/layout/admin-base.twig`) gets no structural change — only the `nav.gallery` label text changes, `/admin/gallery` URL and page stay as-is.
- All 5 language files per translation set (`lang/*.json`, `lang/admin/*.json`) must keep identical key sets.
- Translation tables use column `lang_code`, not `lang` (existing convention — untouched here, no new translation tables added).
- Local dev DB is the Docker MySQL container `balonkydecor_db` (db=`balonkydecor`, user/pass=`balonky`/`balonky`), already running with migrations V001–V007 applied (confirmed via `schema_migrations` table).

---

## File Structure

| File | Change |
|---|---|
| `database/migrations/V008__gallery_video_support.sql` | Create — adds `media_type` column |
| `src/Models/GalleryModel.php` | Modify — `addImage()`, `deleteImage()`, `album()` become media-type aware |
| `tests/Unit/Models/GalleryModelTest.php` | Modify — update/add tests for media-type behavior |
| `src/Services/VideoUploader.php` | Create — MP4 upload handling, no processing |
| `tests/Unit/Services/VideoUploaderTest.php` | Create — unit tests for `VideoUploader` |
| `src/Controllers/Admin/GalleryController.php` | Modify — split photo/video upload handling, type-aware delete |
| `templates/admin/gallery/form.twig` | Modify — video upload field + existing-videos section |
| `lang/admin/{cs,en,ru,uk,sk}.json` | Modify — reworded labels + 3 new keys each |
| `www/.htaccess` | Modify — raise `upload_max_filesize`/`post_max_size` |
| `src/routes.php` | Modify — move public gallery routes under `/services/archive` |
| `templates/public/gallery/index.twig` | Modify — fix album-card links to `/services/archive/{slug}` |
| `src/Services/Sitemap.php` | Modify — emit `/services/archive` paths instead of `/gallery` |
| `tests/Unit/Services/SitemapTest.php` | Modify — update path assertions |
| `lang/{cs,en,ru,uk,sk}.json` | Modify — reworded `nav.gallery`/`gallery.title`/`gallery.back`/`gallery.no_albums` |
| `templates/public/gallery/album.twig` | Modify — video rendering, breadcrumb, back-link URL |
| `templates/layout/base.twig` | Modify — nav dropdown markup |
| `www/assets/css/style.css` | Modify — dropdown CSS |

---

### Task 1: Database migration — `media_type` column

**Files:**
- Create: `database/migrations/V008__gallery_video_support.sql`

**Interfaces:**
- Produces: `gallery_images.media_type` column, `ENUM('image','video') NOT NULL DEFAULT 'image'`, used by Task 2 onward.

- [ ] **Step 1: Write the migration file**

```sql
ALTER TABLE gallery_images
  ADD COLUMN media_type ENUM('image','video') NOT NULL DEFAULT 'image' AFTER filename;
```

- [ ] **Step 2: Apply it to the local Docker DB**

Run:
```bash
docker exec -i balonkydecor_db mysql -ubalonky -pbalonky balonkydecor < database/migrations/V008__gallery_video_support.sql
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "INSERT INTO schema_migrations (version) VALUES ('V008__gallery_video_support')"
```
Expected: no output from the first command (DDL succeeds silently); second command completes with no error.

- [ ] **Step 3: Verify the column exists**

Run: `docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE gallery_images;"`
Expected: a `media_type` row between `filename` and `sort_order`, type `enum('image','video')`, Default `image`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/V008__gallery_video_support.sql
git commit -m "feat: add media_type column to gallery_images for video support"
```

---

### Task 2: `GalleryModel` — media-type aware images

**Files:**
- Modify: `src/Models/GalleryModel.php:20-39` (`album()`), `:108-123` (`addImage()`, `deleteImage()`)
- Test: `tests/Unit/Models/GalleryModelTest.php`

**Interfaces:**
- Consumes: `gallery_images.media_type` column (Task 1).
- Produces:
  - `GalleryModel::addImage(int $albumId, string $filename, string $mediaType = 'image'): void`
  - `GalleryModel::deleteImage(int $imageId): ?array` — returns `['filename' => string, 'media_type' => string]` or `null`
  - `GalleryModel::album(string $slug, string $lang): ?array` — `$album['images']` is now `[['id' => int, 'filename' => string, 'media_type' => string], ...]` instead of a flat filename-string array

These are consumed by Task 4 (`Admin\GalleryController`) and Task 11 (`album.twig`).

- [ ] **Step 1: Write the failing tests**

Replace the existing `test_album_returns_data` test and append three new tests at the end of `tests/Unit/Models/GalleryModelTest.php` (just before the final closing `}`):

```php
    public function test_album_returns_data(): void
    {
        $album = GalleryModel::album('test-album', 'en');
        $this->assertNotNull($album);
        $this->assertSame('Test Album', $album['name']);
        $this->assertArrayHasKey('images', $album);
        $this->assertIsArray($album['images']);
    }

    public function test_add_image_defaults_to_image_media_type(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::addImage($id, 'default-type-test.jpg');
        $row = $pdo->query("SELECT media_type FROM gallery_images WHERE album_id={$id} AND filename='default-type-test.jpg'")->fetch();
        $this->assertSame('image', $row['media_type']);
    }

    public function test_add_image_stores_video_media_type(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::addImage($id, 'clip-test.mp4', 'video');
        $row = $pdo->query("SELECT media_type FROM gallery_images WHERE album_id={$id} AND filename='clip-test.mp4'")->fetch();
        $this->assertSame('video', $row['media_type']);
    }

    public function test_delete_image_returns_media_type(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::addImage($id, 'delete-me-test.mp4', 'video');
        $imageId = $pdo->query("SELECT id FROM gallery_images WHERE album_id={$id} AND filename='delete-me-test.mp4'")->fetch()['id'];

        $result = GalleryModel::deleteImage($imageId);

        $this->assertSame('delete-me-test.mp4', $result['filename']);
        $this->assertSame('video', $result['media_type']);
        $row = $pdo->query("SELECT id FROM gallery_images WHERE id={$imageId}")->fetch();
        $this->assertFalse($row);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/GalleryModelTest.php --testdox`
Expected: `test_add_image_stores_video_media_type` and `test_delete_image_returns_media_type` FAIL (video media_type not yet stored/returned — `addImage()` doesn't accept a 3rd arg, `deleteImage()` returns a string not an array). `test_album_returns_data` and `test_add_image_defaults_to_image_media_type` PASS already (default column behavior from Task 1's migration covers the default case).

- [ ] **Step 3: Implement the model changes**

Replace `album()` (lines 20-39) in `src/Models/GalleryModel.php`:

```php
    public static function album(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT a.id, a.slug, a.cover_image,
                   COALESCE(t.name, a.slug) AS name, t.description, t.meta_title, t.meta_desc
            FROM gallery_albums a
            LEFT JOIN gallery_album_t t ON t.album_id = a.id AND t.lang_code = :lang
            WHERE a.slug = :slug
        ');
        $stmt->execute(['slug' => $slug, 'lang' => $lang]);
        $album = $stmt->fetch();
        if (!$album) {
            return null;
        }
        $imgs = $pdo->prepare('SELECT id, filename, media_type FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$album['id']]);
        $album['images'] = $imgs->fetchAll();
        return $album;
    }
```

Replace `addImage()`/`deleteImage()` (lines 108-123):

```php
    public static function addImage(int $albumId, string $filename, string $mediaType = 'image'): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO gallery_images (album_id, filename, media_type, sort_order) VALUES (?, ?, ?, 0)')
            ->execute([$albumId, $filename, $mediaType]);
    }

    public static function deleteImage(int $imageId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT filename, media_type FROM gallery_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $row  = $stmt->fetch();
        if (!$row) return null;
        $pdo->prepare('DELETE FROM gallery_images WHERE id = ?')->execute([$imageId]);
        return $row;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/GalleryModelTest.php --testdox`
Expected: all tests PASS (7 tests: the 3 original untouched ones + updated `test_album_returns_data` + 3 new ones).

- [ ] **Step 5: Commit**

```bash
git add src/Models/GalleryModel.php tests/Unit/Models/GalleryModelTest.php
git commit -m "feat: make GalleryModel image add/delete/read media-type aware"
```

---

### Task 3: `VideoUploader` service

**Files:**
- Create: `src/Services/VideoUploader.php`
- Test: `tests/Unit/Services/VideoUploaderTest.php`

**Interfaces:**
- Produces: `VideoUploader::upload(array $file, string $dir): string` — same contract shape as `ImageUploader::upload()` (`$file` is `['tmp_name' => string, 'error' => int]`, returns the stored filename). Consumed by Task 4.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/VideoUploaderTest.php`:

```php
<?php
namespace Tests\Unit\Services;

use App\Services\VideoUploader;
use PHPUnit\Framework\TestCase;

class VideoUploaderTest extends TestCase
{
    private string $destDir;

    protected function setUp(): void
    {
        $this->destDir = sys_get_temp_dir() . '/video_uploader_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->destDir)) {
            array_map('unlink', glob($this->destDir . '/*'));
            rmdir($this->destDir);
        }
    }

    private function fakeMp4Path(): string
    {
        $path  = sys_get_temp_dir() . '/fake_upload_' . uniqid() . '.mp4';
        // Minimal ISO-BMFF 'ftyp' box header — enough for fileinfo to detect video/mp4.
        $bytes = hex2bin('00000018667479706d703432000000006d703432') . str_repeat("\x00", 100);
        file_put_contents($path, $bytes);
        return $path;
    }

    public function test_upload_stores_mp4_and_returns_filename(): void
    {
        $tmp = $this->fakeMp4Path();

        $filename = VideoUploader::upload(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], $this->destDir);

        $this->assertStringEndsWith('.mp4', $filename);
        $this->assertFileExists($this->destDir . '/' . $filename);
        unlink($tmp);
    }

    public function test_upload_rejects_non_mp4_file(): void
    {
        $path = sys_get_temp_dir() . '/fake_upload_' . uniqid() . '.txt';
        file_put_contents($path, 'not a video');

        $this->expectException(\RuntimeException::class);
        try {
            VideoUploader::upload(['tmp_name' => $path, 'error' => UPLOAD_ERR_OK], $this->destDir);
        } finally {
            unlink($path);
        }
    }

    public function test_upload_rejects_upload_error(): void
    {
        $this->expectException(\RuntimeException::class);
        VideoUploader::upload(['tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_INI_SIZE], $this->destDir);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Services/VideoUploaderTest.php --testdox`
Expected: FAIL — `Class "App\Services\VideoUploader" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Services/VideoUploader.php`:

```php
<?php
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

        copy($file['tmp_name'], $destDir . '/' . $filename);

        return $filename;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Services/VideoUploaderTest.php --testdox`
Expected: all 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/VideoUploader.php tests/Unit/Services/VideoUploaderTest.php
git commit -m "feat: add VideoUploader service for MP4 gallery uploads"
```

---

### Task 4: Admin `GalleryController` — split photo/video upload handling

**Files:**
- Modify: `src/Controllers/Admin/GalleryController.php`

**Interfaces:**
- Consumes: `GalleryModel::addImage(int, string, string = 'image')`, `GalleryModel::deleteImage(int): ?array` (Task 2); `VideoUploader::upload(array, string): string` (Task 3).
- Produces: reads `photos[]` (renamed from `images[]`) and new `videos[]` multipart fields — consumed by Task 5's form.

No unit test exists for this controller (matches the codebase's existing pattern — no controller/route tests anywhere in `tests/`); verification is via Task 13's manual smoke test plus the existing PHPUnit suite staying green.

- [ ] **Step 1: Rename the `images[]` field to `photos[]` and add `videos[]` handling**

Replace lines 62-98 of `src/Controllers/Admin/GalleryController.php` (from `public function deleteImage` through the closing brace of `handleImageUploads`):

```php
    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $deleted = GalleryModel::deleteImage((int) $args['image_id']);
        if ($deleted) {
            @unlink(self::UPLOAD_DIR . '/' . $deleted['filename']);
            if ($deleted['media_type'] === 'image') {
                @unlink(self::UPLOAD_DIR . '/thumb_' . $deleted['filename']);
            }
        }
        $this->flash('success', 'gallery.flash.image_deleted');
        return $this->redirect($response, '/admin/gallery/' . $args['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $album = GalleryModel::findAlbumById((int) $args['id']);
        if ($album) {
            foreach ($album['images'] as $img) {
                @unlink(self::UPLOAD_DIR . '/' . $img['filename']);
                if ($img['media_type'] === 'image') {
                    @unlink(self::UPLOAD_DIR . '/thumb_' . $img['filename']);
                }
            }
            GalleryModel::deleteAlbum((int) $args['id']);
        }
        $this->flash('success', 'gallery.flash.deleted');
        return $this->redirect($response, '/admin/gallery');
    }

    private function handleImageUploads(Request $request, int $albumId): void
    {
        $files  = $request->getUploadedFiles();
        $photos = $files['photos'] ?? [];
        if (!is_array($photos)) $photos = [$photos];
        foreach ($photos as $file) {
            if ($file->getError() === UPLOAD_ERR_NO_FILE) continue;
            $tmp      = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
            $filename = ImageUploader::upload($tmp, self::UPLOAD_DIR);
            GalleryModel::addImage($albumId, $filename, 'image');
        }
    }

    private function handleVideoUploads(Request $request, int $albumId): void
    {
        $files  = $request->getUploadedFiles();
        $videos = $files['videos'] ?? [];
        if (!is_array($videos)) $videos = [$videos];
        foreach ($videos as $file) {
            if ($file->getError() === UPLOAD_ERR_NO_FILE) continue;
            $tmp      = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
            $filename = VideoUploader::upload($tmp, self::UPLOAD_DIR);
            GalleryModel::addImage($albumId, $filename, 'video');
        }
    }
}
```

- [ ] **Step 2: Call `handleVideoUploads()` from `createSubmit()` and `editSubmit()`**

In `createSubmit()` (line 34), change:
```php
        $this->handleImageUploads($request, $id);
```
to:
```php
        $this->handleImageUploads($request, $id);
        $this->handleVideoUploads($request, $id);
```

In `editSubmit()` (line 57), make the identical change.

- [ ] **Step 3: Add the `VideoUploader` import**

At the top of the file, change:
```php
use App\Services\ImageUploader;
```
to:
```php
use App\Services\ImageUploader;
use App\Services\VideoUploader;
```

- [ ] **Step 4: Run the full test suite to confirm nothing broke**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS (this controller has no direct tests, but this confirms no regressions in `GalleryModel`/other suites from the edit).

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/Admin/GalleryController.php
git commit -m "feat: split admin gallery upload handling into photos and videos"
```

---

### Task 5: Admin form — video upload UI

**Files:**
- Modify: `templates/admin/gallery/form.twig`

**Interfaces:**
- Consumes: `photos[]`/`videos[]` fields (Task 4), `album.images[].media_type` (Task 2), new translation keys `gallery.form.add_videos`/`gallery.form.existing_videos`/`gallery.form.delete_video` (Task 6 — reference them now, they're wired up next task).

- [ ] **Step 1: Rename the photo input and split the existing-media list by type**

Replace the block from `{% if album and album.images %}` through the `{% endif %}` (existing-photos section), plus the "Přidat fotky" input, in `templates/admin/gallery/form.twig`:

```twig
    {% if album and album.images %}
    {% set photos = album.images|filter(img => img.media_type == 'image') %}
    {% set videos = album.images|filter(img => img.media_type == 'video') %}
    {% if photos %}
    <div class="form-group">
        <label>{{ t('gallery.form.existing_photos') }}</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
        {% for img in photos %}
        <div style="text-align:center;">
            <img src="/assets/uploads/gallery/thumb_{{ img.filename }}" class="img-thumb"><br>
            <button type="button" class="btn-link delete-image-btn" style="font-size:0.8rem" data-url="/admin/gallery/{{ album.id }}/image/{{ img.id }}/delete">{{ t('gallery.form.delete_photo') }}</button>
        </div>
        {% endfor %}
        </div>
    </div>
    {% endif %}
    {% if videos %}
    <div class="form-group">
        <label>{{ t('gallery.form.existing_videos') }}</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
        {% for vid in videos %}
        <div style="text-align:center;">
            <video src="/assets/uploads/gallery/{{ vid.filename }}" controls preload="metadata" style="width:160px;"></video><br>
            <button type="button" class="btn-link delete-image-btn" style="font-size:0.8rem" data-url="/admin/gallery/{{ album.id }}/image/{{ vid.id }}/delete">{{ t('gallery.form.delete_video') }}</button>
        </div>
        {% endfor %}
        </div>
    </div>
    {% endif %}
    {% endif %}
    <div class="form-group">
        <label>{{ t('gallery.form.add_photos') }}</label>
        <input type="file" name="photos[]" accept="image/*" multiple>
    </div>
    <div class="form-group">
        <label>{{ t('gallery.form.add_videos') }}</label>
        <input type="file" name="videos[]" accept="video/mp4" multiple>
    </div>
```

- [ ] **Step 2: Commit**

```bash
git add templates/admin/gallery/form.twig
git commit -m "feat: add video upload UI to admin gallery album form"
```

(Translation keys referenced here don't exist yet — Task 6 adds them. Twig's `t()` falls back gracefully per `I18nExtension` behavior used elsewhere in the codebase, so this doesn't break rendering before Task 6 lands; committing now keeps each task's diff focused on one file.)

---

### Task 6: Admin translations — reworded labels + new video keys

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Produces: `gallery.form.add_videos`, `gallery.form.existing_videos`, `gallery.form.delete_video` keys (consumed by Task 5's template, already committed); reworded `nav.gallery`/`gallery.title` values.

- [ ] **Step 1: Update `lang/admin/cs.json`**

Change:
```json
  "gallery.form.add_photos": "Přidat fotky",
```
to:
```json
  "gallery.form.add_photos": "Přidat fotky",
  "gallery.form.add_videos": "Přidat videa",
```

Change:
```json
  "gallery.form.delete_photo": "Smazat",
```
to:
```json
  "gallery.form.delete_photo": "Smazat",
  "gallery.form.delete_video": "Smazat",
```

Change:
```json
  "gallery.form.existing_photos": "Fotky v albu",
```
to:
```json
  "gallery.form.existing_photos": "Fotky v albu",
  "gallery.form.existing_videos": "Videa v albu",
```

Change:
```json
  "gallery.title": "Galerie",
```
to:
```json
  "gallery.title": "Archiv realizací",
```

Change:
```json
  "nav.gallery": "Galerie",
```
to:
```json
  "nav.gallery": "Archiv realizací",
```

- [ ] **Step 2: Update `lang/admin/en.json`** (same 5 edits, English values)

`add_photos` block → add `"gallery.form.add_videos": "Add videos",`
`delete_photo` block → add `"gallery.form.delete_video": "Delete",`
`existing_photos` block → add `"gallery.form.existing_videos": "Album videos",`
`"gallery.title": "Gallery"` → `"gallery.title": "Completed Projects"`
`"nav.gallery": "Gallery"` → `"nav.gallery": "Completed Projects"`

- [ ] **Step 3: Update `lang/admin/ru.json`** (same 5 edits, Russian values)

`add_photos` block → add `"gallery.form.add_videos": "Добавить видео",`
`delete_photo` block → add `"gallery.form.delete_video": "Удалить",`
`existing_photos` block → add `"gallery.form.existing_videos": "Видео в альбоме",`
`"gallery.title": "Галерея"` → `"gallery.title": "Архив оказанных услуг"`
`"nav.gallery": "Галерея"` → `"nav.gallery": "Архив оказанных услуг"`

- [ ] **Step 4: Update `lang/admin/uk.json`** (same 5 edits, Ukrainian values)

`add_photos` block → add `"gallery.form.add_videos": "Додати відео",`
`delete_photo` block → add `"gallery.form.delete_video": "Видалити",`
`existing_photos` block → add `"gallery.form.existing_videos": "Відео в альбомі",`
`"gallery.title": "Галерея"` → `"gallery.title": "Архів наданих послуг"`
`"nav.gallery": "Галерея"` → `"nav.gallery": "Архів наданих послуг"`

- [ ] **Step 5: Update `lang/admin/sk.json`** (same 5 edits, Slovak values)

`add_photos` block → add `"gallery.form.add_videos": "Pridať videá",`
`delete_photo` block → add `"gallery.form.delete_video": "Zmazať",`
`existing_photos` block → add `"gallery.form.existing_videos": "Videá v albe",`
`"gallery.title": "Galéria"` → `"gallery.title": "Naše realizácie"`
`"nav.gallery": "Galéria"` → `"nav.gallery": "Naše realizácie"`

- [ ] **Step 6: Verify all 5 admin files still have identical key sets**

Run:
```bash
for f in cs en ru uk sk; do php -r "echo implode(\"\n\", array_keys(json_decode(file_get_contents('lang/admin/$f.json'), true))), \"\n\";" | sort > /tmp/admin_keys_$f.txt; done
diff /tmp/admin_keys_cs.txt /tmp/admin_keys_en.txt && diff /tmp/admin_keys_cs.txt /tmp/admin_keys_ru.txt && diff /tmp/admin_keys_cs.txt /tmp/admin_keys_uk.txt && diff /tmp/admin_keys_cs.txt /tmp/admin_keys_sk.txt && echo "KEYS MATCH"
```
Expected: `KEYS MATCH` printed, no diff output.

- [ ] **Step 7: Verify all 5 files are valid JSON**

Run: `for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/admin/$f.json')); echo json_last_error_msg(), \"\n\";"; done`
Expected: `No error` printed 5 times.

- [ ] **Step 8: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add video upload translation keys and rename admin gallery label"
```

---

### Task 7: Upload size limits — `.htaccess`

**Files:**
- Modify: `www/.htaccess`

**Interfaces:** None (config-only; consumed implicitly by any future video upload once deployed).

- [ ] **Step 1: Add PHP value overrides**

At the top of `www/.htaccess`, before the existing `# htaccess rules for subdomains and aliases` comment, add:

```apache
# Raise upload limits for gallery video uploads (WEDOS default is 2M/8M)
php_value upload_max_filesize 50M
php_value post_max_size 55M
```

- [ ] **Step 2: Commit**

```bash
git add www/.htaccess
git commit -m "feat: raise PHP upload limits for gallery video uploads"
```

Note for `/verify` after deploy: confirm the override actually took effect on WEDOS (e.g. attempt a >8MB test upload, or check `phpinfo()` output) — shared-hosting `.htaccess` PHP overrides aren't guaranteed to be permitted; if blocked, uploads over 2MB/8MB fail with a standard PHP upload error rather than corrupting data, so the failure mode is safe either way.

---

### Task 8: Public routes — move under `/services/archive`

**Files:**
- Modify: `src/routes.php:137-139`

**Interfaces:**
- Produces: `GET /{lang}/services/archive` → `GalleryController::index`, `GET /{lang}/services/archive/{slug}` → `GalleryController::album`. Consumed by Task 9 (link fixes), Task 11 (album template), and Task 12 (nav link).

- [ ] **Step 1: Update the route registrations**

In `src/routes.php`, change:
```php
$app->get('/{lang}/services',         PageController::class    . ':services');
$app->get('/{lang}/gallery',          GalleryController::class . ':index');
$app->get('/{lang}/gallery/{slug}',   GalleryController::class . ':album');
```
to:
```php
$app->get('/{lang}/services',                 PageController::class    . ':services');
$app->get('/{lang}/services/archive',         GalleryController::class . ':index');
$app->get('/{lang}/services/archive/{slug}',  GalleryController::class . ':album');
```

- [ ] **Step 2: Verify the app boots and routes resolve**

Run:
```bash
php -S localhost:8091 -t www > /tmp/php-server.log 2>&1 &
sleep 1
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8091/cs/services/archive
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8091/cs/gallery
kill %1
```
Expected: first `curl` prints `200`, second prints `404` (old route no longer registered). If the app errors instead (e.g. `500`), check `/tmp/php-server.log` for a FastRoute `BadRouteException` — this would mean the new static-looking `/services/archive` segment collides with the existing `/{lang}/services` variable route; per CLAUDE.md's routing rule this only matters for `/admin/*` static-vs-variable ordering, but if it surfaces here, register `/{lang}/services/archive` and `/{lang}/services/archive/{slug}` immediately after `/{lang}/services` (already the case in Step 1) since FastRoute resolves more specific literal segments correctly when nested under a shared prefix.

- [ ] **Step 3: Commit**

```bash
git add src/routes.php
git commit -m "feat: move public gallery routes under /services/archive"
```

---

### Task 9: Fix internal links to the renamed gallery routes

**Files:**
- Modify: `templates/public/gallery/index.twig:13`
- Modify: `src/Services/Sitemap.php:12,18`
- Modify: `tests/Unit/Services/SitemapTest.php:27,44`

**Interfaces:**
- Consumes: `/{lang}/services/archive` and `/{lang}/services/archive/{slug}` routes (Task 8).

Two references to the old `/gallery` URLs were missed when the spec was written and must be updated or they'll silently 404/mislink once Task 8 lands: the album-listing page's own links to its albums, and the XML sitemap (which also has a dedicated PHPUnit test asserting the old path).

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Services/SitemapTest.php`, change:
```php
    public function test_paths_includes_static_pages(): void
    {
        $paths = Sitemap::paths();
        foreach (['/', '/shop', '/services', '/gallery', '/blog', '/contact'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }
```
to:
```php
    public function test_paths_includes_static_pages(): void
    {
        $paths = Sitemap::paths();
        foreach (['/', '/shop', '/services', '/services/archive', '/blog', '/contact'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }
```

Change:
```php
    public function test_paths_includes_gallery_album(): void
    {
        $this->assertContains('/gallery/sitemap-test-album', Sitemap::paths());
    }
```
to:
```php
    public function test_paths_includes_gallery_album(): void
    {
        $this->assertContains('/services/archive/sitemap-test-album', Sitemap::paths());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Services/SitemapTest.php --testdox`
Expected: `test_paths_includes_static_pages` and `test_paths_includes_gallery_album` FAIL (paths still contain `/gallery`, not `/services/archive`).

- [ ] **Step 3: Update `Sitemap::paths()`**

In `src/Services/Sitemap.php`, change:
```php
        $paths = ['/', '/shop', '/services', '/gallery', '/blog', '/contact'];

        foreach (ProductModel::allActive(Seo::DEFAULT_LANG) as $product) {
            $paths[] = '/shop/' . $product['sku'];
        }
        foreach (GalleryModel::albums(Seo::DEFAULT_LANG) as $album) {
            $paths[] = '/gallery/' . $album['slug'];
        }
```
to:
```php
        $paths = ['/', '/shop', '/services', '/services/archive', '/blog', '/contact'];

        foreach (ProductModel::allActive(Seo::DEFAULT_LANG) as $product) {
            $paths[] = '/shop/' . $product['sku'];
        }
        foreach (GalleryModel::albums(Seo::DEFAULT_LANG) as $album) {
            $paths[] = '/services/archive/' . $album['slug'];
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Services/SitemapTest.php --testdox`
Expected: all 8 tests PASS.

- [ ] **Step 5: Fix the album-listing page's own links**

In `templates/public/gallery/index.twig`, change:
```twig
        <a href="/{{ lang }}/gallery/{{ album.slug }}" class="gallery-album-card">
```
to:
```twig
        <a href="/{{ lang }}/services/archive/{{ album.slug }}" class="gallery-album-card">
```

- [ ] **Step 6: Run the full PHPUnit suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add templates/public/gallery/index.twig src/Services/Sitemap.php tests/Unit/Services/SitemapTest.php
git commit -m "fix: update sitemap and album-listing links for the /services/archive move"
```

---

### Task 10: Public translations — reworded gallery/nav labels

**Files:**
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Produces: updated `nav.gallery`, `gallery.title`, `gallery.back`, `gallery.no_albums` values, consumed by Task 11 (`album.twig`) and Task 12 (`base.twig` nav).

- [ ] **Step 1: Update `lang/cs.json`**

Change:
```json
  "gallery.back": "← Zpět do galerie",
  "gallery.no_albums": "Galerie je prázdná.",
  "gallery.title": "Galerie",
```
to:
```json
  "gallery.back": "← Zpět na realizace",
  "gallery.no_albums": "Zatím žádné realizace.",
  "gallery.title": "Naše realizace",
```

Change:
```json
  "nav.gallery": "Galerie",
```
to:
```json
  "nav.gallery": "Naše realizace",
```

- [ ] **Step 2: Update `lang/en.json`**

Change:
```json
  "gallery.back": "← Back to gallery",
  "gallery.no_albums": "Gallery is empty.",
  "gallery.title": "Gallery",
```
to:
```json
  "gallery.back": "← Back to projects",
  "gallery.no_albums": "No completed projects yet.",
  "gallery.title": "Completed Projects",
```

Change:
```json
  "nav.gallery": "Gallery",
```
to:
```json
  "nav.gallery": "Completed Projects",
```

- [ ] **Step 3: Update `lang/ru.json`**

Change:
```json
  "gallery.back": "← Назад в галерею",
  "gallery.no_albums": "Галерея пуста.",
  "gallery.title": "Галерея",
```
to:
```json
  "gallery.back": "← Назад к архиву",
  "gallery.no_albums": "Пока нет проектов.",
  "gallery.title": "Архив оказанных услуг",
```

Change:
```json
  "nav.gallery": "Галерея",
```
to:
```json
  "nav.gallery": "Архив оказанных услуг",
```

- [ ] **Step 4: Update `lang/uk.json`**

Change:
```json
  "gallery.back": "← Назад до галереї",
  "gallery.no_albums": "Галерея порожня.",
  "gallery.title": "Галерея",
```
to:
```json
  "gallery.back": "← Назад до архіву",
  "gallery.no_albums": "Поки немає проєктів.",
  "gallery.title": "Архів наданих послуг",
```

Change:
```json
  "nav.gallery": "Галерея",
```
to:
```json
  "nav.gallery": "Архів наданих послуг",
```

- [ ] **Step 5: Update `lang/sk.json`**

Change:
```json
  "gallery.back": "← Späť do galérie",
  "gallery.no_albums": "Galéria je prázdna.",
  "gallery.title": "Galéria",
```
to:
```json
  "gallery.back": "← Späť na realizácie",
  "gallery.no_albums": "Zatiaľ žiadne realizácie.",
  "gallery.title": "Naše realizácie",
```

Change:
```json
  "nav.gallery": "Galéria",
```
to:
```json
  "nav.gallery": "Naše realizácie",
```

- [ ] **Step 6: Verify all 5 public files still have identical key sets and are valid JSON**

Run:
```bash
for f in cs en ru uk sk; do php -r "echo implode(\"\n\", array_keys(json_decode(file_get_contents('lang/$f.json'), true))), \"\n\";" | sort > /tmp/pub_keys_$f.txt; done
diff /tmp/pub_keys_cs.txt /tmp/pub_keys_en.txt && diff /tmp/pub_keys_cs.txt /tmp/pub_keys_ru.txt && diff /tmp/pub_keys_cs.txt /tmp/pub_keys_uk.txt && diff /tmp/pub_keys_cs.txt /tmp/pub_keys_sk.txt && echo "KEYS MATCH"
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/$f.json')); echo json_last_error_msg(), \"\n\";"; done
```
Expected: `KEYS MATCH`, then `No error` printed 5 times.

- [ ] **Step 7: Commit**

```bash
git add lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: rename public gallery labels to completed-projects framing"
```

---

### Task 11: Public album template — video rendering + updated links

**Files:**
- Modify: `templates/public/gallery/album.twig`

**Interfaces:**
- Consumes: `album.images[].filename`/`.media_type` (Task 2), `/{{ lang }}/services/archive` route (Task 8), `t('nav.gallery')`/`t('nav.services')` (Task 10, existing key for services already present).

- [ ] **Step 1: Update the breadcrumb JSON-LD**

In `templates/public/gallery/album.twig`, change:
```twig
{% block head %}
<script type="application/ld+json">{{ {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': t('nav.home'), 'item': base_url ~ '/' ~ lang ~ '/'},
        {'@type': 'ListItem', 'position': 2, 'name': t('nav.gallery'), 'item': base_url ~ '/' ~ lang ~ '/gallery'},
        {'@type': 'ListItem', 'position': 3, 'name': album.name, 'item': canonical_url}
    ]
}|json_encode|raw }}</script>
{% endblock %}
```
to:
```twig
{% block head %}
<script type="application/ld+json">{{ {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': t('nav.home'), 'item': base_url ~ '/' ~ lang ~ '/'},
        {'@type': 'ListItem', 'position': 2, 'name': t('nav.services'), 'item': base_url ~ '/' ~ lang ~ '/services'},
        {'@type': 'ListItem', 'position': 3, 'name': t('nav.gallery'), 'item': base_url ~ '/' ~ lang ~ '/services/archive'},
        {'@type': 'ListItem', 'position': 4, 'name': album.name, 'item': canonical_url}
    ]
}|json_encode|raw }}</script>
{% endblock %}
```

- [ ] **Step 2: Update the back-link and render media by type**

Change:
```twig
        <a href="/{{ lang }}/gallery" class="back-link">{{ t('gallery.back') }}</a>
```
to:
```twig
        <a href="/{{ lang }}/services/archive" class="back-link">{{ t('gallery.back') }}</a>
```

Change:
```twig
    {% if album.images %}
    <div class="photo-grid">
        {% for img in album.images %}
        <a href="/assets/uploads/gallery/{{ img }}" class="photo-item" target="_blank">
            <img src="/assets/uploads/gallery/{{ img }}" alt="{{ album.name }}">
        </a>
        {% endfor %}
    </div>
    {% else %}
```
to:
```twig
    {% if album.images %}
    <div class="photo-grid">
        {% for img in album.images %}
        {% if img.media_type == 'video' %}
        <div class="photo-item">
            <video controls preload="metadata">
                <source src="/assets/uploads/gallery/{{ img.filename }}" type="video/mp4">
            </video>
        </div>
        {% else %}
        <a href="/assets/uploads/gallery/{{ img.filename }}" class="photo-item" target="_blank">
            <img src="/assets/uploads/gallery/{{ img.filename }}" alt="{{ album.name }}">
        </a>
        {% endif %}
        {% endfor %}
    </div>
    {% else %}
```

- [ ] **Step 3: Run the full PHPUnit suite to confirm no regressions**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS (this is a template-only change; verifies nothing else broke).

- [ ] **Step 4: Commit**

```bash
git add templates/public/gallery/album.twig
git commit -m "feat: render videos in public gallery album, update links to /services/archive"
```

---

### Task 12: Public nav dropdown

**Files:**
- Modify: `templates/layout/base.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `/{{ lang }}/services/archive` route (Task 8), `t('nav.gallery')`/`t('nav.services')` (Task 10).

- [ ] **Step 1: Update the nav markup**

In `templates/layout/base.twig`, change:
```twig
            <nav class="main-nav">
                <a href="/{{ lang }}/">{{ t('nav.home') }}</a>
                <a href="/{{ lang }}/shop">{{ t('nav.shop') }}</a>
                <a href="/{{ lang }}/services">{{ t('nav.services') }}</a>
                <a href="/{{ lang }}/gallery">{{ t('nav.gallery') }}</a>
                <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
                <a href="/{{ lang }}/contact">{{ t('nav.contact') }}</a>
            </nav>
```
to:
```twig
            <nav class="main-nav">
                <a href="/{{ lang }}/">{{ t('nav.home') }}</a>
                <a href="/{{ lang }}/shop">{{ t('nav.shop') }}</a>
                <div class="nav-item-dropdown">
                    <a href="/{{ lang }}/services">{{ t('nav.services') }}</a>
                    <div class="nav-dropdown-menu">
                        <a href="/{{ lang }}/services/archive">{{ t('nav.gallery') }}</a>
                    </div>
                </div>
                <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
                <a href="/{{ lang }}/contact">{{ t('nav.contact') }}</a>
            </nav>
```

- [ ] **Step 2: Add dropdown CSS**

In `www/assets/css/style.css`, immediately after the `.main-nav a:hover { color: var(--accent); }` rule, add:

```css
.nav-item-dropdown { position: relative; }
.nav-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid var(--border); min-width: 200px; z-index: 10; padding: .5rem 0; }
.nav-dropdown-menu a { display: block; padding: .5rem 1rem; }
.nav-item-dropdown:hover .nav-dropdown-menu { display: block; }
```

Inside the existing `@media (max-width: 768px) { ... }` block, immediately after the `.main-nav a { padding: .85rem 0; border-top: 1px solid var(--border); font-size: 1rem; }` line, add:

```css
    .nav-dropdown-menu { position: static; display: block; border: none; padding-left: 1rem; }
```

- [ ] **Step 3: Manually verify in a browser**

Run:
```bash
php -S localhost:8091 -t www > /tmp/php-server.log 2>&1 &
sleep 1
```
Open `http://localhost:8091/cs/` in a browser:
- Desktop width (>768px): hovering "Služby" reveals a dropdown with "Naše realizace"; clicking "Naše realizace" navigates to `/cs/services/archive` and shows the album grid.
- Resize below 768px (or use device toolbar): tap the hamburger icon, confirm "Naše realizace" appears indented directly under "Služby" in the vertical list, and is tappable.

Then: `kill %1`

- [ ] **Step 4: Commit**

```bash
git add templates/layout/base.twig www/assets/css/style.css
git commit -m "feat: nest gallery link as a dropdown under Services in public nav"
```

---

### Task 13: Full regression pass and end-to-end manual smoke test

**Files:** None (verification only).

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass (existing 37+ tests plus the new `GalleryModelTest` and `VideoUploaderTest` additions from Tasks 2–3).

- [ ] **Step 2: Manual admin flow — upload a photo and a video, confirm both display and delete correctly**

```bash
php -S localhost:8091 -t www > /tmp/php-server.log 2>&1 &
sleep 1
```

In a browser:
1. Log into `/admin/login` (or run `/admin/setup` first if no admin user exists locally).
2. Go to `/admin/gallery`, confirm the sidebar link now reads "Archiv realizací" (or the active admin language's equivalent).
3. Open an existing album's edit page (or create one).
4. Under "Přidat fotky", upload a small JPG — confirm it appears under "Fotky v albu" with a thumbnail and a working delete button.
5. Under "Přidat videa", upload a small MP4 — confirm it appears under "Videa v albu" with a playable `<video>` preview and a working delete button.
6. Delete both — confirm they disappear from the form and the files are removed from `www/assets/uploads/gallery/` (`ls www/assets/uploads/gallery/` before/after).

- [ ] **Step 3: Manual public flow**

1. Visit `/cs/services/archive` — confirm the album grid renders (photo-only covers, unchanged from before).
2. Open an album containing the video uploaded in Step 2 (re-upload one via admin if it was deleted) — confirm the video plays inline with native controls, alongside photos in the same grid.
3. Confirm `/cs/gallery` now returns a 404.
4. Confirm the nav dropdown (Task 12) still works from this page.

```bash
kill %1
```

- [ ] **Step 4: Final commit if any smoke-test fixes were needed**

If Steps 1-3 surfaced no issues, this task requires no commit — it's a verification gate over the prior 11 tasks' commits. If a fix was needed, commit it with a message describing what the smoke test caught, e.g.:

```bash
git add -A
git commit -m "fix: <describe the smoke-test finding and fix>"
```

---

## Out of Scope (carried from the design spec)

- Server-side video thumbnail/poster generation.
- Video formats other than MP4.
- `gallery_albums.cover_image` becoming a video, or any admin UI for setting `cover_image`.
- 301 redirects from old `/gallery` URLs.
- Admin nav restructuring (submenu) — only label text changes.
- Video compression/validation of duration or resolution.
- Drag-and-drop reordering of mixed photo/video sort order.
