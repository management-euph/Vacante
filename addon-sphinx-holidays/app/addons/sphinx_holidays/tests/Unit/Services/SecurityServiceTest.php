<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\SecurityService;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Characterization coverage for sphinx SecurityService's pure validators,
 * added alongside the boundary-typing paydown (mixed inputs now coerced through
 * ValidationHelpers). Pins required-field/date/range validation, search-param
 * sanitisation (incl. strip_tags on destination), and guest-data sanitisation,
 * and demonstrates that string-typed inputs coerce identically to native ints.
 */
#[CoversClass(SecurityService::class)]
class SecurityServiceTest extends TestCase
{
    private SecurityService $security;

    protected function setUp(): void
    {
        $this->security = new SecurityService();
    }

    // ── validateBookingData ──────────────────────────────────────────────────

    public function testValidBookingData(): void
    {
        $result = $this->security->validateBookingData([
            'hotel_id' => 'H1',
            'offer_id' => 'O1',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'adults' => 2,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function testMissingRequiredFields(): void
    {
        $result = $this->security->validateBookingData([]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required field: hotel_id', $result['errors']);
        $this->assertContains('Missing required field: offer_id', $result['errors']);
    }

    public function testInvalidCheckInDate(): void
    {
        $result = $this->security->validateBookingData([
            'hotel_id' => 'H1', 'offer_id' => 'O1', 'check_in' => 'not-a-date', 'check_out' => '2026-07-08',
        ]);

        $this->assertContains('Invalid check-in date format', $result['errors']);
    }

    public function testCheckOutBeforeCheckInRejected(): void
    {
        $result = $this->security->validateBookingData([
            'hotel_id' => 'H1', 'offer_id' => 'O1', 'check_in' => '2026-07-08', 'check_out' => '2026-07-01',
        ]);

        $this->assertContains('Check-out must be after check-in', $result['errors']);
    }

    public function testAdultsRangeCheckCoercesStringInput(): void
    {
        // '999' (string) coerces to int and trips the upper bound — proving the
        // ValidationHelpers::toInt boundary coercion behaves like the old (int) cast.
        $result = $this->security->validateBookingData([
            'hotel_id' => 'H1', 'offer_id' => 'O1', 'check_in' => '2026-07-01', 'check_out' => '2026-07-08',
            'adults' => '999',
        ]);

        $this->assertContains('Adults must be between 1 and ' . TravelConstants::MAX_ADULTS, $result['errors']);
    }

    // ── validateSearchParams ─────────────────────────────────────────────────

    public function testSanitizesDestinationStripsTags(): void
    {
        $sanitized = $this->security->validateSearchParams(['destination' => '<b>Crete</b>']);

        $this->assertSame('Crete', $sanitized['destination']);
    }

    public function testClampsAdultsToMax(): void
    {
        $sanitized = $this->security->validateSearchParams(['adults' => '999']);

        $this->assertSame(TravelConstants::MAX_ADULTS, $sanitized['adults']);
    }

    public function testHotelIdStripsSpecialCharacters(): void
    {
        $sanitized = $this->security->validateSearchParams(['hotel_id' => 'AB!@#12']);

        $this->assertSame('AB12', $sanitized['hotel_id']);
    }

    public function testValidCheckInPassesThrough(): void
    {
        $sanitized = $this->security->validateSearchParams(['check_in' => '2026-07-01']);

        $this->assertSame('2026-07-01', $sanitized['check_in']);
    }

    // ── sanitizeGuestData ────────────────────────────────────────────────────

    public function testSanitizesGuestNameAndClampsAgeAndRoom(): void
    {
        $sanitized = $this->security->sanitizeGuestData([
            'g1' => ['name' => 'Mark<>', 'age' => '150', 'room' => '9', 'type' => 'adult'],
        ]);

        $this->assertSame('Mark', $sanitized['g1']['name']);
        $this->assertSame(99, $sanitized['g1']['age']);
        $this->assertSame(5, $sanitized['g1']['room']);
        $this->assertSame('adult', $sanitized['g1']['type']);
    }

    public function testSkipsNonArrayGuests(): void
    {
        $sanitized = $this->security->sanitizeGuestData(['x' => 'not-an-array']);

        $this->assertSame([], $sanitized);
    }

    /** The guest cards post the date of birth as DD/MM/YYYY (or YYYY-MM-DD): it reaches the booking. */
    public function testKeepsAWellFormedDateOfBirth(): void
    {
        $sanitized = $this->security->sanitizeGuestData([
            'a' => ['name' => 'Ana', 'dob' => ' 04/03/1990 '],
            'b' => ['name' => 'Ion', 'dob' => '1988-01-01'],
            'c' => ['name' => 'Mia', 'dob' => '4/3/2019<script>'],
            'd' => ['name' => 'Eva'],
        ]);

        $this->assertSame('04/03/1990', $sanitized['a']['dob']);
        $this->assertSame('1988-01-01', $sanitized['b']['dob']);
        $this->assertArrayNotHasKey('dob', $sanitized['c']);
        $this->assertArrayNotHasKey('dob', $sanitized['d']);
    }

    /** Sphinx's book calls take m or f: those are kept (any case), nothing else. */
    public function testKeepsOnlyAKnownGender(): void
    {
        $sanitized = $this->security->sanitizeGuestData([
            'a' => ['name' => 'Ana', 'gender' => 'F'],
            'b' => ['name' => 'Ion', 'gender' => 'm'],
            'c' => ['name' => 'Mia', 'gender' => 'x'],
            'd' => ['name' => 'Eva', 'gender' => ['f']],
        ]);

        $this->assertSame('f', $sanitized['a']['gender']);
        $this->assertSame('m', $sanitized['b']['gender']);
        $this->assertArrayNotHasKey('gender', $sanitized['c']);
        $this->assertArrayNotHasKey('gender', $sanitized['d']);
    }
}
