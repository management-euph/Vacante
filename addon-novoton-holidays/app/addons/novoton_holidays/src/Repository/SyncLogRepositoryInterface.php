<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface SyncLogRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $log_id): ?array;
    /**
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $limit = 20, string $type = ''): array;
    /**
     * @return array<string, mixed>|null
     */
    public function getLastSync(string $type): ?array;
    public function getLastSyncDate(string $type): ?string;
    /**
     * @param array<string, mixed> $data
     */
    public function create(string $type, array $data): int;
    /**
     * @param array<string, mixed> $details
     */
    public function logSync(string $type, int $total, int $updated, int $failed = 0, int $duration = 0, string $status = 'completed', array $details = []): int;
    public function deleteOld(int $days = 30): int;
    public function count(string $type = '', string $status = ''): int;
    /**
     * @return array<string, array<string, mixed>>
     */
    public function getStats(int $days = 7): array;
    /**
     * @return list<array<string, mixed>>
     */
    public function findByType(string $type, int $limit = 0): array;
    /**
     * @return array<string, mixed>
     */
    public function findPaginated(int $page = 1, int $per_page = 10, string $type = '', string $status = ''): array;
    public function trimToLatest(int $keep = 100): int;
}
