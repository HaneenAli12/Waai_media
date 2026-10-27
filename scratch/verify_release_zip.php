<?php

$zipFile = __DIR__ . '/../deployment/waai_media_api_release_700aefc.zip';

if (!file_exists($zipFile)) {
    die("ZIP file does not exist!\n");
}

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    die("Failed to open ZIP!\n");
}

echo "ZIP Archive: " . basename($zipFile) . "\n";
echo "Size: " . round(filesize($zipFile) / 1024, 2) . " KB\n";
echo "Total Files Count: " . $zip->numFiles . "\n";

$forbidden = [];
$foundMigrations = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);

    if (
        str_contains($name, '.env') ||
        str_contains($name, 'SuperAdminSeeder') ||
        str_starts_with($name, 'storage/') ||
        str_starts_with($name, 'scratch/') ||
        str_starts_with($name, 'event-module-staging/') ||
        str_starts_with($name, 'tests/') ||
        stripos($name, 'news') !== false
    ) {
        $forbidden[] = $name;
    }

    if (str_starts_with($name, 'database/migrations/')) {
        $foundMigrations[] = basename($name);
    }
}

if (!empty($forbidden)) {
    echo "ERROR: Found forbidden files:\n" . implode("\n", $forbidden) . "\n";
} else {
    echo "SUCCESS: Zero forbidden or sensitive files found in ZIP.\n";
}

$requiredMigrations = [
    '2026_10_26_000001_create_system_settings_table.php',
    '2026_10_26_172948_add_super_admin_role_to_users_table.php',
    '2026_10_27_000001_create_team_members_table.php'
];

foreach ($requiredMigrations as $req) {
    if (in_array($req, $foundMigrations, true)) {
        echo "MATCH: $req is present.\n";
    } else {
        echo "MISSING: $req is NOT in ZIP!\n";
    }
}

$zip->close();
