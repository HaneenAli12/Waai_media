<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$cases = [
    'bool_false' => false,
    'bool_true' => true,
    'str_false' => 'false',
    'str_true' => 'true',
    'str_0' => '0',
    'str_1' => '1',
    'str_off' => 'off',
    'str_no' => 'no',
    'int_0' => 0,
    'int_1' => 1,
    'null' => null,
    'empty_str' => '',
];

echo "Testing Request::boolean() and FormRequest validation:\n\n";

foreach ($cases as $name => $val) {
    $req = new Request;
    $req->initialize(['is_enabled' => $val]);

    $booleanResult = $req->boolean('is_enabled');
    $hasResult = $req->has('is_enabled');
    $filledResult = $req->filled('is_enabled');

    echo sprintf(
        "Input [%-10s]: has=%s, filled=%s, boolean()=%s\n",
        $name,
        $hasResult ? 'true' : 'false',
        $filledResult ? 'true' : 'false',
        $booleanResult ? 'TRUE' : 'FALSE'
    );
}
