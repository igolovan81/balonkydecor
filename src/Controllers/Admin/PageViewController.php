<?php
namespace App\Controllers\Admin;

use App\Models\PageViewModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageViewController extends AdminBaseController
{
    private const ALLOWED_DAYS = [7, 30, 90];
    private const PER_PAGE     = 20;

    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $days   = (int) ($params['days'] ?? 30);
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            $days = 30;
        }
        $page = max(1, (int) ($params['page'] ?? 1));

        $to   = date('Y-m-d H:i:s');
        $from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $summary = PageViewModel::summary($from, $to);
        $data    = PageViewModel::topPages($from, $to, $page, self::PER_PAGE);
        $devices = PageViewModel::deviceBreakdown($from, $to);

        return $this->renderAdmin($request, $response, 'admin/page-views/index.twig', [
            'summary' => $summary,
            'rows'    => $data['rows'],
            'page'    => $page,
            'pages'   => $data['pages'],
            'days'    => $days,
            'devices' => $devices,
        ]);
    }
}
