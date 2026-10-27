<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamMemberResource;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;

class TeamMemberController extends Controller
{
    /**
     * Retrieve the public list of team members for Home Page and About Us.
     *
     * Unauthenticated public endpoint. Returns team members ordered deterministically
     * (newest first, ties broken by id descending).
     */
    public function index(): JsonResponse
    {
        $teamMembers = TeamMember::query()->orderedForAdmin()->get();

        return response()->json([
            'data' => TeamMemberResource::collection($teamMembers),
        ], 200);
    }
}
