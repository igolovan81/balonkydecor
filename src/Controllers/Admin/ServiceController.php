<?php
namespace App\Controllers\Admin;

use App\Models\ServiceModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ServiceController extends AdminBaseController
{
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['name', 'description', 'features'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $services = ServiceModel::all();
        return $this->renderAdmin($request, $response, 'admin/services/index.twig', compact('services'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/services/form.twig', [
            'service'      => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = ServiceModel::create([
            'price_from' => trim($body['price_from'] ?? ''),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ]);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ServiceModel::setTranslations($id, $translations);
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.created');
        return $this->redirect($response, '/admin/services');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $service = ServiceModel::findById((int) $args['id']);
        if (!$service) return $response->withStatus(404);
        $translations = ServiceModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/services/form.twig', [
            'service'      => $service,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        ServiceModel::update($id, [
            'price_from' => trim($body['price_from'] ?? ''),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ]);
        $translations = $body['t'] ?? [];
        ServiceModel::setTranslations($id, $translations);
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'updated', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.updated');
        return $this->redirect($response, '/admin/services');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id           = (int) $args['id'];
        $translations = ServiceModel::getTranslations($id);
        ServiceModel::delete($id);
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'deleted', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.deleted');
        return $this->redirect($response, '/admin/services');
    }

    private function serviceLabel(array $translations, int $id): string
    {
        $name = $translations['cs']['name'] ?? '';
        return $name !== '' ? $name : ('#' . $id);
    }
}
