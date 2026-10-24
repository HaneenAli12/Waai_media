<?php

namespace Tests\Unit;

use App\Models\AppLink;
use PHPUnit\Framework\TestCase;

class AppLinkModelTest extends TestCase
{
    public function test_model_has_strictly_defined_fillable_attributes(): void
    {
        $appLink = new AppLink;

        $expectedFillable = [
            'google_app_link',
            'apple_app_store_link',
            'app_gallery_link',
        ];

        $this->assertSame($expectedFillable, $appLink->getFillable());
    }

    public function test_internal_database_attributes_are_guarded_against_mass_assignment(): void
    {
        $appLink = new AppLink([
            'id' => 999,
            'singleton_lock' => 2,
            'google_app_link' => 'https://play.google.com/store/apps/details?id=com.waaimedia.app',
        ]);

        $this->assertNull($appLink->id);
        $this->assertNull($appLink->singleton_lock);
        $this->assertSame('https://play.google.com/store/apps/details?id=com.waaimedia.app', $appLink->google_app_link);
    }

    public function test_singleton_lock_is_hidden_from_serialization(): void
    {
        $appLink = new AppLink;
        $this->assertContains('singleton_lock', $appLink->getHidden());
    }
}
