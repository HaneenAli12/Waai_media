<?php

namespace Tests\Unit\Events;

use App\Services\Events\EventDateTimeService;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EventDateTimeServiceTest extends TestCase
{
    private EventDateTimeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventDateTimeService;
    }

    public function test_is_valid_timezone_identifies_valid_iana_identifiers(): void
    {
        $this->assertTrue($this->service->isValidTimezone('Africa/Cairo'));
        $this->assertTrue($this->service->isValidTimezone('Asia/Riyadh'));
        $this->assertTrue($this->service->isValidTimezone('UTC'));
        $this->assertTrue($this->service->isValidTimezone('America/New_York'));
        $this->assertTrue($this->service->isValidTimezone('Europe/London'));

        $this->assertFalse($this->service->isValidTimezone(''));
        $this->assertFalse($this->service->isValidTimezone('   '));
        $this->assertFalse($this->service->isValidTimezone(null));
        $this->assertFalse($this->service->isValidTimezone('Invalid/Zone'));
        $this->assertFalse($this->service->isValidTimezone('GMT+2'));
    }

    public function test_strip_offset_removes_iso_offsets_and_subseconds(): void
    {
        $this->assertSame('2026-11-15 18:00:00', $this->service->stripOffset('2026-11-15T18:00:00Z'));
        $this->assertSame('2026-11-15 18:00:00', $this->service->stripOffset('2026-11-15T18:00:00+02:00'));
        $this->assertSame('2026-11-15 18:00:00', $this->service->stripOffset('2026-11-15T18:00:00.000Z'));
        $this->assertSame('2026-11-15 18:00:00', $this->service->stripOffset('2026-11-15 18:00:00'));
    }

    public function test_parse_local_to_utc_converts_cairo_winter_time_correctly(): void
    {
        // In November (winter), Cairo is UTC+2
        $utc = $this->service->parseLocalToUtc('2026-11-15 18:00:00', 'Africa/Cairo');

        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('2026-11-15 16:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_parse_local_to_utc_converts_riyadh_time_correctly(): void
    {
        // Riyadh is perpetually UTC+3
        $utc = $this->service->parseLocalToUtc('2026-11-15 18:00:00', 'Asia/Riyadh');

        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('2026-11-15 15:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_parse_local_to_utc_converts_new_york_winter_time_correctly(): void
    {
        // In November, New York is EST (UTC-5)
        $utc = $this->service->parseLocalToUtc('2026-11-15 18:00:00', 'America/New_York');

        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('2026-11-15 23:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_parse_local_to_utc_throws_exception_on_invalid_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->parseLocalToUtc('2026-11-15 18:00:00', 'Mars/Phobos');
    }

    public function test_to_iso8601_utc_formats_to_unambiguous_iso_zulu_string(): void
    {
        $carbon = Carbon::create(2026, 11, 15, 16, 0, 0, 'UTC');
        $iso = $this->service->toIso8601Utc($carbon);

        $this->assertStringStartsWith('2026-11-15T16:00:00', $iso);
        $this->assertStringEndsWith('Z', $iso);
        $this->assertNull($this->service->toIso8601Utc(null));
    }

    public function test_parse_local_to_utc_accepts_matching_embedded_offset(): void
    {
        // Africa/Cairo in November has offset +02:00
        $utc = $this->service->parseLocalToUtc('2026-11-15T18:00:00+02:00', 'Africa/Cairo');
        $this->assertSame('2026-11-15 16:00:00', $utc->format('Y-m-d H:i:s'));

        // UTC with Z
        $utcZ = $this->service->parseLocalToUtc('2026-11-15T18:00:00Z', 'UTC');
        $this->assertSame('2026-11-15 18:00:00', $utcZ->format('Y-m-d H:i:s'));
    }

    public function test_parse_local_to_utc_rejects_contradicting_embedded_offset(): void
    {
        // Client sends Z (+00:00) but specifies Africa/Cairo (+02:00 in winter)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The embedded timezone offset 'Z' contradicts the specified IANA timezone 'Africa/Cairo'");

        $this->service->parseLocalToUtc('2026-11-15T18:00:00Z', 'Africa/Cairo');
    }
}
