<?php

use Illuminate\Support\Facades\Storage;

$disk = Storage::disk('r2');
$key = 'r2-test/'.bin2hex(random_bytes(8)).'.txt';
$content = 'Waai Media R2 connection test';

try {
    $disk->put($key, $content);

    if (! $disk->exists($key)) {
        throw new RuntimeException('Upload returned, but file was not found.');
    }

    $downloaded = $disk->get($key);

    if ($downloaded !== $content) {
        throw new RuntimeException('Downloaded content does not match.');
    }

    echo "R2_UPLOAD_OK\n";
    echo "R2_READ_OK\n";
    echo "KEY={$key}\n";

    try {
        echo 'URL='.$disk->url($key)."\n";
    } catch (Throwable $e) {
        echo "URL_GENERATION_FAILED\n";
    }

    if ($disk->delete($key)) {
        echo "R2_DELETE_OK\n";
    } else {
        echo "R2_DELETE_FAILED\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "R2_TEST_FAILED\n");
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
