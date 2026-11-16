<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;

/**
 * A circuit page's structured data for search engines
 * (docs/circuit-page-plan.md step 6): a schema.org TouristTrip with the
 * places on its route as its itinerary, its days, and an offer "from" the
 * lowest saved departure, a person's share.
 *
 * Only what the supplier says is stated: a fact it does not give is left
 * out, not guessed.
 */
final class CircuitSchema
{
    /** The days listed (a long circuit's program stays on the page). */
    private const int MAX_DAYS = 31;

    /**
     * @param string $description the product's description, plain text ('' for none)
     * @param string $image the product's main image, a web link ('' for none)
     * @param string $url the page's address ('' to leave it out)
     * @param float $fromPerPerson the lowest price a person, in $currency (0 when none is saved)
     * @param string $currency ISO code of $fromPerPerson
     *
     * @return array<string, mixed>
     */
    public static function build(
        CircuitPage $page,
        ?CircuitDetails $details,
        string $description,
        string $image,
        string $url,
        float $fromPerPerson,
        string $currency,
    ): array {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'TouristTrip',
            'name' => $page->name,
        ];
        if ($description !== '') {
            $schema['description'] = $description;
        }
        if ($image !== '') {
            $schema['image'] = $image;
        }
        if ($url !== '') {
            $schema['url'] = $url;
        }

        $places = array_values(array_filter(array_map('trim', $page->route), static fn (string $name): bool => $name !== ''));
        if ($places !== []) {
            $schema['itinerary'] = [
                '@type' => 'ItemList',
                'numberOfItems' => count($places),
                'itemListElement' => array_map(
                    static fn (string $name, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => ['@type' => 'Place', 'name' => $name]],
                    $places,
                    array_keys($places),
                ),
            ];
        }

        // Each day of the program, in order, as a part of the trip.
        $days = [];
        foreach (array_slice($details?->days ?? [], 0, self::MAX_DAYS) as $day) {
            $title = trim($day->title);
            if ($title !== '') {
                $days[] = ['@type' => 'TouristTrip', 'name' => $title];
            }
        }
        if ($days !== []) {
            $schema['subTrip'] = $days;
        }

        if ($fromPerPerson > 0 && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $offer = [
                '@type' => 'Offer',
                'price' => number_format($fromPerPerson, 2, '.', ''),
                'priceCurrency' => $currency,
                'availability' => 'https://schema.org/InStock',
            ];
            if ($url !== '') {
                $offer['url'] = $url;
            }
            $schema['offers'] = $offer;
        }

        return $schema;
    }

    /**
     * The schema as the page prints it, raw inside
     * <script type="application/ld+json">: JSON_HEX_TAG keeps a supplier's
     * "</script>" from closing the element (JsonLdEscapingTest).
     *
     * @param array<string, mixed> $schema
     */
    public static function json(array $schema): string
    {
        return (string) json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}
