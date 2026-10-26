<?php

$filesToZip = [
    'app/Console/Commands/CleanOrphanedBackgroundMusicCommand.php',
    'app/Http/Controllers/Api/Admin/BackgroundMusicController.php',
    'app/Http/Controllers/Api/BackgroundMusicController.php',
    'app/Http/Requests/Admin/Music/UpdateBackgroundMusicRequest.php',
    'app/Http/Resources/BackgroundMusicAdminResource.php',
    'app/Http/Resources/BackgroundMusicPublicResource.php',
    'app/Models/Music.php',
    'app/Services/Storage/AudioStorageService.php',
    'app/Services/Storage/StoredAudio.php',
    'database/migrations/2026_10_25_000001_add_settings_to_music_table.php',
    'routes/api.php',
    'routes/console.php',
];

$rootDir = dirname(__DIR__);
$zipFile = $rootDir.'/deployment/background-music-deployment.zip';

$zip = new ZipArchive;
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "ERROR: Could not create zip archive: {$zipFile}\n";
    exit(1);
}

foreach ($filesToZip as $relativePath) {
    $fullPath = $rootDir.'/'.$relativePath;
    if (! file_exists($fullPath)) {
        echo "ERROR: File does not exist: {$fullPath}\n";
        $zip->close();
        exit(1);
    }
    // Add file with exact relative path (forward slashes)
    $normalizedPath = str_replace('\\', '/', $relativePath);
    $zip->addFile($fullPath, $normalizedPath);
    echo "Added: {$normalizedPath}\n";
}

$zip->close();

echo "SUCCESS: Archive created at {$zipFile}\n";
