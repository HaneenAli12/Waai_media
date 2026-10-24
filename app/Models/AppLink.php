<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppLink extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'app_links';

    /**
     * The attributes that are mass assignable.
     * Internal database attributes (id, singleton_lock, timestamps) are strictly guarded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'google_app_link',
        'apple_app_store_link',
        'app_gallery_link',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'singleton_lock',
    ];

    /**
     * Retrieve the singleton AppLink instance, or null if unconfigured.
     */
    public static function instance(): ?self
    {
        return static::where('singleton_lock', 1)->first();
    }
}
