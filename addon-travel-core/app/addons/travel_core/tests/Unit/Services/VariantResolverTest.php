<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Services\VariantResolver;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

#[CoversClass(VariantResolver::class)]
class VariantResolverTest extends TestCase
{
    /** @var FeatureMapRepositoryInterface&MockObject */
    private FeatureMapRepositoryInterface $repo;

    private VariantResolver $resolver;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = $this->createMock(FeatureMapRepositoryInterface::class);
        $this->resolver = new VariantResolver($this->repo);
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    // ── ensureVariantExists: early returns ──────────────────────────────────

    public function testEnsureVariantReturnsZeroWhenMapIdMissing(): void
    {
        $this->assertSame(0, $this->resolver->ensureVariantExists(['map_id' => 0]));
    }

    public function testEnsureVariantReturnsZeroWhenFeatureIdAndFeatureTypeBothMissing(): void
    {
        // map_id > 0 but no feature_id and no feature_type → bypasses the
        // static FeatureMapper::getFeatureId() path and returns 0.
        $this->assertSame(0, $this->resolver->ensureVariantExists([
            'map_id' => 42,
            'cscart_feature_id' => 0,
            'feature_type' => '',
        ]));
    }

    public function testEnsureVariantStaticFeatureMapperPathDeferred(): void
    {
        // The feature_id=0 + feature_type!='' branch calls the static
        // FeatureMapper::getFeatureId() and — on success — the repo's
        // updateFeatureId(). Covered by integration-level tests; flipping
        // this to a real assertion requires extracting a
        // FeatureIdResolverInterface (tracked separately).
        $this->markTestSkipped('static FeatureMapper::getFeatureId dep — see plan gap note');
    }

    // ── ensureVariantExists: stored-variant paths ───────────────────────────

    public function testEnsureVariantReturnsStoredVariantWhenItExists(): void
    {
        $mapping = [
            'map_id' => 1,
            'cscart_feature_id' => 10,
            'cscart_variant_id' => 50,
        ];

        DbStub::$getField = function (string $query, ...$params): int {
            $this->assertStringContainsString('variant_id = ?i AND feature_id = ?i', $query);
            $this->assertSame([50, 10], $params);
            return 50; // stored variant confirmed to exist
        };

        $this->repo->expects($this->never())->method('updateVariantId');

        $this->assertSame(50, $this->resolver->ensureVariantExists($mapping));
    }

    public function testEnsureVariantReturnsZeroWhenStoredVariantMissingAndManualLocked(): void
    {
        $mapping = [
            'map_id' => 1,
            'cscart_feature_id' => 10,
            'cscart_variant_id' => 50,
            'variant_source' => 'manual',
        ];

        // Stored check returns null → variant deleted from CS-Cart.
        DbStub::$getField = static fn (): mixed => null;

        $this->repo->expects($this->never())->method('updateVariantId');

        $this->assertSame(0, $this->resolver->ensureVariantExists($mapping));
    }

    public function testEnsureVariantReturnsStoredWhenManualLockedWithoutStoredVariant(): void
    {
        // cscart_variant_id=0 + variant_source=manual → skip exists check,
        // hit the manual-guard and return 0 (the stored value) without
        // trying to match by name or auto-create.
        $mapping = [
            'map_id' => 1,
            'cscart_feature_id' => 10,
            'cscart_variant_id' => 0,
            'variant_source' => 'manual',
        ];

        $this->repo->expects($this->never())->method('updateVariantId');

        $this->assertSame(0, $this->resolver->ensureVariantExists($mapping));
    }

    // ── findVariantByName: 3-pass matching ──────────────────────────────────

    public function testFindVariantByNamePass1ExactMatchEn(): void
    {
        $mapping = ['display_name_en' => 'All Inclusive'];

        DbStub::$getField = function (string $query, ...$params): int {
            $this->assertStringContainsString('vd.variant = ?s', $query);
            $this->assertSame([10, 'en', 'All Inclusive'], $params);
            return 42;
        };

        $this->assertSame(42, $this->resolver->findVariantByName($mapping, 10));
    }

    public function testFindVariantByNamePass2CaseInsensitiveMatchEn(): void
    {
        $mapping = ['display_name_en' => 'All Inclusive'];

        DbStub::$getField = function (string $query, ...$_params): ?int {
            if (str_contains($query, 'LOWER(vd.variant) = LOWER(?s)')) {
                return 43;
            }
            return null;  // Pass 1 exact miss
        };

        $this->assertSame(43, $this->resolver->findVariantByName($mapping, 10));
    }

    public function testFindVariantByNamePass3NormalizedFuzzyMatchEn(): void
    {
        $mapping = ['display_name_en' => 'All-Inclusive (Premium)'];

        DbStub::$getField = static fn (): mixed => null; // Pass 1+2 both miss
        DbStub::$getArray = static fn (): array => [
            ['variant_id' => 99, 'variant' => 'irrelevant row'],
            ['variant_id' => 77, 'variant' => 'All Inclusive Premium'], // normalizes to same
        ];

        $this->assertSame(77, $this->resolver->findVariantByName($mapping, 10));
    }

    public function testFindVariantByNameFallsThroughFromEnToRo(): void
    {
        $mapping = [
            'display_name_en' => 'All Inclusive',
            'display_name_ro' => 'Totul Inclus',
        ];

        $calls = [];
        DbStub::$getField = function (string $_query, ...$params) use (&$calls): ?int {
            $calls[] = $params[1] ?? null; // track language
            // EN misses all three passes; RO Pass 1 (exact) hits.
            return $params[1] === 'ro' && is_string($params[2] ?? null) && $params[2] === 'Totul Inclus' ? 88 : null;
        };
        DbStub::$getArray = static fn (): array => []; // Pass 3 empty

        $this->assertSame(88, $this->resolver->findVariantByName($mapping, 10));
        // EN called 2 times (Pass 1 + 2), RO called 1 time (Pass 1 hit before P2/P3).
        $this->assertSame(['en', 'en', 'ro'], $calls);
    }

    public function testFindVariantByNameSkipsRoWhenIdenticalToEn(): void
    {
        $mapping = [
            'display_name_en' => 'Deluxe',
            'display_name_ro' => 'Deluxe', // same as EN
        ];

        $calls = [];
        DbStub::$getField = function (string $_query, ...$params) use (&$calls): ?int {
            $calls[] = $params[1] ?? null;
            return null;
        };
        DbStub::$getArray = function (string $_query, ...$params) use (&$calls): array {
            $calls[] = $params[1] ?? null;
            return [];
        };

        $this->assertSame(0, $this->resolver->findVariantByName($mapping, 10));
        // Only EN passes — never 'ro'.
        $this->assertSame(['en', 'en', 'en'], $calls);
    }

    public function testFindVariantByNameReturnsZeroWhenFeatureIdMissing(): void
    {
        // getField must never be called when featureId guard trips.
        DbStub::$getField = function (): never {
            $this->fail('db_get_field should not be called when featureId <= 0');
        };

        $this->assertSame(0, $this->resolver->findVariantByName(
            ['display_name_en' => 'All Inclusive'],
            0,
        ));
    }

    public function testFindVariantByNameReturnsZeroWhenDisplayNameEnEmpty(): void
    {
        DbStub::$getField = function (): never {
            $this->fail('db_get_field should not be called when nameEn is empty');
        };

        $this->assertSame(0, $this->resolver->findVariantByName(['display_name_en' => ''], 10));
        $this->assertSame(0, $this->resolver->findVariantByName(['display_name_en' => '   '], 10));
    }

    // ── normalizeName static helper ─────────────────────────────────────────

    public function testNormalizeNameStripsPunctuationAndLowercases(): void
    {
        $this->assertSame(
            'all inclusive premium',
            VariantResolver::normalizeName('All-Inclusive (Premium)'),
        );
    }

    public function testNormalizeNameCollapsesWhitespace(): void
    {
        $this->assertSame(
            'breakfast included',
            VariantResolver::normalizeName("  breakfast   \t\nincluded  "),
        );
    }

    public function testNormalizeNameKeepsUnicodeLetters(): void
    {
        $this->assertSame(
            'demipensiune cu mic dejun',
            VariantResolver::normalizeName('Demipensiune / cu mic-dejun'),
        );
    }

    // ── ensureVariantExists: auto-create path ───────────────────────────────

    public function testEnsureVariantAutoCreatesWhenNoMatchFound(): void
    {
        $mapping = [
            'map_id' => 5,
            'cscart_feature_id' => 10,
            'cscart_variant_id' => 0,
            'display_name_en' => 'New Variant',
            'display_name_ro' => 'Variantă Nouă',
        ];

        // All name-match passes miss.
        DbStub::$getField = static fn (): mixed => null;
        DbStub::$getArray = static fn (): array => [];
        // Active languages for createVariant.
        DbStub::$getFields = static fn (): array => ['en', 'ro'];
        // First db_query = INSERT variant → returns new id; subsequent =
        // description inserts (return value ignored).
        $queryCalls = 0;
        DbStub::$query = static function () use (&$queryCalls): int {
            $queryCalls++;
            return $queryCalls === 1 ? 77 : 1;
        };

        $this->repo->expects($this->once())
            ->method('updateVariantId')
            ->with(5, 77, 'auto');

        $this->assertSame(77, $this->resolver->ensureVariantExists($mapping));
    }

    public function testEnsureVariantSkipsAutoCreateWhenDisplayNameEnEmpty(): void
    {
        $mapping = [
            'map_id' => 5,
            'cscart_feature_id' => 10,
            'cscart_variant_id' => 0,
            'display_name_en' => '',
        ];

        DbStub::$getField = static fn (): mixed => null;
        DbStub::$getArray = static fn (): array => [];
        // If anything reached db_query, createVariant would have been
        // called — guard against that.
        DbStub::$query = function (): never {
            $this->fail('createVariant should not be reached when nameEn is empty');
        };

        $this->repo->expects($this->never())->method('updateVariantId');

        $this->assertSame(0, $this->resolver->ensureVariantExists($mapping));
    }

    public function testEnsureVariantReturnsMatchedAndRecordsItOnRepo(): void
    {
        $mapping = [
            'map_id' => 5,
            'cscart_feature_id' => 10,
            'cscart_variant_id' => 0,
            'display_name_en' => 'Deluxe',
        ];

        DbStub::$getField = static fn (string $query): ?int =>
            str_contains($query, 'vd.variant = ?s') ? 99 : null;

        $this->repo->expects($this->once())
            ->method('updateVariantId')
            ->with(5, 99, 'auto');

        $this->assertSame(99, $this->resolver->ensureVariantExists($mapping));
    }

    public function testMatchNameTriesExactThenCaseInsensitiveThenNormalized(): void
    {
        $variants = ['All Inclusive' => 3, 'half board' => 4, 'Ultra-All (Premium)' => 5];

        $this->assertSame(3, VariantResolver::matchName('All Inclusive', $variants));
        $this->assertSame(4, VariantResolver::matchName('Half Board', $variants));
        $this->assertSame(5, VariantResolver::matchName('ultra all premium', $variants));
        $this->assertNull(VariantResolver::matchName('Bed & Breakfast', $variants));
    }
}
