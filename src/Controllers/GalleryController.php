<?php
namespace App\Controllers;

use App\Models\GalleryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GalleryController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang       = $request->getAttribute('lang');
        $uploadsDir = __DIR__ . '/../../www/assets/uploads/gallery';
        $albums     = array_map(
            fn (array $a) => GalleryModel::resolveCover($a, $uploadsDir),
            GalleryModel::albums($lang)
        );
        return $this->render($request, $response, 'public/gallery/index.twig', [
            'albums' => $albums,
        ]);
    }

    public function album(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $album = GalleryModel::album($args['slug'], $lang);
        if (!$album) {
            return $response->withStatus(404);
        }
        return $this->render($request, $response, 'public/gallery/album.twig', [
            'album' => $album,
        ]);
    }
}
