<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Helper;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Company;
use App\Models\Level;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class UserController extends Controller
{
    private function __uploadPhoto($file, $exist = false)
    {
        $extension = $file->getClientOriginalExtension();
        $safeExtension = in_array(strtolower($extension), ['jpeg', 'jpg', 'png', 'gif', 'webp'])
            ? strtolower($extension)
            : 'jpg';
        $fileName = time() . '_' . uniqid() . '.' . $safeExtension;
        $file->move(public_path("dist/profiles/"), $fileName);
        if ($exist) {
            $existPath = public_path("dist/profiles/" . basename($exist));
            if (file_exists($existPath) && is_file($existPath)) {
                @unlink($existPath);
            }
        }
        return $fileName;
    }

    private function getTimezoneCode()
    {
        return Auth::user()->company?->timezone?->code ?? "UTC";
    }

    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $user = User::manageableBy($request->user())
                ->with("company", "level")
                ->latest()
                ->get();

            return DataTables::of($user)
                ->addIndexColumn()
                ->editColumn("created_at", function ($row) {
                    return Carbon::parse(strtotime($row->created_at))
                        ->setTimezone($this->getTimezoneCode())
                        ->isoFormat("DD MMM Y HH:mm z");
                })
                ->editColumn("company", function ($row) {
                    return $row->company ? $row->company->name : "UNKNOWN";
                })
                ->editColumn("level", function ($row) {
                    return $row->level ? $row->level->name : ($row->isSuperAdmin() ? "Super Admin" : "UNKNOWN");
                })
                ->addColumn("action", function ($row) {
                    return Helper::actionButtons($row, [
                        "reset",
                        "regenerate",
                        "edit",
                        "delete",
                    ]);
                })
                ->rawColumns(["action"])
                ->make(true);
        } else {
            $actor = $request->user();
            $levels = Level::assignableBy($actor)->get();
            if ($actor && $actor->isSuperAdmin()) {
                $superAdminLevel = (object) [
                    "id" => User::LEVEL_SUPER_ADMIN,
                    "name" => "Super Admin",
                ];
                $levels->prepend($superAdminLevel);
            }

            $data = [
                "title" => "User",
                "levels" => $levels,
                "companies" => Company::when(!$actor->isSuperAdmin(), function ($q) use ($actor) {
                    $q->where("id", $actor->company_id);
                })->get(),
            ];
            return view("pages.admin.user", $data);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\Admin\StoreUserRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreUserRequest $request)
    {
        $input = $request->validated();
        $input["password"] = Hash::make($request->password);

        if ($request->hasFile("photo")) {
            $input["photo"] = $this->__uploadPhoto($request->file("photo"));
        } else {
            unset($input["photo"]);
        }

        $create = User::create($input);
        if ($create) {
            // Automatically generated and registered to Mumble in User's booted event (via MumbleService)
            return response()->json(
                [
                    "status" => "success",
                    "message" => "User created successfully",
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

    /**
     * Reset the user password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function reset(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            "new_password" => ["required", "string", "min:6"],
        ]);

        if ($validator->fails()) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "The given data was invalid.",
                    "fields" => $validator->errors(),
                    "errors" => $validator->errors(),
                ],
                422,
            );
        }

        $data = User::manageableBy($request->user())->findOrFail($id);

        $data->update([
            "password" => Hash::make($request->new_password),
        ]);

        // Invalidate active Sanctum tokens
        $data->tokens()->delete();

        return response()->json(
            [
                "status" => "success",
                "message" => "Reset password successfully",
                "data" => $data,
            ],
            200,
        );
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $data = User::manageableBy($request->user())
            ->with(["servers.server"])
            ->findOrFail($id);

        return response()->json(
            [
                "status" => "success",
                "message" => "Data retrieved successfully",
                "data" => $data,
            ],
            200,
        );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\Admin\UpdateUserRequest  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateUserRequest $request, $id)
    {
        $actor = $request->user();
        $data = User::manageableBy($actor)->findOrFail($id);

        // Prevent self-escalation
        if ((int) $actor->id === (int) $data->id) {
            if ($request->has('level_id') && (int) $request->input('level_id') !== (int) $data->level_id) {
                return response()->json(
                    [
                        "status" => "error",
                        "message" => "You cannot modify your own level.",
                    ],
                    403,
                );
            }
            if ($request->has('company_id') && (int) $request->input('company_id') !== (int) $data->company_id) {
                return response()->json(
                    [
                        "status" => "error",
                        "message" => "You cannot modify your own company.",
                    ],
                    403,
                );
            }
        }

        $input = $request->validated();

        if ((int) $actor->id === (int) $data->id) {
            unset($input['level_id'], $input['company_id']);
        }

        if ($request->hasFile("photo")) {
            $input["photo"] = $this->__uploadPhoto($request->file("photo"), $data->photo);
        } else {
            unset($input["photo"]);
        }

        $data->update($input);

        return response()->json(
            [
                "status" => "success",
                "message" => "Data updated successfully",
                "data" => $data,
            ],
            200,
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $actor = $request->user();
        $data = User::manageableBy($actor)->with(["certificate"])->findOrFail($id);

        // Prevent self-deletion
        if ((int) $actor->id === (int) $data->id) {
            return response()->json(
                [
                    "status" => "error",
                    "message" => "You cannot delete your own account.",
                ],
                403,
            );
        }

        if ($data->certificate) {
            $certPath = public_path($data->certificate->certificate_path);
            if (file_exists($certPath)) {
                @unlink($certPath);
            }
        }
        $data->delete();

        return response()->json(
            [
                "status" => "success",
                "message" => "Data deleted successfully",
            ],
            200,
        );
    }

    /**
     * Regenerate and register certificate for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function regenerateCertificate(Request $request, $id)
    {
        $user = User::manageableBy($request->user())->findOrFail($id);

        // Call generateAndRegister to create new certificate and sync it to Mumble
        $success = \App\Services\MumbleService::generateAndRegister($user);

        if ($success) {
            return response()->json([
                "status" => "success",
                "message" => "Certificate regenerated and registered successfully",
            ], 200);
        }

        return response()->json(
            [
                "status" => "error",
                "message" => "Failed to regenerate certificate",
            ],
            500,
        );
    }
}
