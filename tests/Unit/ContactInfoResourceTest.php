<?php

namespace Tests\Unit;

use App\Http\Resources\ContactInfoResource;
use App\Models\ContactInfo;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ContactInfoResourceTest extends TestCase
{
    public function test_resource_transforms_into_clean_twelve_fields_omitting_internal_metadata(): void
    {
        $contactInfo = (new ContactInfo)->setRawAttributes([
            'id' => 1,
            'singleton_lock' => 1,
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
            'created_at' => '2026-10-17 00:00:00',
            'updated_at' => '2026-10-17 00:00:00',
        ], true);

        $resource = new ContactInfoResource($contactInfo);
        $result = $resource->toArray(Request::create('/api/v1/contact-info'));

        $expected = [
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

        $this->assertSame($expected, $result);
        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('singleton_lock', $result);
        $this->assertArrayNotHasKey('created_at', $result);
        $this->assertArrayNotHasKey('updated_at', $result);
    }
}
