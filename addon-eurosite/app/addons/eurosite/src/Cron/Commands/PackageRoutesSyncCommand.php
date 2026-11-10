<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;

/**
 * mode `package_routes` — where Eurosite's packages go, the cities they leave
 * from and their departure dates (getPackageNVRoutesRequest) →
 * ?:eurosite_package_routes. Weekly, through `full`.
 *
 * Asked once for plane packages and once for coach ones: the answer does not
 * say how a package travels, so this is how each route learns it. A
 * transport whose call fails keeps its routes from the last run; the run
 * fails only when neither answers.
 */
final class PackageRoutesSyncCommand extends AbstractSyncCommand
{
    /** The transports asked, each on its own. */
    public const array TRANSPORTS = ['plane', 'bus'];

    #[\Override]
    public static function getModes(): array
    {
        return ['package_routes'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync the package routes: destinations, departure cities and dates, for plane and coach (getPackageNVRoutesRequest)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('package_routes', function (): array {
            $api = Container::getApi();
            $routes = Container::packageRoutes();
            $now = date('Y-m-d H:i:s');
            $stats = ['total' => 0, 'synced' => 0, 'failed' => 0];
            $errors = [];
            foreach (self::TRANSPORTS as $transport) {
                try {
                    $answer = $api->getPackageRoutes($transport);
                    $written = $routes->replaceTransport($transport, $answer, $now);
                    $stats['total'] += count($answer);
                    $stats['synced'] += $written;
                    $stats[$transport] = $written;
                    $this->output(sprintf('  %s: %d routes', $transport, $written));
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $errors[] = $transport . ': ' . $e->getMessage();
                    $this->output(sprintf('  %s: FAILED, its routes from the last run are kept: %s', $transport, $e->getMessage()));
                }
            }
            if ($stats['failed'] === count(self::TRANSPORTS)) {
                throw new \RuntimeException(implode('; ', $errors));
            }

            return $errors === [] ? $stats : $stats + ['error' => implode('; ', $errors)];
        });
    }
}
