<?php

namespace Tests\Unit;

use App\Models\Event;
use Database\Factories\EventFactory;
use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\TestCase;

class EventModelTest extends TestCase
{
    public function test_event_statuses_constants_match_specification(): void
    {
        $this->assertSame('draft', Event::STATUS_DRAFT);
        $this->assertSame('published', Event::STATUS_PUBLISHED);
        $this->assertSame(['draft', 'published'], Event::STATUSES);
    }

    public function test_fillable_attributes_match_expected_columns_and_omit_protected_fields(): void
    {
        $event = new Event;
        $expectedFillable = [
            'name_en',
            'name_ar',
            'slug',
            'category_en',
            'category_ar',
            'description_en',
            'description_ar',
            'place_en',
            'place_ar',
            'event_date',
            'event_time',
            'cover_image_url',
            'url',
            'status',
            'published_at',
        ];

        $this->assertSame($expectedFillable, $event->getFillable());
        $this->assertNotContains('id', $event->getFillable());
        $this->assertNotContains('author_name', $event->getFillable());
        $this->assertNotContains('author_name_en', $event->getFillable());
        $this->assertNotContains('author_name_ar', $event->getFillable());
        $this->assertNotContains('author_id', $event->getFillable());
        $this->assertNotContains('event_category_id', $event->getFillable());
        $this->assertNotContains('created_at', $event->getFillable());
        $this->assertNotContains('updated_at', $event->getFillable());
        $this->assertNotContains('deleted_at', $event->getFillable());
    }

    public function test_casts_properly_cast_event_date_and_published_at(): void
    {
        $event = new Event;
        $casts = $event->getCasts();

        $this->assertSame('date:Y-m-d', $casts['event_date']);
        $this->assertSame('datetime', $casts['published_at']);
    }

    public function test_status_helper_methods_accurately_reflect_event_state(): void
    {
        $draft = new Event(['status' => Event::STATUS_DRAFT, 'published_at' => null]);
        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->isPublished());
        $this->assertFalse($draft->hasBeenPublished());

        $published = new Event;
        $published->setRawAttributes([
            'status' => Event::STATUS_PUBLISHED,
            'published_at' => now(),
        ], true);
        $this->assertFalse($published->isDraft());
        $this->assertTrue($published->isPublished());
        $this->assertTrue($published->hasBeenPublished());
    }

    public function test_event_factory_instantiates_draft_definition_correctly(): void
    {
        $factory = Event::factory();
        $this->assertInstanceOf(EventFactory::class, $factory);

        $raw = $factory->raw();

        $this->assertSame(Event::STATUS_DRAFT, $raw['status']);
        $this->assertNull($raw['slug']);
        $this->assertNull($raw['published_at']);
        $this->assertNull($raw['cover_image_url']);
        $this->assertNotEmpty($raw['name_en']);
        $this->assertNotEmpty($raw['name_ar']);
        $this->assertNotEmpty($raw['category_en']);
        $this->assertNotEmpty($raw['category_ar']);
        $this->assertNotEmpty($raw['place_en']);
        $this->assertNotEmpty($raw['place_ar']);
        $this->assertNotEmpty($raw['event_date']);
        $this->assertNotEmpty($raw['event_time']);
        $this->assertArrayNotHasKey('author_name', $raw);
        $this->assertArrayNotHasKey('author_name_en', $raw);
        $this->assertArrayNotHasKey('author_name_ar', $raw);
        $this->assertArrayNotHasKey('event_category_id', $raw);
    }

    public function test_event_factory_published_state_fulfills_all_publication_invariants(): void
    {
        $factory = Event::factory()->published();
        $raw = $factory->raw();

        $this->assertSame(Event::STATUS_PUBLISHED, $raw['status']);
        $this->assertNotNull($raw['published_at']);
        $this->assertNotNull($raw['slug']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*-\d+$/', $raw['slug']);
        $this->assertStringStartsWith('https://media.waaimedia.com/events/covers/', $raw['cover_image_url']);
        $this->assertNotEmpty($raw['name_en']);
        $this->assertNotEmpty($raw['name_ar']);
        $this->assertNotEmpty($raw['category_en']);
        $this->assertNotEmpty($raw['category_ar']);
        $this->assertNotEmpty($raw['place_en']);
        $this->assertNotEmpty($raw['place_ar']);
        $this->assertNotEmpty($raw['event_date']);
        $this->assertNotEmpty($raw['event_time']);
    }

    public function test_event_factory_upcoming_and_past_states_generate_correct_dates(): void
    {
        $upcoming = Event::factory()->upcoming(10)->raw();
        $this->assertGreaterThan(now()->format('Y-m-d'), $upcoming['event_date']);

        $past = Event::factory()->past(10)->raw();
        $this->assertLessThan(now()->format('Y-m-d'), $past['event_date']);
    }

    public function test_migration_file_exists_and_returns_anonymous_migration(): void
    {
        $migrationFile = 'c:/Projects/Waai_media/database/migrations/2026_10_24_000001_create_events_table.php';
        $this->assertFileExists($migrationFile);

        $migration = require $migrationFile;
        $this->assertInstanceOf(Migration::class, $migration);
    }
}
