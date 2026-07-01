<?php
namespace Tests\Unit\Services;

use App\Services\Seo;
use PHPUnit\Framework\TestCase;

class SeoTest extends TestCase
{
    public function test_canonical_url_builds_full_url(): void
    {
        $this->assertSame('https://balonkydecor.cz/cs/shop', Seo::canonicalUrl('cs', '/shop'));
    }

    public function test_canonical_url_for_home(): void
    {
        $this->assertSame('https://balonkydecor.cz/cs/', Seo::canonicalUrl('cs', '/'));
    }

    public function test_alternate_urls_includes_all_languages_plus_x_default(): void
    {
        $alts = Seo::alternateUrls('/shop');
        $this->assertSame(['cs', 'sk', 'en', 'uk', 'ru', 'x-default'], array_column($alts, 'lang'));
    }

    public function test_alternate_urls_x_default_points_to_default_lang(): void
    {
        $alts     = Seo::alternateUrls('/shop');
        $xDefault = array_values(array_filter($alts, fn($a) => $a['lang'] === 'x-default'))[0];
        $this->assertSame('https://balonkydecor.cz/cs/shop', $xDefault['url']);
    }

    public function test_organization_json_ld_includes_name_and_contact(): void
    {
        $data = json_decode(Seo::organizationJsonLd('BalonkyDecor', '+420123456789', 'info@balonkydecor.cz'), true);
        $this->assertSame('Organization', $data['@type']);
        $this->assertSame('BalonkyDecor', $data['name']);
        $this->assertSame('https://balonkydecor.cz', $data['url']);
        $this->assertSame('+420123456789', $data['telephone']);
        $this->assertSame('info@balonkydecor.cz', $data['email']);
    }

    public function test_organization_json_ld_omits_empty_contact_fields(): void
    {
        $data = json_decode(Seo::organizationJsonLd('BalonkyDecor', '', ''), true);
        $this->assertArrayNotHasKey('telephone', $data);
        $this->assertArrayNotHasKey('email', $data);
    }
}
