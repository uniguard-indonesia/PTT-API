<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Helper;
use App\Models\Company;
use App\Models\LogActivity;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Yajra\DataTables\Utilities\Request as DataTablesRequest;

class LogActivitiesController extends Controller
{
    public const MAX_EXPORT_ROWS = 10000;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $authUser = Auth::user();

        if (!$authUser || (!$authUser->isSuperAdmin() && !$authUser->isCompanyAdmin())) {
            return $request->wantsJson()
                ? response()->json(['message' => 'Forbidden.'], 403)
                : response('Forbidden.', 403);
        }

        if ($request->wantsJson()) {
            $query = LogActivity::query()
                ->select([
                    'log_activities.id',
                    'log_activities.user_id',
                    'log_activities.activity',
                    'log_activities.attachment',
                    'log_activities.created_at',
                ])
                ->with([
                    'user:id,name,company_id',
                    'user.company:id,name,timezone_id',
                    'user.company.timezone:id,code',
                ]);

            $selectedCompanyId = null;

            if ($authUser && $authUser->isSuperAdmin()) {
                $companyId = $request->input('company_id');
                if (
                    $request->filled('company_id') &&
                    (is_int($companyId) || (is_string($companyId) && filter_var($companyId, FILTER_VALIDATE_INT) !== false)) &&
                    (int) $companyId > 0
                ) {
                    $selectedCompanyId = (int) $companyId;
                    $query->whereHas('user', function ($q) use ($selectedCompanyId) {
                        $q->where('company_id', $selectedCompanyId);
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

            $fallbackTimezone = Helper::sanitizeTimezone($authUser->company?->timezone?->code);
            $selectedCompany = $selectedCompanyId !== null
                ? Company::with('timezone:id,code')->find($selectedCompanyId, ['id', 'timezone_id'])
                : null;
            $timezone = $selectedCompany
                ? Helper::sanitizeTimezone($selectedCompany->timezone?->code, $fallbackTimezone)
                : $fallbackTimezone;

            $parseDate = static function ($value) use ($timezone) {
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                    return null;
                }

                [$year, $month, $day] = array_map('intval', explode('-', $value));
                if (!checkdate($month, $day, $year)) {
                    return null;
                }

                return Carbon::createFromFormat('!Y-m-d', $value, $timezone);
            };
            $startDate = $parseDate($request->input('start_date'));
            $endDate = $parseDate($request->input('end_date'));
            $storageTimezone = config('app.timezone', 'UTC');

            if ($startDate && $endDate && $startDate->greaterThan($endDate)) {
                $query->whereRaw('0 = 1');
            } else {
                if ($startDate) {
                    $query->where('created_at', '>=', $startDate->copy()->startOfDay()->setTimezone($storageTimezone));
                }
                if ($endDate) {
                    $query->where('created_at', '<=', $endDate->copy()->endOfDay()->setTimezone($storageTimezone));
                }
            }

            $columns = $request->input('columns', []);
            $orders = $request->input('order', []);
            $sortableOrders = [];

            if (is_array($columns) && is_array($orders)) {
                foreach ($orders as $order) {
                    if (!is_array($order)) {
                        continue;
                    }

                    $columnIndex = filter_var($order['column'] ?? null, FILTER_VALIDATE_INT);
                    if (
                        $columnIndex === false ||
                        !isset($columns[$columnIndex]) ||
                        !is_array($columns[$columnIndex]) ||
                        !in_array($columns[$columnIndex]['orderable'] ?? 'true', [true, 'true'], true)
                    ) {
                        continue;
                    }

                    $rawName = $columns[$columnIndex]['name'] ?? null;
                    $rawData = $columns[$columnIndex]['data'] ?? null;
                    $column = (is_string($rawName) && trim($rawName) !== '')
                        ? trim($rawName)
                        : (is_string($rawData) ? trim($rawData) : null);
                    if (!in_array($column, ['created_at', 'activity'], true)) {
                        continue;
                    }

                    $direction = strtolower(is_string($order['dir'] ?? null) ? $order['dir'] : '') === 'asc'
                        ? 'asc'
                        : 'desc';
                    $sortableOrders[] = [$column, $direction];
                }
            }

            if ($sortableOrders) {
                foreach ($sortableOrders as [$column, $direction]) {
                    $query->orderBy($column, $direction);
                }
            } else {
                $query->latest();
            }

            $locationCache = new \WeakMap();
            $extractRowLocation = static function ($row) use ($locationCache): array {
                if (is_object($row)) {
                    if (!isset($locationCache[$row])) {
                        $locationCache[$row] = LogActivity::extractLocationData($row->activity ?? null);
                    }
                    return $locationCache[$row];
                }

                return LogActivity::extractLocationData($row->activity ?? null);
            };

            // Refresh Yajra's singleton request wrapper so multi-request lifecycles never read stale query params.
            app()->instance('datatables.request', new DataTablesRequest());

            $dataTable = DataTables::of($query)
                ->addColumn('name', function ($row) {
                    return $row->user ? $row->user->name : 'UNKNOWN';
                })
                ->filterColumn('name', function ($query, $keyword) {
                    $escaped = addcslashes((string) $keyword, '\\%_');
                    $query->whereHas('user', function ($q) use ($escaped) {
                        $q->where('name', 'like', '%' . $escaped . '%');
                    });
                })
                ->addColumn('company', function ($row) {
                    return ($row->user && $row->user->company) ? $row->user->company->name : 'UNKNOWN';
                })
                ->filterColumn('company', function ($query, $keyword) {
                    $escaped = addcslashes((string) $keyword, '\\%_');
                    $query->whereHas('user.company', function ($q) use ($escaped) {
                        $q->where('name', 'like', '%' . $escaped . '%');
                    });
                })
                ->editColumn('created_at', function ($row) {
                    if (empty($row->created_at)) {
                        return '';
                    }
                    $tzCode = Helper::sanitizeTimezone($row->user?->company?->timezone?->code);
                    return Carbon::parse($row->created_at)->setTimezone($tzCode)->isoFormat('DD MMM Y HH:mm z');
                })
                ->editColumn('activity', function ($row) use ($extractRowLocation) {
                    return $extractRowLocation($row)['activity'];
                })
                ->addColumn('latitude', function ($row) use ($extractRowLocation) {
                    return $extractRowLocation($row)['latitude'];
                })
                ->addColumn('longitude', function ($row) use ($extractRowLocation) {
                    return $extractRowLocation($row)['longitude'];
                });

            $dataTable->order(static function () {
                // The base query already applies validated ordering.
            });

            $rawLength = $request->input('length');
            $isUnpaginated = is_numeric($rawLength) && (int) $rawLength === -1;
            if ($isUnpaginated) {
                $dataTable->skipPaging();
                $dataTable->prepareQuery();

                $maxExportRows = (int) config('datatables.max_export_rows', self::MAX_EXPORT_ROWS);
                $filteredCount = $dataTable->count();
                if ($maxExportRows > 0 && $filteredCount > $maxExportRows) {
                    return response()->json([
                        'status' => 'error',
                        'message' => sprintf(
                            'Export limit exceeded (%s rows match your filters; maximum %s rows per export). Please narrow your date range or search filters.',
                            number_format($filteredCount),
                            number_format($maxExportRows)
                        ),
                    ], 422);
                }
            }

            return $dataTable->make(true);
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
        $actor = $request->user() ?? Auth::user();
        if (!$actor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'activity' => ['required', 'string', 'max:255'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:mp3,wav,ogg,ogx,m4a,aac,mpga,mp4',
                'max:10240',
                function ($attribute, $value, $fail) {
                    if (!$value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, LogActivity::ALLOWED_AUDIO_EXTENSIONS, true)) {
                        $fail('The attachment must be a valid audio file (mp3, wav, ogg, m4a, aac).');
                        return;
                    }

                    $realPath = $value->getRealPath();
                    $handle = $realPath ? @fopen($realPath, 'rb') : false;
                    if ($handle === false) {
                        $fail('The attachment file is unreadable.');
                        return;
                    }

                    $carry = '';
                    $hasExecutableTag = false;
                    while (!feof($handle)) {
                        $chunk = fread($handle, 65536);
                        if ($chunk === false) {
                            fclose($handle);
                            $fail('The attachment file is unreadable.');
                            return;
                        }
                        $window = $carry . $chunk;
                        if (
                            stripos($window, '<?php') !== false ||
                            stripos($window, '<script') !== false ||
                            preg_match('/<\?=[\x09\x0a\x0d\x20-\x7e]{1,200}\?>/', $window) ||
                            preg_match('/<\?=\s*(?:system|exec|shell_exec|passthru|eval|phpinfo|assert|proc_open|popen|echo|print|\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES))\b/i', $window)
                        ) {
                            $hasExecutableTag = true;
                            break;
                        }
                        $carry = substr($window, -256);
                    }
                    fclose($handle);

                    if ($hasExecutableTag) {
                        $fail('The attachment contains disallowed executable content.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $input = [
            'user_id' => $actor->id,
            'activity' => $validated['activity'],
        ];

        $movedFilePath = null;
        try {
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $safeExtension = strtolower($file->getClientOriginalExtension());
                $fileName = Str::uuid() . '.' . $safeExtension;
                $directory = LogActivity::storageDirectory();

                if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Unable to create log activity storage directory.');
                }

                $file->move($directory, $fileName);
                $movedFilePath = $directory . DIRECTORY_SEPARATOR . $fileName;
                @chmod($movedFilePath, 0640);
                $input['attachment'] = 'audio/' . $fileName;
            }

            $create = LogActivity::create($input);
            if ($create) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Log Activity Created'
                ], 200);
            }
        } catch (\Throwable $e) {
            if ($movedFilePath && is_file($movedFilePath)) {
                @unlink($movedFilePath);
            }

            Log::error('LogActivity store failed.', [
                'user_id' => $actor->id,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Log Activity failed to create'
            ], 500);
        }

        if ($movedFilePath && is_file($movedFilePath)) {
            @unlink($movedFilePath);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Log Activity failed to create'
        ], 500);
    }

    /**
     * Serve an activity log audio attachment with authentication and tenant scoping.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|int  $logActivity
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function attachment(Request $request, $logActivity)
    {
        $authUser = Auth::user();

        if (!$authUser || (!$authUser->isSuperAdmin() && !$authUser->isCompanyAdmin())) {
            return $request->wantsJson()
                ? response()->json(['message' => 'Forbidden.'], 403)
                : response('Forbidden.', 403);
        }

        $logActivityId = filter_var((string) $logActivity, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($logActivityId === false) {
            abort(404);
        }

        $record = LogActivity::with('user')->find($logActivityId);
        if (!$record) {
            abort(404);
        }

        if (!$authUser->isSuperAdmin()) {
            if (
                empty($authUser->company_id) ||
                (int) $authUser->company_id <= 0 ||
                !$record->user ||
                (int) $record->user->company_id !== (int) $authUser->company_id
            ) {
                abort(404);
            }
        }

        $filePath = LogActivity::resolveAttachmentFilePath($record->attachment);
        if ($filePath === null) {
            abort(404);
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = LogActivity::mimeTypeForExtension($extension);

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
