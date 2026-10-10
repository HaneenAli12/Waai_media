<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

// Create a mock request
$request = Request::create('/api/v1/auth/forgot-password', 'POST', [
    'email' => 'areejelghraz@gmail.com',
]);
$request->headers->set('Accept', 'application/json');

// Handle the request through the kernel
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);

echo 'Status: '.$response->getStatusCode()."\n";
echo 'Content: '.$response->getContent()."\n";
