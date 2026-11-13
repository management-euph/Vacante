<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\ViewModels;

use Tygh\Addons\NovotonHolidays\Repository\HotelListingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Novoton → Hotels: the filters of the page in the shape CS-Cart's own
 * filter bar reads (components/search_filters/search_filters.tpl, passed to
 * common/mainbox.tpl as search_filters) — the bar of the Products page: it
 * applies a filter as it changes, a checkbox filter takes several values,
 * and the "secondary" ones wait in its "Add filter" list.
 *
 * Types: input (the widest one, "q", becomes the search field above the
 * bar), radio (one value; the first item is "all"), checkbox (several; each
 * item is its own request parameter).
 */
final class HotelSearchFilters
{
    public const string FORM_ID = 'novoton_hotels_search_filters_form';

    /**
     * @param array<string, mixed> $search HotelListingRepository::normalize()
     * @param list<string> $countries the countries that have hotels
     * @param callable(string, array<string, string|int>=): string $t translates a language key
     *
     * @return array<string, mixed>
     */
    public static function build(array $search, array $countries, callable $t): array
    {
        $stars = TypeCoerce::toIntList($search['stars'] ?? []);
        $types = TypeCoerce::toStringList($search['property_types'] ?? []);
        $country = TypeCoerce::toString($search['country'] ?? '');

        $starItems = [];
        for ($n = 5; $n >= 1; $n--) {
            $starItems['stars_' . $n] = [
                'key' => 'stars_' . $n,
                'label' => $t('novoton_holidays.hotels_n_stars', ['[n]' => $n]),
                'value' => 'Y',
                'is_checked' => in_array($n, $stars, true),
            ];
        }
        $starItems['stars_none'] = [
            'key' => 'stars_none',
            'label' => $t('novoton_holidays.hotels_no_stars'),
            'value' => 'Y',
            'is_checked' => ($search['stars_none'] ?? false) === true,
        ];

        $countryItems = ['country_all' => ['key' => 'country_all', 'label' => $t('all'), 'value' => false, 'is_checked' => $country === '']];
        foreach ($countries as $i => $name) {
            $countryItems['country_' . $i] = ['key' => 'country_' . $i, 'label' => $name, 'value' => $name, 'is_checked' => $country === $name];
        }

        $typeItems = [];
        foreach (HotelListingRepository::PROPERTY_TYPES as $type) {
            $typeItems['property_type_' . $type] = [
                'key' => 'property_type_' . $type,
                'label' => $t('novoton_holidays.hotels_type_' . str_replace('-', '_', $type)),
                'value' => 'Y',
                'is_checked' => in_array($type, $types, true),
            ];
        }

        return [
            'form_id' => self::FORM_ID,
            'dispatch' => 'novoton_hotels.manage',
            'page_part' => '',
            'search_form_prefix' => 'novoton_hotels_',
            'search_type' => 'simple',
            'selected_section' => '',
            'advanced_search' => '',
            'data' => [
                'q' => [
                    'id' => 'q',
                    'type' => 'input',
                    'category' => 'primary',
                    'priority' => 1000,
                    'label' => $t('novoton_holidays.hotels_search_placeholder'),
                    'value' => TypeCoerce::toString($search['q'] ?? ''),
                ],
                'country' => [
                    'id' => 'country',
                    'type' => 'radio',
                    'category' => 'primary',
                    'label' => $t('country'),
                    'nested_data' => $countryItems,
                ],
                'stars' => [
                    'id' => 'stars',
                    'type' => 'checkbox',
                    'category' => 'primary',
                    'label' => $t('novoton_holidays.hotels_col_stars'),
                    'nested_data' => $starItems,
                ],
                // Type-to-search: the template puts CS-Cart's object picker in
                // 'content' (it needs Smarty); the value is the city or region.
                'city' => [
                    'id' => 'city',
                    'type' => 'dropdown',
                    'category' => 'primary',
                    'size' => 'large',
                    'label' => $t('novoton_holidays.hotels_city_or_region'),
                    'value' => TypeCoerce::toString($search['city'] ?? ''),
                    'content' => '',
                ],
                'not_synced_days' => self::radio('not_synced_days', 'primary', $t('novoton_holidays.hotels_not_synced_in'), array_merge(
                    [['value' => false, 'label' => $t('novoton_holidays.hotels_any_time')]],
                    array_map(static fn (int $days): array => ['value' => (string) $days, 'label' => $t('novoton_holidays.hotels_n_days', ['[n]' => $days])], HotelListingRepository::SYNC_AGES),
                ), (string) TypeCoerce::toInt($search['not_synced_days'] ?? 0)),
                'product' => self::radio('product', 'secondary', $t('novoton_holidays.hotels_col_product'), [
                    ['value' => false, 'label' => $t('all')],
                    ['value' => 'with', 'label' => $t('novoton_holidays.hotels_product_with')],
                    ['value' => 'without', 'label' => $t('novoton_holidays.hotels_product_without')],
                    ['value' => 'deleted', 'label' => $t('novoton_holidays.hotels_product_deleted')],
                ], TypeCoerce::toString($search['product'] ?? '')),
                'sync_status' => self::radio('sync_status', 'secondary', $t('novoton_holidays.hotels_col_status'), array_merge(
                    [['value' => false, 'label' => $t('all')]],
                    array_map(static fn (string $st): array => ['value' => $st, 'label' => $t('novoton_holidays.hotels_status_' . $st)], HotelListingRepository::STATUSES),
                ), TypeCoerce::toString($search['sync_status'] ?? '')),
                'property_type' => [
                    'id' => 'property_type',
                    'type' => 'checkbox',
                    'category' => 'secondary',
                    'label' => $t('novoton_holidays.hotels_property_type'),
                    'nested_data' => $typeItems,
                ],
            ],
        ];
    }

    /**
     * A one-value filter; the first choice (value false) is "all".
     *
     * @param list<array{value: string|false, label: string}> $choices
     * @param string $current the value in force ('' or '0': none)
     *
     * @return array<string, mixed>
     */
    private static function radio(string $id, string $category, string $label, array $choices, string $current): array
    {
        $items = [];
        foreach ($choices as $i => $choice) {
            $value = $choice['value'];
            $items[$id . '_' . $i] = [
                'key' => $id . '_' . $i,
                'label' => $choice['label'],
                'value' => $value,
                'is_checked' => $value === false ? ($current === '' || $current === '0') : $current === $value,
            ];
        }

        return ['id' => $id, 'type' => 'radio', 'category' => $category, 'label' => $label, 'nested_data' => $items];
    }
}
