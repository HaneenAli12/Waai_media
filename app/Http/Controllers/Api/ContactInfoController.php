<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactInfoResource;
use App\Models\ContactInfo;
use Illuminate\Http\JsonResponse;

class ContactInfoController extends Controller
{
    /**
     * Retrieve the public contact information.
     *
     * No authentication is required. If contact info has not been configured yet,
     * returns HTTP 200 with data: null.
     */
    public function index(): JsonResponse
    {
        $contactInfo = ContactInfo::instance();

        return response()->json([
            'data' => $contactInfo ? new ContactInfoResource($contactInfo) : null,
        ], 200);
    }
}
