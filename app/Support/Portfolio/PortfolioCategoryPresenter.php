<?php

namespace App\Support\Portfolio;

use App\Models\Category;

final class PortfolioCategoryPresenter
{
    /**
     * Compact category/subcategory representation with the name localized for the negotiated locale.
     *
     * @return array{id: int, slug: string, name: string}|null
     */
    public static function present(?Category $category, string $locale): ?array
    {
        if ($category === null) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'slug' => (string) $category->slug,
            'name' => (string) ($locale === 'ar' ? $category->name_ar : $category->name_en),
        ];
    }
}
