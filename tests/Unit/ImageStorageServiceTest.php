<?php

namespace Tests\Unit;

use App\Services\Storage\Exceptions\ImageUploadException;
use App\Services\Storage\Exceptions\InvalidImageException;
use App\Services\Storage\Exceptions\InvalidStorageKeyException;
use App\Services\Storage\Exceptions\StorageConfigurationException;
use App\Services\Storage\ImageStorageService;
use App\Services\Storage\StoredImage;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelS3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use Tests\TestCase;

class ImageStorageServiceTest extends TestCase
{
    private const DUMMY_PUBLIC_URL = 'https://example.r2.dev';

    /**
     * Minimal valid 1x1 binary image fixtures (base64 encoded) that do not require ext-gd.
     */
    private const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const FIXTURE_1X1_WEBP_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private ImageStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.r2.url' => self::DUMMY_PUBLIC_URL,
            'filesystems.disks.r2.driver' => 's3',
        ]);

        Storage::fake('r2');

        $this->service = new ImageStorageService('r2');
    }

    // =========================================================================
    // 1. Valid JPEG, PNG, and WebP Uploads
    // =========================================================================

    public function test_uploads_valid_jpeg_image_successfully(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_JPEG_BASE64);
        $file = UploadedFile::fake()->createWithContent('sample.jpg', $binary);

        $stored = $this->service->upload($file, 'news/covers/');

        $this->assertInstanceOf(StoredImage::class, $stored);
        $this->assertStringStartsWith('news/covers/', $stored->key);
        $this->assertStringEndsWith('.jpg', $stored->key);
        $this->assertSame('image/jpeg', $stored->mimeType);
        $this->assertSame(strlen($binary), $stored->size);
        $this->assertSame(hash('sha256', $binary), $stored->sha256);
        $this->assertSame(self::DUMMY_PUBLIC_URL.'/'.$stored->key, $stored->url);

        $this->assertTrue(Storage::disk('r2')->exists($stored->key));
        $this->assertSame($binary, Storage::disk('r2')->get($stored->key));
    }

    public function test_uploads_valid_png_image_successfully(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);
        $file = UploadedFile::fake()->createWithContent('logo.png', $binary);

        $stored = $this->service->upload($file, 'clients/logos/');

        $this->assertInstanceOf(StoredImage::class, $stored);
        $this->assertStringStartsWith('clients/logos/', $stored->key);
        $this->assertStringEndsWith('.png', $stored->key);
        $this->assertSame('image/png', $stored->mimeType);
        $this->assertSame(strlen($binary), $stored->size);
        $this->assertSame(hash('sha256', $binary), $stored->sha256);

        $this->assertTrue(Storage::disk('r2')->exists($stored->key));
    }

    public function test_uploads_valid_webp_image_successfully(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_WEBP_BASE64);
        $file = UploadedFile::fake()->createWithContent('event.webp', $binary);

        $stored = $this->service->upload($file, 'events/covers/');

        $this->assertInstanceOf(StoredImage::class, $stored);
        $this->assertStringStartsWith('events/covers/', $stored->key);
        $this->assertStringEndsWith('.webp', $stored->key);
        $this->assertSame('image/webp', $stored->mimeType);
        $this->assertSame(strlen($binary), $stored->size);

        $this->assertTrue(Storage::disk('r2')->exists($stored->key));
    }

    public function test_uploads_raw_binary_string_successfully(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        $stored = $this->service->upload($binary, 'news/editor/');

        $this->assertInstanceOf(StoredImage::class, $stored);
        $this->assertStringStartsWith('news/editor/', $stored->key);
        $this->assertTrue(Storage::disk('r2')->exists($stored->key));
    }

    // =========================================================================
    // 2. MIME Spoofing Rejection
    // =========================================================================

    public function test_rejects_html_content_disguised_with_jpg_extension(): void
    {
        $spoofedContent = '<html><script>alert("xss")</script></html>';
        $file = UploadedFile::fake()->createWithContent('disguised.jpg', $spoofedContent);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage("Unsupported or disguised image format with MIME type 'text/html'");

        $this->service->upload($file, 'news/covers/');
    }

    public function test_rejects_php_script_disguised_with_png_extension(): void
    {
        $spoofedContent = '<?php echo "malicious code"; phpinfo(); ?>';
        $file = UploadedFile::fake()->createWithContent('backdoor.png', $spoofedContent);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('Unsupported or disguised image format');

        $this->service->upload($file, 'news/covers/');
    }

    // =========================================================================
    // 3. Oversized and Empty File Rejection
    // =========================================================================

    public function test_rejects_empty_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('empty.png', '');

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('The provided image file is empty (0 bytes).');

        $this->service->upload($file, 'news/covers/');
    }

    public function test_rejects_file_exceeding_custom_max_size(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);
        $file = UploadedFile::fake()->createWithContent('small.png', $binary);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('exceeds the allowed limit of 10 bytes.');

        // Pass an intentionally small max size of 10 bytes (fixture is ~70 bytes)
        $this->service->upload($file, 'news/covers/', maxSizeBytes: 10);
    }

    public function test_rejects_file_exceeding_default_5mb_limit(): void
    {
        $oversizedService = new ImageStorageService('r2', defaultMaxSizeBytes: 50);
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('exceeds the allowed limit of 50 bytes.');

        $oversizedService->upload($binary, 'news/covers/');
    }

    // =========================================================================
    // 4. UUID Key Generation
    // =========================================================================

    public function test_generates_unique_uuid_v4_keys_for_subsequent_uploads(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        $stored1 = $this->service->upload($binary, 'news/covers/');
        $stored2 = $this->service->upload($binary, 'news/covers/');

        $this->assertNotSame($stored1->key, $stored2->key);

        $uuidRegex = '/^news\/covers\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$/';
        $this->assertMatchesRegularExpression($uuidRegex, $stored1->key);
        $this->assertMatchesRegularExpression($uuidRegex, $stored2->key);
    }

    // =========================================================================
    // 5. Correct Folder Prefixes
    // =========================================================================

    public function test_accepts_all_allowlisted_folder_prefixes(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        foreach (ImageStorageService::ALLOWED_PREFIXES as $prefix) {
            $stored = $this->service->upload($binary, $prefix);
            $this->assertStringStartsWith($prefix, $stored->key);
        }
    }

    public function test_normalizes_prefix_lacking_trailing_slash(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        $stored = $this->service->upload($binary, 'news/covers');

        $this->assertStringStartsWith('news/covers/', $stored->key);
    }

    public function test_rejects_unapproved_directory_prefix(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        $this->expectException(InvalidStorageKeyException::class);
        $this->expectExceptionMessage("Directory prefix 'unauthorized/folder/' is not in the approved storage prefix allowlist.");

        $this->service->upload($binary, 'unauthorized/folder/');
    }

    public function test_rejects_path_traversal_in_prefix(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        $this->expectException(InvalidStorageKeyException::class);

        $this->service->upload($binary, 'news/covers/../../etc/');
    }

    // =========================================================================
    // 6. Correct Content-Type Metadata
    // =========================================================================

    public function test_sets_correct_content_type_in_upload_options(): void
    {
        $mock = new MockHandler([new Result([])]);
        $adapter = $this->createMockedLaravelAdapter($mock);
        Storage::set('r2', $adapter);

        $binary = base64_decode(self::FIXTURE_1X1_WEBP_BASE64);
        $this->service->upload($binary, 'news/covers/');

        $command = $mock->getLastCommand();

        $this->assertSame('PutObject', $command->getName());
        $this->assertSame('image/webp', $command['ContentType']);
    }

    // =========================================================================
    // 7. Public URL Generation
    // =========================================================================

    public function test_resolves_valid_public_url_from_stored_key(): void
    {
        $key = 'news/covers/550e8400-e29b-41d4-a716-446655440000.webp';

        $url = $this->service->getUrl($key);

        $this->assertSame('https://example.r2.dev/news/covers/550e8400-e29b-41d4-a716-446655440000.webp', $url);
    }

    public function test_resolves_url_cleanly_when_base_url_has_trailing_slash(): void
    {
        config(['filesystems.disks.r2.url' => 'https://example.r2.dev/']);

        $key = 'news/covers/550e8400-e29b-41d4-a716-446655440000.webp';
        $url = $this->service->getUrl($key);

        $this->assertSame('https://example.r2.dev/news/covers/550e8400-e29b-41d4-a716-446655440000.webp', $url);
    }

    // =========================================================================
    // 8. Missing or Invalid Public URL Configuration
    // =========================================================================

    public function test_throws_exception_when_public_url_is_null_or_empty(): void
    {
        config(['filesystems.disks.r2.url' => null]);

        $this->expectException(StorageConfigurationException::class);
        $this->expectExceptionMessage("Public delivery URL is not configured for storage disk 'r2'.");

        $this->service->getUrl('news/covers/550e8400-e29b-41d4-a716-446655440000.webp');
    }

    public function test_throws_exception_when_public_url_uses_non_https_protocol(): void
    {
        config(['filesystems.disks.r2.url' => 'http://example.r2.dev']);

        $this->expectException(StorageConfigurationException::class);
        $this->expectExceptionMessage('Public delivery URL must strictly use HTTPS protocol.');

        $this->service->getUrl('news/covers/550e8400-e29b-41d4-a716-446655440000.webp');
    }

    // =========================================================================
    // 9. Prevention of S3 Endpoint URL Fallback
    // =========================================================================

    public function test_rejects_public_url_pointing_to_private_r2_s3_endpoint(): void
    {
        config(['filesystems.disks.r2.url' => 'https://waai-media.account123.r2.cloudflarestorage.com']);

        $this->expectException(StorageConfigurationException::class);
        $this->expectExceptionMessage('Public delivery URL cannot point to the private S3 API endpoint.');

        $this->service->getUrl('news/covers/550e8400-e29b-41d4-a716-446655440000.webp');
    }

    // =========================================================================
    // 10. Upload Failure Handling
    // =========================================================================

    public function test_wraps_storage_upload_failure_in_image_upload_exception(): void
    {
        $mock = new MockHandler;
        $command = new Command('PutObject');
        $mock->append(new S3Exception('Connection timeout to storage', $command, [
            'response' => new Response(500, [], 'InternalError'),
        ]));

        $adapter = $this->createMockedLaravelAdapter($mock);
        Storage::set('r2', $adapter);

        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessage('Failed to store image object at key');

        $this->service->upload($binary, 'news/covers/');
    }

    // =========================================================================
    // 11. Exact-Key Deletion
    // =========================================================================

    public function test_deletes_exact_existing_stored_image(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);
        $stored = $this->service->upload($binary, 'news/covers/');

        $this->assertTrue($this->service->exists($stored->key));

        $deleted = $this->service->delete($stored->key);

        $this->assertTrue($deleted);
        $this->assertFalse($this->service->exists($stored->key));
    }

    // =========================================================================
    // 12. Invalid Deletion Key Rejection
    // =========================================================================

    public function test_rejects_deletion_of_empty_key(): void
    {
        $this->expectException(InvalidStorageKeyException::class);
        $this->expectExceptionMessage('Storage object key cannot be empty.');

        $this->service->delete('');
    }

    public function test_rejects_deletion_with_path_traversal(): void
    {
        $this->expectException(InvalidStorageKeyException::class);
        $this->expectExceptionMessage('Path traversal or invalid characters detected in key');

        $this->service->delete('news/covers/../../passwords.txt');
    }

    public function test_rejects_deletion_of_arbitrary_unauthorized_prefix(): void
    {
        $this->expectException(InvalidStorageKeyException::class);
        $this->expectExceptionMessage('does not start with an authorized prefix.');

        $this->service->delete('arbitrary/prefix/550e8400-e29b-41d4-a716-446655440000.webp');
    }

    public function test_rejects_deletion_of_prefix_without_uuid_filename(): void
    {
        $this->expectException(InvalidStorageKeyException::class);
        $this->expectExceptionMessage('is not a valid UUID v4 with an approved extension');

        $this->service->delete('news/covers/');
    }

    public function test_rejects_deletion_of_non_uuid_filename(): void
    {
        $this->expectException(InvalidStorageKeyException::class);
        $this->expectExceptionMessage('is not a valid UUID v4 with an approved extension');

        $this->service->delete('news/covers/static_photo.png');
    }

    public function test_rejects_deletion_of_external_url(): void
    {
        $this->expectException(InvalidStorageKeyException::class);

        $this->service->delete('https://example.com/malicious.png');
    }

    // =========================================================================
    // 13. Cleanup After Simulated Application Failure
    // =========================================================================

    public function test_compensating_cleanup_deletes_newly_uploaded_image_on_database_failure(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);
        $stored = $this->service->upload($binary, 'news/covers/');

        $this->assertTrue($this->service->exists($stored->key));

        // Simulate database transaction failure
        try {
            throw new \RuntimeException('Database constraint violation on news_articles table');
        } catch (\Throwable) {
            // Caller executes compensating cleanup
            $cleaned = $this->service->safeDelete($stored->key);
            $this->assertTrue($cleaned);
        }

        $this->assertFalse($this->service->exists($stored->key));
    }

    // =========================================================================
    // 14. Cleanup Failure Reporting
    // =========================================================================

    public function test_safe_delete_logs_warning_and_returns_false_when_cleanup_fails(): void
    {
        $mock = new MockHandler;
        $command = new Command('DeleteObject');
        $mock->append(new S3Exception('Network error during delete', $command, [
            'response' => new Response(500, [], 'InternalError'),
        ]));

        $adapter = $this->createMockedLaravelAdapter($mock);
        Storage::set('r2', $adapter);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Compensating storage cleanup failed for object');
            });

        $validKey = 'news/covers/550e8400-e29b-41d4-a716-446655440000.png';
        $result = $this->service->safeDelete($validKey);

        $this->assertFalse($result);
    }

    // =========================================================================
    // 15. No Deletion of Old Images Before Persistence Succeeds
    // =========================================================================

    public function test_does_not_delete_old_image_when_replacement_upload_or_persistence_fails(): void
    {
        $binary = base64_decode(self::FIXTURE_1X1_PNG_BASE64);

        // Initial existing cover image
        $existing = $this->service->upload($binary, 'news/covers/');
        $this->assertTrue($this->service->exists($existing->key));

        // Caller uploads replacement image
        $replacement = $this->service->upload($binary, 'news/covers/');
        $this->assertTrue($this->service->exists($replacement->key));

        // Simulate database update failure
        $dbUpdated = false;
        if (! $dbUpdated) {
            // Compensating transaction: roll back NEW image only
            $this->service->safeDelete($replacement->key);
        }

        // Verify: replacement is gone, but OLD image is preserved intact!
        $this->assertFalse($this->service->exists($replacement->key));
        $this->assertTrue($this->service->exists($existing->key));
    }

    // =========================================================================
    // 16. No Accidental Use of Public-Read ACLs
    // =========================================================================

    public function test_upload_emits_private_acl_and_never_emits_public_read_acl(): void
    {
        $mock = new MockHandler([new Result([])]);
        $adapter = $this->createMockedLaravelAdapter($mock);
        Storage::set('r2', $adapter);

        $binary = base64_decode(self::FIXTURE_1X1_WEBP_BASE64);
        $this->service->upload($binary, 'news/covers/');

        $command = $mock->getLastCommand();
        $request = $mock->getLastRequest();

        $this->assertSame('PutObject', $command->getName());
        $this->assertSame('private', $command['ACL'], 'Standard uploads must default to private ACL.');
        $this->assertNotSame('public-read', $command['ACL']);

        $this->assertTrue($request->hasHeader('x-amz-acl'));
        $this->assertSame('private', $request->getHeaderLine('x-amz-acl'));
        $this->assertNotSame('public-read', $request->getHeaderLine('x-amz-acl'));
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createMockedLaravelAdapter(MockHandler $mock): LaravelS3Adapter
    {
        $s3Config = [
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://example.r2.cloudflarestorage.com',
            'bucket' => 'waai-media',
            'url' => self::DUMMY_PUBLIC_URL,
            'use_path_style_endpoint' => false,
            'credentials' => [
                'key' => 'mock-access-key',
                'secret' => 'mock-secret-key',
            ],
            'handler' => $mock,
        ];

        $client = new S3Client($s3Config);
        $visibilityConverter = new PortableVisibilityConverter;
        $flysystemAdapter = new FlysystemS3Adapter($client, 'waai-media', '', $visibilityConverter);
        $flysystem = new Flysystem($flysystemAdapter, $s3Config);

        return new LaravelS3Adapter(
            $flysystem,
            $flysystemAdapter,
            ['throw' => true] + $s3Config,
            $client
        );
    }
}
