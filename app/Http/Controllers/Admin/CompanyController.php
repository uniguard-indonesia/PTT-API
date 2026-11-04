<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Helper;
use App\Models\Company;
use App\Models\Timezone;
use App\Models\TrCompanyUsers;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class CompanyController extends Controller
{
    /**
     * Instantiate a new controller instance.
     */
    public function __construct()
    {
        $this->middleware("superadmin");
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    private function getTimezoneCode()
    {
        return Auth::user()->company
            ? Auth::user()->company->timezone->code
            : "UTC";
    }
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $data = Company::with("timezone")->latest()->get();
            return DataTables::of($data)
                ->addIndexColumn()
                ->editColumn("created_at", function ($row) {
                    return Carbon::parse(strtotime($row->created_at))
                        ->setTimezone($this->getTimezoneCode())
                        ->isoFormat("DD MMM Y HH:mm z");
                })
                ->editColumn("timezone", function ($row) {
                    return $row->timezone->code;
                })
                ->addColumn("action", function ($row) {
                    return Helper::actionButtons($row, ["edit", "delete"]);
                })
                ->rawColumns(["action"])
                ->make(true);
        } else {
            $data = [
                "title" => "Company",
                "timezones" => Timezone::all(),
            ];
            return view("pages.admin.company", $data);
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
        $input = $request->only([
            "name",
            "email",
            "expire_date",
            "timezone_id",
            "created_by",
            "updated_by",
        ]);
        $validator = Validator::make(
            $input,
            Company::rules(),
            [],
            Company::attributes(),
        );
        if ($validator->fails()) {
            return response()->json(
                [
                    "status" => "error",
                    "errors" => $validator->errors(),
                    "message" => "Bad input values",
                ],
                400,
            );
        } else {
            $create = Company::create($input);
            if ($create) {
                return $this->responseSuccess("Company created Successfully");
            } else {
                return $this->responseError("Internal server Error", 500);
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
        $data = Company::with("tr_users", "timezone")->find($id);
        if ($data) {
            return $this->responseSuccess($data, "data");
        } else {
            return $this->responseError("Data not Exist", 404);
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
        $input = $request->only([
            "name",
            "email",
            "expire_date",
            "updated_by",
            "timezone_id",
        ]);
        $validator = Validator::make(
            $input,
            Company::rules($id),
            [],
            Company::attributes(),
        );
        if ($validator->fails()) {
            return response()->json(
                [
                    "status" => "error",
                    "errors" => $validator->errors(),
                    "message" => "Bad input values",
                ],
                400,
            );
        } else {
            $data = Company::find($id);
            if ($data) {
                $data->update($input);
                return $this->responseSuccess("Company updated Successfully");
            } else {
                return $this->responseError("Data not Exist", 404);
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
        $data = Company::find($id);
        if ($data) {
            $data->delete();
            return $this->responseSuccess("Company deleted Successfully");
        } else {
            return $this->responseError("Data not Exist", 404);
        }
    }
}
