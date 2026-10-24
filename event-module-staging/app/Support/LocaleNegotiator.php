<?php

namespace App\Support;

class LocaleNegotiator
{
    public const SUPPORTED_LOCALES = ['ar', 'en'];

    public const DEFAULT_LOCALE = 'en';

    /**
     * Negotiate the best matching locale from an Accept-Language header.
     * Supports regional tags (e.g. ar-SA, en-US) and quality weights (e.g. q=0.9).
     */
    public static function negotiate(?string $acceptLanguage): string
    {
        if (empty($acceptLanguage)) {
            return self::DEFAULT_LOCALE;
        }

        $preferences = [];
        $parts = explode(',', $acceptLanguage);

        foreach ($parts as $part) {
            $subparts = explode(';', trim($part));
            $tag = strtolower(trim($subparts[0]));
            if (empty($tag)) {
                continue;
            }

            $quality = 1.0;
            if (isset($subparts[1])) {
                $qPart = trim($subparts[1]);
                if (str_starts_with($qPart, 'q=')) {
                    $quality = (float) substr($qPart, 2);
                }
            }

            // Extract primary language subtag (e.g. "ar" from "ar-sa" or "ar_EG")
            $normalizedTag = str_replace('_', '-', $tag);
            $primary = explode('-', $normalizedTag)[0];

            if (in_array($primary, self::SUPPORTED_LOCALES, true)) {
                if (! isset($preferences[$primary]) || $quality > $preferences[$primary]) {
                    $preferences[$primary] = $quality;
                }
            }
        }

        if (empty($preferences)) {
            return self::DEFAULT_LOCALE;
        }

        arsort($preferences, SORT_NUMERIC);

        return array_key_first($preferences);
    }
}
