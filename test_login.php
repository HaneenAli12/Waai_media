<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$request = Request::create('/api/v1/auth/login', 'POST', [
    'email' => 'admin@waaimedia.com',
    'password' => 'Secret123!',
    'device_name' => 'test_device',
]);
$request->headers->set('Accept', 'application/json');
$response = $kernel->handle($request);
echo 'STATUS: '.$response->getStatusCode()."\n";
echo 'BODY: '.$response->getContent()."\n";
