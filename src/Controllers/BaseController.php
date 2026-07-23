<?php
namespace App\Controllers;

use App\Models\Database;
use App\Services\Compare;
use App\Services\I18n;
use App\Services\Seo;
use App\Twig\I18nExtension;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class BaseController
{
    public function __construct(protected Twig $twig) {}

    protected function render(
        Request  $request,
        Response $response,
        string   $template,
        array    $data = []
    ): Response {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->ensureI18nExtension($request);

        /** @var I18n $i18n */
        $i18n = $request->getAttribute('i18n');
        $lang = $request->getAttribute('lang');

        $uri  = $request->getUri()->getPath();
        $path = preg_replace('#^/' . preg_quote($lang, '#') . '#', '', $uri) ?: '/';

        $pdo          = Database::getConnection();
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email','facebook_url','instagram_url','whatsapp_phone')");
        $settingsStmt->execute();
        $settingsMap = array_column($settingsStmt->fetchAll(), 'value', 'key');

        $whatsappDigits = preg_replace('/\D+/', '', $settingsMap['whatsapp_phone'] ?? '');

        return $this->twig->render($response, $template, array_merge([
            'lang'                 => $lang,
            'current_path'         => $path,
            'base_url'             => Seo::BASE_URL,
            'canonical_url'        => Seo::canonicalUrl($lang, $path),
            'alternate_urls'       => Seo::alternateUrls($path),
            'organization_json_ld' => Seo::organizationJsonLd(
                $i18n->t('site.name'),
                $settingsMap['contact_phone'] ?? '',
                $settingsMap['contact_email'] ?? ''
            ),
            'facebook_url'         => $settingsMap['facebook_url'] ?? '',
            'instagram_url'        => $settingsMap['instagram_url'] ?? '',
            'whatsapp_url'         => $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '',
            'flash'                => $this->getFlash(),
            'compare_count'        => Compare::count(),
            'customer'             => $_SESSION['customer'] ?? null,
        ], $data));
    }

    /**
     * Renders an email body from a template without going through a Response.
     * Registers I18nExtension first (if not already present) so that a later
     * $this->render() call in the same request doesn't hit Twig's "extensions
     * already initialized" error — Twig locks its extension set on first use.
     */
    protected function fetchEmail(Request $request, string $template, array $data = []): string
    {
        $this->ensureI18nExtension($request);
        return $this->twig->fetch($template, $data);
    }

    private function ensureI18nExtension(Request $request): void
    {
        $env = $this->twig->getEnvironment();
        if (!$env->hasExtension(I18nExtension::class)) {
            /** @var I18n $i18n */
            $i18n = $request->getAttribute('i18n');
            $env->addExtension(new I18nExtension($i18n));
        }
    }

    protected function flash(string $type, string $message, array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'params' => $params];
    }

    protected function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}
