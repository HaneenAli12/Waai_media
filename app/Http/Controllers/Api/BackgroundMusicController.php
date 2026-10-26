<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackgroundMusicPublicResource;
use App\Models\Music;

class BackgroundMusicController extends Controller
{
    /**
     * Display the background music settings and playback URL for the public website.
     */
    public function show(): BackgroundMusicPublicResource
    {
        $music = Music::instance();

        return new BackgroundMusicPublicResource($music);
    }
}
