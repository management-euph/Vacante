<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: show what Novoton's hotel_facilities answers for ONE hotel.
 *
 * hotel_facilities_batched reported "OK (0 facilities)" for every hotel: the
 * reply held no <IdFacility> the sync could read. This command makes the
 * same call, READ-ONLY (nothing is stored or deleted), and prints the raw
 * reply, what the parser sees in it and what is stored for the hotel — so the
 * reply's actual shape (an error, another element name) is visible.
 *
 * Usage:
 *   cron_mode=diagnose_hotel_facilities&hotel_id=2906
 */
class DiagnoseHotelFacilitiesCommand extends AbstractCronCommand
{
    private const int RAW_LIMIT = 4000;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['diagnose_hotel_facilities'];
    }

    public static function getDescription(): string
    {
        return 'Show the raw Novoton hotel_facilities reply for one hotel (hotel_id=…) and what the sync reads from it; read-only';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $hotelId = TypeCoerce::toString($this->getParam('hotel_id', ''));
        if ($hotelId === '') {
            $this->output('ERROR: &hotel_id=<id> is required. Example: &cron_mode=diagnose_hotel_facilities&hotel_id=2906');
            return ['success' => false, 'error' => 'hotel_id required'];
        }

        $stored = TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s', $hotelId));
        $this->output("=== hotel_facilities for hotel [{$hotelId}] (read-only) ===");
        $this->output("Stored now: {$stored} facilities");

        $api = fn_novoton_holidays_get_api();
        if ($api === null) {
            $this->output('ERROR: the Novoton API is not configured.');
            return ['success' => false, 'error' => 'no api'];
        }

        try {
            $response = $api->hotels()->getHotelFacilities($hotelId);
        } catch (\Throwable $e) {
            $this->output('API call failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $client = $api->hotels();
        $client->syncDebugState();
        $raw = $client->lastResponseRaw;

        $ids = [];
        foreach ($response->xpath('//IdFacility') ?: [] as $node) {
            if ((int) $node > 0) {
                $ids[] = (int) $node;
            }
        }
        $elements = [];
        foreach ($response->xpath('//*') ?: [] as $node) {
            $elements[$node->getName()] = ($elements[$node->getName()] ?? 0) + 1;
        }

        $this->output('Parsed root: <' . $response->getName() . '>');
        $this->output('Elements in the reply: ' . ($elements === [] ? '(none)' : implode(', ', array_map(static fn (string $n, int $c): string => "{$n}×{$c}", array_keys($elements), $elements))));
        $this->output('<IdFacility> the sync reads: ' . count($ids) . ($ids !== [] ? ' (' . implode(', ', array_slice($ids, 0, 30)) . (count($ids) > 30 ? ', …' : '') . ')' : ''));
        $this->output('');
        $this->output('--- Raw reply (first ' . self::RAW_LIMIT . ' chars) ---');
        $this->output($raw !== '' ? mb_substr($raw, 0, self::RAW_LIMIT) : mb_substr((string) $response->asXML(), 0, self::RAW_LIMIT));

        return ['success' => true, 'facility_ids' => count($ids), 'stored' => $stored];
    }
}
