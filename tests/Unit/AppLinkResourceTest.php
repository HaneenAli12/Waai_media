<?php

namespace Tests\Unit;

use App\Http\Resources\AppLinkResource;
use App\Models\AppLink;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AppLinkResourceTest extends TestCase
{
    public function test_resource_transforms_into_clean_three_fields_omitting_internal_metadata(): void
    {
        $appLink = (new AppLink)->setRawAttributes([
            'id' => 1,
            'singleton_lock' => 1,
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id123456789',
            'app_gallery_link' => 'https://appgallery.huawei.com/app/C123456789',
            'created_at' => '2026-10-18 00:00:00',
            'updated_at' => '2026-10-18 00:00:00',
        ], true);

        $resource = new AppLinkResource($appLink);
        $result = $resource->toArray(Request::create('/api/v1/app-links'));

        $expected = [
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
            'apple_app_store_link' => 'https://apps.apple.com/app/waai-media/id123456789',
            'app_gallery_link' => 'https://appgallery.huawei.com/app/C123456789',
        ];

        $this->assertSame($expected, $result);
        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('singleton_lock', $result);
        $this->assertArrayNotHasKey('created_at', $result);
        $this->assertArrayNotHasKey('updated_at', $result);
    }
}
