<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Helper;
use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class LevelController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            return DataTables::of(Level::latest()->get())
                ->addIndexColumn()
                ->addColumn("action", function ($row) {
                    return Helper::actionButtons($row, ["edit", "delete"]);
                })
                ->rawColumns(["action"])
                ->make(true);
        } else {
            $data = [
                "title" => "Level",
            ];
            return view("pages.admin.level", $data);
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
        $input = $request->only(["name", "menu"]);
        $validator = Validator::make(
            $input,
            Level::rules(),
            [],
            Level::attributes(),
        );
        if ($validator->fails()) {
            $this->responseError($validator->errors(), 400);
        } else {
            $menus = array_values(
                array_filter((array) $request->menu, function ($m) {
                    return $m !== "Company";
                }),
            );
            $input["menu"] = implode(",", $menus);
            $create = Level::create($input);
            if ($create) {
                return $this->responseSuccess("Level created Successfully");
            } else {
                return $this->responseError("internal server error", 500);
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
        $data = Level::find($id);
        if ($data) {
            return $this->responseSuccess($data, "data");
        } else {
            return $this->responseError("Level not exist", 404);
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
        $input = $request->only(["name", "menu"]);
        $validator = Validator::make(
            $input,
            Level::rules(),
            [],
            Level::attributes(),
        );
        if ($validator->fails()) {
            $this->responseError($validator->errors(), 400);
        } else {
            $data = Level::find($id);
            if ($data) {
                $menus = array_values(
                    array_filter((array) $request->menu, function ($m) {
                        return $m !== "Company";
                    }),
                );
                $input["menu"] = implode(",", $menus);
                $data->update($input);
                return $this->responseSuccess("Level updated Successfully");
            } else {
                return $this->responseError("Level not exist", 404);
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
        $data = Level::find($id);
        if ($data) {
            $data->delete();
            return $this->responseSuccess("Level deleted Successfully");
        } else {
            return $this->responseError("Level not exist", 404);
        }
    }
}
