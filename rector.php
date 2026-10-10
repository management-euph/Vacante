<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Rector configuration — NovotonBUN monorepo.
 *
 * Gated adoption strategy (per /root/.claude/plans/i-want-to-resolve-golden-lovelace.md):
 *   - Wave 1 (this PR): scaffolding + dry-run CI, NO rules enabled. Any drift
 *     detected is an operator error, not an intentional rewrite.
 *   - Wave 3: rules whitelisted one-at-a-time, per PR. Each PR adds one rule
 *     (or a narrow pair), runs `composer rector:dry`, inspects diff, narrows
 *     `skip` until diff matches intent, then commits.
 *
 * Scope:
 *   - Paths: three addons' `src/` directories only.
 *   - Never runs on controllers/, hooks/, functions/, func.php, init.php,
 *     hooks.php, cron.php (CS-Cart procedural boundary).
 *   - Skip list excludes directories that are already clean-slate: Dto/,
 *     Enums/, ValueObjects/, Contracts/, and vendor artefacts.
 *
 * Sequencing with other tools: Rector → PHP-CS-Fixer → PHPCS → PHPStan →
 * Psalm. Rector output isn't PSR-12-clean; PHP-CS-Fixer normalises.
 */
return RectorConfig::configure()
    ->withPhpVersion(Rector\ValueObject\PhpVersion::PHP_83)
    ->withPaths([
        __DIR__ . '/addon-novoton-holidays/app/addons/novoton_holidays/src',
        __DIR__ . '/addon-travel-core/app/addons/travel_core/src',
        __DIR__ . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src',
        __DIR__ . '/addon-fgo-invoicing/app/addons/fgo_invoicing/src',
        __DIR__ . '/addon-eurosite/app/addons/eurosite/src',
    ])
    ->withSkip([
        // Vendor + caches
        '*/vendor/*',
        '*/var/*',
        '*/node_modules/*',
        '*/tests/*',

        // Repository/ — CS-Cart DB helpers return mixed / array|false.
        // Rector's return-type inference would over-narrow to `array`
        // and hide the false path. Add specific repositories back
        // case-by-case after hand-typing.
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedPropertyRector::class => [
            '*/Repository/*',
        ],
        Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByMethodCallTypeRector::class => [
            '*/Repository/*',
        ],
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector::class => [
            '*/Repository/*',
        ],
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnDirectArrayRector::class => [
            '*/Repository/*',
        ],
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNewArrayRector::class => [
            '*/Repository/*',
        ],
    ])
    ->withRules([
        // Wave 3 PR 7 — narrow, safe type-declaration rules:
        //
        //   - TypedPropertyFromAssignsRector adds declared types to
        //     untyped class properties based on how they're initialised /
        //     assigned. Idempotent on already-typed props.
        //
        //   - AddOverrideAttributeToOverriddenMethodsRector appends the
        //     PHP 8.3 #[\Override] attribute to methods that override a
        //     parent/interface method. Catches future rename-drift
        //     (removing a parent method becomes a fatal at compile time
        //     instead of silent dead code).
        Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector::class,
        Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector::class,

        // Wave 3 PR 8 — return / param type inference + small safe
        // batch of related type-declaration rules. Every rule here is
        // pure inference: if Rector can prove a narrower type from
        // existing structure, it writes it in; otherwise it leaves
        // the method alone.
        //
        // Repository/ excluded from this wave (see withSkip): CS-Cart
        // DB helpers return mixed / array|false and inference would
        // over-narrow to `array`, hiding the `false` path.
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedPropertyRector::class,
        Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByMethodCallTypeRector::class,
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector::class,
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnDirectArrayRector::class,
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNewArrayRector::class,
        Rector\TypeDeclaration\Rector\Function_\AddFunctionVoidReturnTypeWhereNoReturnRector::class,
        Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector::class,
    ])
    ->withImportNames(
        importNames: false,
        importDocBlockNames: false,
        importShortClasses: false,
        removeUnusedImports: false,
    );
