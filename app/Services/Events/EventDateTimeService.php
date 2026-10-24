<?php

namespace App\Services\Events;

use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

class EventDateTimeService
{
    /**
     * Determine whether the given string is a valid IANA timezone identifier.
     */
    public function isValidTimezone(?string $timezone): bool
    {
        if ($timezone === null || trim($timezone) === '') {
            return false;
        }

        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true);
    }

    /**
     * Extract an embedded ISO-8601 offset indicator (e.g. 'Z', '+02:00', '-05:00') if present.
     */
    public function extractEmbeddedOffset(string $dateTimeString): ?string
    {
        $trimmed = trim($dateTimeString);
        if (preg_match('/([zZ]|[+-]\d{2}(?::?\d{2})?)$/', $trimmed, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Convert an offset string ('Z', '+02:00', '-0500') into total signed seconds.
     */
    public function offsetToSeconds(string $offset): int
    {
        $upper = strtoupper(trim($offset));
        if ($upper === 'Z') {
            return 0;
        }

        $sign = $upper[0] === '-' ? -1 : 1;
        $clean = str_replace(':', '', substr($upper, 1));

        $hours = (int) substr($clean, 0, 2);
        $minutes = strlen($clean) >= 4 ? (int) substr($clean, 2, 2) : 0;

        return $sign * (($hours * 3600) + ($minutes * 60));
    }

    /**
     * Strip any trailing ISO-8601 offset or Z indicator so the datetime can be
     * parsed as calendar clock time within the specified IANA timezone.
     */
    public function stripOffset(string $dateTimeString): string
    {
        $trimmed = trim($dateTimeString);
        $trimmed = str_replace('T', ' ', $trimmed);

        // Strip sub-seconds and trailing Z or +XX:XX / -XX:XX
        $cleaned = preg_replace('/(\.\d+)?([zZ]|[+-]\d{2}(?::?\d{2})?)$/', '', $trimmed);

        return trim((string) $cleaned);
    }

    /**
     * Parse local date/time input together with an IANA timezone and convert to UTC.
     *
     * Deliberate validation rules:
     * 1. If no embedded offset is present in $localDateTime, the string is strictly interpreted
     *    as local clock time within $timezone and converted to UTC.
     * 2. If an embedded offset (e.g. 'Z', '+02:00') is supplied in $localDateTime, it must match
     *    the actual offset of the specified IANA timezone on that date. Contradictory offsets are
     *    rejected to avoid ambiguous interpretation.
     *
     * @throws InvalidArgumentException
     */
    public function parseLocalToUtc(string|DateTimeInterface $localDateTime, string $timezone): Carbon
    {
        if (! $this->isValidTimezone($timezone)) {
            throw new InvalidArgumentException("Invalid or unrecognized IANA timezone: '{$timezone}'.");
        }

        if (is_string($localDateTime)) {
            $embeddedOffset = $this->extractEmbeddedOffset($localDateTime);
            $cleanDateString = $this->stripOffset($localDateTime);

            if ($embeddedOffset !== null) {
                try {
                    $targetTz = new DateTimeZone($timezone);
                    $tempDt = new DateTime($cleanDateString, $targetTz);
                    $expectedOffsetSeconds = $targetTz->getOffset($tempDt);
                    $actualOffsetSeconds = $this->offsetToSeconds($embeddedOffset);

                    if ($expectedOffsetSeconds !== $actualOffsetSeconds) {
                        $expectedFormatted = sprintf(
                            '%+03d:%02d',
                            intdiv($expectedOffsetSeconds, 3600),
                            abs($expectedOffsetSeconds % 3600) / 60
                        );

                        throw new InvalidArgumentException(sprintf(
                            "The embedded timezone offset '%s' contradicts the specified IANA timezone '%s' (expected offset: %s).",
                            $embeddedOffset,
                            $timezone,
                            $expectedFormatted
                        ));
                    }
                } catch (Throwable $e) {
                    if ($e instanceof InvalidArgumentException) {
                        throw $e;
                    }

                    throw new InvalidArgumentException("Invalid date string for offset evaluation: '{$localDateTime}'.", 0, $e);
                }
            }

            $dateString = $cleanDateString;
        } else {
            $dateString = $localDateTime->format('Y-m-d H:i:s');
        }

        try {
            $carbon = Carbon::parse($dateString, $timezone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                "Unable to parse local date/time '{$dateString}' in timezone '{$timezone}': ".$e->getMessage(),
                0,
                $e
            );
        }

        return $carbon->setTimezone('UTC');
    }

    /**
     * Format a DateTime instance to an unambiguous ISO 8601 UTC string.
     */
    public function toIso8601Utc(?DateTimeInterface $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return Carbon::instance($dateTime)->setTimezone('UTC')->toISOString();
    }
}
