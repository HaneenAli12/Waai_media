<?php

namespace App\Models;

use Database\Factories\BlogImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogImage extends Model
{
    /** @use HasFactory<BlogImageFactory> */
    use HasFactory;

    protected $table = 'blog_images';

    protected $fillable = [
        'disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by',
        'deleting_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'deleting_at' => 'datetime',
        ];
    }

    /**
     * User who uploaded this image.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Blog articles using this image as their featured image.
     */
    public function featuredInBlogs(): HasMany
    {
        return $this->hasMany(Blog::class, 'featured_image_id');
    }

    /**
     * Blog articles referencing this image via the article images pivot table.
     */
    public function blogArticles(): BelongsToMany
    {
        return $this->belongsToMany(Blog::class, 'blog_article_images', 'blog_image_id', 'blog_id')
            ->withPivot('role')
            ->withTimestamps();
    }
}
