<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\TestCase;

require __DIR__.'/../vendor/autoload.php';

// Force testing environment
putenv('APP_ENV=testing');
putenv('DB_DATABASE=waai_media_testing');
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_DATABASE'] = 'waai_media_testing';
$_SERVER['APP_ENV'] = 'testing';
$_SERVER['DB_DATABASE'] = 'waai_media_testing';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Strict Test Database Guard verification
$dbName = $app['db']->connection()->getDatabaseName();
if ($dbName !== TestCase::PERMITTED_TEST_DATABASE) {
    echo json_encode([
        'error' => "CRITICAL TEST DATABASE GUARD FAILED: targeted {$dbName}",
        'status' => 500,
    ]);
    exit(1);
}

$workerId = $argv[1] ?? '1';
$googleLink = $argv[2] ?? "https://play.google.com/store/apps/details?id=com.waaimedia.worker{$workerId}";
$appleLink = $argv[3] ?? "https://apps.apple.com/app/waai-media/id10000000{$workerId}";

// Create/find test admin in waai_media_testing
$admin = User::firstOrCreate(
    ['email' => "admin_applinks_worker_{$workerId}@waaimedia.testing"],
    [
        'name' => "Admin Worker {$workerId}",
        'password' => 'Secret123!',
        'role' => 'admin',
    ]
);

$token = $admin->createToken('applinks_concurrency_test', ['access'])->plainTextToken;

$payload = [
    'google_app_link' => $googleLink,
    'apple_app_store_link' => $appleLink,
    'app_gallery_link' => "https://appgallery.huawei.com/app/C10000000{$workerId}",
];

// Optional synchronization delay if specified in argv[4] (in microseconds)
$delay = isset($argv[4]) ? (int) $argv[4] : 0;
if ($delay > 0) {
    usleep($delay);
}

$request = Request::create(
    '/api/v1/app-links',
    'PUT',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer {$token}",
    ],
    json_encode($payload)
);

$response = $kernel->handle($request);
$statusCode = $response->getStatusCode();
$content = $response->getContent();

echo json_encode([
    'worker' => $workerId,
    'status' => $statusCode,
    'body' => json_decode($content, true),
]);
