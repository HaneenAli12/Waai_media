<?php

$rootDir = dirname(__DIR__);
$zipFile = $rootDir.'/deployment/background-music-deployment.zip';

$zip = new ZipArchive;
if ($zip->open($zipFile) !== true) {
    echo "ERROR: Could not open ZIP: {$zipFile}\n";
    exit(1);
}

echo "ZIP File: {$zipFile}\n";
echo "Total Files in ZIP: {$zip->numFiles}\n\n";

$allValid = true;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $entryName = $stat['name'];
    $entrySize = $stat['size'];

    $localFilePath = $rootDir.'/'.$entryName;

    if (! file_exists($localFilePath)) {
        echo "FAIL: File in ZIP does not exist locally: {$entryName}\n";
        $allValid = false;

        continue;
    }

    $localContent = file_get_contents($localFilePath);
    $zipStream = $zip->getFromIndex($i);

    if ($localContent !== $zipStream) {
        echo "FAIL: Content mismatch for {$entryName}\n";
        $allValid = false;

        continue;
    }

    $sha256 = hash('sha256', $localContent);
    echo "OK: [{$entrySize} bytes] {$entryName} (SHA-256: ".substr($sha256, 0, 16)."...)\n";
}

$zip->close();

if ($allValid) {
    echo "\nVERIFICATION SUCCESS: All files match local project 1:1 and archive is healthy.\n";
} else {
    echo "\nVERIFICATION FAILED.\n";
    exit(1);
}
