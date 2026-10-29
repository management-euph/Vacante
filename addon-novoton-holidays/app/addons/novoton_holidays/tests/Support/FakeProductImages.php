<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Support;

use Tygh\Addons\TravelCore\Contracts\ProductImageStoreInterface;

/** A product's images in memory, in their order. */
final class FakeProductImages implements ProductImageStoreInterface
{
    /** @var array<int, array{bytes: string, main: bool, position: int, readable: bool}> */
    public array $pairs = [];

    private int $next = 100;

    public function has(string $bytes, bool $main = false, bool $readable = true, ?int $position = null): int
    {
        $id = ++$this->next;
        $this->pairs[$id] = ['bytes' => $bytes, 'main' => $main, 'position' => $position ?? count($this->pairs), 'readable' => $readable];

        return $id;
    }

    public function pairOf(string $bytes): int
    {
        foreach ($this->pairs as $id => $pair) {
            if ($pair['bytes'] === $bytes) {
                return $id;
            }
        }

        return 0;
    }

    public function main(): int
    {
        foreach ($this->pairs as $id => $pair) {
            if ($pair['main']) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * The bytes in gallery order: the main image, then by position and pair id.
     *
     * @return list<string>
     */
    public function gallery(): array
    {
        return array_map(fn (array $image): string => $this->pairs[$image['pair_id']]['bytes'], $this->images(0));
    }

    public function adminRemoves(int $pairId): void
    {
        unset($this->pairs[$pairId]);
    }

    public function images(int $productId): array
    {
        $out = [];
        foreach ($this->pairs as $id => $pair) {
            $out[] = ['pair_id' => $id, 'main' => $pair['main'], 'position' => $pair['position'], 'file' => $pair['readable'] ? 'pair-' . $id : ''];
        }
        usort($out, static fn (array $a, array $b): int => [$b['main'], $a['position'], $a['pair_id']] <=> [$a['main'], $b['position'], $b['pair_id']]);

        return $out;
    }

    public function read(string $file): ?string
    {
        $id = (int) substr($file, 5);

        return str_starts_with($file, 'pair-') && isset($this->pairs[$id]) ? $this->pairs[$id]['bytes'] : null;
    }

    public function attach(int $productId, string $bytes, bool $main): int
    {
        return $this->has($bytes, $main);
    }

    public function remove(int $productId, int $pairId): bool
    {
        if (!isset($this->pairs[$pairId])) {
            return false;
        }
        unset($this->pairs[$pairId]);

        return true;
    }

    public function makeMain(int $productId, int $pairId): void
    {
        foreach (array_keys($this->pairs) as $id) {
            $this->pairs[$id]['main'] = $id === $pairId;
        }
    }

    public function setPosition(int $productId, int $pairId, int $position): void
    {
        $this->pairs[$pairId]['position'] = $position;
    }
}
