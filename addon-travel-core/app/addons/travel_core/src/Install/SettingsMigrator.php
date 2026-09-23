<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Install;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Enum\SettingTypes;
use Tygh\Settings;

/**
 * Creates settings that addon.xml declares but the database never got.
 *
 * CS-Cart imports an addon's <settings> only when the addon is INSTALLED or
 * UPGRADED. It does not re-read addon.xml on a code deploy or a cache clear,
 * and this project deploys by pulling code. So every setting added after a
 * store's install is invisible there: the field is absent from the addon's
 * settings page and there is no way to set it. That is what left the
 * reverse-geocoding switch unreachable in the field.
 *
 * Language keys and the schema each already self-heal for exactly this
 * reason; settings were the one delivery path with no repair. This closes it.
 *
 * Written against the real CS-Cart API (Tygh\Settings):
 *   - getSectionByName($addon, ADDON_SECTION)  find the addon's section
 *   - isExists($name, $addon)                  idempotency — only fill gaps
 *   - update($data, $variants, $descriptions)  CREATES when object_id is
 *                                              omitted, returns the new id
 *   - updateValue($name, $value, $addon)       REQUIRED as a second call:
 *                                              update() deliberately unsets
 *                                              'value', so a setting created
 *                                              in one call would read empty
 *
 * Two deliberate robustness choices:
 *
 *  1. edition_type / section_tab_id / is_global are COPIED from a setting
 *     that already exists in the same section instead of being guessed.
 *     update() runs checkEdition() and silently returns false when that
 *     fails; reusing whatever CS-Cart already accepted for this addon cannot
 *     fail that check, and keeps new fields on the same tab as their
 *     neighbours.
 *  2. New settings are positioned after the last existing one, so healed
 *     fields appear at the end of the section in addon.xml order rather than
 *     interleaving unpredictably.
 *
 * Labels come from the addon's .po files, where CS-Cart's own importer would
 * have read them (msgctxt "SettingsOptions::<addon>::<name>" and
 * "SettingsTooltips::<addon>::<name>"), and are written through
 * updateDescription() — the same table that importer targets.
 */
final class SettingsMigrator
{
    /** addon.xml <type> → ?:settings_objects.type (Tygh\Enum\SettingTypes). */
    private const array TYPE_MAP = [
        'checkbox' => SettingTypes::CHECKBOX,
        'input' => SettingTypes::INPUT,
        'password' => SettingTypes::PASSWORD,
        'textarea' => SettingTypes::TEXTAREA,
        'selectbox' => SettingTypes::SELECTBOX,
        'multiple checkboxes' => SettingTypes::MULTIPLE_CHECKBOXES,
        'multiple select' => SettingTypes::MULTIPLE_SELECT,
        'header' => SettingTypes::HEADER,
        'info' => SettingTypes::INFO,
        'number' => SettingTypes::NUMBER,
        'template' => SettingTypes::TEMPLATE,
        'hidden' => SettingTypes::HIDDEN,
    ];

    /**
     * Settings deleted from an addon.xml that must also LEAVE the database.
     *
     * Dropping an <item> only stops FUTURE installs from getting it. CS-Cart
     * renders the settings page from ?:settings_objects, so on a store that
     * was installed while the item existed the field keeps rendering forever —
     * which is how geocoding ended up configurable in two places at once, the
     * novoton copy able to contradict the shared Travel Core one.
     *
     * The list is EXPLICIT on purpose. "Delete everything absent from
     * addon.xml" would be catastrophic: settings created by other means, or by
     * a newer addon version than the deployed code, would silently vanish
     * along with their values.
     *
     * @var array<string, list<string>> addon => setting names to delete
     */
    private const array RETIRED = [
        // Geocoding lives once, in Travel Core: the Nominatim usage policy
        // caps requests per APPLICATION and both provider addons share
        // GeocodeBacklogRunner.
        'novoton_holidays' => [
            'geocoding_header',
            'geocoding_enabled',
            'geocoding_contact_email',
            'geocoding_endpoint',
        ],
    ];

    /**
     * Shipped secrets that must be replaced with a real one, per addon.
     *
     * Dropping `<default_value>1234</default_value>` protects future installs.
     * It does nothing for a store that already has the row — the value sits
     * there, and `1234` is not a secret. This rewrites it once, in place.
     *
     * EXPLICIT on purpose, exactly like RETIRED. A rule like "randomise every
     * password-typed setting holding a default" would reach `api_password`
     * and silently destroy the provider credentials the store runs on.
     *
     * Self-limiting: the match is on the weak value itself, so once rewritten
     * the row never matches again. An operator who deliberately sets `1234`
     * gets it replaced — which is the intent.
     *
     * @var array<string, list<string>> addon => setting names
     */
    private const array WEAK_SECRETS = [
        'travel_core' => ['cron_access_key'],
        'novoton_holidays' => ['cron_access_key'],
        'sphinx_holidays' => ['cron_access_key'],
        'eurosite' => ['cron_access_key'],
    ];

    /** Values that are not secrets, whatever the setting claims. */
    private const array WEAK_VALUES = ['1234'];

    /** @var array<string, true> one attempt per addon per request */
    private static array $done = [];

    /**
     * Create every setting addon.xml declares and the database lacks.
     *
     * @param string $addon Addon name, e.g. 'travel_core'
     * @param string $addonDir Directory holding addon.xml
     * @param string $langsDir var/langs root that holds <lc>/addons/<addon>.po
     * @return list<string> names of the settings created or removed (empty
     *                      when in sync)
     */
    public static function ensure(string $addon, string $addonDir, string $langsDir): array
    {
        if (isset(self::$done[$addon])) {
            return [];
        }
        self::$done[$addon] = true;

        $declared = self::parseAddonXml($addonDir . '/addon.xml');
        if ($declared === []) {
            return [];
        }

        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return [];
        }

        $retired = self::retire($addon);

        $section = $settings->getSectionByName($addon, Settings::ADDON_SECTION);
        $sectionId = is_array($section) ? TypeCoerce::toInt($section['section_id'] ?? 0) : 0;
        if ($sectionId <= 0) {
            return $retired; // addon not installed — nothing to heal into
        }

        $template = self::siblingTemplate($sectionId);
        $position = TypeCoerce::toInt($template['position'] ?? 0);
        $labels = self::parsePoLabels($langsDir, $addon);

        $created = $retired;
        foreach ($declared as $item) {
            $name = $item['name'];
            if ($name === '') {
                continue;
            }

            if ($settings->isExists($name, $addon)) {
                // Present already — but possibly LABEL-LESS or VALUE-LESS. The
                // first heal on a Windows checkout parsed no labels (CRLF, see
                // below) and created these fields blank; isExists() alone would
                // then skip them forever. Repair both, never overwriting
                // anything an admin has actually set.
                $repaired = self::repairDescriptions($addon, $name, $labels);
                $repaired = self::repairValue($addon, $name, $item['default']) || $repaired;
                if ($repaired) {
                    $created[] = $name;
                }

                continue;
            }

            $type = self::TYPE_MAP[strtolower($item['type'])] ?? SettingTypes::INPUT;
            $position += 10;

            $objectId = $settings->update(
                [
                    'name' => $name,
                    'section_id' => $sectionId,
                    'section_tab_id' => TypeCoerce::toInt($template['section_tab_id'] ?? 0),
                    'type' => $type,
                    'position' => $position,
                    'is_global' => TypeCoerce::toString($template['is_global'] ?? 'N'),
                    'edition_type' => TypeCoerce::toString($template['edition_type'] ?? 'ROOT'),
                    // Both NOT NULL with no usable default: update() writes via
                    // REPLACE INTO, which under strict SQL mode fails on an
                    // omitted NOT NULL column. Neither applies to a plain
                    // addon setting, so they are set to their empty forms.
                    'handler' => '',
                    'parent_id' => 0,
                ],
                self::variantRows($item['variants']),
                self::descriptionRows($labels, $name),
            );

            if (!is_int($objectId) || $objectId <= 0) {
                continue;
            }

            // update() strips 'value' on purpose — seed the default separately
            // or the setting exists but reads empty (worse than missing).
            if ($item['default'] !== '') {
                $settings->updateValue($name, $item['default'], $addon);
            }

            $created[] = $name;
        }

        foreach (self::rotateWeakSecrets($addon) as $rotated) {
            $created[] = $rotated;
        }

        return $created;
    }

    /**
     * Replace a shipped secret that is still in place with a real one.
     *
     * Verified through Settings::getValue rather than trusting updateValue:
     * a silent no-op here would leave `1234` live while reporting a rotation,
     * which is worse than not trying.
     *
     * @return list<string> names actually rotated
     */
    private static function rotateWeakSecrets(string $addon): array
    {
        $names = self::WEAK_SECRETS[$addon] ?? [];
        if ($names === []) {
            return [];
        }

        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return [];
        }

        $rotated = [];
        foreach ($names as $name) {
            // EVERY kit call is inside the try, the read included: CS-Cart is
            // licensed code that is not in this repository, so its signatures
            // cannot be pinned by a test. This runs on every admin page load,
            // where the failure mode must be "not rotated", never "no store".
            try {
                $current = $settings->getValue($name, $addon);
                if (!is_string($current) || !in_array($current, self::WEAK_VALUES, true)) {
                    continue;
                }

                $fresh = bin2hex(random_bytes(16));
                $settings->updateValue($name, $fresh, $addon);

                if ($settings->getValue($name, $addon) !== $fresh) {
                    continue; // write did not land — leave the row alone and stay quiet
                }
            } catch (\Throwable $e) {
                error_log("travel_core: could not rotate {$addon}.{$name} — " . $e->getMessage());

                continue;
            }

            $rotated[] = $name;
            self::reportRotation($addon, $name);
        }

        return $rotated;
    }

    /**
     * Tell the operator, because a rotated key breaks the crontab they pasted.
     *
     * Silence would turn a security fix into "the syncs stopped overnight and
     * nobody knows why".
     */
    private static function reportRotation(string $addon, string $name): void
    {
        $message = "Travel addons: the default \"1234\" {$name} on {$addon} was replaced "
            . 'with a generated one. Re-copy the cron commands from that addon\'s dashboard '
            . '— the old URLs no longer authenticate.';

        error_log('travel_core: ' . $message);

        if (function_exists('fn_set_notification')) {
            try {
                fn_set_notification('W', __('warning'), $message);
            } catch (\Throwable) {
                // Already in error_log; a notification failure must not escalate.
            }
        }
    }

    /**
     * MOVE the addon's RETIRED settings out of a store that still has them:
     * carry each row's value into the same-named travel_core setting (when
     * that one exists and is empty), then delete the row.
     *
     * The carry step is what makes retirement safe on a store where the
     * operator configured the provider copy before the consolidation — the
     * value follows the setting to its new home instead of vanishing with the
     * row. It runs BEFORE the delete, so a failed carry keeps the source row.
     *
     * Runs before the create loop so a retired name can never be re-created in
     * the same pass, and is idempotent: getId() returns 0 once the row is
     * gone, so every later request short-circuits.
     *
     * removeById() is called through Reflection guards because the CS-Cart kit
     * is licensed software supplied by the operator and is NOT part of this
     * repository — its exact signature cannot be pinned here. If the deployed
     * kit disagrees, the setting is simply left in place; a stale row is a
     * cosmetic duplicate, whereas an ArgumentCountError on a self-heal that
     * runs on every admin page load would take the admin down.
     *
     * @return list<string> names actually removed
     */
    private static function retire(string $addon): array
    {
        $names = self::RETIRED[$addon] ?? [];
        if ($names === []) {
            return [];
        }

        $settings = Settings::instance();
        if (!$settings instanceof Settings || !method_exists($settings, 'removeById')) {
            return [];
        }

        try {
            $method = new \ReflectionMethod($settings, 'removeById');
        } catch (\ReflectionException) {
            return [];
        }
        if ($method->getNumberOfRequiredParameters() > 1) {
            return [];
        }

        $removed = [];
        foreach ($names as $name) {
            $objectId = TypeCoerce::toInt($settings->getId($name, $addon));
            if ($objectId <= 0) {
                continue; // already gone, or never existed on this store
            }

            // Per-setting, so one row the deployed kit refuses to delete does
            // not abandon the rest — and never propagates out of a heal that
            // runs on every admin page load.
            try {
                self::carryValueToCore($addon, $name);
                $settings->removeById($objectId);
                $removed[] = $name;
            } catch (\Throwable $e) {
                error_log("travel_core: could not retire {$addon}.{$name} — " . $e->getMessage());
            }
        }

        return $removed;
    }

    /**
     * Hand a retiring provider setting's value to its travel_core successor.
     *
     * Only fills a genuine gap: the travel_core setting must exist (created
     * by an earlier heal or the install) and hold nothing, and the provider
     * value must be a non-empty string. A travel_core value the admin already
     * set — or a default an earlier pass seeded — is never overwritten.
     * Provider addons heal before travel_core (see
     * fn_travel_core_ensure_all_settings), so a carried operator value lands
     * before default-seeding and therefore wins over the repo default.
     */
    private static function carryValueToCore(string $addon, string $name): void
    {
        if ($addon === 'travel_core') {
            return;
        }

        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return;
        }

        $value = $settings->getValue($name, $addon);
        if (!is_string($value) || trim($value) === '') {
            return; // nothing worth carrying
        }

        if (!$settings->isExists($name, 'travel_core')) {
            return; // no successor row yet — travel_core's own heal seeds it
        }

        $current = $settings->getValue($name, 'travel_core');
        if ($current !== null && $current !== '' && $current !== []) {
            return; // successor already configured — never overwrite
        }

        $settings->updateValue($name, $value, 'travel_core');
    }

    /**
     * Seed an existing setting's default when it holds no value at all.
     *
     * A setting created before its default could be applied reads as an empty
     * field, which looks like a deliberate blank.
     *
     * READ THIS BEFORE GIVING A SECRET A DEFAULT. An earlier version of this
     * docblock claimed "an admin who cleared a field on purpose is never
     * overridden". That is FALSE and the code below says so: the early return
     * fires on `$current !== ''`, so a deliberately cleared field DOES get the
     * declared default written back on the next heal. The claim was reasoning
     * about checkboxes, which store 'N' rather than '' — true for those, and
     * for nothing else.
     *
     * The consequence was live: `cron_access_key` shipped `1234`, so clearing
     * it to close the endpoint reopened it with a four-digit key on the next
     * admin page load. Blank was not a stable state.
     *
     * The fix is the guard immediately below — a setting with no declared
     * default is never touched — which is why no `*_access_key` item declares
     * one any more (pinned by CronKeyDefaultsTest).
     */
    private static function repairValue(string $addon, string $name, string $default): bool
    {
        if ($default === '') {
            return false;
        }

        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return false;
        }

        $current = $settings->getValue($name, $addon);
        if ($current !== null && $current !== '' && $current !== []) {
            return false;
        }

        $settings->updateValue($name, $default, $addon);

        return true;
    }

    /**
     * Give an existing setting its label back when it has none.
     *
     * Settings created by a heal that could not read the .po (CRLF) exist but
     * render as blank rows — a field with no name is arguably worse than a
     * missing one. Only writes when the setting genuinely has no description
     * in that language, so an admin-edited label is never overwritten.
     *
     * @param array<string, array<string, array{value: string, tooltip: string}>> $labels
     * @return bool whether anything was repaired
     */
    private static function repairDescriptions(string $addon, string $name, array $labels): bool
    {
        $rows = self::descriptionRows($labels, $name);
        if ($rows === null) {
            return false;
        }

        // Instances are cached by Settings::instance(), so re-fetching here
        // costs nothing and keeps the signature free of a kit-only type
        // (the CS-Cart source is not part of this repository).
        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return false;
        }

        $objectId = TypeCoerce::toInt($settings->getId($name, $addon));
        if ($objectId <= 0) {
            return false;
        }

        $repaired = false;
        foreach ($rows as $row) {
            $existing = $settings->getDescription(
                $objectId,
                TypeCoerce::toString(Settings::SETTING_DESCRIPTION),
                $row['lang_code'],
            );
            if (is_string($existing) && trim($existing) !== '') {
                continue; // already labelled — leave admin edits alone
            }

            $row['object_id'] = (string) $objectId;
            $settings->updateDescription($row);
            $repaired = true;
        }

        return $repaired;
    }

    /**
     * A setting already living in this section, whose shape new rows copy.
     *
     * @return array<string, mixed>
     */
    private static function siblingTemplate(int $sectionId): array
    {
        $row = db_get_row(
            'SELECT edition_type, section_tab_id, is_global
             FROM ?:settings_objects WHERE section_id = ?i
             ORDER BY position DESC LIMIT 1',
            $sectionId,
        );
        $row = TypeCoerce::toStringMap($row);

        $row['position'] = TypeCoerce::toInt(db_get_field(
            'SELECT MAX(position) FROM ?:settings_objects WHERE section_id = ?i',
            $sectionId,
        ));

        return $row;
    }

    /**
     * Settings declared in addon.xml, in document order.
     *
     * @return list<array{name: string, type: string, default: string, variants: list<string>}>
     */
    private static function parseAddonXml(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $xml = @simplexml_load_file($path);
        if ($xml === false || !isset($xml->settings->sections->section)) {
            return [];
        }

        $items = [];
        foreach ($xml->settings->sections->section as $section) {
            foreach ($section->items->item ?? [] as $item) {
                $variants = [];
                foreach ($item->variants->item ?? [] as $variant) {
                    $variants[] = (string) $variant['id'];
                }

                $items[] = [
                    'name' => (string) $item['id'],
                    'type' => (string) $item->type,
                    'default' => (string) $item->default_value,
                    'variants' => $variants,
                ];
            }
        }

        return $items;
    }

    /**
     * Labels + tooltips from the addon's .po files — the same source and the
     * same table CS-Cart's own importer uses for settings.
     *
     * @return array<string, array<string, array{value: string, tooltip: string}>>
     *                                                                             setting name => lang code => texts
     */
    private static function parsePoLabels(string $langsDir, string $addon): array
    {
        $out = [];
        foreach ((array) glob($langsDir . '/*/addons/' . $addon . '.po') as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }
            $lang = basename(dirname($file, 2));
            $body = (string) file_get_contents($file);

            // \r?\n, not \n: a Windows checkout stores these files with CRLF
            // (there is no .gitattributes forcing LF), and a \n-only pattern
            // silently matches nothing — which is exactly how the first heal
            // created the settings with blank labels.
            $pattern = '/msgctxt "Settings(Options|Tooltips)::' . preg_quote($addon, '/')
                . '::([a-zA-Z0-9_]+)"\r?\nmsgid "(?:[^"\\\\]|\\\\.)*"\r?\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/';
            if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $m) {
                $name = $m[2];
                $text = str_replace(['\\"', '\\\\'], ['"', '\\'], $m[3]);
                $out[$name][$lang] ??= ['value' => '', 'tooltip' => ''];
                $out[$name][$lang][$m[1] === 'Options' ? 'value' : 'tooltip'] = $text;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, array{value: string, tooltip: string}>> $labels
     * @return list<array<string, string>>|null
     */
    private static function descriptionRows(array $labels, string $name): ?array
    {
        $perLang = $labels[$name] ?? [];
        if ($perLang === []) {
            return null;
        }

        $rows = [];
        foreach ($perLang as $lang => $texts) {
            $rows[] = [
                'object_type' => TypeCoerce::toString(Settings::SETTING_DESCRIPTION),
                'lang_code' => (string) $lang,
                'value' => $texts['value'],
                'tooltip' => $texts['tooltip'],
            ];
        }

        return $rows;
    }

    /**
     * Selectbox options — ?:settings_variants rows (name + position; the
     * object_id is injected by Settings::update()).
     *
     * @param list<string> $variants
     * @return list<array<string, string>>|null
     */
    private static function variantRows(array $variants): ?array
    {
        if ($variants === []) {
            return null;
        }

        $rows = [];
        $position = 0;
        foreach ($variants as $variant) {
            $position += 10;
            $rows[] = [
                'name' => $variant,
                'position' => (string) $position,
            ];
        }

        return $rows;
    }
}
