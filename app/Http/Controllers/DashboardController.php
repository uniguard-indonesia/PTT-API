<?php

namespace App\Http\Controllers;

use App\Http\Helper;
use App\Models\Company;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $authUser = Auth::user();
        $isSuperAdmin = $authUser->isSuperAdmin();

        $data = [
            "title" => "Dashboard",
            "countServer" => $isSuperAdmin
                ? Server::count()
                : Server::whereHas("companies", function ($q) {
                    $q->where("company_id", Auth::user()->company_id);
                })->count(),
            "countUser" => User::when(!$isSuperAdmin, function ($query) {
                $query->where("company_id", Auth::user()->company_id);
            })->count(),
            "countCompanies" => $isSuperAdmin ? Company::count() : 1,
        ];
        return view("pages.dashboard", $data);
    }
    public function markersUser(Request $request)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $isSuperAdmin = $authUser->isSuperAdmin();

        // Fail-closed scoping: if non-superadmin has empty or invalid company_id, short-circuit immediately.
        if (!$isSuperAdmin && (empty($authUser->company_id) || (int) $authUser->company_id <= 0)) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ], 200);
        }

        // Sanitized projection: omit sensitive columns (password, remember_token)
        $query = User::select(
            'users.id',
            'users.name',
            'users.company_id',
            'users.position_id',
            'tz.code as code'
        )
            ->join('companies as co', 'co.id', '=', 'users.company_id')
            ->leftJoin('timezones as tz', 'tz.id', '=', 'co.timezone_id')
            ->whereHas('position', function ($query) {
                $query->whereNotNull('latitude')
                    ->where('latitude', '!=', '')
                    ->where('latitude', '!=', 'null')
                    ->whereNotNull('longitude')
                    ->where('longitude', '!=', '')
                    ->where('longitude', '!=', 'null');
            })
            ->with(['position' => function ($q) {
                $q->select('id', 'user_id', 'latitude', 'longitude', 'created_at');
            }]);

        if ($isSuperAdmin) {
            // Super Admin can optionally filter by company_id query parameter
            $companyId = $request->input('company_id');
            if (
                $request->filled('company_id') &&
                (is_int($companyId) || (is_string($companyId) && filter_var($companyId, FILTER_VALIDATE_INT) !== false)) &&
                (int) $companyId > 0
            ) {
                $query->where('users.company_id', (int) $companyId);
            }
        } else {
            // Non-superadmin is strictly locked to their assigned company_id.
            // Any ?company_id= query parameter is strictly ignored to prevent tampering.
            $query->where('users.company_id', (int) $authUser->company_id);
        }

        $data = $query->get();
        $data->transform(function ($item) {
            if (!empty($item->code)) {
                $item->code = Helper::sanitizeTimezone($item->code);
            }
            return $item;
        });

        return response()->json(
            [
                'status' => 'success',
                'data' => $data,
            ],
            200,
        );
    }
}
