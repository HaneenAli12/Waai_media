<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'logo_path',
    ];

    /**
     * Deterministic ordering: newest first, ties broken by ID descending.
     */
    public function scopeOrderedForAdmin(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }
}
