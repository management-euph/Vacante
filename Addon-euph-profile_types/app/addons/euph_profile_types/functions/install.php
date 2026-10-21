<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Db\Database;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CheckoutBlockFilling;
use Tygh\Addons\EuphProfileTypes\Service\LanguagePack;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** Storage key of the checkout blocks state: Y switched to the add-on's filling. */
function fn_euph_profile_types_blocks_state_key(): string
{
    return 'euph_profile_types_checkout_blocks';
}

/** Storage key of the language packs last written by fn_euph_profile_types_refresh_texts(). */
function fn_euph_profile_types_texts_key(): string
{
    return 'euph_profile_types_texts';
}

/**
 * Install: the type columns, the two standard types, the 1.x data when the
 * old add-on's tables are there, a type for every customer and vendor, and
 * the checkout blocks on the "By profile type" filling.
 */
function fn_euph_profile_types_install(): void
{
    $container = fn_euph_profile_types_container(true);
    fn_euph_profile_types_add_columns($container->db(), $container->isMultiVendor());
    fn_euph_profile_types_ensure_schema();
    fn_euph_profile_types_create_standard_types();

    $importer = $container->importer();
    if ($importer->isAvailable()) {
        $stats = $importer->import(fn_euph_profile_types_lang(), fn_euph_profile_types_lang_codes());
        fn_euph_profile_types_notify('N', fn_euph_profile_types_text(
            'euph_profile_types.legacy_imported',
            'Carried over from version 1.x: [types] types, [rules] field settings, [adopted] fields joined the common list, [values] values copied.',
            [
                '[types]' => (string) $stats['types'],
                '[rules]' => (string) $stats['rules'],
                '[adopted]' => (string) $stats['adopted_fields'],
                '[values]' => (string) $stats['values'],
            ],
        ));
    }
    fn_euph_profile_types_fill_empty_types();
    fn_euph_profile_types_switch_checkout_blocks(true);
}

/**
 * Uninstall: the checkout blocks get their filling back and the type columns
 * of customers, orders and vendors are dropped. The add-on's tables are
 * dropped by addon.xml. Profile fields and their values stay: they are
 * CS-Cart's own.
 */
function fn_euph_profile_types_uninstall(): void
{
    $container = fn_euph_profile_types_container(true);
    try {
        fn_euph_profile_types_switch_checkout_blocks(false);
    } catch (\Throwable $e) {
        error_log('euph_profile_types: checkout blocks were not restored: ' . $e->getMessage());
    }
    fn_euph_profile_types_drop_columns($container->db());
    if (function_exists('fn_set_storage_data')) {
        fn_set_storage_data(fn_euph_profile_types_blocks_state_key(), null);
        fn_set_storage_data(fn_euph_profile_types_texts_key(), null);
    }
}

/**
 * Settings earlier versions of the add-on had and this one dropped.
 *
 * @return list<string>
 */
function fn_euph_profile_types_obsolete_settings(): array
{
    return ['invoice_details_from_profile'];
}

/**
 * Removes the settings this version dropped from a store that has the add-on
 * installed: CS-Cart keeps the settings an add-on had at install, so the
 * settings page would go on showing options that do nothing. Returns how many
 * were removed. Never breaks a page: on a failure they just stay.
 */
function fn_euph_profile_types_remove_obsolete_settings(): int
{
    try {
        $db = fn_euph_profile_types_container()->db();
        if (!$db->tableExists('?:settings_objects') || !$db->tableExists('?:settings_sections')) {
            return 0;
        }
        $ids = Cast::idList($db->column(
            'SELECT o.object_id FROM ?:settings_objects AS o'
            . ' INNER JOIN ?:settings_sections AS s ON s.section_id = o.section_id'
            . ' WHERE s.name = ?s AND s.type = ?s AND o.name IN (?a)',
            'euph_profile_types',
            'ADDON',
            fn_euph_profile_types_obsolete_settings(),
        ));
        if ($ids === []) {
            return 0;
        }
        $db->execute('DELETE FROM ?:settings_objects WHERE object_id IN (?n)', $ids);
        if ($db->tableExists('?:settings_descriptions')) {
            $db->execute('DELETE FROM ?:settings_descriptions WHERE object_id IN (?n) AND object_type = ?s', $ids, 'O');
        }
        if ($db->tableExists('?:settings_vendor_values')) {
            $db->execute('DELETE FROM ?:settings_vendor_values WHERE object_id IN (?n)', $ids);
        }

        return count($ids);
    } catch (\Throwable $e) {
        error_log('euph_profile_types: old settings were not removed: ' . $e->getMessage());

        return 0;
    }
}

/**
 * The add-on's texts in every language of the store, written again when its
 * language packs change. CS-Cart reads the packs at install only, so a text
 * an update of the add-on changed or added would never show on a store that
 * already has it. A language without a pack of its own gets the English
 * texts, as at install. A text edited in Translations is replaced when the
 * add-on's packs change. Never breaks a page: on a failure the texts stay.
 */
function fn_euph_profile_types_refresh_texts(): void
{
    try {
        if (!function_exists('fn_get_storage_data') || !function_exists('fn_set_storage_data')) {
            return;
        }
        $root = Cast::string(Registry::get('config.dir.lang_packs'));
        if ($root === '') {
            $root = Cast::string(Registry::get('config.dir.root')) . '/var/langs/';
        }
        $files = glob(rtrim($root, '/') . '/*/addons/euph_profile_types.po') ?: [];
        $stamp = '';
        foreach ($files as $file) {
            $stamp .= $file . ':' . (int) filemtime($file) . ':' . (int) filesize($file) . '|';
        }
        $stamp = md5($stamp);
        if ($files === [] || fn_get_storage_data(fn_euph_profile_types_texts_key()) === $stamp) {
            return;
        }
        // Stamped first: packs that cannot be written are tried again after
        // their next change, not on every request.
        fn_set_storage_data(fn_euph_profile_types_texts_key(), $stamp);
        $packs = [];
        foreach ($files as $file) {
            $packs[basename(dirname($file, 2))] = LanguagePack::variables((string) file_get_contents($file));
        }
        $db = fn_euph_profile_types_container()->db();
        $languages = array_map(static fn (mixed $code): string => Cast::string($code), $db->column('SELECT lang_code FROM ?:languages'));
        foreach (array_chunk(LanguagePack::rows($packs, $languages, 'en'), 200) as $rows) {
            $db->execute('REPLACE INTO ?:language_values ?m', $rows);
        }
    } catch (\Throwable $e) {
        error_log('euph_profile_types: the texts were not refreshed: ' . $e->getMessage());
    }
}

/**
 * The columns later versions added to ?:euph_profile_types, for the table of
 * an earlier version: on install, and when the types are opened in the admin
 * panel (files updated without a reinstall). Every type then has the key of
 * its registration links.
 */
function fn_euph_profile_types_ensure_schema(): void
{
    $container = fn_euph_profile_types_container();
    $db = $container->db();
    if (!$db->tableExists('?:euph_profile_types')) {
        return;
    }
    $columns = [
        'link_key' => "varchar(64) NOT NULL DEFAULT '' AFTER `activation`",
        'invoice_as' => "char(1) NOT NULL DEFAULT '' AFTER `link_key`",
    ];
    foreach ($columns as $column => $definition) {
        if (!$db->columnExists('?:euph_profile_types', $column)) {
            $db->execute('ALTER TABLE ?:euph_profile_types ADD `' . $column . '` ' . $definition);
        }
    }
    $container->types()->fillLinkKeys();
    fn_euph_profile_types_remove_obsolete_settings();
}

/**
 * @return list<array{0: string, 1: string, 2: string}> table, column, definition
 */
function fn_euph_profile_types_columns(bool $multiVendor): array
{
    $columns = [
        ['?:users', 'euph_profile_type', "varchar(32) NOT NULL DEFAULT ''"],
        ['?:users', 'euph_profile_type_changes', 'smallint(5) unsigned NOT NULL DEFAULT 0'],
        ['?:orders', 'euph_profile_type', "varchar(32) NOT NULL DEFAULT ''"],
    ];
    if ($multiVendor) {
        $columns[] = ['?:companies', 'euph_profile_type', "varchar(32) NOT NULL DEFAULT ''"];
    }

    return $columns;
}

/** Adds the columns that are not there yet: a reinstall must not fail on them. */
function fn_euph_profile_types_add_columns(Database $db, bool $multiVendor): void
{
    foreach (fn_euph_profile_types_columns($multiVendor) as [$table, $column, $definition]) {
        if (!$db->columnExists($table, $column)) {
            $db->execute('ALTER TABLE ' . $table . ' ADD `' . $column . '` ' . $definition);
        }
    }
    if ($db->rows('SHOW INDEX FROM ?:users WHERE Key_name = ?s', 'euph_profile_type') === []) {
        $db->execute('ALTER TABLE ?:users ADD INDEX `euph_profile_type` (`euph_profile_type`)');
    }
}

function fn_euph_profile_types_drop_columns(Database $db): void
{
    foreach (fn_euph_profile_types_columns(true) as [$table, $column]) {
        if ($db->columnExists($table, $column)) {
            $db->execute('ALTER TABLE ' . $table . ' DROP `' . $column . '`');
        }
    }
}

/**
 * The standard customer type (code U) and, in Multi-Vendor, the standard
 * vendor type (code S): what everybody has until the store creates its own.
 */
function fn_euph_profile_types_create_standard_types(): void
{
    $container = fn_euph_profile_types_container();
    $types = $container->types();
    $langCode = fn_euph_profile_types_lang();
    $langCodes = fn_euph_profile_types_lang_codes();
    $kinds = $container->isMultiVendor() ? [UserKind::Customer, UserKind::Vendor] : [UserKind::Customer];
    $names = [
        UserKind::Customer->value => ['euph_profile_types.standard_customer_type', 'Customer'],
        UserKind::Vendor->value => ['euph_profile_types.standard_vendor_type', 'Vendor'],
    ];

    foreach ($kinds as $kind) {
        if ($types->standard($kind, $langCode) !== null) {
            continue;
        }
        [$langVar, $fallback] = $names[$kind->value];
        $existing = $types->findByCode($kind->standardCode(), $langCode);
        if ($existing !== null) {
            // Left over from an earlier install: it becomes the standard type again.
            $container->db()->execute('UPDATE ?:euph_profile_types SET is_standard = ?s, status = ?s WHERE type_id = ?i', 'Y', 'A', $existing->id);
            $types->forget();
            continue;
        }
        $typeId = $types->save(
            0,
            [
                'code' => $kind->standardCode(),
                'user_type' => $kind->value,
                'status' => 'A',
                'position' => 0,
                'activation' => 'S',
                'is_standard' => 'Y',
            ],
            fn_euph_profile_types_text($langVar, $fallback, [], $langCode),
            '',
            $langCode,
            [],
            [],
        );
        foreach ($langCodes as $lang) {
            $types->saveDescription($typeId, $lang, fn_euph_profile_types_text($langVar, $fallback, [], $lang), '');
        }
        $types->setDefault($typeId, $kind);
    }
}

/** Customers and vendors without a type get the standard one. */
function fn_euph_profile_types_fill_empty_types(): void
{
    $container = fn_euph_profile_types_container();
    $db = $container->db();
    $db->execute(
        'UPDATE ?:users SET euph_profile_type = ?s WHERE user_type = ?s AND euph_profile_type = ?s',
        UserKind::Customer->standardCode(),
        UserKind::Customer->value,
        '',
    );
    if ($container->isMultiVendor()) {
        $db->execute(
            'UPDATE ?:companies SET euph_profile_type = ?s WHERE euph_profile_type = ?s',
            UserKind::Vendor->standardCode(),
            '',
        );
        $db->execute(
            'UPDATE ?:users AS u INNER JOIN ?:companies AS c ON c.company_id = u.company_id'
            . ' SET u.euph_profile_type = c.euph_profile_type WHERE u.user_type = ?s AND u.euph_profile_type = ?s',
            UserKind::Vendor->value,
            '',
        );
    }
}

/**
 * Switches the checkout customer blocks to the add-on's filling (on) or gives
 * them their own filling back (off). Returns how many block contents changed.
 */
function fn_euph_profile_types_switch_checkout_blocks(bool $on): int
{
    $db = fn_euph_profile_types_container()->db();
    $changed = 0;
    if ($db->tableExists('?:bm_blocks') && $db->tableExists('?:bm_blocks_content')) {
        $blockIds = Cast::idList($db->column(
            'SELECT block_id FROM ?:bm_blocks WHERE type IN (?a)',
            array_keys(CheckoutBlockFilling::BLOCKS),
        ));
        $rows = $blockIds !== [] ? $db->rows('SELECT * FROM ?:bm_blocks_content WHERE block_id IN (?n)', $blockIds) : [];
        foreach ($rows as $row) {
            $raw = Cast::string($row['content'] ?? '');
            $content = CheckoutBlockFilling::decode($raw);
            $switched = $content === null ? null : ($on ? CheckoutBlockFilling::switchOn($content) : CheckoutBlockFilling::switchOff($content));
            if ($switched === null) {
                continue;
            }
            $where = array_intersect_key($row, array_flip(['snapping_id', 'object_id', 'object_type', 'block_id', 'lang_code']));
            $db->execute('UPDATE ?:bm_blocks_content SET content = ?s WHERE ?w', CheckoutBlockFilling::encode($switched, $raw), $where);
            ++$changed;
        }
    }
    if (function_exists('fn_set_storage_data')) {
        fn_set_storage_data(fn_euph_profile_types_blocks_state_key(), $on ? 'Y' : 'N');
    }

    return $changed;
}

/** After the add-on is enabled again: the blocks go back to its filling. */
function fn_euph_profile_types_ensure_checkout_blocks(): void
{
    if (!function_exists('fn_get_storage_data')
        || Cast::string(fn_get_storage_data(fn_euph_profile_types_blocks_state_key())) === 'Y'
    ) {
        return;
    }
    fn_euph_profile_types_switch_checkout_blocks(true);
}
