<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\AppLinks\UpdateAppLinksRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpdateAppLinksRequestTest extends TestCase
{
    private function getValidPayload(): array
    {
        return [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id123456789',
            'app_gallery_link' => 'https://appgallery.huawei.com/app/C123456789',
        ];
    }

    public function test_authorization_allows_admin_and_editor_roles_and_denies_viewer(): void
    {
        $admin = new User;
        $admin->role = 'admin';

        $editor = new User;
        $editor->role = 'editor';

        $viewer = new User;
        $viewer->role = 'viewer';

        $requestAdmin = new UpdateAppLinksRequest;
        $requestAdmin->setUserResolver(fn () => $admin);
        $this->assertTrue($requestAdmin->authorize());

        $requestEditor = new UpdateAppLinksRequest;
        $requestEditor->setUserResolver(fn () => $editor);
        $this->assertTrue($requestEditor->authorize());

        $requestViewer = new UpdateAppLinksRequest;
        $requestViewer->setUserResolver(fn () => $viewer);
        $this->assertFalse($requestViewer->authorize());

        $requestUnauth = new UpdateAppLinksRequest;
        $requestUnauth->setUserResolver(fn () => null);
        $this->assertFalse($requestUnauth->authorize());
    }

    public function test_normalization_normalizes_empty_and_whitespace_strings_to_null(): void
    {
        $request = new class extends UpdateAppLinksRequest
        {
            public function callPrepareForValidation(): void
            {
                $this->prepareForValidation();
            }
        };

        $request->merge([
            'google_app_link' => '   ',
            'apple_app_store_link' => '',
            'app_gallery_link' => "  \t\n  ",
        ]);

        $request->callPrepareForValidation();

        $this->assertNull($request->input('google_app_link'));
        $this->assertNull($request->input('apple_app_store_link'));
        $this->assertNull($request->input('app_gallery_link'));
    }

    public function test_normalization_does_not_convert_missing_properties_into_present_properties(): void
    {
        $request = new class extends UpdateAppLinksRequest
        {
            public function callPrepareForValidation(): void
            {
                $this->prepareForValidation();
            }
        };

        // Missing app_gallery_link
        $request->merge([
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id123456789',
        ]);

        $request->callPrepareForValidation();

        $this->assertTrue($request->has('google_app_link'));
        $this->assertTrue($request->has('apple_app_store_link'));
        $this->assertFalse($request->has('app_gallery_link'));
    }

    public function test_normalization_preserves_non_string_types(): void
    {
        $request = new class extends UpdateAppLinksRequest
        {
            public function callPrepareForValidation(): void
            {
                $this->prepareForValidation();
            }
        };

        $request->merge([
            'google_app_link' => 12345,
            'apple_app_store_link' => ['an', 'array'],
            'app_gallery_link' => true,
        ]);

        $request->callPrepareForValidation();

        $this->assertSame(12345, $request->input('google_app_link'));
        $this->assertSame(['an', 'array'], $request->input('apple_app_store_link'));
        $this->assertTrue($request->input('app_gallery_link'));
    }

    public function test_validation_passes_with_valid_urls(): void
    {
        $rules = (new UpdateAppLinksRequest)->rules();
        $validator = Validator::make($this->getValidPayload(), $rules);

        $this->assertTrue($validator->passes());
    }

    public function test_validation_passes_with_null_values(): void
    {
        $rules = (new UpdateAppLinksRequest)->rules();
        $validator = Validator::make([
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ], $rules);

        $this->assertTrue($validator->passes());
    }

    public function test_validation_fails_when_field_is_missing(): void
    {
        $rules = (new UpdateAppLinksRequest)->rules();

        // Omit app_gallery_link
        $payload = [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id123456789',
        ];

        $validator = Validator::make($payload, $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('app_gallery_link', $validator->errors()->toArray());
    }

    public function test_validation_fails_with_invalid_urls_and_schemes(): void
    {
        $rules = (new UpdateAppLinksRequest)->rules();

        // Malformed URL
        $validator1 = Validator::make([
            'google_app_link' => 'not-a-valid-url',
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ], $rules);
        $this->assertFalse($validator1->passes());
        $this->assertArrayHasKey('google_app_link', $validator1->errors()->toArray());

        // JavaScript scheme
        $validator2 = Validator::make([
            'google_app_link' => 'javascript:alert(1)',
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ], $rules);
        $this->assertFalse($validator2->passes());
        $this->assertArrayHasKey('google_app_link', $validator2->errors()->toArray());

        // FTP scheme
        $validator3 = Validator::make([
            'google_app_link' => 'ftp://files.example.com',
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ], $rules);
        $this->assertFalse($validator3->passes());
        $this->assertArrayHasKey('google_app_link', $validator3->errors()->toArray());
    }

    public function test_validation_fails_when_url_exceeds_500_characters(): void
    {
        $rules = (new UpdateAppLinksRequest)->rules();

        $longUrl = 'https://play.google.com/store/apps/details?id='.str_repeat('a', 500);

        $validator = Validator::make([
            'google_app_link' => $longUrl,
            'apple_app_store_link' => null,
            'app_gallery_link' => null,
        ], $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('google_app_link', $validator->errors()->toArray());
    }
}
