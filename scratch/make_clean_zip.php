<?php

/**
 * Script to build release zip from git tracked files.
 */
$rootDir = realpath(__DIR__ . '/..');
$zipPath = $rootDir . '/deployment/waai_media_api_release_700aefc.zip';

if (file_exists($zipPath)) {
    unlink($zipPath);
}

// Get list of tracked files via git ls-files
exec('git ls-files', $trackedFiles, $returnCode);

if ($returnCode !== 0 || empty($trackedFiles)) {
    die("Error getting tracked files from git.\n");
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Failed to create zip file.\n");
}

$excludedPrefixes = [
    'tests/',
    'scratch/',
    'event-module-staging/',
    'deployment/',
    'storage/',
];

$excludedExact = [
    '.env',
    '.env.example',
    '.env.testing',
    'phpunit.xml',
    'test-r2.php',
    'test_login.php',
    'event-calendar-deploy.zip',
    'database/seeders/SuperAdminSeeder.php',
];

$packed = 0;
foreach ($trackedFiles as $file) {
    $file = trim(str_replace('\\', '/', $file));

    // Check directory exclusions
    $skip = false;
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($file, $prefix)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    // Check exact exclusions
    if (in_array($file, $excludedExact, true)) {
        continue;
    }

    $fullPath = $rootDir . '/' . $file;
    if (file_exists($fullPath) && is_file($fullPath)) {
        $zip->addFile($fullPath, $file);
        $packed++;
    }
}

$zip->close();

echo "RELEASE ZIP CREATED.\n";
echo "Path: $zipPath\n";
echo "Files packed: $packed\n";
