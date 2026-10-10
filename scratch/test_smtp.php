<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Mail\Auth\PasswordResetOtpMail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;

try {
    Mail::to('haneenelfwal03@gmail.com')->send(new PasswordResetOtpMail('1234'));
    echo "SMTP email sent successfully!\n";
} catch (Exception $e) {
    echo 'SMTP email failed: '.$e->getMessage()."\n";
}
