<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $isSuperAdmin = (int) Auth::user()->level_id === 0;

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
    public function markersUser()
    {
        $authUser = Auth::user();
        $isSuperAdmin = (int) $authUser->level_id === 0;

        $data = User::select("users.*", "tz.code")
            ->join("companies as co", "co.id", "=", "users.company_id")
            ->join("timezones as tz", "tz.id", "=", "co.timezone_id")
            ->whereHas("position", function ($query) {
                $query->whereNotNull("latitude")
                    ->where("latitude", "!=", "")
                    ->where("latitude", "!=", "null")
                    ->whereNotNull("longitude")
                    ->where("longitude", "!=", "")
                    ->where("longitude", "!=", "null");
            })
            ->with("position")
            ->when(!$isSuperAdmin, function ($query) use ($authUser) {
                if (empty($authUser->company_id)) {
                    return $query->whereRaw("0 = 1");
                }
                return $query->where(
                    "users.company_id",
                    $authUser->company_id,
                );
            })
            ->get();

        return response()->json(
            [
                "status" => "success",
                "data" => $data,
            ],
            200,
        );
    }
}
