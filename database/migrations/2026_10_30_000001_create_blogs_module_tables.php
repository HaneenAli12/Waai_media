<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blog_images', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 32)->default('r2');
            $table->string('storage_key', 500)->unique();
            $table->string('mime_type', 64);
            $table->unsignedInteger('size_bytes');
            $table->string('sha256', 64)->nullable();
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('deleting_at')->nullable();
            $table->timestamps();

            $table->index(['created_at'], 'idx_blog_images_created_at');
        });

        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();
            $table->foreignId('featured_image_id')
                ->nullable()
                ->constrained('blog_images')
                ->nullOnDelete();
            $table->string('featured_image_url', 500)->nullable();
            $table->string('title_ar', 255)->nullable();
            $table->string('title_en', 255)->nullable();
            $table->string('slug', 255)->nullable()->unique();
            $table->string('author_name_ar', 255)->nullable();
            $table->string('author_name_en', 255)->nullable();
            $table->text('short_description_ar')->nullable();
            $table->text('short_description_en')->nullable();
            $table->longText('content_ar')->nullable();
            $table->longText('content_en')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'published_at', 'id'], 'idx_blogs_public_listing');
            $table->index(['category_id', 'status', 'published_at'], 'idx_blogs_category_status');
            $table->index(['created_at', 'id'], 'idx_blogs_created_id');
        });

        Schema::create('blog_article_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')
                ->constrained('blogs')
                ->cascadeOnDelete();
            $table->foreignId('blog_image_id')
                ->constrained('blog_images')
                ->cascadeOnDelete();
            $table->string('role', 32)->default('editor');
            $table->timestamps();

            $table->unique(['blog_id', 'blog_image_id', 'role'], 'uniq_blog_article_image_role');
            $table->index(['blog_image_id'], 'idx_blog_article_images_image_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_article_images');
        Schema::dropIfExists('blogs');
        Schema::dropIfExists('blog_images');
    }
};
