<?php

namespace App\Rules;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidCategoryForModule implements ValidationRule
{
    /**
     * @param  string  $module  The expected category module type ('news', 'blogs', or 'portfolio')
     * @param  bool  $disallowParentCategories  If true, categories with children cannot be directly assigned
     */
    public function __construct(
        protected string $module,
        protected bool $disallowParentCategories = true
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail('The selected :attribute is invalid.');

            return;
        }

        $category = Category::find((int) $value);

        if (! $category) {
            $fail('The selected :attribute does not exist.');

            return;
        }

        if ($category->type !== $this->module) {
            $fail("The selected :attribute does not belong to the {$this->module} module.");

            return;
        }

        if ($this->disallowParentCategories && $category->children()->exists()) {
            $fail('The selected :attribute is an organizational parent category and cannot be assigned directly. Please select a subcategory.');

            return;
        }
    }
}
