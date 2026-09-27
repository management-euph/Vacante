<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\DestinationWhitelistRepository;

/**
 * What we sell from Novoton: the destination whitelist, read once per request.
 *
 * Each country is in one of three states:
 *   - not sold     — no row;
 *   - all resorts  — a country row 'all': every resort, new ones included;
 *   - only selected — a country row 'specific' plus one row per resort:
 *                    resorts Novoton adds later wait for review.
 * HIDDEN_RESORTS (the gift-voucher pseudo resort) is never sold either way.
 *
 * An empty whitelist means "not configured": everything works as before it
 * existed (selected_countries + the dashboard's excluded resorts), so
 * upgrading changes nothing until someone saves the Destinations page.
 * Sphinx treats empty as "sell nothing"; here that would stop every
 * product on upgrade day.
 */
final class DestinationScope
{
    public const string MODE_OFF = 'off';
    public const string MODE_ALL = 'all';
    public const string MODE_SPECIFIC = 'specific';

    private static ?self $current = null;

    /**
     * Keyed by upper-case country; each country's resorts keyed by upper-case
     * name => the name as stored.
     *
     * @param array<string, array{mode: string, resorts: array<string, string>, reviewed_at: string}> $countries
     */
    private function __construct(private readonly array $countries)
    {
    }

    /**
     * @param list<array{country: string, resort: string, selection_type: string, reviewed_at?: string}> $rows
     */
    public static function fromRows(array $rows): self
    {
        $countries = [];
        foreach ($rows as $row) {
            $country = mb_strtoupper(trim($row['country']));
            $type = $row['selection_type'];
            if ($country === '' || !in_array($type, [self::MODE_ALL, self::MODE_SPECIFIC], true)) {
                continue;
            }
            $countries[$country] ??= ['mode' => self::MODE_SPECIFIC, 'resorts' => [], 'reviewed_at' => ''];
            $resort = trim($row['resort']);
            if ($resort === '') {
                $countries[$country]['mode'] = $type;
                $countries[$country]['reviewed_at'] = $row['reviewed_at'] ?? '';
            } else {
                $countries[$country]['resorts'][mb_strtoupper($resort)] = $resort;
            }
        }

        return new self($countries);
    }

    /** The whitelist saved on the Destinations page, loaded once per request. */
    public static function current(): self
    {
        if (self::$current === null) {
            try {
                self::$current = self::fromRows((new DestinationWhitelistRepository())->findAll());
            } catch (\Throwable $e) {
                error_log('novoton_holidays: could not read the destination whitelist — ' . $e->getMessage());
                self::$current = self::fromRows([]);
            }
        }

        return self::$current;
    }

    /** Tests, and the Save that just replaced the rows. */
    public static function setCurrent(?self $scope): void
    {
        self::$current = $scope;
    }

    public function isConfigured(): bool
    {
        return $this->countries !== [];
    }

    /**
     * The countries sold, as stored (e.g. BULGARIA).
     *
     * @return list<string>
     */
    public function countries(): array
    {
        return array_map('strval', array_keys($this->countries));
    }

    public function mode(string $country): string
    {
        return $this->countries[mb_strtoupper(trim($country))]['mode'] ?? self::MODE_OFF;
    }

    public function reviewedAt(string $country): string
    {
        return $this->countries[mb_strtoupper(trim($country))]['reviewed_at'] ?? '';
    }

    /**
     * The resorts picked for an 'only selected' country, as stored; null
     * when the country sells every resort (or is not sold at all).
     *
     * @return list<string>|null
     */
    public function selectedResorts(string $country): ?array
    {
        $c = $this->countries[mb_strtoupper(trim($country))] ?? null;
        if ($c === null || $c['mode'] !== self::MODE_SPECIFIC) {
            return null;
        }

        return array_values($c['resorts']);
    }

    /** Whether hotels in this resort may be sold. Trimmed, case-insensitive. */
    public function allows(string $country, ?string $resort): bool
    {
        $c = $this->countries[mb_strtoupper(trim($country))] ?? null;
        if ($c === null) {
            return false;
        }
        $key = mb_strtoupper(trim((string) $resort));
        if ($key !== '' && ConfigProvider::isResortExcluded($key, ConfigProvider::getHiddenResorts())) {
            return false;
        }

        return $c['mode'] === self::MODE_ALL || ($key !== '' && isset($c['resorts'][$key]));
    }

    /**
     * What a product-creation run may take from one country.
     *
     * Configured: skip a country that is not sold; 'only selected' limits the
     * query to its resorts; extra &exclude_resorts names and the hidden
     * resorts are left out either way. Not configured: the dashboard's
     * excluded resorts, as before.
     *
     * @param list<string> $extraExcluded
     * @return array{skip: bool, exclude: list<string>, only: list<string>|null}
     */
    public function productQuery(string $country, array $extraExcluded = []): array
    {
        if (!$this->isConfigured()) {
            return ['skip' => false, 'exclude' => ConfigProvider::getProductExclusions($extraExcluded), 'only' => null];
        }

        $exclude = [];
        foreach ([...$extraExcluded, ...ConfigProvider::getHiddenResorts()] as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array($name, $exclude, true)) {
                $exclude[] = $name;
            }
        }

        return [
            'skip' => $this->mode($country) === self::MODE_OFF,
            'exclude' => $exclude,
            'only' => $this->selectedResorts($country),
        ];
    }

    /** One hotel (offers_update): may it become a product? */
    public function allowsProduct(string $country, ?string $resort): bool
    {
        if (!$this->isConfigured()) {
            return !ConfigProvider::isResortExcluded($resort, ConfigProvider::getProductExclusions());
        }

        return $this->allows($country, $resort);
    }
}
