<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogActivity;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class LogActivitiesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = LogActivity::with('user.company.timezone')
                ->whereHas('user.company', function($query){
                    $query->when(Auth::user()->level_id != 0, function($query){
                        $query->where('company_id', Auth::user()->company_id);
                    });
                })
                ->latest();
            return DataTables::of($query)
                ->addColumn('name', function($row){
                    return $row->user?$row->user->name:'UNKNOWN';
                })
                ->addColumn('company', function($row){
                    return $row->user?$row->user->company->name:'UNKNOWN';
                })
                ->editColumn('created_at', function($row){
                    return Carbon::parse(strtotime($row->created_at))->setTimezone($row->user->company->timezone->code)->isoFormat('DD MMM Y HH:mm z');
                })
                ->make(true);
        } else {
            $data = [
                'title' => 'Log Activities'
            ];
            return view('pages.admin.log-activities', $data);
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
        $input = [
            'user_id' => Auth::user()->id,
            'activity' => $request->activity
        ];
        if ($request->has('attachment')) {
            $file = $request->file('attachment');

            $fileName = $file->getClientOriginalName();
            $file->move(public_path('audio/'), $fileName);
            $input['attachment'] = 'audio/' . $fileName;
        }

        $create = LogActivity::create($input);
        if ($create) {
            return response()->json([
                'status' => 'success',
                'message' => 'Log Activity Created'
            ], 200);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Log Activity failed to create'
            ], 500);
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
        //
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
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
