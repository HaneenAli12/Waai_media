<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$email = 'areejelghraz@gmail.com';
$password = 'Secret123!';

$user = User::where('email', $email)->first();

if (! $user) {
    $user = User::create([
        'name' => 'Areej Elghraz',
        'email' => $email,
        'password' => $password,
        'role' => 'admin',
    ]);
    echo "User created successfully.\n";
} else {
    echo "User already exists.\n";
    if ($user->role !== 'admin') {
        $user->update(['role' => 'admin']);
        echo "User role updated to admin.\n";
    }
}

// Verify that the password is NOT stored as plaintext
if ($user->password === $password) {
    echo "ERROR: Password was stored as plaintext!\n";
} else {
    echo "Verified: Password is hashed.\n";
}

// Test login via API
$request = Request::create('/api/v1/auth/login', 'POST', [
    'email' => $email,
    'password' => $password,
]);
$request->headers->set('Accept', 'application/json');

$response = app()->handle($request);

if ($response->getStatusCode() === 200) {
    echo "Login successful.\n";
} else {
    echo 'Login failed. Status code: '.$response->getStatusCode()."\n";
    echo 'Response: '.$response->getContent()."\n";
}
