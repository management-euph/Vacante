<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Support;

/**
 * Page rows shaped as fn_get_page_data() returns them (pages.* and
 * page_descriptions.*; a form gets "form" from the Form builder add-on).
 */
final class Pages
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function page(int $id, string $name, string $description = '', array $overrides = []): array
    {
        return array_merge([
            'page_id' => (string) $id,
            'company_id' => '1',
            'parent_id' => '0',
            'id_path' => (string) $id,
            'status' => 'A',
            'page_type' => 'T',
            'position' => '0',
            'timestamp' => '1760000000',
            'usergroup_ids' => '0',
            'localization' => '',
            'new_window' => '0',
            'use_avail_period' => 'N',
            'avail_from_timestamp' => '0',
            'avail_till_timestamp' => '0',
            'lang_code' => 'en',
            'page' => $name,
            'description' => $description,
            'meta_keywords' => '',
            'meta_description' => '',
            'page_title' => '',
            'link' => '',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(int $id, string $name, string $link, bool $newWindow = false): array
    {
        return self::page($id, $name, '', ['page_type' => 'L', 'link' => $link, 'new_window' => $newWindow ? '1' : '0']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function form(int $id, string $name, string $description = ''): array
    {
        return self::page($id, $name, $description, [
            'page_type' => 'F',
            'form' => [
                'general' => ['L' => 'Thank you, we will get back to you.'],
                'elements' => [
                    '7' => ['element_id' => '7', 'element_type' => 'I', 'description' => 'Your e-mail', 'required' => 'Y'],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function popup(int $id, string $name, string $description = ''): array
    {
        return self::page($id, $name, $description, ['show_in_popup' => 'Y']);
    }

    /**
     * A Page content block as the block manager draws it.
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    public static function block(array $properties = [], int $snappingId = 12, int $blockId = 40): array
    {
        return [
            'block_id' => (string) $blockId,
            'snapping_id' => (string) $snappingId,
            'type' => 'euph_page_content',
            'name' => 'Useful information',
            'properties' => $properties,
        ];
    }
}
