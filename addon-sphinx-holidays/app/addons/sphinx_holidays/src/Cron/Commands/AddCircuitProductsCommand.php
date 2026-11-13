<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Helpers\CircuitProductFactory;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Creates CS-Cart products for synced circuits (sphinx_circuits rows with
 * no product_id yet) — the missing half of the circuit vertical: without a
 * product, the storefront circuit flow dead-ends at add-to-cart.
 *
 * Resumable by construction: linked circuits drop out of the work queue.
 * Run after the `circuits` sync; re-runs are a no-op once the backlog is
 * drained.
 *
 * Params:
 *   limit=N   circuits per run (default 200)
 */
class AddCircuitProductsCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['add_circuit_products'];
    }

    public static function getDescription(): string
    {
        return 'Create CS-Cart products for synced circuits (makes them sellable)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        if (ConfigProvider::getCircuitsCategoryId() <= 0) {
            $this->output('Set "Circuits category" in the addon settings first — products need a category.');

            return ['success' => false, 'error' => 'circuits_category_id not configured'];
        }

        $limit = max(1, TypeCoerce::toInt($params['limit'] ?? 200));

        $repo = new CircuitRepository();
        $factory = new CircuitProductFactory($repo);

        // A circuit whose product was deleted gets a new one.
        $orphaned = $repo->unlinkMissingProducts();
        if ($orphaned > 0) {
            $this->output("{$orphaned} circuits were linked to a product that no longer exists: unlinked, a new product is made for them.");
        }

        $circuits = $repo->findUnlinked($limit);

        if ($circuits === []) {
            $this->output('Nothing to do — every active circuit already has a product.');

            return ['success' => true, 'stats' => ['added' => 0, 'linked' => 0, 'skipped' => 0, 'failed' => 0]];
        }

        $this->output('Creating products for ' . count($circuits) . ' circuits...');
        $this->output('');

        $stats = ['added' => 0, 'linked' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($circuits as $circuit) {
            $circuitId = TypeCoerce::toInt($circuit['circuit_id'] ?? 0);
            $name = TypeCoerce::toString($circuit['name'] ?? '');

            $result = $factory->createFromCircuit($circuit);
            $status = TypeCoerce::toString($result['status']);
            $stats[$status] = TypeCoerce::toInt($stats[$status] ?? 0) + 1;

            $suffix = $result['reason'] !== '' ? " ({$result['reason']})" : " -> product #{$result['product_id']}";
            $this->output("  {$circuitId} | {$name}: {$status}{$suffix}");
            if (($result['features'] ?? '') !== '') {
                $this->output('      features not written: ' . $result['features']);
            }
        }

        $this->output('');
        $this->output("Done: {$stats['added']} added, {$stats['linked']} linked, {$stats['skipped']} skipped, {$stats['failed']} failed.");

        return ['success' => $stats['failed'] === 0, 'stats' => $stats];
    }
}
