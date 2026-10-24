<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppLinks\UpdateAppLinksRequest;
use App\Http\Resources\AppLinkResource;
use App\Models\AppLink;
use App\Services\Auth\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AppLinkController extends Controller
{
    public function __construct(
        protected ActivityLogger $activityLogger
    ) {}

    /**
     * Retrieve the public mobile app download links.
     *
     * No authentication is required. If app links have not been configured yet,
     * returns HTTP 200 with data: null.
     */
    public function index(): JsonResponse
    {
        $appLink = AppLink::instance();

        return response()->json([
            'data' => $appLink ? new AppLinkResource($appLink) : null,
        ], 200);
    }

    /**
     * Atomically create or update the singleton mobile app download links.
     *
     * Requires authentication with 'access' token ability and 'admin' or 'editor' role.
     * Concurrency-safe against initial creation races and serializes concurrent updates.
     */
    public function update(UpdateAppLinksRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $appLink = DB::transaction(function () use ($validated): AppLink {
            $record = AppLink::where('singleton_lock', 1)->lockForUpdate()->first();

            if (! $record) {
                try {
                    return AppLink::create(array_merge($validated, ['singleton_lock' => 1]));
                } catch (QueryException $e) {
                    // Check specifically for duplicate key conflict on the singleton lock index
                    $isSingletonDuplicate = (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)
                        && str_contains($e->getMessage(), 'app_links_singleton_lock_unique');

                    if ($isSingletonDuplicate) {
                        $record = AppLink::where('singleton_lock', 1)->lockForUpdate()->firstOrFail();
                        $record->update($validated);

                        return $record;
                    }

                    throw $e;
                }
            }

            $record->update($validated);

            return $record;
        }, 3);

        // Audit log the update
        $user = $request->user();
        if ($user) {
            $this->activityLogger->log(
                $user->id,
                'app_links_updated',
                $request,
                [
                    'updated_fields' => array_keys($validated),
                ]
            );
        }

        return response()->json([
            'message' => 'App download links updated successfully.',
            'data' => new AppLinkResource($appLink),
        ], 200);
    }
}
