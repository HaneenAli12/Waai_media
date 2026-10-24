<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactInfo extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'contact_info';

    /**
     * The attributes that are mass assignable.
     * Internal database attributes (id, singleton_lock, timestamps) are strictly guarded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'phone_1',
        'phone_2',
        'phone_3',
        'official_email',
        'facebook',
        'instagram',
        'youtube',
        'linkedin',
        'tiktok',
        'whatsapp',
        'complete_address',
        'google_maps_url',
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
     * Retrieve the singleton ContactInfo instance, or null if unconfigured.
     */
    public static function instance(): ?self
    {
        return static::where('singleton_lock', 1)->first();
    }
}
