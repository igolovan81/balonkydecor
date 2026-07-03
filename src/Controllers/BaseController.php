<?php
namespace App\Controllers;

use App\Models\Database;
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
        /** @var I18n $i18n */
        $i18n = $request->getAttribute('i18n');
        $lang = $request->getAttribute('lang');

        $env = $this->twig->getEnvironment();
        if (!$env->hasExtension(I18nExtension::class)) {
            $env->addExtension(new I18nExtension($i18n));
        }

        $uri  = $request->getUri()->getPath();
        $path = preg_replace('#^/' . preg_quote($lang, '#') . '#', '', $uri) ?: '/';

        $pdo          = Database::getConnection();
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email','facebook_url','instagram_url')");
        $settingsStmt->execute();
        $settingsMap = array_column($settingsStmt->fetchAll(), 'value', 'key');

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
        ], $data));
    }
}
