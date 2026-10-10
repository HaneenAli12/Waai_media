<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Music extends Model
{
    protected $table = 'music';

    protected $primaryKey = 'id';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'file_name',
        'file_path',
        'file_url',
        'mime_type',
        'file_size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /**
     * Retrieve the guaranteed singleton instance.
     */
    public static function instance(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'file_name' => null,
                'file_path' => null,
                'file_url' => null,
                'mime_type' => null,
                'file_size' => null,
            ]
        );
    }
}
