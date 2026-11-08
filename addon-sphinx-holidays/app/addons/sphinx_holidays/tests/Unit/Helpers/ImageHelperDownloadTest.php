<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\ImageHelper;

/**
 * process_image_queue failed every image with an empty reason (sandbox:
 * 1230 rows in 8.7 s, none downloaded): an image not on the API's host was
 * handed to fn_update_product as a URL, which reports nothing when CS-Cart
 * does not fetch it. Every image is now downloaded with cURL and attached
 * from the file; a failure says why. The API key goes to the API's host only.
 */
final class ImageHelperDownloadTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/Api/ImageHelper.php');
    }

    public function testEveryImageIsDownloadedThenAttachedFromTheFile(): void
    {
        $code = self::source();

        self::assertStringNotContainsString('fn_travel_core_attach_images_from_urls($product_id', $code);
        self::assertStringContainsString('if (!self::download($download_url, $headers, $temp_file, $product_id)) {', $code);
        self::assertStringContainsString("if (fn_travel_core_attach_product_image(\$product_id, \$temp_file, 'sphinx', \$is_main)) {", $code);
        self::assertStringContainsString('$headers = $isApiHosted ? self::getCurlAuthHeaders() : [];', $code, 'the API key only for the API\'s host');
        self::assertStringContainsString('CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,', $code);
    }

    public function testAFailureSaysWhy(): void
    {
        self::assertFalse(ImageHelper::attachToProduct(0, 'https://cdn.example.com/a.jpg'));
        self::assertSame('empty args', ImageHelper::$lastDownloadError);
        self::assertFalse(ImageHelper::attachToProduct(5, 'ftp://cdn.example.com/a.jpg'));
        self::assertSame('not an http(s) URL', ImageHelper::$lastDownloadError);
        self::assertStringContainsString('self::$lastDownloadError = "HTTP {$httpCode}"', self::source());
        self::assertStringContainsString("? \\Tygh\\Addons\\TravelCore\\Helpers\\DebugLogger::\$lastImageAttachError", self::source(), 'an attach failure gives the attach reason');
    }

    public function testSiblingHostsOfTheApiGetItsKey(): void
    {
        self::assertTrue(ImageHelper::matchesApiHost('https://media.supplier.test/a.jpg', 'https://api.supplier.test'));
        self::assertTrue(ImageHelper::matchesApiHost('https://api.supplier.test/img/a.jpg', 'https://api.supplier.test'));
        self::assertFalse(ImageHelper::matchesApiHost('https://cdn.other.test/a.jpg', 'https://api.supplier.test'));
    }
}
