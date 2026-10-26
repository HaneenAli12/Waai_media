<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Team\IndexTeamMemberRequest;
use App\Http\Requests\Admin\Team\StoreTeamMemberRequest;
use App\Http\Requests\Admin\Team\UpdateTeamMemberRequest;
use App\Http\Resources\TeamMemberResource;
use App\Models\TeamMember;
use App\Services\Storage\Exceptions\InvalidImageException;
use App\Services\Team\TeamPhotoService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamMemberController extends Controller
{
    /**
     * Default page size for the admin team listing.
     */
    public const PER_PAGE = 10;

    public function __construct(
        protected TeamPhotoService $photoService
    ) {}

    /**
     * List team members with page-number pagination.
     */
    public function index(IndexTeamMemberRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        $paginated = TeamMember::query()->orderedForAdmin()->paginate($perPage);

        return TeamMemberResource::collection($paginated)->response();
    }

    /**
     * Display a single team member.
     */
    public function show(TeamMember $teamMember): JsonResponse
    {
        return response()->json([
            'data' => new TeamMemberResource($teamMember),
        ], 200);
    }

    /**
     * Create a team member, optionally with a profile photo.
     */
    public function store(StoreTeamMemberRequest $request): JsonResponse
    {
        $validated = $request->safe()->only(['name', 'position']);

        $uploadedKey = $this->uploadPhoto($request);
        if ($uploadedKey !== null) {
            $validated['photo_path'] = $uploadedKey;
        }

        try {
            $member = DB::transaction(fn () => TeamMember::create($validated));
        } catch (\Throwable $e) {
            $this->photoService->deleteIfUnshared($uploadedKey);

            throw $e;
        }

        return response()->json([
            'data' => new TeamMemberResource($member),
        ], 201);
    }

    /**
     * Update name, position and/or photo.
     *
     * The previous photo is deleted only after the new one is persisted; on failure the
     * newly uploaded object is removed and the existing record/photo stay untouched.
     */
    public function update(UpdateTeamMemberRequest $request, TeamMember $teamMember): JsonResponse
    {
        $validated = $request->safe()->only(['name', 'position']);

        $uploadedKey = $this->uploadPhoto($request);
        if ($uploadedKey !== null) {
            $validated['photo_path'] = $uploadedKey;
        }

        $previousKey = null;

        try {
            DB::transaction(function () use ($teamMember, $validated, &$previousKey) {
                // The route-bound model may be stale if another request replaced the photo meanwhile.
                // Read the committed key under a row lock so exactly the superseded object is removed
                // and concurrent replacements cannot orphan each other's uploads.
                $current = DB::table('team_members')->where('id', $teamMember->id)->lockForUpdate()->first(['id', 'photo_path']);
                if ($current === null) {
                    throw (new ModelNotFoundException)->setModel(TeamMember::class, [$teamMember->id]);
                }

                $previousKey = $current->photo_path;
                $teamMember->update($validated);
            });
        } catch (\Throwable $e) {
            $this->photoService->deleteIfUnshared($uploadedKey);

            throw $e;
        }

        if ($uploadedKey !== null && $previousKey !== null && $previousKey !== $uploadedKey) {
            $this->photoService->deleteIfUnshared($previousKey, $teamMember->id);
        }

        return response()->json([
            'data' => new TeamMemberResource($teamMember->fresh()),
        ], 200);
    }

    /**
     * Permanently delete a team member and its photo.
     *
     * Accessible by Administrator ONLY (route middleware + controller-level check).
     * The R2 object is removed after the database delete commits; storage failures are logged
     * and never fail the request.
     */
    public function destroy(Request $request, TeamMember $teamMember): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Only administrators can permanently delete team members.',
            ], 403);
        }

        $photoKey = $teamMember->photo_path;
        $memberId = $teamMember->id;

        DB::transaction(function () use ($teamMember, &$photoKey) {
            // Use the committed key (a concurrent replacement may have changed it since route binding)
            $photoKey = DB::table('team_members')->where('id', $teamMember->id)->lockForUpdate()->value('photo_path') ?? $photoKey;
            $teamMember->delete();
        });

        $this->photoService->deleteIfUnshared($photoKey, $memberId);

        return response()->json([
            'message' => 'Team member deleted successfully.',
        ], 200);
    }

    /**
     * Upload the request photo (if any) and return its R2 key.
     *
     * @throws ValidationException when the content fails server-side image inspection
     */
    private function uploadPhoto(Request $request): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        try {
            return $this->photoService->upload($request->file('photo'));
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages([
                'photo' => [$e->getMessage()],
            ]);
        }
    }
}
