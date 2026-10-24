<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

$email = getenv('TEST_LOGIN_EMAIL') ?: 'admin@example.com';
$password = getenv('TEST_LOGIN_PASSWORD') ?: 'Secret123!';

$request = Request::create('/api/v1/auth/login', 'POST', [
    'email' => $email,
    'password' => $password,
    'device_name' => 'test_device',
]);
$request->headers->set('Accept', 'application/json');
$response = $kernel->handle($request);
echo 'STATUS: '.$response->getStatusCode()."\n";
echo 'Response received (credentials masked for security).'."\n";
