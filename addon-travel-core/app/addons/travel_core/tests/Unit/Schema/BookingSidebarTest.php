<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shared booking-form sidebar + conditions modal.
 *
 * Both providers' booking pages are now ONE 2-column layout: the summary
 * sidebar (components/booking_sidebar.tpl) on the left, the guest form on the
 * right. Before this, each provider carried its own summary/price/terms
 * markup, so every design change had to be made twice (x2 themes).
 *
 * The pins below are the ones a future edit could break silently: the brief's
 * ordering rules, the CS-Cart thumbnail integration, and — most importantly —
 * the DOM contract the booking JS depends on (see the constraint block).
 */
final class BookingSidebarTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function timelinePartial(): string
    {
        return (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_terms_timeline.tpl',
        );
    }

    private static function tpl(string $theme = 'responsive'): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/design/themes/' . $theme
            . '/templates/addons/travel_core/components/booking_sidebar.tpl',
        );
    }

    private static function css(): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-pages.css',
        );
    }

    public function testBothThemesShipTheComponentByteIdentical(): void
    {
        self::assertSame(self::tpl('responsive'), self::tpl('nova_theme'));
        self::assertNotSame('', self::tpl());
    }

    /**
     * Brief: stars ABOVE the name, availability badge right-aligned on the
     * SAME row as the stars. (hotel_header.tpl puts stars after the name
     * inside the h1, which is why the booking form no longer includes it.)
     */
    public function testStarsSitAboveTheNameWithTheBadgeOnTheirRow(): void
    {
        $tpl = self::tpl();

        $rowPos = strpos($tpl, 'travel-bsidebar-toprow');
        $starsPos = strpos($tpl, 'travel-hotel-stars');
        $badgePos = strpos($tpl, 'id="availability-badge"');
        $namePos = strpos($tpl, 'travel-bsidebar-name');

        self::assertIsInt($rowPos);
        self::assertIsInt($starsPos);
        self::assertIsInt($badgePos);
        self::assertIsInt($namePos);
        self::assertLessThan($starsPos, $rowPos, 'stars render inside the top row');
        self::assertLessThan($badgePos, $starsPos, 'badge follows the stars on that row');
        self::assertLessThan($namePos, $badgePos, 'the whole row precedes the name');

        $css = self::css();
        $start = strpos($css, '.travel-booking-page .travel-bsidebar-toprow {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('justify-content: space-between', $rule);
    }

    /**
     * The hero still honours the store's Settings ▸ Thumbnails, per the brief —
     * but only its WIDTH. The stored thumbnail HEIGHT is what produced the
     * tall, squeezed crop the field reported: CS-Cart generated a portrait
     * thumbnail and `height: auto` let it through untouched. Width from the
     * setting, height derived from the card's landscape ratio, and the CSS
     * container enforces the shape.
     */
    public function testHeroUsesTheStoresThumbnailWidthAtALandscapeRatio(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('{include file="common/image.tpl"', $tpl);
        self::assertStringContainsString('$settings.Thumbnails.product_lists_thumbnail_width', $tpl);
        self::assertStringContainsString('image_height=($tbs_hero_w * 2 / 3)|round', $tpl);
        self::assertStringNotContainsString('product_lists_thumbnail_height', $tpl);

        // The ratio is enforced by the container, and the image is cropped to
        // it rather than squeezed.
        $css = self::css();
        $start = strpos($css, '.travel-booking-page .travel-bsidebar-hero {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('aspect-ratio: 3 / 2', $rule);
        self::assertStringContainsString('overflow: hidden', $rule);

        $imgStart = strpos($css, '.travel-booking-page .travel-bsidebar-hero img {');
        self::assertNotFalse($imgStart);
        $imgRule = substr($css, $imgStart, (int) strpos($css, '}', $imgStart) - $imgStart);
        self::assertStringContainsString('object-fit: cover', $imgRule);
        self::assertStringNotContainsString('height: auto', $imgRule);
    }

    /**
     * The booking page must fill the same theme container as the header,
     * breadcrumbs and footer. A page-level max-width here is what made the
     * hotel card float narrower than the logo with uneven margins.
     */
    public function testTheBookingPageDoesNotCapItsOwnWidth(): void
    {
        $css = self::css();

        $start = strpos($css, '.travel-booking-page.novoton-reservation-form,');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringNotContainsString('max-width', $rule);

        $novoton = (string) file_get_contents(
            self::repoRoot() . '/addon-novoton-holidays/design/themes/responsive/css/addons/novoton_holidays/styles.css',
        );
        $legacy = strpos($novoton, '.novoton-reservation-form {');
        self::assertNotFalse($legacy);
        $legacyRule = substr($novoton, $legacy, (int) strpos($novoton, '}', $legacy) - $legacy);
        self::assertStringNotContainsString('max-width', $legacyRule);
    }

    /**
     * Amenities are chips a guest can skim, not a run-on line of text.
     */
    public function testAmenitiesRenderAsCheckedChips(): void
    {
        $css = self::css();

        $start = strpos($css, '.travel-booking-page .travel-bsidebar-features {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('flex-wrap: wrap', $rule);

        $markStart = strpos($css, '.travel-booking-page .travel-bsidebar-features li::before {');
        self::assertNotFalse($markStart, 'each amenity carries a check mark');
        $markRule = substr($css, $markStart, (int) strpos($css, '}', $markStart) - $markStart);
        self::assertStringContainsString('\2713', $markRule);
        self::assertStringContainsString('--nvt-success-strong', $markRule);
    }

    public function testSummaryCardFollowsTheBriefsOrderAndCopy(): void
    {
        $tpl = self::tpl();

        // Header reuses the existing key rather than inventing a new one.
        self::assertStringContainsString('{__("travel_core.your_booking_details")}', $tpl);

        // Two-column dates, then a rule, then the "you selected" line.
        $datesPos = strpos($tpl, 'travel-bsidebar-dates');
        $rulePos = strpos($tpl, 'travel-bsidebar-rule');
        $selectedPos = strpos($tpl, 'travel_core.you_selected');
        self::assertIsInt($datesPos);
        self::assertIsInt($rulePos);
        self::assertIsInt($selectedPos);
        self::assertLessThan($rulePos, $datesPos);
        self::assertLessThan($selectedPos, $rulePos);

        // Counts use CS-Cart plural forms, joined by a translatable sentence
        // so Romanian keeps its own word order and connector.
        foreach (['n_nights', 'n_rooms', 'n_adults', 'n_children'] as $key) {
            self::assertStringContainsString('travel_core.' . $key, $tpl);
        }
        self::assertStringContainsString('travel_core.selected_line_with_children', $tpl);
        self::assertStringContainsString('travel_core.selected_line', $tpl);

        // Room lines are "Nx <name>"; board and the change link follow.
        self::assertStringContainsString('{$tbs_room.qty}x', $tpl);
        self::assertStringContainsString('travel_core.change_selection', $tpl);
    }

    /**
     * Plural counts must be passed POSITIONALLY.
     *
     * Field report: the summary read "7 noapte|7 nopți, 1 cameră|1 camere
     * pentru 2 adult|2 adulți" — both halves of every plural, verbatim.
     * CS-Cart selects one side of the "|" only when the count arrives as a
     * positional argument, `__("key", [$n])`. An associative
     * `["[n]" => $n]` is a plain string substitution: it fills [n] on BOTH
     * sides and returns the lot. Fourteen other call sites in this repo
     * already used the positional form; these four were the exceptions.
     *
     * Scanned repo-wide, because the same mistake in any provider template
     * would look equally fine in review and equally broken on the page.
     */
    public function testPluralCountsArePassedPositionallyEverywhere(): void
    {
        $offenders = [];

        foreach (self::pluralKeys() as $key) {
            foreach (self::templatesUsing($key) as $file => $line) {
                $offenders[] = $file . ': ' . trim($line);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "these render BOTH plural forms verbatim — pass the count as [\$n], not [\"[n]\" => \$n]:\n"
            . implode("\n", $offenders),
        );
    }

    /**
     * Romanian needs THREE plural forms, not two.
     *
     * CS-Cart selects the form by the Unicode CLDR plural rules for the
     * language, and supports as many variants as the rules define — the
     * documentation shows Russian with three. Romanian also has three:
     *
     *   one   n = 1                        -> "1 noapte"
     *   few   n = 0, or n % 100 in 2..19   -> "2 nopți", "19 nopți"
     *   other everything else              -> "20 DE nopți", "100 DE nopți"
     *
     * With only two forms a 20-night stay reads "20 nopți", which is wrong in
     * Romanian. English keeps its two forms — per the docs the msgid stays in
     * the source language and only the translation lists every variant.
     */
    public function testRomanianPluralsCarryTheThreeCldrForms(): void
    {
        $wrong = [];

        foreach ([
            'addon-travel-core/app/addons/travel_core/lang_keys.php',
            'addon-novoton-holidays/app/addons/novoton_holidays/lang_keys.php',
            'addon-sphinx-holidays/app/addons/sphinx_holidays/lang_keys.php',
        ] as $relative) {
            $path = self::repoRoot() . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            /** @var array<string, array<string, string>> $vars */
            $vars = require $path;
            foreach ($vars as $key => $translations) {
                $en = (string) ($translations['en'] ?? '');
                if (!str_contains($en, '|')) {
                    continue;
                }
                $ro = (string) ($translations['ro'] ?? '');
                if (self::braced($en)) {
                    // DestinationPicker::plurals(): English groups keep two forms;
                    // the group after a number lists Romanian's three, "de" last.
                    preg_match_all('/\\{([^{}]*)\\}/u', $en, $enGroups);
                    preg_match_all('/\\d*\\]?\\s*\\{([^{}]*)\\}/u', $ro, $roGroups);
                    foreach ($enGroups[1] as $group) {
                        if (count(explode('|', $group)) !== 2) {
                            $wrong[] = $key . ': English {…} groups keep two forms — ' . $en;
                        }
                    }
                    foreach ($roGroups[1] as $group) {
                        if (count(explode('|', $group)) !== 3) {
                            $wrong[] = $key . ': Romanian {…} groups need 3 forms — ' . $ro;
                        }
                    }
                    $first = explode('|', $roGroups[1][0] ?? '');
                    if (!str_starts_with($first[2] ?? '', 'de ')) {
                        $wrong[] = $key . ': the third form after the number must read "de …" — ' . $ro;
                    }

                    continue;
                }
                $forms = explode('|', $ro);

                if (count($forms) !== 3) {
                    $wrong[] = $key . ': Romanian has ' . count($forms) . ' form(s), needs 3 — ' . $ro;

                    continue;
                }
                // The "other" form is the one that carries "de".
                if (!str_contains($forms[2], ' de ')) {
                    $wrong[] = $key . ': the third (CLDR "other") form must read "[n] de …" — ' . $forms[2];
                }
                // English stays two forms; only the translation lists all of them.
                if (count(explode('|', $en)) !== 2) {
                    $wrong[] = $key . ': English should keep its two forms — ' . $en;
                }
            }
        }

        self::assertSame([], $wrong, "Romanian plural forms are wrong:\n" . implode("\n", $wrong));
    }

    /**
     * "{a|b}" plurals (DestinationPicker::plurals(), for labels with several
     * counts): their "|" sits only inside braces, and __() never sees a
     * positional count, so the CS-Cart plural rules do not apply to them.
     */
    private static function braced(string $text): bool
    {
        return str_contains($text, '{') && !str_contains((string) preg_replace('/\{[^{}]*\}/u', '', $text), '|');
    }

    /**
     * Every language key whose value carries a "|" is a plural form and must be
     * called positionally.
     *
     * @return list<string>
     */
    private static function pluralKeys(): array
    {
        $keys = [];
        foreach ([
            'addon-travel-core/app/addons/travel_core/lang_keys.php',
            'addon-novoton-holidays/app/addons/novoton_holidays/lang_keys.php',
            'addon-sphinx-holidays/app/addons/sphinx_holidays/lang_keys.php',
        ] as $relative) {
            $path = self::repoRoot() . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            /** @var array<string, array<string, string>> $vars */
            $vars = require $path;
            foreach ($vars as $key => $translations) {
                $en = (string) ($translations['en'] ?? '');
                if (str_contains($en, '|') && !self::braced($en)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Template lines that call $key with an ASSOCIATIVE placeholder map.
     *
     * @return array<string, string> file => offending line
     */
    private static function templatesUsing(string $key): array
    {
        $hits = [];
        $needle = '__("' . $key . '", ["[n]"';

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::repoRoot(), \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'tpl') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            foreach (file($path) ?: [] as $line) {
                if (str_contains($line, $needle)) {
                    $hits[str_replace(self::repoRoot() . '/', '', $path)] = $line;
                }
            }
        }

        return $hits;
    }

    /** "Change your selection" goes back to the PDP carrying the search. */
    public function testChangeSelectionTargetsTheProductPageWithTheSearch(): void
    {
        $factory = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/src/ViewModels/BookingSidebarFactory.php',
        );
        self::assertStringContainsString("'products.view?' . http_build_query(\$query)", $factory);
        // No product => no link, rather than a dead one.
        self::assertStringContainsString('if ($productId <= 0) {', $factory);
    }

    /**
     * The booking JS addresses these nodes directly (booking-form.js writes
     * refreshed prices into .price-total / #novoton-total-price and toggles the
     * price-state nodes). Losing them silently breaks re-pricing.
     */
    public function testPriceBoxKeepsTheDomContractTheBookingJsDependsOn(): void
    {
        $tpl = self::tpl();

        foreach ([
            'booking-price-box',
            'price-total',
            'id="novoton-total-price"',
            'id="price-error-message"',
            'id="price-unverified-badge"',
            'id="refresh-price-link"',
            'id="availability-badge"',
        ] as $hook) {
            self::assertStringContainsString($hook, $tpl, "JS hook {$hook} must survive the redesign");
        }
    }

    public function testCancellationCardIsHiddenUntilItHasSomethingToSay(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('id="travel-cancel-card"', $tpl);
        // Novoton swaps the server-rendered partial into this body after its
        // price re-check, so the id is a JS contract.
        self::assertStringContainsString('id="travel-cancel-body"', $tpl);
        self::assertStringContainsString('travel_core.cancel_payment_title', $tpl);
        self::assertStringContainsString(
            '{include file="addons/travel_core/components/booking_terms_timeline.tpl" tt=$tbs tt_show_link=true tt_ids=true}',
            $tpl,
        );
        // Empty policy => hidden card. Timeline steps, prose lines, a
        // free-until date or payment terms are each reason enough to show it.
        self::assertStringContainsString(
            '{if !$tbs.cancel_steps && !$tbs.cancel_lines && !$tbs.cancel_full_amount && !$tbs.cancel_free_until && !$tbs.payment_steps && !$tbs.payment_lines} travel-is-hidden{/if}',
            $tpl,
        );

        $partial = self::timelinePartial();
        self::assertStringContainsString('{foreach from=$tt.cancel_steps item="tt_step"}', $partial);
        // Prose terms keep the old lines + full-charge headline.
        self::assertStringContainsString('travel_core.cancel_you_will_pay', $partial);
        self::assertStringContainsString('{if $_tt_ids} id="travel-cancel-lines"{/if}', $partial);
    }

    /**
     * The step in force today is policy, not an error: amber with a neutral
     * "Today" tag, never the danger red. A free step is green, the same
     * promise the search-results card makes.
     */
    public function testTimelineColoursCurrentStepAmberAndFreeGreen(): void
    {
        $css = self::css();
        $start = strpos($css, '.travel-booking-page .travel-timeline__step--current::before {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('#f59e0b', $rule);
        self::assertStringNotContainsString('danger', $rule);
        self::assertStringContainsString('.travel-booking-page .travel-timeline__step--free .travel-timeline__what { color: #15803d;', $css);

        // Legacy prose fallback keeps its green free-until line.
        $start = strpos($css, '.travel-booking-page .travel-bsidebar-freecancel {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('--nvt-success-strong', $rule);

        $partial = self::timelinePartial();
        self::assertStringContainsString('travel_core.today', $partial);
        self::assertStringContainsString('travel_core.timeline_free', $partial);

        // Novoton only learns its terms from the price re-check: the endpoint
        // builds the same timeline and returns this partial rendered.
        $recalc = (string) file_get_contents(
            self::repoRoot()
            . '/addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/ajax_recalculate_price.php',
        );
        self::assertStringContainsString('TermsTimelineFactory', $recalc);
        self::assertStringContainsString("'terms_html'", $recalc);
        $js = (string) file_get_contents(
            self::repoRoot() . '/addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js',
        );
        self::assertStringContainsString("getElementById('travel-cancel-body')", $js);
        self::assertStringContainsString('data.terms_html', $js);
    }

    /**
     * Every customer-facing date follows Settings ▸ Appearance ▸ date format.
     * Field report: "Check-in 07.09.2026" beside "Anulare gratuită până la
     * 08/28/2026" — one card, two formats, because the sidebar hardcoded
     * dd.mm.yyyy while the terms line followed the store setting.
     */
    public function testDatesFollowTheStoreFormatEverywhere(): void
    {
        // The sidebar builders (which both create and edit modes call).
        foreach ([
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/ViewModels/NovotonBookingSidebarBuilder.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/ViewModels/SphinxBookingSidebarBuilder.php',
        ] as $builder) {
            $src = (string) file_get_contents(self::repoRoot() . $builder);
            self::assertStringContainsString('DateHelper::formatStoreDate(', $src, $builder);
            self::assertStringNotContainsString("'%d.%m.%Y'", $src, $builder . ': hardcoded date format');
        }

        // Both providers' terms formatters share the same one formatter.
        foreach ([
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/Services/TermsFormatter.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/TermsFormatter.php',
        ] as $formatter) {
            $src = (string) file_get_contents(self::repoRoot() . $formatter);
            self::assertStringContainsString('DateHelper::formatStoreDate(', $src, $formatter);
        }

        // The cart/checkout card too — it used to print "%a %d %b %Y".
        foreach (['responsive', 'nova_theme'] as $theme) {
            $card = (string) file_get_contents(
                self::repoRoot() . '/addon-travel-core/design/themes/' . $theme
                . '/templates/addons/travel_core/components/cart_booking_details.tpl',
            );
            self::assertStringContainsString('date_format:$settings.Appearance.date_format', $card, $theme);
            self::assertStringNotContainsString('date_format:"%a %d %b %Y"', $card, $theme);
        }
    }

    /**
     * Romanian amenity names: sphinx already resolved them through the shared
     * feature map; novoton read its own facility_name_ro column, which its
     * sync fills with a verbatim copy of the ENGLISH name — so a Romanian
     * storefront listed "Air conditioning/Heating". Both go through the shared
     * resolver now.
     */
    public function testAmenityNamesComeFromTheSharedFeatureMap(): void
    {
        $repo = (string) file_get_contents(
            self::repoRoot()
            . '/addon-novoton-holidays/app/addons/novoton_holidays/src/Repository/FacilityRepository.php',
        );
        self::assertStringContainsString('FacilityLabelResolver::labels(', $repo);

        $resolver = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/src/Services/FacilityLabelResolver.php',
        );
        self::assertStringContainsString('FeatureMapper::resolveFacility(', $resolver);
        // Unmapped facilities keep the provider's own label rather than being
        // silently dropped, which is what an empty RO column used to cause.
        self::assertStringContainsString("\$facility['name'] ?? ''", $resolver);

        $sphinx = (string) file_get_contents(
            self::repoRoot()
            . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/SphinxFeatureAssigner.php',
        );
        self::assertStringContainsString('FacilityLabelResolver::column($lang)', $sphinx);
    }

    /** Only a 100% penalty gets the headline row. */
    public function testFullChargeHeadlineOnlyForATotalLoss(): void
    {
        $factory = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/src/ViewModels/BookingSidebarFactory.php',
        );
        self::assertStringContainsString("str_contains(\$line, '100%')", $factory);
    }

    /**
     * "What are my booking conditions?" — the link under Add-to-Cart and its
     * modal, accumulating ONE SECTION PER ROOM for multi-room bookings.
     */
    public function testBookingConditionsModalIsSharedAndPerRoom(): void
    {
        $modal = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_conditions_modal.tpl',
        );
        $novaModal = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/themes/nova_theme/templates/addons/travel_core/components/booking_conditions_modal.tpl',
        );
        self::assertSame($modal, $novaModal);

        self::assertStringContainsString('travel_core.booking_conditions_link', $modal);
        self::assertStringContainsString('id="travel-conditions-rooms"', $modal);
        self::assertStringContainsString('travel_core.cancellation_policy', $modal);
        // Each room section shows the same timeline as the sidebar card.
        self::assertStringContainsString('components/booking_terms_timeline.tpl" tt=$tbc}', $modal);
        // The link moved into the cancellation & payment card.
        $partial = self::timelinePartial();
        self::assertStringContainsString('data-travel-conditions-open', $partial);
        self::assertStringContainsString('travel_core.payment_terms', $partial);

        // Behaviour lives in a real JS file (no inline script), and keys each
        // section by room so a multi-room booking accumulates rather than
        // overwrites.
        $js = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/js/addons/travel_core/booking-conditions.js',
        );
        self::assertStringContainsString('function setRoomSection(roomNum, data)', $js);
        self::assertStringContainsString("host.querySelector('[data-room=\"' + key + '\"]')", $js);
        self::assertStringContainsString('window.TravelConditions', $js);
        self::assertStringContainsString('function setRoomHtml(roomNum, title, html)', $js);

        // Loaded site-wide with the other travel_core booking scripts.
        $scripts = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/hooks/index/scripts.post.tpl',
        );
        self::assertStringContainsString('js/addons/travel_core/booking-conditions.js', $scripts);

        // Both providers render the link+modal.
        foreach ([
            '/addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
            '/addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl',
        ] as $path) {
            $form = (string) file_get_contents(self::repoRoot() . $path);
            self::assertStringContainsString(
                '{include file="addons/travel_core/components/booking_conditions_modal.tpl"}',
                $form,
                "missing conditions modal in {$path}",
            );
        }
    }

    /**
     * User ruling: "we get rid of left bordered/highlighted line on the form".
     * The child card keeps only its warm background.
     */
    public function testGuestCardsHaveNoLeftAccentBar(): void
    {
        $css = self::css();

        $start = strpos($css, '.travel-booking-page .guest-entry {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringNotContainsString('border-left', $rule);

        $childStart = strpos($css, '.travel-booking-page .guest-entry-child {');
        self::assertNotFalse($childStart);
        $childRule = substr($css, $childStart, (int) strpos($css, '}', $childStart) - $childStart);
        self::assertStringNotContainsString('border-left', $childRule);
    }

    /** Novoton no longer prints a bed line ("Regular Bed") on adult cards. */
    public function testNovotonDropsTheBedSublabel(): void
    {
        $form = (string) file_get_contents(
            self::repoRoot()
            . '/addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
        );
        self::assertStringNotContainsString('gb_adult_sublabel', $form);
        self::assertStringNotContainsString('novoton_holidays.regular_bed', $form);
    }

    /** Every new frontend key must reach installed stores AND fresh installs. */
    public function testNewSidebarKeysAreSeededInAllThreePlaces(): void
    {
        $langKeys = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/lang_keys.php',
        );
        $keys = [
            'travel_core.available',
            'travel_core.package',
            'travel_core.you_selected',
            'travel_core.n_nights',
            'travel_core.n_rooms',
            'travel_core.n_adults',
            'travel_core.n_children',
            'travel_core.selected_line',
            'travel_core.selected_line_with_children',
            'travel_core.change_selection',
            'travel_core.cancel_cost_title',
            'travel_core.cancel_you_will_pay',
            'travel_core.booking_conditions_link',
        ];
        foreach ($keys as $key) {
            self::assertStringContainsString("'{$key}'", $langKeys, "{$key} missing from lang_keys.php");
            foreach (['en', 'ro'] as $lang) {
                $po = (string) file_get_contents(
                    self::repoRoot() . '/addon-travel-core/var/langs/' . $lang . '/addons/travel_core.po',
                );
                self::assertStringContainsString(
                    'msgctxt "Languages::' . $key . '"',
                    $po,
                    "{$key} missing from {$lang}.po (fresh installs never get it)",
                );
            }
        }
    }
}
