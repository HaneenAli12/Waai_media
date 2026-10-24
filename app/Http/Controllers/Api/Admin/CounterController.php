<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Counter\UpdateCountersRequest;
use App\Http\Resources\CounterResource;
use App\Models\Counter;
use App\Services\Auth\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CounterController extends Controller
{
    public function __construct(
        protected ActivityLogger $activityLogger
    ) {}

    /**
     * Retrieve all system counters for dashboard management.
     *
     * Accessible by authorized Admin and Editor roles.
     */
    public function index(): JsonResponse
    {
        $counters = Counter::ordered()->get();

        return response()->json([
            'data' => CounterResource::collection($counters),
        ], 200);
    }

    /**
     * Atomically update one or multiple counter values in a single batch request.
     *
     * Unspecified counters remain unchanged. If any counter fails validation,
     * the entire batch is rolled back and no partial updates are saved.
     */
    public function update(UpdateCountersRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $updatedKeys = [];

        DB::transaction(function () use ($validatedData, &$updatedKeys): void {
            foreach ($validatedData['counters'] as $item) {
                $counter = isset($item['key'])
                    ? Counter::where('key', $item['key'])->firstOrFail()
                    : Counter::findOrFail($item['id']);

                $counter->update([
                    'value' => (int) $item['value'],
                ]);

                $updatedKeys[] = $counter->key;
            }
        });

        // Audit log the update
        $user = $request->user();
        if ($user) {
            $this->activityLogger->log(
                $user->id,
                'counters_updated',
                $request,
                [
                    'updated_counters' => $updatedKeys,
                ]
            );
        }

        // Return the fresh, complete list of ordered counters
        $allCounters = Counter::ordered()->get();

        return response()->json([
            'message' => 'Counters updated successfully.',
            'data' => CounterResource::collection($allCounters),
        ], 200);
    }
}
