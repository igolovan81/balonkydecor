<?php
namespace App\Controllers\Admin;

use App\Models\HeroSlideModel;
use App\Services\ImageUploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HeroSlideController extends AdminBaseController
{
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['title', 'subtitle', 'cta_label'];
    private const UPLOAD_DIR          = __DIR__ . '/../../../www/assets/uploads/hero';

    public function index(Request $request, Response $response, array $args): Response
    {
        $slides = HeroSlideModel::all($request->getAttribute('admin_lang', 'cs'));
        return $this->renderAdmin($request, $response, 'admin/hero-slides/index.twig', compact('slides'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/hero-slides/form.twig', [
            'slide'        => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);

        $id = HeroSlideModel::create([
            'image'      => $this->uploadedFilename($request),
            'cta_url'    => $body['cta_url'] ?? '/shop',
            'is_active'  => isset($body['is_active']) ? 1 : 0,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ], $userId);

        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        HeroSlideModel::setTranslations($id, $translations);

        $this->flash('success', 'hero_slides.flash.created');
        return $this->redirect($response, '/admin/hero-slides');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $slide = HeroSlideModel::findById((int) $args['id']);
        if (!$slide) return $response->withStatus(404);
        $translations = HeroSlideModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/hero-slides/form.twig', [
            'slide'        => $slide,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id       = (int) $args['id'];
        $existing = HeroSlideModel::findById($id);
        if (!$existing) return $response->withStatus(404);

        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);

        $newFilename = $this->uploadedFilename($request);
        $image       = $existing['image'];
        if ($newFilename !== null) {
            if ($existing['image']) {
                @unlink(self::UPLOAD_DIR . '/' . $existing['image']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $existing['image']);
            }
            $image = $newFilename;
        }

        HeroSlideModel::update($id, [
            'image'      => $image,
            'cta_url'    => $body['cta_url'] ?? '/shop',
            'is_active'  => isset($body['is_active']) ? 1 : 0,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ], $userId);
        HeroSlideModel::setTranslations($id, $body['t'] ?? []);

        $this->flash('success', 'hero_slides.flash.updated');
        return $this->redirect($response, '/admin/hero-slides');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id    = (int) $args['id'];
        $slide = HeroSlideModel::findById($id);
        if ($slide) {
            if ($slide['image']) {
                @unlink(self::UPLOAD_DIR . '/' . $slide['image']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $slide['image']);
            }
            HeroSlideModel::delete($id);
        }
        $this->flash('success', 'hero_slides.flash.deleted');
        return $this->redirect($response, '/admin/hero-slides');
    }

    private function uploadedFilename(Request $request): ?string
    {
        $files = $request->getUploadedFiles();
        $file  = $files['image'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return null;

        $tmp = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
        return ImageUploader::upload($tmp, self::UPLOAD_DIR);
    }
}
