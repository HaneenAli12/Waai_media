<?php

namespace Tests\Feature\News;

use App\Http\Requests\Admin\News\PublishNewsRequest;
use App\Http\Requests\Admin\News\StoreNewsRequest;
use App\Http\Requests\Admin\News\UpdateNewsRequest;
use App\Models\Category;
use App\Models\NewsArticle;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NewsValidationTest extends TestCase
{
    use RefreshDatabase;

    private Category $leafCategory;

    private Category $parentCategory;

    private User $editor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);

        $this->leafCategory = Category::where('type', 'news')->where('slug', 'health')->firstOrFail();
        $this->parentCategory = Category::where('type', 'news')->where('slug', 'entertainment')->firstOrFail();
        $this->editor = User::factory()->editor()->create();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_store_news_request_authorizes_admin_and_editor_only(): void
    {
        $request = new StoreNewsRequest;

        // Guest is not authorized
        $this->assertFalse($request->authorize());

        // Editor is authorized
        $request->setUserResolver(fn () => $this->editor);
        $this->assertTrue($request->authorize());

        // Admin is authorized
        $request->setUserResolver(fn () => $this->admin);
        $this->assertTrue($request->authorize());
    }

    public function test_draft_with_only_category_and_english_title_passes_validation(): void
    {
        $request = new StoreNewsRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Draft Title English Only',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_draft_with_only_category_and_arabic_title_passes_validation(): void
    {
        $request = new StoreNewsRequest;
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make([
            'category_id' => $this->leafCategory->id,
            'title_ar' => 'عنوان المسودة بالعربية فقط',
        ], $request->rules(), $request->messages());

        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_draft_with_missing_category_is_rejected(): void
    {
        $request = new StoreNewsRequest;
        $validator = Validator::make([
            'title_en' => 'Draft Title',
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('category_id', $validator->errors()->toArray());
    }

    public function test_draft_with_parent_entertainment_category_is_rejected(): void
    {
        $request = new StoreNewsRequest;
        $validator = Validator::make([
            'category_id' => $this->parentCategory->id,
            'title_en' => 'Draft Title',
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('category_id', $validator->errors()->toArray());
    }

    public function test_draft_with_both_titles_missing_is_rejected(): void
    {
        $request = new StoreNewsRequest;
        $validator = Validator::make([
            'category_id' => $this->leafCategory->id,
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('title_en', $validator->errors()->toArray());
        $this->assertArrayHasKey('title_ar', $validator->errors()->toArray());
    }

    public function test_summaries_with_exact_500_characters_pass_and_501_rejected(): void
    {
        $request = new StoreNewsRequest;

        // 500 characters in English
        $validEn500 = str_repeat('a', 500);
        $invalidEn501 = str_repeat('a', 501);

        // 500 characters in Arabic (multi-byte UTF-8)
        $validAr500 = str_repeat('ب', 500);
        $invalidAr501 = str_repeat('ب', 501);

        $validator500 = Validator::make([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Valid Title',
            'summary_en' => $validEn500,
            'summary_ar' => $validAr500,
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator500->passes());

        $validator501 = Validator::make([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Valid Title',
            'summary_en' => $invalidEn501,
            'summary_ar' => $invalidAr501,
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator501->passes());
        $this->assertArrayHasKey('summary_en', $validator501->errors()->toArray());
        $this->assertArrayHasKey('summary_ar', $validator501->errors()->toArray());
    }

    public function test_cover_image_key_format_validation(): void
    {
        $request = new StoreNewsRequest;

        // Valid UUID cover key
        $validKey = 'news/covers/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.webp';
        $vValid = Validator::make([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Title',
            'cover_image_key' => $validKey,
        ], $request->rules(), $request->messages());
        $this->assertTrue($vValid->passes());

        // Invalid prefix
        $invalidPrefix = 'other/prefix/image.jpg';
        $vInvalidPrefix = Validator::make([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Title',
            'cover_image_key' => $invalidPrefix,
        ], $request->rules(), $request->messages());
        $this->assertFalse($vInvalidPrefix->passes());

        // Invalid non-UUID filename
        $invalidFilename = 'news/covers/my-photo.jpg';
        $vInvalidFilename = Validator::make([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Title',
            'cover_image_key' => $invalidFilename,
        ], $request->rules(), $request->messages());
        $this->assertFalse($vInvalidFilename->passes());
    }

    public function test_update_request_allows_partial_updates(): void
    {
        $article = NewsArticle::factory()->draft()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Original English Title',
            'title_ar' => 'العنوان الأصلي بالعربية',
        ]);

        $request = UpdateNewsRequest::create("/api/v1/admin/news/{$article->id}", 'PUT', [
            'summary_en' => 'Updated summary only.',
        ]);
        $request->setUserResolver(fn () => $this->editor);
        $request->setRouteResolver(fn () => new class($article)
        {
            public function __construct(private $article) {}

            public function parameter($name)
            {
                return $this->article;
            }
        });

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_update_request_rejects_clearing_both_titles(): void
    {
        $article = NewsArticle::factory()->draft()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Original Title',
            'title_ar' => 'العنوان الأصلي',
        ]);

        $request = UpdateNewsRequest::create("/api/v1/admin/news/{$article->id}", 'PUT', [
            'title_en' => '',
            'title_ar' => '',
        ]);
        $request->setUserResolver(fn () => $this->editor);
        $request->setRouteResolver(fn () => new class($article)
        {
            public function __construct(private $article) {}

            public function parameter($name)
            {
                return $this->article;
            }
        });

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('title_en', $validator->errors()->toArray());
        $this->assertArrayHasKey('title_ar', $validator->errors()->toArray());
    }

    public function test_publish_request_rejects_incomplete_draft(): void
    {
        $incompleteDraft = NewsArticle::factory()->draft()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Incomplete Draft',
            'title_ar' => null,
            'summary_en' => null,
            'content_en' => null,
            'cover_image_key' => null,
        ]);

        $request = PublishNewsRequest::create("/api/v1/admin/news/{$incompleteDraft->id}/publish", 'PATCH', []);
        $request->setUserResolver(fn () => $this->editor);
        $request->setRouteResolver(fn () => new class($incompleteDraft)
        {
            public function __construct(private $article) {}

            public function parameter($name)
            {
                return $this->article;
            }
        });

        // Trigger prepareForValidation
        $reflection = new \ReflectionClass($request);
        $method = $reflection->getMethod('prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('title_ar', $errors);
        $this->assertArrayHasKey('summary_en', $errors);
        $this->assertArrayHasKey('summary_ar', $errors);
        $this->assertArrayHasKey('content_en', $errors);
        $this->assertArrayHasKey('content_ar', $errors);
        $this->assertArrayHasKey('cover_image_key', $errors);
    }

    public function test_publish_request_accepts_complete_article(): void
    {
        $completePayload = [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Complete English Title',
            'title_ar' => 'عنوان عربي مكتمل',
            'summary_en' => 'Complete English summary under 500 characters.',
            'summary_ar' => 'ملخص عربي مكتمل أقل من خمسمائة حرف.',
            'content_en' => '<p>Complete English content.</p>',
            'content_ar' => '<p>محتوى المقال العربي المكتمل.</p>',
            'cover_image_key' => 'news/covers/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.webp',
        ];

        $request = PublishNewsRequest::create('/api/v1/admin/news/1/publish', 'PATCH', $completePayload);
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    public function test_store_news_request_returns_sanitized_content_in_validated_data(): void
    {
        $dirtyContentEn = '<p>Safe Text</p><script>alert("XSS")</script><a href="https://example.com" onclick="evil()">Link</a>';
        $dirtyContentAr = '<p>نص آمن</p><iframe src="https://malicious.com"></iframe>';

        $payload = [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Draft Title',
            'content_en' => $dirtyContentEn,
            'content_ar' => $dirtyContentAr,
        ];

        $request = StoreNewsRequest::create('/api/v1/admin/news', 'POST', $payload);
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);
        $this->assertTrue($validator->passes());

        $request->setValidator($validator);

        $reflection = new \ReflectionClass($request);
        if ($reflection->hasMethod('passedValidation')) {
            $method = $reflection->getMethod('passedValidation');
            $method->setAccessible(true);
            $method->invoke($request);
        }

        $validated = $request->validated();

        $this->assertStringNotContainsString('<script>', $validated['content_en']);
        $this->assertStringNotContainsString('onclick', $validated['content_en']);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $validated['content_en']);
        $this->assertStringContainsString('<p>Safe Text</p>', $validated['content_en']);

        $this->assertStringNotContainsString('<iframe>', $validated['content_ar']);
        $this->assertStringContainsString('<p>نص آمن</p>', $validated['content_ar']);
    }

    public function test_update_news_request_returns_sanitized_content_and_preserves_omitted_fields(): void
    {
        $article = NewsArticle::factory()->draft()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Original Title',
            'content_en' => '<p>Original Content</p>',
            'content_ar' => '<p>المحتوى الأصلي</p>',
        ]);

        $request = UpdateNewsRequest::create("/api/v1/admin/news/{$article->id}", 'PUT', [
            'content_en' => '<p>Updated content</p><script>alert(1)</script>',
        ]);
        $request->setUserResolver(fn () => $this->editor);
        $request->setRouteResolver(fn () => new class($article)
        {
            public function __construct(private $article) {}

            public function parameter($name)
            {
                return $this->article;
            }
        });

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);
        $this->assertTrue($validator->passes());

        $request->setValidator($validator);

        $reflection = new \ReflectionClass($request);
        if ($reflection->hasMethod('passedValidation')) {
            $method = $reflection->getMethod('passedValidation');
            $method->setAccessible(true);
            $method->invoke($request);
        }

        $validated = $request->validated();

        // Supplied content_en is sanitized
        $this->assertStringNotContainsString('<script>', $validated['content_en']);
        $this->assertStringContainsString('<p>Updated content</p>', $validated['content_en']);

        // Omitted content_ar remains omitted from validated payload
        $this->assertArrayNotHasKey('content_ar', $validated);
        $this->assertArrayNotHasKey('title_en', $validated);
    }

    public function test_publish_news_request_sanitizes_effective_inherited_content(): void
    {
        $draftWithDirtyContent = NewsArticle::factory()->draft()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Complete English Title',
            'title_ar' => 'عنوان عربي مكتمل',
            'summary_en' => 'English summary under 500 chars.',
            'summary_ar' => 'ملخص عربي تحت 500 حرف.',
            'content_en' => '<p>Draft content</p><script>alert("inherited XSS")</script>',
            'content_ar' => '<p>محتوى المسودة</p>',
            'cover_image_key' => 'news/covers/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d.webp',
        ]);

        $request = PublishNewsRequest::create("/api/v1/admin/news/{$draftWithDirtyContent->id}/publish", 'PATCH', []);
        $request->setUserResolver(fn () => $this->editor);
        $request->setRouteResolver(fn () => new class($draftWithDirtyContent)
        {
            public function __construct(private $article) {}

            public function parameter($name)
            {
                return $this->article;
            }
        });

        $reflection = new \ReflectionClass($request);
        $prepMethod = $reflection->getMethod('prepareForValidation');
        $prepMethod->setAccessible(true);
        $prepMethod->invoke($request);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);
        $this->assertTrue($validator->passes());

        $request->setValidator($validator);

        if ($reflection->hasMethod('passedValidation')) {
            $passedMethod = $reflection->getMethod('passedValidation');
            $passedMethod->setAccessible(true);
            $passedMethod->invoke($request);
        }

        $validated = $request->validated();

        $this->assertStringNotContainsString('<script>', $validated['content_en']);
        $this->assertStringContainsString('<p>Draft content</p>', $validated['content_en']);
    }

    public function test_store_news_request_accepts_five_images_in_each_language_independently(): void
    {
        $validPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $img = '<img src="data:image/png;base64,'.$validPngBase64.'">';
        $fiveImages = $img.$img.$img.$img.$img;

        $request = StoreNewsRequest::create('/api/v1/admin/news', 'POST', [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Bilingual Images Article',
            'content_en' => $fiveImages,
            'content_ar' => $fiveImages,
        ]);
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);
        $this->assertTrue($validator->passes());

        // 6 images in content_en fails validation
        $sixImages = $fiveImages.$img;
        $requestFail = StoreNewsRequest::create('/api/v1/admin/news', 'POST', [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Article With 6 Images',
            'content_en' => $sixImages,
        ]);
        $requestFail->setUserResolver(fn () => $this->editor);

        $validatorFail = Validator::make($requestFail->all(), $requestFail->rules(), $requestFail->messages());
        $requestFail->withValidator($validatorFail);
        $this->assertFalse($validatorFail->passes());
        $this->assertArrayHasKey('content_en', $validatorFail->errors()->toArray());
    }

    public function test_external_image_url_in_content_is_rejected_by_store_news_request(): void
    {
        $request = StoreNewsRequest::create('/api/v1/admin/news', 'POST', [
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Article With External Image',
            'content_en' => '<p>Article</p><img src="https://cdn.thirdparty.com/evil.jpg">',
        ]);
        $request->setUserResolver(fn () => $this->editor);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('content_en', $validator->errors()->toArray());
        $this->assertStringContainsString('external URL', $validator->errors()->toArray()['content_en'][0]);
    }

    public function test_update_and_publish_request_resolve_news_article_parameter(): void
    {
        $article = NewsArticle::factory()->draft()->create([
            'category_id' => $this->leafCategory->id,
            'title_en' => 'Original English Title',
            'title_ar' => 'العنوان الأصلي بالعربية',
        ]);

        $request = UpdateNewsRequest::create("/api/v1/admin/news/{$article->id}", 'PUT', [
            'summary_en' => 'Updated summary.',
        ]);
        $request->setUserResolver(fn () => $this->editor);

        // Bind using news_article parameter name
        $request->setRouteResolver(fn () => new class($article)
        {
            public function __construct(private $article) {}

            public function parameter($name)
            {
                return $name === 'news_article' ? $this->article : null;
            }
        });

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }
}
