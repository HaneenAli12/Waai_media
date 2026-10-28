<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Clients\IndexClientRequest;
use App\Http\Requests\Admin\Clients\StoreClientRequest;
use App\Http\Requests\Admin\Clients\UpdateClientRequest;
use App\Http\Resources\ClientAdminResource;
use App\Models\Client;
use App\Services\Clients\ClientLogoService;
use App\Services\Storage\Exceptions\InvalidImageException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /**
     * Default page size for the admin client listing.
     */
    public const PER_PAGE = 10;

    public function __construct(
        protected ClientLogoService $logoService
    ) {}

    /**
     * List clients with page-number pagination.
     */
    public function index(IndexClientRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        $paginated = Client::query()->orderedForAdmin()->paginate($perPage);

        return ClientAdminResource::collection($paginated)->response();
    }

    /**
     * Display a single client.
     */
    public function show(Client $client): JsonResponse
    {
        return response()->json([
            'data' => new ClientAdminResource($client),
        ], 200);
    }

    /**
     * Create a client with required logo.
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $validated = $request->safe()->only(['name']);

        $uploadedKey = $this->uploadLogo($request);
        if ($uploadedKey !== null) {
            $validated['logo_path'] = $uploadedKey;
        }

        try {
            $client = DB::transaction(fn () => Client::create($validated));
        } catch (\Throwable $e) {
            $this->logoService->deleteIfUnshared($uploadedKey);

            throw $e;
        }

        return response()->json([
            'data' => new ClientAdminResource($client),
        ], 201);
    }

    /**
     * Update name and/or logo.
     *
     * The previous logo is deleted only after the new one is persisted; on failure the
     * newly uploaded object is removed and the existing record/logo stay untouched.
     */
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $validated = $request->safe()->only(['name']);

        $uploadedKey = $this->uploadLogo($request);
        if ($uploadedKey !== null) {
            $validated['logo_path'] = $uploadedKey;
        }

        $previousKey = null;

        try {
            DB::transaction(function () use ($client, $validated, &$previousKey) {
                // Read committed key under a row lock so exactly the superseded object is removed
                // and concurrent replacements cannot orphan each other's uploads.
                $current = DB::table('clients')->where('id', $client->id)->lockForUpdate()->first(['id', 'logo_path']);
                if ($current === null) {
                    throw (new ModelNotFoundException)->setModel(Client::class, [$client->id]);
                }

                $previousKey = $current->logo_path;
                $client->update($validated);
            });
        } catch (\Throwable $e) {
            $this->logoService->deleteIfUnshared($uploadedKey);

            throw $e;
        }

        if ($uploadedKey !== null && $previousKey !== null && $previousKey !== $uploadedKey) {
            $this->logoService->deleteIfUnshared($previousKey, $client->id);
        }

        return response()->json([
            'data' => new ClientAdminResource($client->fresh()),
        ], 200);
    }

    /**
     * Permanently delete a client and its logo.
     *
     * Accessible by Administrator ONLY (route middleware + controller-level check).
     * The R2 object is removed after the database delete commits; storage failures are logged
     * and never fail the request.
     */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAnyAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Only administrators can permanently delete clients.',
            ], 403);
        }

        $logoKey = $client->logo_path;
        $clientId = $client->id;

        DB::transaction(function () use ($client, &$logoKey) {
            $logoKey = DB::table('clients')->where('id', $client->id)->lockForUpdate()->value('logo_path') ?? $logoKey;
            $client->delete();
        });

        $this->logoService->deleteIfUnshared($logoKey, $clientId);

        return response()->json([
            'message' => 'Client deleted successfully.',
        ], 200);
    }

    /**
     * Upload the request logo (if any) and return its R2 key.
     *
     * @throws ValidationException when the content fails server-side image inspection
     */
    private function uploadLogo(Request $request): ?string
    {
        if (! $request->hasFile('logo')) {
            return null;
        }

        try {
            return $this->logoService->upload($request->file('logo'));
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages([
                'logo' => [$e->getMessage()],
            ]);
        }
    }
}
