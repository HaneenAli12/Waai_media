<?php

namespace App\Models;

use Database\Factories\NewsImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsImage extends Model
{
    /** @use HasFactory<NewsImageFactory> */
    use HasFactory;

    protected $table = 'news_images';

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
     * News articles using this image as their featured image.
     */
    public function featuredInNews(): HasMany
    {
        return $this->hasMany(News::class, 'featured_image_id');
    }

    /**
     * News articles referencing this image via the article images pivot table.
     */
    public function newsArticles(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'news_article_images', 'news_image_id', 'news_id')
            ->withPivot('role')
            ->withTimestamps();
    }
}
