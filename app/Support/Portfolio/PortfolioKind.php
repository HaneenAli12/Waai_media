<?php

namespace App\Support\Portfolio;

use App\Models\Category;

/**
 * Maps portfolio categories to the kind of content they accept.
 *
 * The mapping is keyed on the stable seeded slugs of the `categories` table (never on translated names).
 * Events/Opening subcategories have parent-scoped slugs (events-image vs opening-image), so they never collide.
 * A subcategory whose slug is not mapped here resolves to null and is rejected (fail closed).
 */
final class PortfolioKind
{
    /** Uploaded images only (Graphic Design, Branding, Media Covering, Events/Opening Image). */
    public const IMAGES = 'images';

    /** Uploaded images, each with its own required URL (Press Covering). */
    public const IMAGES_WITH_URL = 'images_with_url';

    /** Video URLs only, never video files (Videos, Events Video, Opening Video). */
    public const VIDEO_URLS = 'video_urls';

    /** Featured image + project links (Web Design, which has no subcategories). */
    public const WEB_DESIGN = 'web_design';

    public const WEB_DESIGN_PARENT_SLUG = 'web-design';

    /**
     * @var array<string, string>
     */
    private const BY_SUBCATEGORY_SLUG = [
        'graphic-design' => self::IMAGES,
        'branding' => self::IMAGES,
        'media-covering' => self::IMAGES,
        'events-image' => self::IMAGES,
        'opening-image' => self::IMAGES,
        'press-covering' => self::IMAGES_WITH_URL,
        'videos' => self::VIDEO_URLS,
        'events-video' => self::VIDEO_URLS,
        'opening-video' => self::VIDEO_URLS,
    ];

    /**
     * Resolve the content kind of a (parent, subcategory) selection, or null when unsupported.
     */
    public static function resolve(?Category $parent, ?Category $subcategory): ?string
    {
        if ($parent === null) {
            return null;
        }

        if ($parent->slug === self::WEB_DESIGN_PARENT_SLUG) {
            return $subcategory === null ? self::WEB_DESIGN : null;
        }

        if ($subcategory === null) {
            return null;
        }

        return self::BY_SUBCATEGORY_SLUG[$subcategory->slug] ?? null;
    }

    /**
     * Form/input contract a dashboard can use to render the right inputs for a kind.
     *
     * @return array<string, mixed>
     */
    public static function describe(string $kind): array
    {
        return match ($kind) {
            self::IMAGES => [
                'media' => 'images',
                'fields' => ['media[].image'],
                'media_url' => 'not_allowed',
            ],
            self::IMAGES_WITH_URL => [
                'media' => 'images',
                'fields' => ['media[].image', 'media[].url'],
                'media_url' => 'required_per_image_to_publish',
            ],
            self::VIDEO_URLS => [
                'media' => 'video_urls',
                'fields' => ['media[].url'],
                'media_url' => 'required',
            ],
            self::WEB_DESIGN => [
                'media' => 'featured_image',
                'fields' => ['featured_image', 'link_1', 'link_2', 'link_3'],
                'required_links' => ['link_1', 'link_2'],
                'optional_links' => ['link_3'],
            ],
            default => [],
        };
    }
}
