<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeNormalizer;

/**
 * Pins the Eurosite → canonical normalization so Eurosite hotels resolve into
 * the same star/board/room/property vocabulary as novoton and sphinx.
 */
final class EurositeNormalizerTest extends TestCase
{
    private EurositeNormalizer $n;

    protected function setUp(): void
    {
        $this->n = new EurositeNormalizer();
    }

    public function testProviderName(): void
    {
        self::assertSame('eurosite', $this->n->getProviderName());
    }

    public function testStarRatingFromProductCategory(): void
    {
        self::assertSame('4', $this->n->normalizeStarRating('4'));
        self::assertSame('5', $this->n->normalizeStarRating(5));
        self::assertSame('3', $this->n->normalizeStarRating('3*'));
        self::assertNull($this->n->normalizeStarRating(''));
        self::assertNull($this->n->normalizeStarRating('0'));
    }

    public function testBoardCodesRomanianAndEnglish(): void
    {
        self::assertSame('BB', $this->n->normalizeBoardCode('Mic dejun'));
        self::assertSame('HB', $this->n->normalizeBoardCode('Demipensiune'));
        self::assertSame('FB', $this->n->normalizeBoardCode('Pensiune completa'));
        self::assertSame('AI', $this->n->normalizeBoardCode('All Inclusive'));
        self::assertSame('UAI', $this->n->normalizeBoardCode('Ultra All Inclusive'));
        self::assertSame('AI', $this->n->normalizeBoardCode('All Inclusive Plus'));
        self::assertNull($this->n->normalizeBoardCode('Unknown plan'));
    }

    public function testRoomTypeCodes(): void
    {
        self::assertSame('DBL', $this->n->normalizeRoomTypeCode('DB'));
        self::assertSame('DBL', $this->n->normalizeRoomTypeCode('Dubla'));
        self::assertSame('TRP', $this->n->normalizeRoomTypeCode('Tripla'));
        self::assertSame('APT', $this->n->normalizeRoomTypeCode('Apartament'));
        self::assertNull($this->n->normalizeRoomTypeCode(''));
    }

    public function testPropertyTypeFromClass(): void
    {
        self::assertSame('hotel', $this->n->normalizePropertyType('Hotel'));
        self::assertSame('villa', $this->n->normalizePropertyType('Vila'));
        self::assertSame('apartment', $this->n->normalizePropertyType('Apartament'));
        // Unknown/blank falls back to hotel (never null for a present value).
        self::assertSame('hotel', $this->n->normalizePropertyType('Something'));
        self::assertNull($this->n->normalizePropertyType(''));
    }

    public function testFacilityCodePassesNumericIdsOnly(): void
    {
        self::assertSame('6', $this->n->normalizeFacilityCode('6'));
        self::assertSame('124', $this->n->normalizeFacilityCode(124));
        self::assertNull($this->n->normalizeFacilityCode('wifi'));
        self::assertNull($this->n->normalizeFacilityCode(''));
    }
}
