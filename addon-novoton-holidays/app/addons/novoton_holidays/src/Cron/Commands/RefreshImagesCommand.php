<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Api\Refusal;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Repository\HotelImageRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelImageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\HotelImageSync;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `refresh_images`: hotel photos, refreshed about once a week per hotel
 * (Novoton's suggestion, 28 Oct 2026). Photos used to be downloaded once,
 * when the product was made.
 *
 * Each night at 02:15 it takes a seventh of the hotels with a product, least
 * recently checked first: one hotel_images call each, and a download only
 * for a photo not seen before. HotelImageSync decides what changes: new
 * photos are added, in Novoton's order, and replace the ones Novoton
 * dropped; when nothing is new, nothing is added or removed.
 *
 * No hotel is started after MAX_MINUTES, so the run ends before 03:00,
 * Novoton's busiest hour. A hotel Novoton does not answer for goes first
 * again the next night; SILENT_STOP of them in a row end the run.
 *
 * &limit=N hotels this run · &hotel_id=X one hotel · &max_time=MINUTES
 */
class RefreshImagesCommand extends AbstractCronCommand
{
    /** Each hotel's photos are checked about once in this many nights. */
    public const int CYCLE_DAYS = 7;

    /** Started at 02:15, the run starts no hotel after this, so it ends before 03:00. */
    public const int MAX_MINUTES = 35;

    /** Hotels in a row without an answer that end the run. */
    public const int SILENT_STOP = 3;

    private ?HotelImageSync $sync = null;

    private ?HotelImageRepositoryInterface $photos = null;

    /** @var (\Closure(): float)|null */
    private ?\Closure $clock = null;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['refresh_images'];
    }

    public static function getDescription(): string
    {
        return 'Refresh hotel photos from Novoton: a seventh of the hotels a night, a download only for a photo not seen before';
    }

    /**
     * For tests: the photo sync, its table and a clock (seconds).
     *
     * @param (\Closure(): float)|null $clock
     */
    public function useServices(HotelImageSync $sync, HotelImageRepositoryInterface $photos, ?\Closure $clock = null): void
    {
        $this->sync = $sync;
        $this->photos = $photos;
        $this->clock = $clock;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $photos = $this->photos ?? new HotelImageRepository();
        $sync = $this->sync ?? new HotelImageSync($photos);
        $clock = $this->clock ?? static fn (): float => microtime(true);
        $started = $clock();

        $hotelId = TypeCoerce::toString($this->getParam('hotel_id', ''));
        $limit = TypeCoerce::toInt($this->getParam('limit', 0));
        $minutes = TypeCoerce::toInt($this->getParam('max_time', self::MAX_MINUTES));
        $maxSeconds = 60 * max(1, min(240, $minutes > 0 ? $minutes : self::MAX_MINUTES));
        $linked = $photos->linkedCount();
        $take = match (true) {
            $hotelId !== '' => 1,
            $limit > 0 => $limit,
            default => (int) ceil($linked / self::CYCLE_DAYS),
        };
        $hotels = $take > 0 ? $photos->due($take, $hotelId) : [];

        $this->output(sprintf(
            'Hotel photos: %d of the %d hotels with a product, least recently checked first (each about once every %d days); at most %d minutes.',
            count($hotels),
            $linked,
            self::CYCLE_DAYS,
            intdiv($maxSeconds, 60),
        ));
        $this->output('');

        $stats = [
            'hotels' => 0, 'updated' => 0, 'unchanged' => 0, 'refused' => 0, 'no_answer' => 0, 'empty' => 0, 'unreadable' => 0,
            'added' => 0, 'same' => 0, 'removed' => 0, 'moved' => 0, 'failed' => 0, 'left' => 0, 'stopped' => '',
        ];
        $silent = 0;
        foreach ($hotels as $index => $hotel) {
            if ($clock() - $started >= $maxSeconds) {
                $stats['stopped'] = 'time';
                $stats['left'] = count($hotels) - $index;

                break;
            }
            $stats['hotels']++;
            $name = 'NVT-' . $hotel['hotel_id'] . ' ' . $hotel['hotel_name'];
            $now = date('Y-m-d H:i:s');

            try {
                $reply = $this->api->hotels()->getHotelImages($hotel['hotel_id']);
            } catch (\Throwable $e) {
                $stats['no_answer']++;
                $this->output("  {$name}: no answer ({$e->getMessage()}); first again next night.");
                if (++$silent >= self::SILENT_STOP) {
                    $stats['stopped'] = 'silent';
                    $stats['left'] = count($hotels) - $index - 1;

                    break;
                }

                continue;
            }
            $silent = 0;
            $photos->stampChecked($hotel['hotel_id'], $now);

            $refused = Refusal::reason($reply);
            if ($refused !== null) {
                $stats['refused']++;
                $this->output("  {$name}: Novoton: {$refused}; photos kept as they are.");

                continue;
            }

            $result = $sync->sync($hotel['hotel_id'], $hotel['product_id'], HotelImageSync::paths($reply), $now);
            foreach (['added', 'same', 'removed', 'moved', 'failed'] as $key) {
                $stats[$key] += $result[$key];
            }
            $stats[match ($result['outcome']) {
                HotelImageSync::UPDATED => 'updated',
                HotelImageSync::EMPTY => 'empty',
                HotelImageSync::UNREADABLE => 'unreadable',
                HotelImageSync::NO_ANSWER => 'refused',
                default => 'unchanged',
            }]++;
            $this->output("  {$name}: " . self::describe($result));
        }

        $this->output('');
        $this->output(sprintf(
            'Done: %d hotels, %d with changes. Photos: %d added, %d removed, %d already on the product, %d did not download. Novoton did not answer for %d, refused %d.',
            $stats['hotels'],
            $stats['updated'],
            $stats['added'],
            $stats['removed'],
            $stats['same'],
            $stats['failed'],
            $stats['no_answer'],
            $stats['refused'],
        ));
        $reason = [
            'time' => 'Stopped at the time budget (' . intdiv($maxSeconds, 60) . ' minutes)',
            'silent' => 'Stopped: Novoton did not answer for ' . self::SILENT_STOP . ' hotels in a row',
        ][$stats['stopped']] ?? '';
        if ($reason !== '') {
            $this->output("{$reason}: {$stats['left']} hotels go first next night.");
        }

        $this->logComplete('refresh_images', $stats);
        $this->logToSyncTable('refresh_images', $stats['updated'], $stats['failed']);
        if ($stats['hotels'] > 0 && $stats['no_answer'] === $stats['hotels']) {
            return ['success' => false, 'error' => 'Novoton did not answer the photo refresh', 'stats' => $stats];
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * One hotel's line in the log.
     *
     * @param array{outcome: string, listed: int, known: int, added: int, same: int, failed: int, removed: int, moved: int} $result
     */
    public static function describe(array $result): string
    {
        $failed = $result['failed'] > 0 ? sprintf('; %d did not download, tried again next time', $result['failed']) : '';

        return match ($result['outcome']) {
            HotelImageSync::EMPTY => 'Novoton lists no photo; photos kept as they are.',
            HotelImageSync::NO_ANSWER => 'no answer; photos kept as they are.',
            HotelImageSync::UNREADABLE => 'an image of the product cannot be read here, so no photo was added.',
            HotelImageSync::UPDATED => sprintf(
                '%d listed: %d added, %d removed, %d already there%s%s.',
                $result['listed'],
                $result['added'],
                $result['removed'],
                $result['known'] + $result['same'],
                $result['moved'] > 0 ? "; put in Novoton's order" : '',
                $failed,
            ),
            default => sprintf('%d listed, nothing new%s.', $result['listed'], $failed),
        };
    }
}
