<?php

use App\Models\ContactInfo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

// Strict Safety Guard Check
$connection = DB::connection();
TestCase::enforceDatabaseSafety($connection);

echo "===============================================================\n";
echo "CONCURRENCY TEST: Two Independent Processes against waai_media_testing\n";
echo 'Verified Connection DB: '.$connection->getDatabaseName()."\n";
echo "===============================================================\n\n";

if ($connection->getDatabaseName() !== TestCase::PERMITTED_TEST_DATABASE) {
    echo "ABORT: Unsafe database name detected!\n";
    exit(1);
}

// 1. Ensure testing database tables are migrated
Artisan::call('migrate', [
    '--database' => 'mysql',
    '--force' => true,
]);

// 2. Clear ContactInfo on testing database
DB::table('contact_info')->delete();
$initialCount = ContactInfo::count();
echo "Initial contact_info count in waai_media_testing: {$initialCount}\n";

if ($initialCount !== 0) {
    echo "ERROR: Table was not empty before concurrency test!\n";
    exit(1);
}

// 3. Launch Process 1 and Process 2 simultaneously
$cmd1 = 'php '.__DIR__.'/concurrency_worker.php 1 worker_1@waaimedia.com +201000000001';
$cmd2 = 'php '.__DIR__.'/concurrency_worker.php 2 worker_2@waaimedia.com +201000000002';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

echo "Launching Process 1: {$cmd1}\n";
echo "Launching Process 2: {$cmd2}\n";

$start = microtime(true);

$p1 = proc_open($cmd1, $descriptors, $pipes1, __DIR__.'/..');
$p2 = proc_open($cmd2, $descriptors, $pipes2, __DIR__.'/..');

if (! is_resource($p1) || ! is_resource($p2)) {
    echo "ERROR: Failed to spawn child processes!\n";
    exit(1);
}

// Close child stdin
fclose($pipes1[0]);
fclose($pipes2[0]);

// Read child stdout
$out1 = stream_get_contents($pipes1[1]);
$err1 = stream_get_contents($pipes1[2]);
fclose($pipes1[1]);
fclose($pipes1[2]);

$out2 = stream_get_contents($pipes2[1]);
$err2 = stream_get_contents($pipes2[2]);
fclose($pipes2[1]);
fclose($pipes2[2]);

$status1 = proc_close($p1);
$status2 = proc_close($p2);

$duration = round((microtime(true) - $start) * 1000, 2);

echo "\nCompleted parallel execution in {$duration} ms.\n";

echo "Process 1 Exit Code: {$status1}\n";
echo "Process 1 Output: {$out1}\n";
if ($err1) {
    echo "Process 1 Stderr: {$err1}\n";
}

echo "Process 2 Exit Code: {$status2}\n";
echo "Process 2 Output: {$out2}\n";
if ($err2) {
    echo "Process 2 Stderr: {$err2}\n";
}

// 4. Verify Final State in waai_media_testing
$finalCount = ContactInfo::count();
$record = ContactInfo::instance();

echo "\n--- VERIFICATION OF TESTING DATABASE ---\n";
echo "Final contact_info record count: {$finalCount}\n";

if ($finalCount === 1 && $record !== null) {
    echo "SUCCESS: Exactly one singleton record exists!\n";
    echo "Singleton ID: {$record->id}\n";
    echo "Singleton Lock: {$record->singleton_lock}\n";
    echo "Saved Email: {$record->official_email}\n";
    echo "Saved Phone: {$record->phone_1}\n";
    echo "Saved Address: {$record->complete_address}\n";
    echo 'All 12 fields populated: '.(! empty($record->google_maps_url) && ! empty($record->facebook) ? 'YES' : 'NO')."\n";
} else {
    echo "FAILED: Count is {$finalCount}, expected 1.\n";
    exit(1);
}

$res1 = json_decode($out1, true);
$res2 = json_decode($out2, true);

if (($res1['status'] ?? 0) === 200 && ($res2['status'] ?? 0) === 200) {
    echo "SUCCESS: Both concurrent processes returned HTTP 200 OK! Zero 500 errors.\n";
} else {
    echo "FAILED: One or more processes did not return HTTP 200.\n";
    exit(1);
}
