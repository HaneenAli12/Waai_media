<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

$email = 'haneenelfwal03@gmail.com';
$password = 'Haneen123@';

$user = User::where('email', $email)->first();

if ($user) {
    echo "User already exists with email: {$email}\n";
} else {
    $user = User::create([
        'name' => 'Haneen Elfwal',
        'email' => $email,
        'password' => $password,
        'role' => 'admin',
    ]);
    echo "User created successfully.\n";
}

echo "Role: {$user->role}\n";
echo "Email: {$user->email}\n";

$isPlaintext = $user->password === $password;
$isHashed = Hash::check($password, $user->password);
echo 'Password is hashed: '.($isHashed && ! $isPlaintext ? 'Yes' : 'No')."\n";

// Test authentication
$authSuccess = Auth::attempt(['email' => $email, 'password' => $password]);
echo 'Authentication success: '.($authSuccess ? 'Yes' : 'No')."\n";
