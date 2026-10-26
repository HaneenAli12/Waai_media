<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Music\UpdateBackgroundMusicRequest;
use App\Http\Resources\BackgroundMusicAdminResource;
use App\Models\Music;
use App\Services\Storage\AudioStorageService;
use App\Services\Storage\Exceptions\InvalidImageException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class BackgroundMusicController extends Controller
{
    public function __construct(
        protected AudioStorageService $audioStorage
    ) {}

    /**
     * Display the background music settings and metadata for administrators.
     */
    public function show(): BackgroundMusicAdminResource
    {
        $music = Music::instance();

        return new BackgroundMusicAdminResource($music);
    }

    /**
     * Update the background music settings or replace the audio track.
     */
    public function update(UpdateBackgroundMusicRequest $request): JsonResponse
    {
        $uploadedFile = $request->file('audio_file');
        $hasFile = $uploadedFile !== null;

        $newKey = null;
        $storedAudio = null;
        $originalClientFilename = null;

        // 1. If an audio file is uploaded, upload to R2 prior to beginning the database transaction.
        if ($hasFile) {
            $originalClientFilename = $uploadedFile->getClientOriginalName();
            try {
                $storedAudio = $this->audioStorage->upload($uploadedFile);
                $newKey = $storedAudio->key;
            } catch (InvalidImageException $e) {
                throw ValidationException::withMessages([
                    'audio_file' => [$e->getMessage()],
                ]);
            }
        }

        $connection = DB::connection();
        $txPhase = 'PRE_TRANSACTION';
        $oldKeyToDelete = null;

        try {
            $txPhase = 'BEGINNING';
            $connection->beginTransaction();
            $txPhase = 'INSIDE_TRANSACTION';

            /** @var Music $music */
            $music = Music::where('id', 1)->lockForUpdate()->first();
            if (! $music) {
                $music = Music::instance();
                $music = Music::where('id', 1)->lockForUpdate()->first();
            }

            if ($hasFile && $storedAudio !== null) {
                $oldKeyToDelete = $music->file_path;
                $music->file_path = $storedAudio->key;
                $music->file_name = $originalClientFilename ?? basename($storedAudio->key);
                $music->file_size = $storedAudio->size;
                $music->mime_type = $storedAudio->mimeType;
            }

            if ($request->has('is_enabled')) {
                $music->is_enabled = $request->boolean('is_enabled');
            }

            if ($request->has('is_loop')) {
                $music->is_loop = $request->boolean('is_loop');
            }

            $music->save();

            $txPhase = 'COMMITTING';
            $connection->commit();
            $txPhase = 'COMMITTED';

            // 5. Safe Replacement: Delete old track ONLY after confirmed successful commit
            // Verify database record has committed and references the new object
            if ($oldKeyToDelete && $oldKeyToDelete !== $newKey) {
                $verifiedMusic = Music::find(1);
                $isStillReferenced = DB::table('music')->where('file_path', $oldKeyToDelete)->exists();

                if ($verifiedMusic && $verifiedMusic->file_path === $newKey && ! $isStillReferenced) {
                    $deleted = $this->audioStorage->safeDelete($oldKeyToDelete);
                    if (! $deleted) {
                        Log::warning('Superseded audio file deletion failed after confirmed commit. Retained for cleanup.', [
                            'superseded_key' => $oldKeyToDelete,
                            'new_key' => $newKey,
                        ]);
                    } else {
                        Log::info('Superseded audio file safely deleted following confirmed replacement commit.', [
                            'deleted_key' => $oldKeyToDelete,
                            'new_key' => $newKey,
                        ]);
                    }
                } else {
                    Log::critical('Database record verification failed post-commit or old key is still referenced; deletion aborted.', [
                        'expected_new_key' => $newKey,
                        'current_active_key' => $verifiedMusic?->file_path,
                        'superseded_key' => $oldKeyToDelete,
                        'is_still_referenced' => $isStillReferenced,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Background music updated successfully.',
                'data' => new BackgroundMusicAdminResource($music->fresh()),
            ]);

        } catch (Throwable $e) {
            // Commit ambiguity safety handling:
            if ($txPhase === 'COMMITTING') {
                // Once commit phase begins, connection loss or exception leaves database state uncertain.
                // Strictly retain all objects (zero deletions).
                Log::critical('Ambiguous database commit outcome during background music update. No R2 deletions performed.', [
                    'new_key' => $newKey,
                    'old_key' => $oldKeyToDelete,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            // If we are strictly pre-commit inside an active transaction, attempt safe rollback
            if ($txPhase === 'INSIDE_TRANSACTION') {
                try {
                    $connection->rollBack();
                    $txPhase = 'ROLLED_BACK';
                } catch (Throwable $rollbackException) {
                    // Rollback failed - transaction status is uncertain. Retain all objects and log critical.
                    Log::critical('Database transaction rollback failed during background music update. R2 deletion aborted.', [
                        'new_key' => $newKey,
                        'rollback_error' => $rollbackException->getMessage(),
                        'original_error' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            }

            // Only delete the newly uploaded object if rollback succeeded cleanly AND the error occurred strictly pre-commit
            if ($txPhase === 'ROLLED_BACK' && $newKey !== null) {
                $this->audioStorage->safeDelete($newKey);
            }

            throw $e;
        }
    }
}
