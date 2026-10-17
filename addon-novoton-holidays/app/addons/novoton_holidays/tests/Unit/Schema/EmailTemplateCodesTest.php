<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\AlternativeEmail;

/**
 * Every email the addon sends must name a template addon.xml registers, in
 * the area it is sent from. The alternatives emails named codes registered
 * nowhere (novoton_alternatives_*), in the admin area, so CS-Cart's mailer
 * found no template and none of them ever reached a customer.
 */
final class EmailTemplateCodesTest extends TestCase
{
    /** @return array<string, string> registered template code => area */
    private static function registered(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);

        $codes = [];
        foreach ($xml->xpath('//email_templates/templates/item') ?: [] as $item) {
            $codes[trim((string) $item->code)] = trim((string) $item->area);
        }

        return $codes;
    }

    public function testTheAlternativesEmailsAreRegisteredCustomerTemplates(): void
    {
        $registered = self::registered();

        foreach ([AlternativeEmail::REQUEST_RECEIVED, AlternativeEmail::ALTERNATIVES_FOUND] as $code) {
            self::assertArrayHasKey($code, $registered, "{$code} is not registered in addon.xml");
            self::assertSame(AlternativeEmail::AREA, $registered[$code], "{$code} is sent from area " . AlternativeEmail::AREA);
        }
    }

    public function testEveryTemplateCodeInTheCodeIsRegistered(): void
    {
        $registered = self::registered();
        $root = dirname(__DIR__, 3);

        $unknown = [];
        foreach (['src', 'functions', 'controllers', 'hooks'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all("/'template_code'\\s*=>\\s*'([a-z0-9_]+)'/", $source, $m);
                foreach ($m[1] as $code) {
                    if (!isset($registered[$code])) {
                        $unknown[] = substr($file->getPathname(), strlen($root) + 1) . ": {$code}";
                    }
                }
            }
        }

        self::assertSame([], $unknown, "template codes sent but not registered in addon.xml:\n" . implode("\n", $unknown));
    }
}
