<?php

$zipFile = __DIR__ . '/../deployment/waai_media_api_release_700aefc.zip';
$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    die("Cannot open zip\n");
}

$critical = [
    'app/Http/Controllers/Api/Admin/TeamMemberController.php',
    'app/Http/Controllers/Api/TeamMemberController.php',
    'app/Models/TeamMember.php',
    'app/Models/SystemSetting.php',
    'app/Models/Event.php',
    'app/Models/User.php',
    'app/Services/Team/TeamPhotoService.php',
    'app/Services/Admin/AdminUserService.php',
    'routes/api.php',
    'routes/console.php',
    'bootstrap/app.php',
    'composer.json',
    'composer.lock',
    'public/index.php',
    'artisan',
];

foreach ($critical as $c) {
    $found = $zip->locateName($c) !== false;
    echo ($found ? "[CONFIRMED] " : "[FAILED] ") . $c . "\n";
}

$zip->close();
