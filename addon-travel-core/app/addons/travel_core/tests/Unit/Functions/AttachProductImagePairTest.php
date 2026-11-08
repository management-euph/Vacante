<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CART_LANGUAGE')) {
        define('CART_LANGUAGE', 'en');
    }
    // CS-Cart's image pairs: Storage::put() MOVES a source that lies in its own
    // temp dir (fn_create_temp_file(), where Sphinx downloads go).
    if (!function_exists('fn_update_image_pairs')) {
        /**
         * @param array<mixed> $icons
         * @param array<int, array{path: string}> $detailed
         * @param array<mixed> $pairsData
         *
         * @return list<int>
         */
        function fn_update_image_pairs(array $icons, array $detailed, array $pairsData, int $objectId, string $objectType): array
        {
            if (($GLOBALS['travel_core_test_moves_source'] ?? false) === true) {
                unlink($detailed[0]['path']);
            }

            return [77];
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/hotels.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\TravelCore\Tests\Support\DbStub;

    /**
     * Sphinx's image queue attached its photos with a PHP warning each:
     * "unlink(…/var/cache/misc/1/tmp/tmp_…): No such file or directory".
     * CS-Cart had moved the temp file into its storage; the attach step took
     * for granted that it copies and deleted it again.
     */
    final class AttachProductImagePairTest extends TestCase
    {
        private string $file = '';

        protected function setUp(): void
        {
            DbStub::reset();
            DbStub::$getField = static fn (): int => 0;
            $this->file = (string) tempnam(sys_get_temp_dir(), 'tc_img_');
            $image = imagecreatetruecolor(60, 60);
            for ($x = 0; $x < 60; $x++) {
                for ($y = 0; $y < 60; $y++) {
                    imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, ($x * 37) % 256, ($y * 53) % 256, ($x * $y) % 256));
                }
            }
            imagepng($image, $this->file);
        }

        protected function tearDown(): void
        {
            DbStub::reset();
            unset($GLOBALS['travel_core_test_moves_source']);
            if (is_file($this->file)) {
                unlink($this->file);
            }
        }

        public function testAMovedSourceIsNotDeletedAgain(): void
        {
            $GLOBALS['travel_core_test_moves_source'] = true;

            self::assertSame(77, fn_travel_core_attach_product_image_pair(12, $this->file, 'sphinx', true), 'attached, without a warning');
            self::assertFileDoesNotExist($this->file);
        }

        public function testACopiedSourceIsRemoved(): void
        {
            $GLOBALS['travel_core_test_moves_source'] = false;

            self::assertSame(77, fn_travel_core_attach_product_image_pair(12, $this->file, 'novoton'));
            self::assertFileDoesNotExist($this->file, 'the temp file does not stay behind');
        }
    }
}
