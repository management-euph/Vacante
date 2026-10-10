<?php

declare(strict_types=1);

/**
 * composer package — build production-ready zips for the CS-Cart addons.
 *
 * Each zip contains ONE addon's deploy artifacts with store-root-relative
 * paths (app/addons/<id>/…, design/…, js/…, var/langs/…) — the exact layout
 * CS-Cart's Add-ons → "+" → "Upload & install" expects, and the same tree you
 * would rsync onto a production store. Dev-only files never ship: per-addon
 * tests/, vendor/ (phpunit + friends), composer manifests, phpunit configs
 * and lint caches are excluded; react-src/ and each repo's docs are excluded
 * by construction because only the four artifact trees are walked.
 *
 * Output: dist/<addon_id>-<version>.zip (version read from addon.xml).
 * dist/ is gitignored — packages are build artifacts, not source.
 *
 * Usage:
 *   composer package                # the four gated addons
 *   php scripts/package-addons.php eurosite   # specific addon(s), e.g. the MVP
 */

$repoRoot = dirname(__DIR__);

// addon-id -> repo top-level dir. Mirrors docker/fullstore/link-addons.sh —
// the sandbox links the same trees these zips carry.
$addons = [
    'travel_core' => 'addon-travel-core',
    'novoton_holidays' => 'addon-novoton-holidays',
    'sphinx_holidays' => 'addon-sphinx-holidays',
    'fgo_invoicing' => 'addon-fgo-invoicing',
    // Not packaged by default: an MVP outside the CI gates. Request it
    // explicitly (`php scripts/package-addons.php eurosite`) when needed.
    'eurosite' => 'addon-eurosite',
];
$defaultIds = ['travel_core', 'novoton_holidays', 'sphinx_holidays', 'fgo_invoicing'];

/** Top-level names inside app/addons/<id>/ that must never reach production. */
$excludeNames = [
    'tests', 'vendor', 'composer.json', 'composer.lock',
    'phpunit.xml', 'phpunit-integration.xml',
    '.phpunit.cache', '.php-cs-fixer.cache', '.php-cs-fixer.dist.php',
    '.phpunit.result.cache', '.gitignore',
    'node_modules',
];

$requested = array_values(array_filter(array_slice($argv ?? [], 1), static fn (string $a): bool => !str_starts_with($a, '-')));
$ids = $requested === [] ? $defaultIds : $requested;

$distDir = $repoRoot . '/dist';
if (!is_dir($distDir) && !mkdir($distDir, 0755, true)) {
    fwrite(STDERR, "cannot create {$distDir}\n");
    exit(1);
}

$failures = 0;
foreach ($ids as $id) {
    if (!isset($addons[$id])) {
        fwrite(STDERR, "unknown addon '{$id}' (known: " . implode(', ', array_keys($addons)) . ")\n");
        $failures++;
        continue;
    }

    $base = $repoRoot . '/' . $addons[$id];
    $xmlPath = $base . '/app/addons/' . $id . '/addon.xml';
    if (!is_file($xmlPath)) {
        fwrite(STDERR, "{$id}: missing {$xmlPath}\n");
        $failures++;
        continue;
    }

    $xml = @simplexml_load_file($xmlPath);
    $version = $xml !== false ? trim((string) ($xml->version ?? '')) : '';
    $version = $version !== '' ? $version : '0.0.0';

    $zipPath = "{$distDir}/{$id}-{$version}.zip";
    @unlink($zipPath);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "{$id}: cannot open {$zipPath}\n");
        $failures++;
        continue;
    }

    // The deploy artifact trees, store-root-relative — the same set
    // link-addons.sh links into the sandbox docroot.
    $trees = [
        "app/addons/{$id}",
        "design/backend/templates/addons/{$id}",
        "design/backend/css/addons/{$id}",
        "design/backend/js/addons/{$id}",
        "design/backend/mail/templates/addons/{$id}",
        "design/backend/media/images/addons/{$id}",
        "design/themes/responsive/templates/addons/{$id}",
        "design/themes/responsive/css/addons/{$id}",
        "design/themes/nova_theme/templates/addons/{$id}",
        "design/themes/nova_theme/css/addons/{$id}",
        "js/addons/{$id}",
        "var/langs/en/addons/{$id}.po",
        "var/langs/ro/addons/{$id}.po",
    ];

    $count = 0;
    $skipPrefixes = [];
    foreach ($excludeNames as $name) {
        $skipPrefixes[] = "app/addons/{$id}/{$name}";
    }

    foreach ($trees as $tree) {
        $abs = $base . '/' . $tree;
        if (is_file($abs)) {
            $zip->addFile($abs, $tree);
            $count++;
            continue;
        }
        if (!is_dir($abs)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = $tree . '/' . substr($file->getPathname(), strlen($abs) + 1);
            $rel = str_replace('\\', '/', $rel);

            $excluded = false;
            foreach ($skipPrefixes as $prefix) {
                if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded || str_ends_with($rel, '.php-cs-fixer.cache')) {
                continue;
            }

            $zip->addFile($file->getPathname(), $rel);
            $count++;
        }
    }

    $zip->close();
    printf("packaged %-18s v%-8s %5d files  %7.1f KB  %s\n", $id, $version, $count, filesize($zipPath) / 1024, $zipPath);

    if ($count === 0) {
        fwrite(STDERR, "{$id}: packaged ZERO files — wrong repo dir?\n");
        $failures++;
    }
}

exit($failures === 0 ? 0 : 1);
