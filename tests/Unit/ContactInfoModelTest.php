<?php

namespace Tests\Unit;

use App\Models\ContactInfo;
use PHPUnit\Framework\TestCase;

class ContactInfoModelTest extends TestCase
{
    public function test_model_has_strictly_defined_fillable_attributes(): void
    {
        $contactInfo = new ContactInfo;

        $expectedFillable = [
            'phone_1',
            'phone_2',
            'phone_3',
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

        $this->assertSame($expectedFillable, $contactInfo->getFillable());
    }

    public function test_internal_database_attributes_are_guarded_against_mass_assignment(): void
    {
        $contactInfo = new ContactInfo([
            'id' => 999,
            'singleton_lock' => 2,
            'phone_1' => '+201000000000',
        ]);

        $this->assertNull($contactInfo->id);
        $this->assertNull($contactInfo->singleton_lock);
        $this->assertSame('+201000000000', $contactInfo->phone_1);
    }

    public function test_singleton_lock_is_hidden_from_serialization(): void
    {
        $contactInfo = new ContactInfo;
        $this->assertContains('singleton_lock', $contactInfo->getHidden());
    }
}
