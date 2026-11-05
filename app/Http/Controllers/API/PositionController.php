<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Http\Resources\UserPositionResource;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PositionController extends Controller
{
    /**
     * Retrieve GPS positions scoped to the tenant of the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getData(Request $request)
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Fail closed if non-superadmin has no company assigned
        if (!$actor->isSuperAdmin() && empty($actor->company_id)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Data retrieved Successfully',
                'data' => [],
            ], 200);
        }

        $query = Position::query();

        if ($actor->isSuperAdmin()) {
            if ($request->filled('company_id') && is_numeric($request->company_id) && (int) $request->company_id > 0) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('company_id', (int) $request->company_id);
                });
            }
        } else {
            $query->inTenantOf($actor);
        }

        $data = $query->get();

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Data retrieved Successfully',
                'data' => PositionResource::collection($data),
            ],
            200
        );
    }

    /**
     * Retrieve the last position for a specific user or all users in the tenant.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLastPositionUser(Request $request)
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Fail closed for non-superadmin without company_id
        if (!$actor->isSuperAdmin() && empty($actor->company_id)) {
            if ($request->has('id_user')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found',
                ], 404);
            }
            return response()->json([
                'status' => 'success',
                'message' => 'Data retrieved Successfully',
                'data' => [],
            ], 200);
        }

        // Single user query (with IDOR prevention)
        if ($request->has('id_user')) {
            $targetUserId = $request->id_user;

            if ($targetUserId === null || $targetUserId === '' || !is_numeric($targetUserId) || (int) $targetUserId <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found',
                ], 404);
            }

            $query = User::with(['position', 'company.timezone'])
                ->leftJoin('positions as p', function ($join) {
                    $join->on('p.id', '=', 'users.position_id')
                        ->where('users.position_id', '>', 0);
                })
                ->leftJoin('companies as co', 'co.id', '=', 'users.company_id')
                ->leftJoin('timezones as tz', 'tz.id', '=', 'co.timezone_id')
                ->select(
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.photo',
                    'users.company_id',
                    'users.level_id',
                    'users.position_id',
                    'users.created_at',
                    'users.updated_at',
                    'p.latitude',
                    'p.longitude',
                    'co.name as company_name',
                    'tz.name as timezone_name'
                );

            if ($actor->isSuperAdmin()) {
                $user = $query->where('users.id', $targetUserId)->first();
            } else {
                $user = $query->inTenantOf($actor)->where('users.id', $targetUserId)->first();
            }

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found',
                ], 404);
            }

            return response()->json(
                [
                    'status' => 'success',
                    'message' => 'Data retrieved Successfully',
                    'data' => new UserPositionResource($user),
                ],
                200
            );
        }

        // Listing mode
        $query = User::whereNotNull('users.position_id')
            ->where('users.position_id', '>', 0)
            ->has('position')
            ->with(['position', 'company.timezone'])
            ->leftJoin('positions as p', function ($join) {
                $join->on('p.id', '=', 'users.position_id')
                    ->where('users.position_id', '>', 0);
            })
            ->leftJoin('companies as co', 'co.id', '=', 'users.company_id')
            ->leftJoin('timezones as tz', 'tz.id', '=', 'co.timezone_id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.photo',
                'users.company_id',
                'users.level_id',
                'users.position_id',
                'users.created_at',
                'users.updated_at',
                'p.latitude',
                'p.longitude',
                'co.name as company_name',
                'tz.name as timezone_name'
            );

        if ($actor->isSuperAdmin()) {
            if ($request->filled('company_id') && is_numeric($request->company_id) && (int) $request->company_id > 0) {
                $query->where('users.company_id', (int) $request->company_id);
            }
        } else {
            $query->inTenantOf($actor);
        }

        $data = $query->get();

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Data retrieved Successfully',
                'data' => UserPositionResource::collection($data),
            ],
            200
        );
    }

    /**
     * Store a new position for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $input = $request->only(['latitude', 'longitude']);
        $validator = Validator::make(
            $input,
            Position::rules(),
            [],
            Position::attributes()
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors(),
                    'fields' => $validator->errors(),
                ],
                422
            );
        }

        try {
            $position = DB::transaction(function () use ($actor, $input) {
                $pos = Position::create([
                    'user_id' => $actor->id,
                    'latitude' => (string) $input['latitude'],
                    'longitude' => (string) $input['longitude'],
                ]);

                User::where('id', $actor->id)->update([
                    'position_id' => $pos->id,
                ]);

                return $pos;
            });

            $actor->position_id = $position->id;

            // Activity logging is best-effort: a failure here must not turn a saved position into a 500.
            try {
                $this->saveActivity('Updating Location', $actor->id);
            } catch (\Throwable $e) {
                Log::warning('Position: failed to save activity log', [
                    'user_id' => $actor->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json(
                [
                    'status' => 'success',
                    'message' => 'Position create successfully',
                    'data' => new PositionResource($position),
                ],
                200
            );
        } catch (\Throwable $e) {
            Log::error('Position: failed to store position', [
                'user_id' => $actor->id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'internal server error',
                ],
                500
            );
        }
    }
}
