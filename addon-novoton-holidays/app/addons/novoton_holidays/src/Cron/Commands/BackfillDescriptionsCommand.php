<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Helpers\HotelDescription;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Fill in the description of Novoton products created without one.
 *
 * Every creator read the hotel_description response with
 * (string) $response->Description, which is empty for Novoton's HTML
 * descriptions (see HotelDescription), so products were created with no
 * full_description. add_hotels_as_products never revisits a linked hotel,
 * and "Apply templates now" does not call the API, so this is the recovery
 * path: linked products whose description is empty get the hotel's text.
 *
 * Only full_description is written, in every language row of the product
 * (Novoton has one text; its RO answer is the same English text). Name,
 * page title, meta fields and SEO name are left alone. A description
 * someone wrote is never replaced unless force=1.
 *
 * Naturally resumable: a product drops out of the candidates once it has a
 * description.
 *
 * Params:
 *   limit=N     products per run (default 100)
 *   status=1    print the backlog, no processing
 *   force=1     rewrite every linked product's description
 *   hotel_id=X  one hotel's product
 */
class BackfillDescriptionsCommand extends AbstractCronCommand
{
    private const int DEFAULT_LIMIT = 100;

    /** Description source; defaults to the live API, injectable for tests. */
    private ?\Closure $descriptionFetcher = null;

    /** Pacing hook (microseconds); injectable for tests. */
    private ?\Closure $sleeper = null;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['backfill_descriptions'];
    }

    public static function getDescription(): string
    {
        return 'Fill in the description of products created without one (&force=1 rewrites all, &hotel_id=X one)';
    }

    /** @param callable(string): mixed $fetcher */
    public function setDescriptionFetcher(callable $fetcher): void
    {
        $this->descriptionFetcher = $fetcher(...);
    }

    /** @param callable(int): void $sleeper */
    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = $sleeper(...);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        if (!empty($this->getParam('status'))) {
            $missing = $this->countMissing();
            $this->output("Linked products without a description: {$missing}");

            return ['success' => true, 'stats' => ['remaining' => $missing]];
        }

        $force = !empty($this->getParam('force'));
        $limit = TypeCoerce::toInt($this->getParam('limit', self::DEFAULT_LIMIT));
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $hotelId = TypeCoerce::toString($this->getParam('hotel_id', ''));
        $candidates = $this->getCandidates($limit, $force, $hotelId);

        if ($candidates === []) {
            $this->output($force || $hotelId !== ''
                ? 'No linked product found.'
                : 'Nothing to backfill — every linked product has a description.');
            $stats = ['processed' => 0, 'written' => 0, 'no_text' => 0, 'remaining' => 0];
            $this->logComplete('backfill_descriptions', $stats);

            return ['success' => true, 'stats' => $stats];
        }

        $this->output('Backfilling descriptions for ' . count($candidates) . ' products...');
        $this->output('');

        $fetch = $this->descriptionFetcher ?? fn (string $hid): mixed => $this->api->hotels()->getHotelDescription($hid, 'UK');
        $sleeper = $this->sleeper ?? static function (int $us): void {
            usleep($us);
        };

        $processed = 0;
        $written = 0;
        $noText = 0;
        foreach ($candidates as $i => $hotel) {
            if ($i > 0) {
                $sleeper(Constants::API_DELAY_MODERATE); // pace the provider API
            }
            $hid = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $pid = TypeCoerce::toInt($hotel['product_id'] ?? 0);
            $name = TypeCoerce::toString($hotel['hotel_name'] ?? '');
            if ($hid === '' || $pid <= 0) {
                continue;
            }
            $processed++;

            try {
                $html = HotelDescription::fromResponse($fetch($hid));
            } catch (\Throwable $e) {
                $this->output("  {$hid} | {$name}: description fetch failed — " . $e->getMessage());
                continue;
            }
            if ($html === '') {
                $noText++;
                $this->output("  {$hid} | {$name}: Novoton has no description");
                continue;
            }

            $rows = $this->writeDescription($pid, $html, $force);
            $written += $rows > 0 ? 1 : 0;
            $this->output("  {$hid} | {$name} -> product #{$pid}: " . ($rows > 0 ? "description written ({$rows} language(s))" : 'already had one'));
        }

        $remaining = $this->countMissing();
        $this->output('');
        $this->output("Done: {$processed} products processed, {$written} got a description, {$noText} had none at Novoton. Remaining without a description: {$remaining}.");

        $stats = ['processed' => $processed, 'written' => $written, 'no_text' => $noText, 'remaining' => $remaining];
        $this->logComplete('backfill_descriptions', $stats);
        $this->logToSyncTable('backfill_descriptions', $written, 0);

        return ['success' => true, 'stats' => $stats];
    }

    /** Every language row of the product; an existing text only with force. */
    private function writeDescription(int $productId, string $html, bool $force): int
    {
        $where = $force ? '' : " AND (full_description IS NULL OR TRIM(full_description) = '')";

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:product_descriptions SET full_description = ?s WHERE product_id = ?i' . $where,
            $html,
            $productId,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getCandidates(int $limit, bool $force, string $hotelId): array
    {
        if ($hotelId !== '') {
            return TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, hotel_name, product_id FROM ?:novoton_hotels
                 WHERE hotel_id = ?s AND product_id > 0',
                $hotelId,
            ));
        }

        if ($force) {
            return TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, hotel_name, product_id FROM ?:novoton_hotels
                 WHERE product_id > 0 ORDER BY hotel_id LIMIT ?i',
                $limit,
            ));
        }

        return TypeCoerce::toRowList(db_get_array(
            "SELECT h.hotel_id, h.hotel_name, h.product_id FROM ?:novoton_hotels h
             WHERE h.product_id > 0
               AND EXISTS (
                   SELECT 1 FROM ?:product_descriptions pd
                   WHERE pd.product_id = h.product_id
                     AND (pd.full_description IS NULL OR TRIM(pd.full_description) = '')
               )
             ORDER BY h.hotel_id LIMIT ?i",
            $limit,
        ));
    }

    private function countMissing(): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(DISTINCT h.product_id) FROM ?:novoton_hotels h
             JOIN ?:product_descriptions pd ON pd.product_id = h.product_id
             WHERE h.product_id > 0 AND (pd.full_description IS NULL OR TRIM(pd.full_description) = '')",
        ));
    }
}
