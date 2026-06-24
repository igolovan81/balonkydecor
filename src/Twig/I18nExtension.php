<?php
namespace App\Twig;

use App\Services\I18n;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class I18nExtension extends AbstractExtension
{
    public function __construct(private I18n $i18n) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', fn(string $key, array $p = []) => $this->i18n->t($key, $p)),
        ];
    }
}
