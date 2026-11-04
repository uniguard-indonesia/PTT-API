<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Helper;
use App\Models\Company;
use App\Models\Server;
use App\Models\ServerCompany;
use App\Models\TrServerUsers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class ServerController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    private function __insertCompanies($companies, $server_id, $update = false)
    {
        $result = [];
        foreach ($companies as $idx => $row) {
            $result[] = [
                "server_id" => $server_id,
                "company_id" => $row,
            ];
        }
        if ($update) {
            ServerCompany::where("server_id", $server_id)->delete();
        }
        ServerCompany::insert($result);
        return true;
    }

    private function findAuthorizedServer($id, array $with = [])
    {
        return Server::when(!empty($with), function ($query) use ($with) {
            $query->with($with);
        })
            ->when(!Auth::user()->isSuperAdmin(), function ($query) {
                $query->whereHas("companies", function ($q) {
                    $q->where("company_id", Auth::user()->company_id);
                });
            })
            ->find($id);
    }

    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $data = Server::when(!Auth::user()->isSuperAdmin(), function ($q) {
                $q->whereHas("companies", function ($query) {
                    $query->where("company_id", Auth::user()->company_id);
                });
            })->get();

            return DataTables::of($data)
                ->addIndexColumn()
                ->addColumn("action", function ($row) {
                    if (!Auth::user()->isSuperAdmin()) {
                        return '<span class="badge badge-secondary">Read Only</span>';
                    }
                    return Helper::actionButtons($row, ["edit", "delete"]);
                })
                ->rawColumns(["action"])
                ->make(true);
        } else {
            $data = [
                "title" => "Server List | PTT UniGuard",
                "companies" => Company::when(
                    !Auth::user()->isSuperAdmin(),
                    function ($q) {
                        $q->where("id", Auth::user()->company_id);
                    },
                )->get(),
                "users" => User::when(!Auth::user()->isSuperAdmin(), function (
                    $q,
                ) {
                    $q->where("company_id", Auth::user()->company_id);
                })->get(),
            ];
            return view("pages.admin.server", $data);
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!Auth::user()->isSuperAdmin()) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Unauthorized. Server configuration is read-only for company administrators.",
                ],
                403,
            );
        }
        $input = $request->only([
            "name",
            "host",
            "port",
            "user_id",
            "username",
            "password",
        ]);
        $validator = Validator::make(
            $input,
            Server::rules(),
            [],
            Server::attributes(),
        );
        if ($validator->fails()) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Bad input value",
                    "errors" => $validator->errors(),
                ],
                400,
            );
        } else {
            if ($request->input("password") == "") {
                $input["password"] = (string) "";
            }
            $create = Server::create($input);
            if ($create) {
                $companies =
                    !Auth::user()->isSuperAdmin()
                        ? [Auth::user()->company_id]
                        : $request->companies ?? [];
                $this->__insertCompanies($companies, $create->id);
                return response()->json(
                    [
                        "status" => "success",
                        "message" => "Server create successfully",
                    ],
                    200,
                );
            } else {
                return response()->json(
                    [
                        "status" => "error",
                        "message" => "internal server error",
                    ],
                    500,
                );
            }
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (!Auth::user()->isSuperAdmin()) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Unauthorized. Server configuration is read-only for company administrators.",
                ],
                403,
            );
        }

        $data = $this->findAuthorizedServer($id, ["companies"]);
        if ($data) {
            return response()->json(
                [
                    "status" => "success",
                    "message" => "Server retrieved successfully",
                    "data" => $data,
                ],
                200,
            );
        } else {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Server not exist",
                ],
                404,
            );
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!Auth::user()->isSuperAdmin()) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Unauthorized. Server configuration is read-only for company administrators.",
                ],
                403,
            );
        }
        $input = $request->only([
            "name",
            "host",
            "port",
            "user_id",
            "username",
            "password",
        ]);
        $validator = Validator::make(
            $input,
            Server::rules(),
            [],
            Server::attributes(),
        );
        if ($validator->fails()) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Bad input value",
                    "errors" => $validator->errors(),
                ],
                400,
            );
        } else {
            $data = $this->findAuthorizedServer($id);
            if ($request->input("password") == "") {
                $input["password"] = (string) "";
            }
            if ($data) {
                $data->update($input);
                $companies =
                    !Auth::user()->isSuperAdmin()
                        ? [Auth::user()->company_id]
                        : $request->companies ?? [];
                $this->__insertCompanies($companies, $id, true);
                return response()->json(
                    [
                        "status" => "success",
                        "message" => "Server updated successfully",
                        "data" => $data,
                    ],
                    200,
                );
            } else {
                return response()->json(
                    [
                        "status" => "error",
                        "message" => "Server not exist",
                        "data" => $data,
                    ],
                    404,
                );
            }
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!Auth::user()->isSuperAdmin()) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Unauthorized. Server configuration is read-only for company administrators.",
                ],
                403,
            );
        }
        $data = $this->findAuthorizedServer($id);
        if ($data) {
            $data->delete();
            return response()->json(
                [
                    "status" => "success",
                    "message" => "Server deleted successfully",
                ],
                200,
            );
        } else {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "Server not exist",
                ],
                404,
            );
        }
    }
}
