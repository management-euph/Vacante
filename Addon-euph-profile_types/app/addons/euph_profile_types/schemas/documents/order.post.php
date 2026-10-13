<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Design → Documents and the order e-mails: the "user" variable carries the
 * fields of the order's type under their codes (user.company_inn), and the
 * type (user.profile_type, user.profile_type_code). The stock invoice prints
 * only the fields CS-Cart knows about: add your own fields to it once.
 */

/** @var array<string, mixed> $schema */
if (isset($schema['user']) && is_array($schema['user'])) {
    $euphUserData = $schema['user']['data'] ?? null;
    $schema['user']['data'] = static function ($context) use ($euphUserData): array {
        return fn_euph_profile_types_document_user_data($context, $euphUserData);
    };
    $euphUserAttributes = $schema['user']['attributes'] ?? null;
    $schema['user']['attributes'] = static function () use ($euphUserAttributes): array {
        return fn_euph_profile_types_document_user_attributes($euphUserAttributes);
    };
    unset($euphUserData, $euphUserAttributes);
}

return $schema;
