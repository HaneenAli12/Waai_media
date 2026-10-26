<?php

use App\Models\Music;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$admin = User::where('role', 'admin')->first();
if (! $admin) {
    echo "No admin user found\n";
    exit(1);
}

Sanctum::actingAs($admin, ['access']);

$music = Music::instance();
$music->is_enabled = true;
$music->is_loop = true;
$music->save();

echo 'Initial DB values: is_enabled='.json_encode($music->is_enabled).', is_loop='.json_encode($music->is_loop)."\n";

// Scenario 1: POST form-data with text 'false'
$request1 = Request::create(
    '/api/v1/admin/background-music',
    'POST',
    ['is_enabled' => 'false', 'is_loop' => 'false'],
    [],
    [],
    ['HTTP_ACCEPT' => 'application/json']
);
$response1 = $app->handle($request1);
echo "Scenario 1 (POST 'false' strings):\n";
echo 'Status: '.$response1->getStatusCode()."\n";
echo 'Response body: '.$response1->getContent()."\n";

$fresh1 = Music::find(1);
echo 'DB after scenario 1: is_enabled='.json_encode($fresh1->is_enabled).', is_loop='.json_encode($fresh1->is_loop)."\n\n";

// Scenario 2: What about PUT with form-data? Note: In PHP/Symfony, PUT does not parse multipart/form-data by default!
// Let's test PUT with simulated php://input or parameters
$request2 = Request::create(
    '/api/v1/admin/background-music',
    'PUT',
    ['is_enabled' => 'false', 'is_loop' => 'false'],
    [],
    [],
    ['HTTP_ACCEPT' => 'application/json']
);
$response2 = $app->handle($request2);
echo "Scenario 2 (PUT 'false' strings via request parameters):\n";
echo 'Status: '.$response2->getStatusCode()."\n";
echo 'Response body: '.$response2->getContent()."\n";
