<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ContactInfo\UpdateContactInfoRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpdateContactInfoRequestTest extends TestCase
{
    private function getValidPayload(): array
    {
        return [
            'phone_1' => '+201000000000',
            'phone_2' => '+201100000000',
            'phone_3' => null,
            'official_email' => 'info@example.com',
            'facebook' => 'https://facebook.com/example',
            'instagram' => 'https://instagram.com/example',
            'youtube' => 'https://youtube.com/@example',
            'linkedin' => 'https://linkedin.com/company/example',
            'tiktok' => 'https://tiktok.com/@example',
            'whatsapp' => 'https://wa.me/201000000000',
            'complete_address' => 'Damietta, Egypt',
            'google_maps_url' => 'https://maps.app.goo.gl/example123',
        ];
    }

    public function test_authorization_allows_admin_and_editor_roles(): void
    {
        $admin = new User;
        $admin->role = 'admin';

        $editor = new User;
        $editor->role = 'editor';

        $viewer = new User;
        $viewer->role = 'viewer';

        $requestAdmin = new UpdateContactInfoRequest;
        $requestAdmin->setUserResolver(fn () => $admin);
        $this->assertTrue($requestAdmin->authorize());

        $requestEditor = new UpdateContactInfoRequest;
        $requestEditor->setUserResolver(fn () => $editor);
        $this->assertTrue($requestEditor->authorize());

        $requestViewer = new UpdateContactInfoRequest;
        $requestViewer->setUserResolver(fn () => $viewer);
        $this->assertFalse($requestViewer->authorize());

        $requestUnauth = new UpdateContactInfoRequest;
        $requestUnauth->setUserResolver(fn () => null);
        $this->assertFalse($requestUnauth->authorize());
    }

    public function test_safe_phone_normalization_normalizes_empty_and_whitespace_strings_to_null(): void
    {
        $request = new class extends UpdateContactInfoRequest
        {
            public function invokePrepareForValidation(): void
            {
                $this->prepareForValidation();
            }
        };

        $request->merge([
            'phone_2' => '   ',
            'phone_3' => '',
        ]);

        $request->invokePrepareForValidation();

        $this->assertNull($request->input('phone_2'));
        $this->assertNull($request->input('phone_3'));
    }

    public function test_safe_phone_normalization_trims_valid_strings(): void
    {
        $request = new class extends UpdateContactInfoRequest
        {
            public function invokePrepareForValidation(): void
            {
                $this->prepareForValidation();
            }
        };

        $request->merge([
            'phone_2' => '  +201100000000  ',
            'phone_3' => ' +201200000000 ',
        ]);

        $request->invokePrepareForValidation();

        $this->assertSame('+201100000000', $request->input('phone_2'));
        $this->assertSame('+201200000000', $request->input('phone_3'));
    }

    public function test_safe_phone_normalization_preserves_non_strings_without_type_error(): void
    {
        $request = new class extends UpdateContactInfoRequest
        {
            public function invokePrepareForValidation(): void
            {
                $this->prepareForValidation();
            }
        };

        $request->merge([
            'phone_2' => ['invalid', 'array'],
            'phone_3' => 123456,
        ]);

        // Must not throw TypeError
        $request->invokePrepareForValidation();

        $this->assertSame(['invalid', 'array'], $request->input('phone_2'));
        $this->assertSame(123456, $request->input('phone_3'));
    }

    public function test_valid_payload_passes_validation(): void
    {
        $request = new UpdateContactInfoRequest;
        $validator = Validator::make($this->getValidPayload(), $request->rules(), $request->messages());

        $this->assertTrue($validator->passes());
    }

    public function test_missing_required_fields_fails_validation(): void
    {
        $request = new UpdateContactInfoRequest;
        $requiredFields = [
            'phone_1',
            'official_email',
            'facebook',
            'instagram',
            'youtube',
            'linkedin',
            'tiktok',
            'whatsapp',
            'complete_address',
            'google_maps_url',
        ];

        foreach ($requiredFields as $field) {
            $payload = $this->getValidPayload();
            unset($payload[$field]);

            $validator = Validator::make($payload, $request->rules(), $request->messages());
            $this->assertTrue($validator->fails(), "Validation unexpectedly passed when [{$field}] was missing.");
            $this->assertArrayHasKey($field, $validator->errors()->toArray());
        }
    }

    public function test_flexible_url_validation_accepts_valid_schemes_and_short_links(): void
    {
        $request = new UpdateContactInfoRequest;
        $validUrls = [
            'https://facebook.com/company',
            'http://instagram.com/profile',
            'https://youtu.be/abc123xyz',
            'https://maps.app.goo.gl/short123',
            'https://goo.gl/maps/short456',
            'https://wa.me/201000000000?text=Hello%20World',
            'https://maps.google.com/?q=31.4175,31.8144&z=15#location',
        ];

        foreach ($validUrls as $url) {
            $payload = $this->getValidPayload();
            $payload['google_maps_url'] = $url;
            $payload['facebook'] = $url;

            $validator = Validator::make($payload, $request->rules(), $request->messages());
            $this->assertTrue($validator->passes(), "Validation unexpectedly failed for URL: [{$url}]");
        }
    }

    public function test_url_validation_rejects_unsupported_schemes_and_plain_text(): void
    {
        $request = new UpdateContactInfoRequest;
        $invalidUrls = [
            'javascript:alert(1)',
            'file:///etc/passwd',
            'plain text not a url',
            'ftp://files.example.com',
            '//missing-scheme.com',
        ];

        foreach ($invalidUrls as $url) {
            $payload = $this->getValidPayload();
            $payload['facebook'] = $url;

            $validator = Validator::make($payload, $request->rules(), $request->messages());
            $this->assertTrue($validator->fails(), "Validation unexpectedly passed for invalid URL: [{$url}]");
            $this->assertArrayHasKey('facebook', $validator->errors()->toArray());
        }
    }

    public function test_email_validation_rejects_invalid_email_format(): void
    {
        $request = new UpdateContactInfoRequest;
        $payload = $this->getValidPayload();
        $payload['official_email'] = 'not-an-email';

        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('official_email', $validator->errors()->toArray());
    }

    public function test_phone_validation_rejects_invalid_characters(): void
    {
        $request = new UpdateContactInfoRequest;
        $invalidPhones = [
            'abc12345',
            '+20100@#$000',
            'phone-number',
            '12', // too short (< 5)
        ];

        foreach ($invalidPhones as $phone) {
            $payload = $this->getValidPayload();
            $payload['phone_1'] = $phone;

            $validator = Validator::make($payload, $request->rules(), $request->messages());
            $this->assertTrue($validator->fails(), "Validation unexpectedly passed for invalid phone: [{$phone}]");
            $this->assertArrayHasKey('phone_1', $validator->errors()->toArray());
        }
    }

    public function test_phone_validation_rejects_non_string_types(): void
    {
        $request = new UpdateContactInfoRequest;
        $payload = $this->getValidPayload();
        $payload['phone_2'] = ['invalid', 'array'];

        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('phone_2', $validator->errors()->toArray());
    }
}
