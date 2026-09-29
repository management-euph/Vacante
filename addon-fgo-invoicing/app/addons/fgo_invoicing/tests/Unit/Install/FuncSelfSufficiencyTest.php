<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldRepository;

/**
 * Guards the settings form of the CIF / Reg. Com. / CNP selectors.
 *
 * CS-Cart builds an add-on's settings form (fn_get_schema(..., force_addon_init
 * = true)) by including func.php WITHOUT init.php, the only registrar of the
 * Tygh\Addons\FgoInvoicing\* autoloader. A variants callback that touched an
 * add-on class would die there in a silent fatal — travel_core shipped
 * exactly that as "click Install, nothing happens". So this runs func.php in
 * a CLEAN php process with CS-Cart stubs only and no autoloader, and calls the
 * callbacks the way Settings::getVariants() does.
 */
#[CoversNothing]
final class FuncSelfSufficiencyTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function simulate(string $mode): array
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/install_environment_sim.php';
        $funcPhp = dirname(__DIR__, 3) . '/func.php';
        self::assertFileExists($fixture);
        self::assertFileExists($funcPhp);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' '
            . escapeshellarg($funcPhp) . ' ' . escapeshellarg($mode) . ' 2>&1';
        exec($cmd, $lines, $exitCode);
        $output = implode("\n", $lines);

        self::assertSame(0, $exitCode, "func.php is not self-sufficient without the autoloader:\n{$output}");
        self::assertStringNotContainsString('Fatal error', $output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, "unexpected output:\n{$output}");

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testVariantsCallbacksRunWithoutTheAutoloader(): void
    {
        $out = self::simulate('missing-key');

        $expected = [
            '' => 'Auto-detect by field name',
            36 => 'CIF (#36)',
            40 => 'cnp (#40)',
            41 => 'Profile field (#41)',
            42 => 'Nr. Reg. Com. (#42)',
        ];
        self::assertSame($expected, $out['cif'], 'shipping twin #37 and the broken row are left out');
        self::assertSame($expected, $out['reg']);
        self::assertSame($expected, $out['cnp']);
        self::assertFalse($out['autoloaded'], 'no add-on class may be loaded on this path');
    }

    public function testOneQueryInTheAdminLanguageForAllThreeSelectors(): void
    {
        $out = self::simulate('missing-key');

        self::assertIsArray($out['queries']);
        self::assertCount(1, $out['queries'], 'the three selectors share one query');
        $query = $out['queries'][0];
        self::assertIsArray($query);
        self::assertStringContainsString("f.is_default = 'N'", (string) $query['query']);
        self::assertStringContainsString("f.profile_type = 'U'", (string) $query['query'], 'same filter as ProfileFieldRepository');
        self::assertSame(
            ['ro', ProfileFieldRepository::TEXT_FIELD_TYPES],
            $query['params'],
            'the dropdown must offer exactly the field types the resolver reads',
        );
    }

    public function testTheAutoDetectLabelIsTranslatedWhenTheKeyExists(): void
    {
        $out = self::simulate('translated');

        self::assertIsArray($out['cif']);
        self::assertSame('Detectare automată', $out['cif']['']);
    }
}
