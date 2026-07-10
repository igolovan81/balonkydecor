<?php
namespace App\Controllers\Admin;

use App\Models\GalleryModel;
use App\Services\ImageUploader;
use App\Services\VideoUploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GalleryController extends AdminBaseController
{
    private const LANGS      = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const UPLOAD_DIR = __DIR__ . '/../../../www/assets/uploads/gallery';

    public function index(Request $request, Response $response, array $args): Response
    {
        $albums = GalleryModel::allAlbums();
        return $this->renderAdmin($request, $response, 'admin/gallery/index.twig', compact('albums'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/gallery/form.twig', [
            'album'        => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $id     = GalleryModel::createAlbum(
            ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->handleVideoUploads($request, $id);
        $this->flash('success', 'gallery.flash.created');
        return $this->redirect($response, '/admin/gallery');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $album = GalleryModel::findAlbumById((int) $args['id']);
        if (!$album) return $response->withStatus(404);
        $translations = GalleryModel::getAlbumTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/gallery/form.twig', [
            'album'        => $album,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        GalleryModel::updateAlbum(
            $id,
            ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->handleVideoUploads($request, $id);
        $this->flash('success', 'gallery.flash.updated');
        return $this->redirect($response, '/admin/gallery');
    }

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
