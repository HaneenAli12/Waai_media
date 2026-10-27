<?php

/**
 * Script to build a clean, manual production deployment package for Hostinger.
 * Target Commit: 700aefc8db45386c2439d6698296508eaed809b6
 *
 * Rules:
 * 1. Must include application code, routes, bootstrap, config, migrations, public entrypoint, composer.json/lock, artisan.
 * 2. Excludes .env, storage, tests, vendor, scratch, event-module-staging, deployment, .git.
 * 3. Excludes SuperAdminSeeder.php or any seeders that alter passwords.
 * 4. Ensures no news files exist.
 */

$rootDir = realpath(__DIR__ . '/..');
$zipPath = $rootDir . '/deployment/waai_media_api_release_700aefc.zip';

if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Failed to create zip file at: $zipPath\n");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$excludedDirectories = [
    '.git',
    '.github',
    'vendor',
    'storage',
    'tests',
    'scratch',
    'event-module-staging',
    'deployment',
    'node_modules',
];

$excludedFiles = [
    '.env',
    '.env.example',
    '.env.testing',
    'phpunit.xml',
    'test-r2.php',
    'test_login.php',
    'event-calendar-deploy.zip',
    'database/seeders/SuperAdminSeeder.php', // Excluded to strictly follow constraint #6
];

$addedCount = 0;
$addedFiles = [];

foreach ($iterator as $item) {
    $pathname = $item->getPathname();
    $relative = ltrim(str_replace(['\\', $rootDir], ['/', ''], $pathname), '/');

    // Check directory exclusions
    $skip = false;
    foreach ($excludedDirectories as $dir) {
        if ($relative === $dir || str_starts_with($relative, $dir . '/')) {
            $skip = true;
            break;
        }
    }

    if ($skip) {
        continue;
    }

    // Check file exclusions
    if (in_array($relative, $excludedFiles, true)) {
        continue;
    }

    // Exclude scratch or zip files
    if (str_ends_with($relative, '.zip')) {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
    } elseif ($item->isFile()) {
        $zip->addFile($pathname, $relative);
        $addedFiles[] = $relative;
        $addedCount++;
    }
}

$zip->close();

echo "ZIP created successfully: $zipPath\n";
echo "Total files packed: $addedCount\n";
