<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactInfo\UpdateContactInfoRequest;
use App\Http\Resources\ContactInfoResource;
use App\Models\ContactInfo;
use App\Services\Auth\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ContactInfoController extends Controller
{
    public function __construct(
        protected ActivityLogger $activityLogger
    ) {}

    /**
     * Retrieve the current contact information for dashboard management.
     *
     * Accessible by authorized Admin and Editor roles.
     * If unconfigured, returns HTTP 200 with data: null.
     */
    public function show(): JsonResponse
    {
        $contactInfo = ContactInfo::instance();

        return response()->json([
            'data' => $contactInfo ? new ContactInfoResource($contactInfo) : null,
        ], 200);
    }

    /**
     * Atomically create or update the singleton contact information record.
     *
     * Accessible by authorized Admin and Editor roles.
     * Concurrency-safe against initial creation races and serializes concurrent updates.
     */
    public function update(UpdateContactInfoRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $contactInfo = DB::transaction(function () use ($validated): ContactInfo {
            $record = ContactInfo::where('singleton_lock', 1)->lockForUpdate()->first();

            if (! $record) {
                try {
                    return ContactInfo::create(array_merge($validated, ['singleton_lock' => 1]));
                } catch (QueryException $e) {
                    // Handle duplicate-key race on initial creation (error 1062 / SQLSTATE 23000)
                    if ($e->getCode() == 23000 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062)) {
                        $record = ContactInfo::where('singleton_lock', 1)->lockForUpdate()->firstOrFail();
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
                'contact_info_updated',
                $request,
                [
                    'updated_fields' => array_keys($validated),
                ]
            );
        }

        return response()->json([
            'message' => 'Contact information updated successfully.',
            'data' => new ContactInfoResource($contactInfo),
        ], 200);
    }
}
