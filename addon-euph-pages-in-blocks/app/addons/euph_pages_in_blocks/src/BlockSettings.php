<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks;

use Tygh\Addons\EuphPagesInBlocks\Enum\ContentMode;

/**
 * The template settings of a Page content block, typed. CS-Cart keeps them
 * in the block's properties; a setting the block has no value for (it was
 * saved with another template) takes its default, and a value out of range
 * falls back to the default or to the nearest limit.
 */
final readonly class BlockSettings
{
    public const DEFAULT_EXCERPT_LENGTH = 200;
    public const MIN_EXCERPT_LENGTH = 40;
    public const MAX_EXCERPT_LENGTH = 2000;

    /** Cards per row on wide screens. */
    public const COLUMNS = [2, 3, 4];
    public const DEFAULT_COLUMNS = 3;

    public function __construct(
        public bool $showTitles = true,
        public bool $separators = true,
        public ContentMode $content = ContentMode::Full,
        public int $excerptLength = self::DEFAULT_EXCERPT_LENGTH,
        public bool $openFirst = false,
        public bool $oneOpen = true,
        public int $columns = self::DEFAULT_COLUMNS,
    ) {
    }

    /**
     * @param array<string, mixed> $properties the block's properties
     */
    public static function fromProperties(array $properties): self
    {
        $length = Cast::int($properties[BlockSchema::EXCERPT_LENGTH] ?? null);
        $columns = Cast::int($properties[BlockSchema::COLUMNS] ?? null);

        return new self(
            showTitles: self::flag($properties, BlockSchema::SHOW_TITLES, true),
            separators: self::flag($properties, BlockSchema::SEPARATORS, true),
            content: ContentMode::tryFrom(Cast::string($properties[BlockSchema::CONTENT] ?? '')) ?? ContentMode::Full,
            excerptLength: $length <= 0 ? self::DEFAULT_EXCERPT_LENGTH : max(self::MIN_EXCERPT_LENGTH, min(self::MAX_EXCERPT_LENGTH, $length)),
            openFirst: self::flag($properties, BlockSchema::OPEN_FIRST, false),
            oneOpen: self::flag($properties, BlockSchema::ONE_OPEN, true),
            columns: in_array($columns, self::COLUMNS, true) ? $columns : self::DEFAULT_COLUMNS,
        );
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function flag(array $properties, string $key, bool $default): bool
    {
        return array_key_exists($key, $properties) ? Cast::flag($properties[$key]) : $default;
    }
}
