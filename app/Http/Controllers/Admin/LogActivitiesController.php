<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
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
        $authUser = Auth::user();

        if ($request->wantsJson()) {
            $query = LogActivity::with('user.company.timezone');

            if ($authUser && $authUser->isSuperAdmin()) {
                if ($request->filled('company_id') && is_numeric($request->company_id) && (int) $request->company_id > 0) {
                    $query->whereHas('user', function ($q) use ($request) {
                        $q->where('company_id', (int) $request->company_id);
                    });
                }
            } else {
                if (!$authUser || empty($authUser->company_id) || (int) $authUser->company_id <= 0) {
                    $query->whereRaw('0 = 1');
                } else {
                    $query->whereHas('user', function ($q) use ($authUser) {
                        $q->where('company_id', (int) $authUser->company_id);
                    });
                }
            }

            $query->latest();

            return DataTables::of($query)
                ->addColumn('name', function ($row) {
                    return $row->user ? $row->user->name : 'UNKNOWN';
                })
                ->addColumn('company', function ($row) {
                    return ($row->user && $row->user->company) ? $row->user->company->name : 'UNKNOWN';
                })
                ->editColumn('created_at', function ($row) {
                    $tzCode = $row->user?->company?->timezone?->code ?? config('app.timezone', 'UTC');
                    return Carbon::parse($row->created_at)->setTimezone($tzCode)->isoFormat('DD MMM Y HH:mm z');
                })
                ->make(true);
        } else {
            $companies = ($authUser && $authUser->isSuperAdmin())
                ? Company::orderBy('name')->get(['id', 'name'])
                : collect();

            $data = [
                'title' => 'Log Activities',
                'companies' => $companies,
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
