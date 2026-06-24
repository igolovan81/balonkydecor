<?php
namespace App\Controllers\Admin;

use App\Models\GalleryModel;
use App\Services\ImageUploader;
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
        $body = (array) $request->getParsedBody();
        $id   = GalleryModel::createAlbum(['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->flash('success', 'Album vytvořeno.');
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
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        GalleryModel::updateAlbum($id, ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->flash('success', 'Album uloženo.');
        return $this->redirect($response, '/admin/gallery');
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $filename = GalleryModel::deleteImage((int) $args['image_id']);
        if ($filename) {
            @unlink(self::UPLOAD_DIR . '/' . $filename);
            @unlink(self::UPLOAD_DIR . '/thumb_' . $filename);
        }
        $this->flash('success', 'Obrázek smazán.');
        return $this->redirect($response, '/admin/gallery/' . $args['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $album = GalleryModel::findAlbumById((int) $args['id']);
        if ($album) {
            foreach ($album['images'] as $img) {
                @unlink(self::UPLOAD_DIR . '/' . $img['filename']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $img['filename']);
            }
            GalleryModel::deleteAlbum((int) $args['id']);
        }
        $this->flash('success', 'Album smazáno.');
        return $this->redirect($response, '/admin/gallery');
    }

    private function handleImageUploads(Request $request, int $albumId): void
    {
        $files  = $request->getUploadedFiles();
        $images = $files['images'] ?? [];
        if (!is_array($images)) $images = [$images];
        foreach ($images as $file) {
            if ($file->getError() === UPLOAD_ERR_NO_FILE) continue;
            $tmp      = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
            $filename = ImageUploader::upload($tmp, self::UPLOAD_DIR);
            GalleryModel::addImage($albumId, $filename);
        }
    }
}
