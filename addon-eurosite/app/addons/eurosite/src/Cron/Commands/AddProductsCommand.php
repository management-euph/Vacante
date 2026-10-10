<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `add_products` — a product for every listed (whitelisted) hotel with
 * an Immediate offer that is not a product yet. Hotels without images are
 * skipped unless "Create products for hotels without images" is on; they are
 * picked up by a later run once their pictures arrive.
 *
 * `&city=CODE` one destination · `&limit=N` products per run (default 100;
 * each downloads pictures) · `&dry_run=1` lists what it would create.
 */
final class AddProductsCommand extends AbstractSyncCommand
{
    private const DEFAULT_LIMIT = 100;

    #[\Override]
    public static function getModes(): array
    {
        return ['add_products'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Create products for Immediate, whitelisted hotels with images (&city=CODE, &limit=N, &dry_run=1)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('add_products', function () use ($params): array {
            $only = strtoupper(trim(TypeCoerce::toString($params['city'] ?? '')));
            $limit = max(1, TypeCoerce::toInt($params['limit'] ?? self::DEFAULT_LIMIT));
            $dryRun = !empty($params['dry_run']);

            $candidates = Container::hotels()->getProductCandidates($only);
            if ($candidates === []) {
                $this->output('  No Immediate hotels waiting to become products. Run the availability check first.');
            }
            $r = Container::hotelProducts()->createFor($candidates, $dryRun, $limit, fn (string $line) => $this->output($line));

            $skipped = [];
            foreach ($r['skipped'] as $reason => $n) {
                $skipped[] = "{$n} {$reason}";
            }
            $this->output(sprintf(
                '  %s%d created, %d linked to existing products, %d failed%s%s',
                $dryRun ? '[dry run] ' . $r['would_create'] . ' would be created; ' : '',
                $r['added'],
                $r['linked'],
                $r['failed'],
                $skipped !== [] ? '; skipped: ' . implode(', ', $skipped) : '',
                $r['details_fetched'] > 0 ? "; details fetched for {$r['details_fetched']}" : '',
            ));

            return [
                'total' => count($candidates),
                'synced' => $r['added'] + $r['linked'] + $r['would_create'],
                'failed' => $r['failed'],
                'added' => $r['added'],
                'linked' => $r['linked'],
                'skipped' => array_sum($r['skipped']),
                'dry_run' => $dryRun,
                'error' => implode('; ', array_slice($r['errors'], 0, 5)),
            ];
        });
    }
}
