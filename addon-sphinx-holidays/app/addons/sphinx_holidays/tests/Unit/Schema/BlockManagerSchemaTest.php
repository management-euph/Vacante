<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Block Manager schema invariants that keep the Sphinx widgets
 * out of CS-Cart's shared 'template' block-type pool — the pool the core
 * header Search block selects its template from.
 *
 * Regression context: the widgets were once injected into
 * $schema['template']['templates'] (with paths that did not even resolve).
 * Because the core value of that key is a directory-scan STRING
 * ('blocks/static_templates'), the injection replaced the whole core pool and
 * polluted the Search block's template list.
 */
final class BlockManagerSchemaTest extends TestCase
{
    private const DEDICATED_TYPES = ['sphinx_package_search'];

    /** Core schema shape: 'templates' is a directory-scan string. */
    private const CORE_TEMPLATE_TYPE = [
        'templates' => 'blocks/static_templates',
        'wrappers'  => 'blocks/wrappers',
    ];

    /** @return array<string, mixed> */
    private static function applySchema(): array
    {
        $schema = ['template' => self::CORE_TEMPLATE_TYPE];

        $result = (static function (array $schema) {
            return include dirname(__DIR__, 3) . '/schemas/block_manager/blocks.post.php';
        })($schema);

        self::assertIsArray($result);

        /** @var array<string, mixed> $result */
        return $result;
    }

    public function testSharedTemplatePoolIsUntouched(): void
    {
        $schema = self::applySchema();

        self::assertSame(
            self::CORE_TEMPLATE_TYPE,
            $schema['template'] ?? null,
            'The shared template pool feeds the core Search block — the addon must never modify it',
        );
    }

    public function testDedicatedBlockTypesAreRegistered(): void
    {
        $schema = self::applySchema();

        foreach (self::DEDICATED_TYPES as $type) {
            self::assertArrayHasKey($type, $schema);
            self::assertIsArray($schema[$type]);
            self::assertIsArray($schema[$type]['templates'] ?? null, "$type must declare a templates array");
            self::assertNotEmpty($schema[$type]['templates'], "$type must register at least one template");
            self::assertSame('blocks/wrappers', $schema[$type]['wrappers'] ?? null, "$type must use standard wrappers");
        }
    }

    public function testRegisteredTemplateFilesExistInResponsiveTheme(): void
    {
        $schema = self::applySchema();
        $templatesRoot = dirname(__DIR__, 6) . '/design/themes/responsive/templates/';

        foreach (self::DEDICATED_TYPES as $type) {
            self::assertIsArray($schema[$type]);
            self::assertIsArray($schema[$type]['templates']);
            foreach (array_keys($schema[$type]['templates']) as $tplPath) {
                self::assertIsString($tplPath);
                self::assertFileExists(
                    $templatesRoot . $tplPath,
                    "$type registers '$tplPath' which does not resolve to a shipped template file",
                );
            }
        }
    }

    /**
     * Hotels are booked from their product pages only: the destination-browse
     * "Booking Form" block and the "Best Deals" block (no store product behind
     * their hotels) are gone, and placed ones are removed once on upgrade.
     */
    public function testTheNonProductHotelBlocksAreGone(): void
    {
        $schema = self::applySchema();
        $templatesRoot = dirname(__DIR__, 6) . '/design/themes/responsive/templates/addons/sphinx_holidays/blocks/';

        foreach (['sphinx_booking_engine', 'sphinx_best_deals'] as $type) {
            self::assertArrayNotHasKey($type, $schema);
        }
        self::assertFileDoesNotExist($templatesRoot . 'booking_engine.tpl');
        self::assertFileDoesNotExist($templatesRoot . 'best_deals.tpl');

        $cleanup = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Install/RemovedBlocksCleanup.php');
        self::assertStringContainsString("public const REMOVED_TYPES = ['sphinx_booking_engine', 'sphinx_best_deals'];", $cleanup);
        self::assertStringContainsString('fn_set_storage_data(self::FLAG, \'Y\');', $cleanup);
        self::assertStringContainsString(
            'RemovedBlocksCleanup::runOnce();',
            (string) file_get_contents(dirname(__DIR__, 3) . '/src/Install/SchemaMigrator.php'),
        );
    }

    public function testNoTemplatesShipInAutoScannedStaticTemplatesDir(): void
    {
        $designRoot = dirname(__DIR__, 6) . '/design';
        $offenders = [];

        $staticDirs = glob($designRoot . '/themes/*/templates/blocks/static_templates', GLOB_ONLYDIR) ?: [];
        foreach ($staticDirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'tpl') {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Files under blocks/static_templates/ are auto-discovered into the shared pool '
            . '(and become selectable for the core Search block) — ship widgets as dedicated block types instead',
        );
    }
}
