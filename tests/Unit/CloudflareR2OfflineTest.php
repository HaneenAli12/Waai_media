<?php

namespace Tests\Unit;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelS3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Tests\TestCase;

class CloudflareR2OfflineTest extends TestCase
{
    /**
     * Dummy public URL used strictly for offline testing to avoid leaking real endpoints.
     */
    private const DUMMY_PUBLIC_URL = 'https://example.r2.dev';

    /**
     * Minimal valid 1x1 binary image fixtures (base64 encoded) that do not require ext-gd.
     */
    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const FIXTURE_1X1_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    // =========================================================================
    // 1. Disk Registration & Environment Mapping Tests
    // =========================================================================

    public function test_r2_disk_is_registered_in_filesystem_manager(): void
    {
        $disks = config('filesystems.disks');

        $this->assertArrayHasKey('r2', $disks);
        $this->assertSame('s3', $disks['r2']['driver']);
    }

    public function test_r2_disk_resolves_to_laravel_aws_s3_adapter_without_network_call(): void
    {
        $disk = Storage::disk('r2');

        $this->assertInstanceOf(LaravelS3Adapter::class, $disk);
        $this->assertInstanceOf(FlysystemS3Adapter::class, $disk->getAdapter());
        $this->assertInstanceOf(S3Client::class, $disk->getClient());
    }

    public function test_default_filesystem_disk_remains_local(): void
    {
        $this->assertSame('local', config('filesystems.default'));
    }

    public function test_existing_disks_remain_registered(): void
    {
        $disks = config('filesystems.disks');

        $this->assertArrayHasKey('local', $disks);
        $this->assertArrayHasKey('public', $disks);
        $this->assertArrayHasKey('s3', $disks);
    }

    public function test_r2_disk_configuration_attributes(): void
    {
        $r2Config = config('filesystems.disks.r2');

        $this->assertSame('s3', $r2Config['driver']);
        $this->assertTrue($r2Config['throw'], 'Throw must be enabled for R2 to allow transactional failure handling.');
        $this->assertFalse($r2Config['report'], 'Report must be false to prevent leaking AWS credentials in traces.');
        $this->assertArrayNotHasKey('visibility', $r2Config, 'Visibility must not be preconfigured to avoid forced ACL headers.');
    }

    // =========================================================================
    // 2. Public URL Generation Tests
    // =========================================================================

    public function test_public_url_generation_for_standard_cover_key(): void
    {
        config(['filesystems.disks.r2.url' => self::DUMMY_PUBLIC_URL]);
        Storage::forgetDisk('r2');

        $url = Storage::disk('r2')->url('news/covers/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.webp');

        $this->assertSame('https://example.r2.dev/news/covers/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.webp', $url);
    }

    public function test_public_url_generation_for_nested_editor_key(): void
    {
        config(['filesystems.disks.r2.url' => self::DUMMY_PUBLIC_URL]);
        Storage::forgetDisk('r2');

        $url = Storage::disk('r2')->url('news/editor/550e8400-e29b-41d4-a716-446655440000.png');

        $this->assertSame('https://example.r2.dev/news/editor/550e8400-e29b-41d4-a716-446655440000.png', $url);
    }

    public function test_public_url_normalizes_leading_slash_in_object_key(): void
    {
        config(['filesystems.disks.r2.url' => self::DUMMY_PUBLIC_URL]);
        Storage::forgetDisk('r2');

        $url = Storage::disk('r2')->url('/news/covers/sample.webp');

        $this->assertSame('https://example.r2.dev/news/covers/sample.webp', $url);
    }

    public function test_public_url_normalizes_trailing_slash_in_configured_base_url(): void
    {
        config(['filesystems.disks.r2.url' => 'https://example.r2.dev/']);
        Storage::forgetDisk('r2');

        $url = Storage::disk('r2')->url('news/covers/sample.webp');

        $this->assertSame('https://example.r2.dev/news/covers/sample.webp', $url);
    }

    public function test_public_url_does_not_encode_spaces_documenting_existing_adapter_limitation(): void
    {
        config(['filesystems.disks.r2.url' => self::DUMMY_PUBLIC_URL]);
        Storage::forgetDisk('r2');

        $url = Storage::disk('r2')->url('news/covers/my cover image.png');

        // Documents that Laravel concatPathToUrl does not rawurlencode keys.
        // Therefore, keys MUST be normalized (UUIDs) before storage.
        $this->assertSame('https://example.r2.dev/news/covers/my cover image.png', $url);
    }

    public function test_missing_or_null_public_url_reveals_unsafe_fallback_to_s3_endpoint(): void
    {
        config([
            'filesystems.disks.r2.url' => null,
            'filesystems.disks.r2.endpoint' => 'https://dummy-account.r2.cloudflarestorage.com',
            'filesystems.disks.r2.bucket' => 'test-bucket',
        ]);
        Storage::forgetDisk('r2');

        $url = Storage::disk('r2')->url('news/covers/sample.webp');

        // Confirms that when 'url' is null, Laravel's S3 adapter automatically and unsafely
        // falls back to generating a private S3 endpoint URL containing the bucket and account.
        $this->assertStringContainsString('r2.cloudflarestorage.com', $url);
        $this->assertStringContainsString('test-bucket', $url);
    }

    public function test_empty_string_public_url_produces_root_relative_path(): void
    {
        config(['filesystems.disks.r2.url' => '']);
        Storage::forgetDisk('r2');

        $url = Storage::disk('r2')->url('news/covers/sample.webp');

        $this->assertSame('/news/covers/sample.webp', $url);
    }

    // =========================================================================
    // 3. SDK Request Construction & ACL Inspection (Mocked Offline)
    // =========================================================================

    public function test_mocked_upload_without_explicit_visibility_emits_private_acl_header(): void
    {
        $mock = new MockHandler;
        $mock->append(new Result([]));

        $adapter = $this->createMockedLaravelAdapter($mock);
        $adapter->put('news/covers/sample.webp', 'fake-binary-content');

        $command = $mock->getLastCommand();
        $request = $mock->getLastRequest();

        $this->assertSame('PutObject', $command->getName());
        $this->assertSame('private', $command['ACL'], 'Standard Flysystem S3 adapter defaults to private ACL.');
        $this->assertTrue($request->hasHeader('x-amz-acl'));
        $this->assertSame('private', $request->getHeaderLine('x-amz-acl'));
    }

    public function test_mocked_upload_with_explicit_private_visibility_emits_private_acl(): void
    {
        $mock = new MockHandler;
        $mock->append(new Result([]));

        $adapter = $this->createMockedLaravelAdapter($mock);
        $adapter->put('news/covers/sample.webp', 'fake-binary-content', ['visibility' => Visibility::PRIVATE]);

        $command = $mock->getLastCommand();
        $request = $mock->getLastRequest();

        $this->assertSame('private', $command['ACL']);
        $this->assertSame('private', $request->getHeaderLine('x-amz-acl'));
    }

    public function test_mocked_upload_with_explicit_public_visibility_emits_unsupported_public_read_acl(): void
    {
        $mock = new MockHandler;
        $mock->append(new Result([]));

        $adapter = $this->createMockedLaravelAdapter($mock);
        $adapter->put('news/covers/sample.webp', 'fake-binary-content', ['visibility' => Visibility::PUBLIC]);

        $command = $mock->getLastCommand();
        $request = $mock->getLastRequest();

        // Proves that storePublicly() or visibility => public sends x-amz-acl: public-read,
        // which must be strictly avoided on Cloudflare R2.
        $this->assertSame('public-read', $command['ACL']);
        $this->assertSame('public-read', $request->getHeaderLine('x-amz-acl'));
    }

    public function test_mocked_request_respects_virtual_hosted_endpoint_style(): void
    {
        $mock = new MockHandler;
        $mock->append(new Result([]));

        $adapter = $this->createMockedLaravelAdapter($mock, usePathStyle: false);
        $adapter->put('news/covers/test.webp', 'data');

        $request = $mock->getLastRequest();

        $this->assertSame('dummy-bucket.example.r2.cloudflarestorage.com', $request->getUri()->getHost());
        $this->assertSame('/news/covers/test.webp', $request->getUri()->getPath());
    }

    public function test_mocked_request_respects_path_style_endpoint_when_enabled(): void
    {
        $mock = new MockHandler;
        $mock->append(new Result([]));

        $adapter = $this->createMockedLaravelAdapter($mock, usePathStyle: true);
        $adapter->put('news/covers/test.webp', 'data');

        $request = $mock->getLastRequest();

        $this->assertSame('example.r2.cloudflarestorage.com', $request->getUri()->getHost());
        $this->assertSame('/dummy-bucket/news/covers/test.webp', $request->getUri()->getPath());
    }

    // =========================================================================
    // 4. Failure Handling Tests (Mocked S3 Errors)
    // =========================================================================

    public function test_mocked_access_denied_403_throws_unable_to_write_file_exception(): void
    {
        $mock = new MockHandler;
        $command = new Command('PutObject');
        $mock->append(new S3Exception('Access Denied', $command, [
            'response' => new Response(403, [], 'AccessDenied'),
        ]));

        $adapter = $this->createMockedLaravelAdapter($mock);

        $this->expectException(UnableToWriteFile::class);
        $this->expectExceptionMessage('Access Denied');

        $adapter->put('news/covers/forbidden.webp', 'content');
    }

    public function test_mocked_no_such_bucket_404_throws_unable_to_write_file_exception(): void
    {
        $mock = new MockHandler;
        $command = new Command('PutObject');
        $mock->append(new S3Exception('Bucket not found', $command, [
            'response' => new Response(404, [], 'NoSuchBucket'),
        ]));

        $adapter = $this->createMockedLaravelAdapter($mock);

        $this->expectException(UnableToWriteFile::class);
        $this->expectExceptionMessage('Bucket not found');

        $adapter->put('news/covers/missing-bucket.webp', 'content');
    }

    public function test_mocked_network_timeout_throws_unable_to_write_file_exception(): void
    {
        $mock = new MockHandler;
        $req = new Request('PUT', 'https://example.r2.cloudflarestorage.com');
        $mock->append(new ConnectException('Connection timed out after 5000ms', $req));

        $adapter = $this->createMockedLaravelAdapter($mock);

        $this->expectException(UnableToWriteFile::class);
        $this->expectExceptionMessage('Connection timed out after 5000ms');

        $adapter->put('news/covers/timeout.webp', 'content');
    }

    public function test_mocked_deletion_failure_throws_unable_to_delete_file_exception(): void
    {
        $mock = new MockHandler;
        $command = new Command('DeleteObject');
        $mock->append(new S3Exception('Delete forbidden', $command, [
            'response' => new Response(403, [], 'AccessDenied'),
        ]));

        $adapter = $this->createMockedLaravelAdapter($mock);

        $this->expectException(UnableToDeleteFile::class);

        $adapter->delete('news/covers/undeletable.webp');
    }

    public function test_mocked_read_failure_throws_unable_to_read_file_exception(): void
    {
        $mock = new MockHandler;
        $command = new Command('GetObject');
        $mock->append(new S3Exception('Read failed', $command, [
            'response' => new Response(500, [], 'InternalError'),
        ]));

        $adapter = $this->createMockedLaravelAdapter($mock);

        $this->expectException(UnableToReadFile::class);

        $adapter->get('news/covers/broken.webp');
    }

    // =========================================================================
    // 5. GD-Independent Image Fixture Validation Tests
    // =========================================================================

    public function test_valid_minimal_jpeg_binary_fixture_is_detected_by_fileinfo(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_JPEG_BASE64);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $this->assertSame('image/jpeg', $finfo->buffer($binary));
    }

    public function test_valid_minimal_png_binary_fixture_is_detected_by_fileinfo(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $this->assertSame('image/png', $finfo->buffer($binary));
    }

    public function test_valid_minimal_webp_binary_fixture_is_detected_by_fileinfo(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_WEBP_BASE64);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $this->assertSame('image/webp', $finfo->buffer($binary));
    }

    public function test_uploaded_file_fixture_validates_successfully_without_gd_extension(): void
    {
        $jpegBinary = base64_decode(self::FIXTURE_1X1_JPEG_BASE64);
        $file = UploadedFile::fake()->createWithContent('cover.jpg', $jpegBinary);

        $validator = Validator::make(
            ['cover_image' => $file],
            ['cover_image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120']]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_disguised_text_file_binary_is_detected_as_html_not_image(): void
    {
        $binary = '<html><script>alert(1)</script></html>';
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $this->assertSame('text/html', $finfo->buffer($binary));
        $this->assertNotSame('image/jpeg', $finfo->buffer($binary));
    }

    public function test_uploaded_file_rejects_disguised_text_file_with_jpg_extension(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'test_img_');
        file_put_contents($temp, '<html><script>alert(1)</script></html>');
        $file = new UploadedFile($temp, 'disguised.jpg', 'text/html', null, true);

        $validator = Validator::make(
            ['cover_image' => $file],
            ['cover_image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120']]
        );

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('cover_image', $validator->errors()->toArray());

        @unlink($temp);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createMockedLaravelAdapter(MockHandler $mock, bool $usePathStyle = false): LaravelS3Adapter
    {
        $s3Config = [
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://example.r2.cloudflarestorage.com',
            'bucket' => 'dummy-bucket',
            'url' => self::DUMMY_PUBLIC_URL,
            'use_path_style_endpoint' => $usePathStyle,
            'credentials' => [
                'key' => 'mock-access-key',
                'secret' => 'mock-secret-key',
            ],
            'handler' => $mock,
        ];

        $client = new S3Client($s3Config);
        $visibilityConverter = new PortableVisibilityConverter;
        $flysystemAdapter = new FlysystemS3Adapter($client, 'dummy-bucket', '', $visibilityConverter);
        $flysystem = new Flysystem($flysystemAdapter, $s3Config);

        return new LaravelS3Adapter(
            $flysystem,
            $flysystemAdapter,
            ['throw' => true] + $s3Config,
            $client
        );
    }
}
