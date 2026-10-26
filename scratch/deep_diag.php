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

function testCase($name, $method, $parameters, $content = null, $contentType = null)
{
    echo "========================================\n";
    echo "TEST: {$name}\n";
    echo "========================================\n";

    $admin = User::where('role', 'admin')->first();
    Sanctum::actingAs($admin, ['access']);

    $music = Music::instance();
    $music->is_enabled = true;
    $music->is_loop = true;
    $music->save();

    echo 'Before update: is_enabled='.json_encode($music->is_enabled).', is_loop='.json_encode($music->is_loop)."\n";

    $server = [
        'HTTP_ACCEPT' => 'application/json',
    ];
    if ($contentType) {
        $server['CONTENT_TYPE'] = $contentType;
    }

    $req = Request::create(
        '/api/v1/admin/background-music',
        $method,
        $parameters,
        [],
        [],
        $server,
        $content
    );

    $response = app()->handle($req);

    echo 'Status: '.$response->getStatusCode()."\n";
    echo 'Response JSON: '.$response->getContent()."\n";

    $fresh = Music::find(1);
    echo 'After update DB: is_enabled='.json_encode($fresh->is_enabled).', is_loop='.json_encode($fresh->is_loop)."\n\n";
}

// Case 1: POST application/x-www-form-urlencoded with 'false'
testCase("Case 1: POST form-data with text 'false'", 'POST', ['is_enabled' => 'false', 'is_loop' => 'false']);

// Case 2: PUT application/x-www-form-urlencoded with 'false'
testCase("Case 2: PUT form-data with text 'false'", 'PUT', ['is_enabled' => 'false', 'is_loop' => 'false']);

// Case 3: PUT with multipart/form-data simulated
// When browsers do fetch('...', { method: 'PUT', body: formData })
$boundary = '----WebKitFormBoundary7MA4YWxkTrZu0gW';
$multipartBody = "--{$boundary}\r\n"
    ."Content-Disposition: form-data; name=\"is_enabled\"\r\n\r\n"
    ."false\r\n"
    ."--{$boundary}\r\n"
    ."Content-Disposition: form-data; name=\"is_loop\"\r\n\r\n"
    ."false\r\n"
    ."--{$boundary}--\r\n";

testCase(
    'Case 3: PUT with actual multipart/form-data body (raw php://input)',
    'PUT',
    [],
    $multipartBody,
    "multipart/form-data; boundary={$boundary}"
);

// Case 4: POST with actual multipart/form-data body
testCase(
    'Case 4: POST with actual multipart/form-data body (raw php://input)',
    'POST',
    [],
    $multipartBody,
    "multipart/form-data; boundary={$boundary}"
);
