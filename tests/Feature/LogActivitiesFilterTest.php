<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Level;
use App\Models\LogActivity;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogActivitiesFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected User $superAdmin;
    protected User $companyAdminA;
    protected User $companyAdminB;
    protected User $userA;
    protected User $userB;
    protected Company $companyA;
    protected Company $companyB;
    protected Timezone $timezone;
    protected Level $levelCompanyAdmin;
    protected Level $levelUser;

    private function createUserWithoutRegistration(array $attributes): User
    {
        return User::withoutEvents(function () use ($attributes) {
            return User::create($attributes);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->timezone = Timezone::firstOrCreate(
            ['code' => 'Australia/Perth'],
            ['name' => 'Perth Timezone']
        );

        $this->companyA = Company::create([
            'name' => 'Company A ' . uniqid(),
            'email' => 'comp_a_' . uniqid() . '@test.com',
            'timezone_id' => $this->timezone->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);

        $this->companyB = Company::create([
            'name' => 'Company B ' . uniqid(),
            'email' => 'comp_b_' . uniqid() . '@test.com',
            'timezone_id' => $this->timezone->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);

        $this->levelCompanyAdmin = Level::firstOrCreate(
            ['id' => User::LEVEL_COMPANY_ADMIN],
            ['name' => 'Company Admin', 'menu' => 'Dashboard']
        );

        $this->levelUser = Level::firstOrCreate(
            ['id' => User::LEVEL_USER],
            ['name' => 'User', 'menu' => 'Dashboard']
        );

        $this->superAdmin = $this->createUserWithoutRegistration([
            'name' => 'Super Admin',
            'email' => 'superadmin_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_SUPER_ADMIN,
            'company_id' => 0,
        ]);

        $this->companyAdminA = $this->createUserWithoutRegistration([
            'name' => 'Admin Company A',
            'email' => 'admin_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_COMPANY_ADMIN,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminB = $this->createUserWithoutRegistration([
            'name' => 'Admin Company B',
            'email' => 'admin_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_COMPANY_ADMIN,
            'company_id' => $this->companyB->id,
        ]);

        $this->userA = $this->createUserWithoutRegistration([
            'name' => 'User In Company A',
            'email' => 'user_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_USER,
            'company_id' => $this->companyA->id,
        ]);

        $this->userB = $this->createUserWithoutRegistration([
            'name' => 'User In Company B',
            'email' => 'user_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_USER,
            'company_id' => $this->companyB->id,
        ]);
    }

    public function test_super_admin_view_receives_companies_list_ordered_by_name(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.log-activity.index'));

        $response->assertOk()
            ->assertViewIs('pages.admin.log-activities')
            ->assertViewHas('companies');

        $companies = $response->viewData('companies');
        $this->assertNotEmpty($companies);

        $content = $response->getContent();
        // Super Admin must see the dropdown with "All Companies"
        $this->assertStringContainsString('id="Company"', $content);
        $this->assertStringContainsString('All Companies', $content);
        $this->assertStringContainsString($this->companyA->name, $content);
        $this->assertStringContainsString($this->companyB->name, $content);

        // Must NOT contain hardcoded "HMV"
        $this->assertStringNotContainsString('value="HMV"', $content);
        $this->assertStringNotContainsString('>HMV<', $content);
    }

    public function test_company_admin_view_receives_empty_companies_collection_and_no_dropdown(): void
    {
        $response = $this->actingAs($this->companyAdminA)->get(route('admin.log-activity.index'));

        $response->assertOk()
            ->assertViewIs('pages.admin.log-activities')
            ->assertViewHas('companies');

        $companies = $response->viewData('companies');
        $this->assertCount(0, $companies);

        $content = $response->getContent();
        // Dropdown must not be rendered for Company Admin
        $this->assertStringNotContainsString('id="Company"', $content);
        $this->assertStringNotContainsString('value="HMV"', $content);
    }

    public function test_super_admin_datatable_can_filter_by_company_id(): void
    {
        $activityA = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Activity in Company A ' . uniqid(),
        ]);

        $activityB = LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => 'Activity in Company B ' . uniqid(),
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', ['company_id' => (string) $this->companyA->id])
        );

        $response->assertOk();
        $activities = collect($response->json('data'))->pluck('activity')->all();

        $this->assertContains($activityA->activity, $activities);
        $this->assertNotContains($activityB->activity, $activities);
    }

    public function test_super_admin_datatable_without_filter_returns_all_companies(): void
    {
        $activityA = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Global Log Company A ' . uniqid(),
        ]);

        $activityB = LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => 'Global Log Company B ' . uniqid(),
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(route('admin.log-activity.index'));

        $response->assertOk();
        $activities = collect($response->json('data'))->pluck('activity')->all();

        $this->assertContains($activityA->activity, $activities);
        $this->assertContains($activityB->activity, $activities);
    }

    public function test_super_admin_activity_name_and_missing_company_are_displayed_for_companyless_user(): void
    {
        $activity = LogActivity::create([
            'user_id' => $this->superAdmin->id,
            'activity' => 'Super Admin Activity',
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(route('admin.log-activity.index'));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $activity->id);

        $this->assertNotNull($row);
        $this->assertSame($this->superAdmin->name, $row['name']);
        $this->assertSame('UNKNOWN', $row['company']);
    }

    public function test_super_admin_ignores_non_integer_company_filters(): void
    {
        $activityA = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Invalid Filter Company A',
        ]);
        $activityB = LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => 'Invalid Filter Company B',
        ]);

        $invalidCompanyIds = [
            '0',
            '-1',
            '1.5',
            '1e2',
            'abc',
            ['1'],
            $this->companyA->id . '.5',
            $this->companyA->id . 'e0',
        ];

        foreach ($invalidCompanyIds as $companyId) {
            $response = $this->actingAs($this->superAdmin)->getJson(
                route('admin.log-activity.index', ['company_id' => $companyId])
            );

            $response->assertOk();
            $activities = collect($response->json('data'))->pluck('activity')->all();
            $this->assertContains($activityA->activity, $activities);
            $this->assertContains($activityB->activity, $activities);
        }
    }

    public function test_regular_users_are_forbidden_from_activity_logs_html_and_json(): void
    {
        $this->actingAs($this->userA)->get(route('admin.log-activity.index'))->assertForbidden();
        $this->actingAs($this->userA)->getJson(route('admin.log-activity.index'))->assertForbidden();
    }

    public function test_company_admin_datatable_strictly_locked_to_own_company_even_with_tampered_param(): void
    {
        $activityA = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Scoped Log Company A ' . uniqid(),
        ]);

        $activityB = LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => 'Scoped Log Company B ' . uniqid(),
        ]);

        // Company Admin A passes company_id of Company B attempting to access Company B logs
        $response = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', ['company_id' => $this->companyB->id])
        );

        $response->assertOk();
        $activities = collect($response->json('data'))->pluck('activity')->all();

        // Must only return Company A logs, never Company B
        $this->assertContains($activityA->activity, $activities);
        $this->assertNotContains($activityB->activity, $activities);
    }

    public function test_regular_user_with_empty_or_zero_company_id_is_forbidden(): void
    {
        $userZero = $this->createUserWithoutRegistration([
            'name' => 'User Zero Company',
            'email' => 'user_zero_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_USER,
            'company_id' => 0,
        ]);

        $response = $this->actingAs($userZero)->getJson(route('admin.log-activity.index'));

        $response->assertForbidden();
    }

    public function test_log_activities_view_contains_datatable_filter_and_clear_contracts(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.log-activity.index'));

        $response->assertOk();
        $content = $response->getContent();

        // 1. DataTables ajax config passes company_id
        $this->assertMatchesRegularExpression('/data:\s*function\s*\(\s*d\s*\)\s*\{[^}]*company_id\s*=\s*\$\(["\']#Company["\']\)\.val\(\)/', $content);

        // 2. #Company change handler triggers reload
        $this->assertMatchesRegularExpression('/\$\(["\']#Company["\']\)\.on\(["\']change["\']/', $content);

        // 3. Clear button resets #Company and reloads
        $this->assertMatchesRegularExpression('/\$\(["\']#btnClear["\']\)\.on\(["\']click["\']/', $content);
        $this->assertMatchesRegularExpression('/\$\(["\']#Company["\']\)\.val\(["\']["\']\)/', $content);
    }

    public function test_datatable_server_side_sorting_works_without_sql_errors(): void
    {
        LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Sort Activity 1',
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'draw' => 1,
                'columns' => [
                    ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true'],
                    ['data' => 'company', 'name' => 'company', 'searchable' => 'false', 'orderable' => 'false'],
                    ['data' => 'name', 'name' => 'name', 'searchable' => 'false', 'orderable' => 'false'],
                    ['data' => 'activity', 'name' => 'activity', 'searchable' => 'true', 'orderable' => 'true'],
                    ['data' => 'attachment', 'name' => 'attachment', 'searchable' => 'false', 'orderable' => 'false'],
                ],
                'order' => [
                    ['column' => 0, 'dir' => 'desc'],
                ],
                'start' => 0,
                'length' => 10,
            ])
        );

        $response->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_datatable_respects_created_at_asc_desc_and_activity_sorting_when_order_provided(): void
    {
        $this->assertTrue($this->companyAdminA->isCompanyAdmin());

        $logMiddle = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Bravo Log ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-05 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-05 10:00:00', 'UTC'),
        ]);

        $logOldest = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Charlie Log ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-04 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-04 10:00:00', 'UTC'),
        ]);

        $logNewest = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Alpha Log ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 10:00:00', 'UTC'),
        ]);

        $columns = [
            ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'company', 'name' => 'company', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'name', 'name' => 'name', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'activity', 'name' => 'activity', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'attachment', 'name' => 'attachment', 'searchable' => 'false', 'orderable' => 'false'],
        ];

        // 1. Default without order parameter should remain newest-first
        $defaultRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', ['draw' => 1, 'start' => 0, 'length' => 10])
        );
        $defaultRes->assertOk();
        $this->assertSame(
            [$logNewest->activity, $logMiddle->activity, $logOldest->activity],
            collect($defaultRes->json('data'))->pluck('activity')->all()
        );

        // 2. Ascending on column 0 (created_at) must return oldest -> newest
        $ascRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'draw' => 2,
                'columns' => $columns,
                'order' => [['column' => 0, 'dir' => 'asc']],
                'start' => 0,
                'length' => 10,
            ])
        );
        $ascRes->assertOk();
        $this->assertSame(
            [$logOldest->activity, $logMiddle->activity, $logNewest->activity],
            collect($ascRes->json('data'))->pluck('activity')->all()
        );

        // 3. Descending on column 0 (created_at) must return newest -> oldest
        $descRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'draw' => 3,
                'columns' => $columns,
                'order' => [['column' => 0, 'dir' => 'desc']],
                'start' => 0,
                'length' => 10,
            ])
        );
        $descRes->assertOk();
        $this->assertSame(
            [$logNewest->activity, $logMiddle->activity, $logOldest->activity],
            collect($descRes->json('data'))->pluck('activity')->all()
        );

        // 4. Ascending on column 3 (activity) must sort alphabetically by activity, not locked by created_at DESC
        $activityAscRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'draw' => 4,
                'columns' => $columns,
                'order' => [['column' => 3, 'dir' => 'asc']],
                'start' => 0,
                'length' => 10,
            ])
        );
        $activityAscRes->assertOk();
        $this->assertSame(
            [$logNewest->activity, $logMiddle->activity, $logOldest->activity],
            collect($activityAscRes->json('data'))->pluck('activity')->all()
        );

        $activityDescRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'draw' => 5,
                'columns' => $columns,
                'order' => [['column' => 3, 'dir' => 'desc']],
                'start' => 0,
                'length' => 10,
            ])
        );
        $activityDescRes->assertOk();
        $this->assertSame(
            [$logOldest->activity, $logMiddle->activity, $logNewest->activity],
            collect($activityDescRes->json('data'))->pluck('activity')->all()
        );
    }

    public function test_tabbed_invalid_and_abbreviation_timezones_and_null_created_at_do_not_error(): void
    {
        $tzTabbed = Timezone::firstOrCreate(
            ['code' => "Australia/Brisbane\t"],
            ['name' => "Brisbane Tabbed " . uniqid() . "\t"]
        );
        $tzInvalid = Timezone::firstOrCreate(
            ['code' => 'Invalid/NonExistent_' . uniqid()],
            ['name' => 'Invalid Zone ' . uniqid()]
        );
        $tzWib = Timezone::firstOrCreate(
            ['code' => 'WIB'],
            ['name' => 'WIB Zone ' . uniqid()]
        );

        $companyTabbed = Company::create([
            'name' => 'Comp Tabbed ' . uniqid(),
            'email' => 'tabbed_' . uniqid() . '@test.com',
            'timezone_id' => $tzTabbed->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);
        $companyInvalid = Company::create([
            'name' => 'Comp Invalid ' . uniqid(),
            'email' => 'invalid_' . uniqid() . '@test.com',
            'timezone_id' => $tzInvalid->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);
        $companyWib = Company::create([
            'name' => 'Comp WIB ' . uniqid(),
            'email' => 'wib_' . uniqid() . '@test.com',
            'timezone_id' => $tzWib->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);

        $userTabbed = $this->createUserWithoutRegistration([
            'name' => 'User Tabbed',
            'email' => 'u_tab_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_USER,
            'company_id' => $companyTabbed->id,
        ]);
        $userInvalid = $this->createUserWithoutRegistration([
            'name' => 'User Invalid',
            'email' => 'u_inv_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_USER,
            'company_id' => $companyInvalid->id,
        ]);
        $userWib = $this->createUserWithoutRegistration([
            'name' => 'User WIB',
            'email' => 'u_wib_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_USER,
            'company_id' => $companyWib->id,
        ]);

        $fixedUtc = Carbon::parse('2026-11-06 05:00:00', 'UTC');

        LogActivity::create([
            'user_id' => $userTabbed->id,
            'activity' => 'Tabbed TZ Activity',
            'created_at' => $fixedUtc,
            'updated_at' => $fixedUtc,
        ]);
        LogActivity::create([
            'user_id' => $userInvalid->id,
            'activity' => 'Invalid TZ Activity',
            'created_at' => $fixedUtc,
            'updated_at' => $fixedUtc,
        ]);
        LogActivity::create([
            'user_id' => $userWib->id,
            'activity' => 'WIB TZ Activity',
            'created_at' => $fixedUtc,
            'updated_at' => $fixedUtc,
        ]);
        $nullLog = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Null CreatedAt Activity ' . uniqid(),
        ]);
        DB::table('log_activities')->where('id', $nullLog->id)->update(['created_at' => null]);

        // Tabbed timezone trimmed & formatted properly (no 500)
        $resTabbed = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', ['company_id' => $companyTabbed->id])
        );
        $resTabbed->assertOk();
        $expectedTabbed = $fixedUtc->copy()->setTimezone('Australia/Brisbane')->isoFormat('DD MMM Y HH:mm z');
        $this->assertSame($expectedTabbed, $resTabbed->json('data.0.created_at'));

        // Malformed unknown timezone falls back cleanly to app.timezone (UTC)
        $resInvalid = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', ['company_id' => $companyInvalid->id])
        );
        $resInvalid->assertOk();
        $expectedFallback = $fixedUtc->copy()->setTimezone(config('app.timezone', 'UTC'))->isoFormat('DD MMM Y HH:mm z');
        $this->assertSame($expectedFallback, $resInvalid->json('data.0.created_at'));

        // WIB timezone returns 200 cleanly without HTTP 500
        $resWib = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', ['company_id' => $companyWib->id])
        );
        $resWib->assertOk();
        $this->assertNotEmpty($resWib->json('data.0.created_at'));

        // Null created_at renders as '' instead of defaulting to now()
        $resNull = $this->actingAs($this->companyAdminA)->getJson(route('admin.log-activity.index'));
        $resNull->assertOk();
        $nullRow = collect($resNull->json('data'))->firstWhere('activity', $nullLog->activity);
        $this->assertNotNull($nullRow);
        $this->assertSame('', $nullRow['created_at']);
    }

    public function test_date_range_filtering_is_inclusive_respects_user_timezone_and_preserves_tenant_isolation(): void
    {
        // companyAdminA belongs to Australia/Perth (UTC+8):
        // 2026-11-06 00:00:00 AWST == 2026-11-05 16:00:00 UTC
        // 2026-11-06 23:59:59 AWST == 2026-11-06 15:59:59 UTC
        $beforeRange = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Before Nov 6 AWST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-05 15:59:59', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-05 15:59:59', 'UTC'),
        ]);
        $startBoundary = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Start Nov 6 AWST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-05 16:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-05 16:00:00', 'UTC'),
        ]);
        $endBoundary = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'End Nov 6 AWST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 15:59:59', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 15:59:59', 'UTC'),
        ]);
        $afterRange = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'After Nov 6 AWST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 16:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 16:00:00', 'UTC'),
        ]);
        $otherCompanyInRange = LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => 'Company B In Range ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 05:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 05:00:00', 'UTC'),
        ]);

        // Single day inclusive filter with tampered company_id
        $res = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'company_id' => $this->companyB->id,
                'start_date' => '2026-11-06',
                'end_date' => '2026-11-06',
            ])
        );
        $res->assertOk();
        $activities = collect($res->json('data'))->pluck('activity')->all();

        $this->assertContains($startBoundary->activity, $activities);
        $this->assertContains($endBoundary->activity, $activities);
        $this->assertNotContains($beforeRange->activity, $activities);
        $this->assertNotContains($afterRange->activity, $activities);
        $this->assertNotContains($otherCompanyInRange->activity, $activities);
    }

    public function test_malformed_and_inverted_date_filters_do_not_error_or_bypass_tenant_isolation(): void
    {
        $logA = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Log A Date Resilience ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 05:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 05:00:00', 'UTC'),
        ]);
        $logB = LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => 'Log B Date Resilience ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 05:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 05:00:00', 'UTC'),
        ]);

        // Malformed calendar date & non-date strings never return 500 and remain tenant-isolated
        $malformedRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'start_date' => '2026-02-30',
                'end_date' => 'not-a-date',
            ])
        );
        $malformedRes->assertOk();
        $malformedActivities = collect($malformedRes->json('data'))->pluck('activity')->all();
        $this->assertContains($logA->activity, $malformedActivities);
        $this->assertNotContains($logB->activity, $malformedActivities);

        // Inverted range returns clean empty results
        $invertedRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'start_date' => '2026-11-10',
                'end_date' => '2026-11-05',
            ])
        );
        $invertedRes->assertOk();
        $this->assertSame([], $invertedRes->json('data'));
    }

    public function test_log_activities_view_contains_date_inputs_button_types_and_default_order(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.log-activity.index'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*type=["\']button["\'][^>]*id=["\']btnFilter["\']/', $content);
        $this->assertMatchesRegularExpression('/<button[^>]*type=["\']button["\'][^>]*id=["\']btnClear["\']/', $content);
        $this->assertMatchesRegularExpression('/<input[^>]*type=["\']date["\'][^>]*id=["\']StartDate["\'][^>]*name=["\']start_date["\']/', $content);
        $this->assertMatchesRegularExpression('/<input[^>]*type=["\']date["\'][^>]*id=["\']EndDate["\'][^>]*name=["\']end_date["\']/', $content);
        $this->assertMatchesRegularExpression('/order:\s*\[\s*\[\s*0\s*,\s*[\'"]desc[\'"]\s*\]\s*\]/', $content);
        $this->assertMatchesRegularExpression('/d\.start_date\s*=/', $content);
        $this->assertMatchesRegularExpression('/d\.end_date\s*=/', $content);
    }

    public function test_super_admin_company_filter_resolves_target_company_timezone_for_date_boundaries(): void
    {
        // Tokyo (UTC+9, no DST) vs UTC fallback for Super Admin (company_id = 0)
        $tzTokyo = Timezone::firstOrCreate(
            ['code' => 'Asia/Tokyo'],
            ['name' => 'Tokyo Timezone ' . uniqid()]
        );

        $companyTokyo = Company::create([
            'name' => 'Tokyo Co ' . uniqid(),
            'email' => 'tokyo_' . uniqid() . '@test.com',
            'timezone_id' => $tzTokyo->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);

        $userTokyo = $this->createUserWithoutRegistration([
            'name' => 'Tokyo User',
            'email' => 'u_tokyo_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_USER,
            'company_id' => $companyTokyo->id,
        ]);

        // In Asia/Tokyo (UTC+9):
        // 2026-11-06 00:00:00 JST == 2026-11-05 15:00:00 UTC
        // 2026-11-06 23:59:59 JST == 2026-11-06 14:59:59 UTC
        $beforeJst = LogActivity::create([
            'user_id' => $userTokyo->id,
            'activity' => 'Before Nov 6 JST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-05 14:59:59', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-05 14:59:59', 'UTC'),
        ]);
        $startJst = LogActivity::create([
            'user_id' => $userTokyo->id,
            'activity' => 'Start Nov 6 JST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-05 15:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-05 15:00:00', 'UTC'),
        ]);
        $endJst = LogActivity::create([
            'user_id' => $userTokyo->id,
            'activity' => 'End Nov 6 JST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 14:59:59', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 14:59:59', 'UTC'),
        ]);
        $afterJst = LogActivity::create([
            'user_id' => $userTokyo->id,
            'activity' => 'After Nov 6 JST ' . uniqid(),
            'created_at' => Carbon::parse('2026-11-06 15:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 15:00:00', 'UTC'),
        ]);

        $filteredRes = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'company_id' => (string) $companyTokyo->id,
                'start_date' => '2026-11-06',
                'end_date' => '2026-11-06',
                'start' => 0,
                'length' => -1,
            ])
        );

        $filteredRes->assertOk();
        $rows = collect($filteredRes->json('data'));
        $activities = $rows->pluck('activity')->all();

        $this->assertContains($startJst->activity, $activities);
        $this->assertContains($endJst->activity, $activities);
        $this->assertNotContains($beforeJst->activity, $activities);
        $this->assertNotContains($afterJst->activity, $activities);

        // Displayed CREATED AT strings match the filtered 06 Nov 2026 date in JST
        $rows->each(function ($row) {
            $this->assertStringStartsWith('06 Nov 2026', $row['created_at']);
        });

        // Without company_id ("All Companies"), falls back to UTC boundaries for Super Admin
        config(['app.timezone' => 'UTC']);
        $allCompaniesRes = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'start_date' => '2026-11-06',
                'end_date' => '2026-11-06',
                'start' => 0,
                'length' => -1,
            ])
        );
        $allCompaniesRes->assertOk();
        $allActivities = collect($allCompaniesRes->json('data'))->pluck('activity')->all();
        $this->assertNotContains($startJst->activity, $allActivities);
        $this->assertContains($endJst->activity, $allActivities);
        $this->assertContains($afterJst->activity, $allActivities);
    }

    public function test_datatable_server_side_sorting_handles_empty_column_name_from_browser_payload(): void
    {
        $suffix = uniqid();
        $logCharlieOldest = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => "Charlie EmptyName {$suffix}",
            'created_at' => Carbon::parse('2026-11-04 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-04 10:00:00', 'UTC'),
        ]);
        $logBravoMiddle = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => "Bravo EmptyName {$suffix}",
            'created_at' => Carbon::parse('2026-11-05 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-05 10:00:00', 'UTC'),
        ]);
        $logAlphaNewest = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => "Alpha EmptyName {$suffix}",
            'created_at' => Carbon::parse('2026-11-06 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 10:00:00', 'UTC'),
        ]);

        // Browser DataTables sends name: "" when column name is unset or blank
        $columnsWithEmptyNames = [
            ['data' => 'created_at', 'name' => '', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'company', 'name' => '', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'name', 'name' => '', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'activity', 'name' => '', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'attachment', 'name' => 'attachment', 'searchable' => 'false', 'orderable' => 'false'],
        ];

        // Sort by activity DESC -> Charlie, Bravo, Alpha (opposite of newest-first created_at DESC)
        $resDesc = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'draw' => 1,
                'columns' => $columnsWithEmptyNames,
                'order' => [['column' => 3, 'dir' => 'desc']],
                'start' => 0,
                'length' => 10,
            ])
        );
        $resDesc->assertOk();
        $this->assertSame(
            [$logCharlieOldest->activity, $logBravoMiddle->activity, $logAlphaNewest->activity],
            collect($resDesc->json('data'))->pluck('activity')->all()
        );

        // Sort by created_at ASC -> Charlie (Nov 4), Bravo (Nov 5), Alpha (Nov 6)
        $resCreatedAsc = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'draw' => 2,
                'columns' => $columnsWithEmptyNames,
                'order' => [['column' => 0, 'dir' => 'asc']],
                'start' => 0,
                'length' => 10,
            ])
        );
        $resCreatedAsc->assertOk();
        $this->assertSame(
            [$logCharlieOldest->activity, $logBravoMiddle->activity, $logAlphaNewest->activity],
            collect($resCreatedAsc->json('data'))->pluck('activity')->all()
        );
    }

    public function test_unpaginated_export_returns_all_filtered_sorted_rows_across_pages_and_enforces_tenant_lock(): void
    {
        $batchTag = 'ExportBatch_' . uniqid();

        // Create 14 matching logs in Company A (> 10 page size) plus 2 non-matching / out-of-range
        for ($i = 1; $i <= 12; $i++) {
            $padded = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            LogActivity::create([
                'user_id' => $this->userA->id,
                'activity' => "{$batchTag} Item {$padded}",
                'created_at' => Carbon::parse("2026-11-06 08:{$padded}:00", 'UTC'),
                'updated_at' => Carbon::parse("2026-11-06 08:{$padded}:00", 'UTC'),
            ]);
        }

        // Location rows also matching search/date filters
        $loc1 = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => 'Updating Location (-6.208800, 106.845600)',
            'created_at' => Carbon::parse('2026-11-06 09:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 09:00:00', 'UTC'),
        ]);

        // Out-of-date-range log in Company A
        $outOfRange = LogActivity::create([
            'user_id' => $this->userA->id,
            'activity' => "{$batchTag} OutOfRange",
            'created_at' => Carbon::parse('2026-11-01 08:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-01 08:00:00', 'UTC'),
        ]);

        // Matching keyword/date log in Company B (must not leak into Company A filter)
        $companyBLog = LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => "{$batchTag} CompanyB",
            'created_at' => Carbon::parse('2026-11-06 08:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-11-06 08:30:00', 'UTC'),
        ]);

        $columns = [
            ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'company', 'name' => 'company', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'name', 'name' => 'name', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'activity', 'name' => 'activity', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'attachment', 'name' => 'attachment', 'searchable' => 'false', 'orderable' => 'false'],
        ];

        // 1. Paginated request returns only 10 rows while recordsFiltered shows all 12
        $pagedRes = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'draw' => 1,
                'company_id' => (string) $this->companyA->id,
                'start_date' => '2026-11-06',
                'end_date' => '2026-11-06',
                'search' => ['value' => $batchTag, 'regex' => 'false'],
                'columns' => $columns,
                'order' => [['column' => 3, 'dir' => 'asc']],
                'start' => 0,
                'length' => 10,
            ])
        );
        $pagedRes->assertOk();
        $this->assertCount(10, $pagedRes->json('data'));
        $this->assertSame(12, $pagedRes->json('recordsFiltered'));

        // 2. Unpaginated export request (length = -1) returns ALL 12 matching rows sorted ASC
        $exportRes = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'draw' => 2,
                'company_id' => (string) $this->companyA->id,
                'start_date' => '2026-11-06',
                'end_date' => '2026-11-06',
                'search' => ['value' => $batchTag, 'regex' => 'false'],
                'columns' => $columns,
                'order' => [['column' => 3, 'dir' => 'asc']],
                'start' => 0,
                'length' => -1,
            ])
        );
        $exportRes->assertOk();
        $exportedActivities = collect($exportRes->json('data'))->pluck('activity')->all();
        $this->assertCount(12, $exportedActivities);
        $this->assertSame("{$batchTag} Item 01", $exportedActivities[0]);
        $this->assertSame("{$batchTag} Item 12", $exportedActivities[11]);
        $this->assertNotContains($outOfRange->activity, $exportedActivities);
        $this->assertNotContains($companyBLog->activity, $exportedActivities);

        // 3. Location row in export response uses clean 'Updating Location' without raw coordinates
        $locExportRes = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'draw' => 3,
                'company_id' => (string) $this->companyA->id,
                'start_date' => '2026-11-06',
                'end_date' => '2026-11-06',
                'search' => ['value' => 'Updating Location', 'regex' => 'false'],
                'columns' => $columns,
                'order' => [['column' => 0, 'dir' => 'desc']],
                'start' => 0,
                'length' => -1,
            ])
        );
        $locExportRes->assertOk();
        $locRow = collect($locExportRes->json('data'))->firstWhere('id', $loc1->id);
        $this->assertNotNull($locRow);
        $this->assertSame('Updating Location', $locRow['activity']);
        $this->assertStringNotContainsString('-6.2088', $locRow['activity']);

        // 4. Company Admin unpaginated export ignores tampered company_id and stays locked to own tenant
        $tenantExportRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'draw' => 4,
                'company_id' => (string) $this->companyB->id,
                'start_date' => '2026-11-06',
                'end_date' => '2026-11-06',
                'columns' => $columns,
                'order' => [['column' => 0, 'dir' => 'desc']],
                'start' => 0,
                'length' => -1,
            ])
        );
        $tenantExportRes->assertOk();
        $tenantActivities = collect($tenantExportRes->json('data'))->pluck('activity')->all();
        $this->assertCount(13, $tenantActivities);
        $this->assertNotContains($companyBLog->activity, $tenantActivities);

        // 5. Regular user export request with length=-1 is 403 Forbidden
        $this->actingAs($this->userA)->getJson(
            route('admin.log-activity.index', ['start' => 0, 'length' => -1])
        )->assertForbidden();
    }

    public function test_unpaginated_export_enforces_max_export_rows_guardrail_after_filtering(): void
    {
        $tag = 'CapGuard_' . uniqid();
        for ($i = 1; $i <= 6; $i++) {
            LogActivity::create([
                'user_id' => $this->userA->id,
                'activity' => ($i <= 2 ? "{$tag} NarrowMatch {$i}" : "{$tag} Other {$i}"),
            ]);
        }

        config(['datatables.max_export_rows' => 5]);

        $columns = [
            ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'company', 'name' => 'company', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'name', 'name' => 'name', 'searchable' => 'false', 'orderable' => 'false'],
            ['data' => 'activity', 'name' => 'activity', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'attachment', 'name' => 'attachment', 'searchable' => 'false', 'orderable' => 'false'],
        ];

        // 6 filtered rows > limit of 5 -> 422 error JSON
        $overCapRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'columns' => $columns,
                'search' => ['value' => $tag, 'regex' => 'false'],
                'start' => 0,
                'length' => -1,
            ])
        );
        $overCapRes->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
        $this->assertStringContainsString('5', (string) $overCapRes->json('message'));

        // Narrowed search matching 2 rows <= limit of 5 -> succeeds with 2 rows
        $narrowedRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'columns' => $columns,
                'search' => ['value' => "{$tag} NarrowMatch", 'regex' => 'false'],
                'start' => 0,
                'length' => -1,
            ])
        );
        $narrowedRes->assertOk();
        $this->assertCount(2, $narrowedRes->json('data'));
    }

    public function test_global_search_matches_user_name_and_company_name_without_sql_errors_or_wildcard_leak(): void
    {
        $uniqueSuffix = uniqid();
        $targetUser = $this->createUserWithoutRegistration([
            'name' => "ALI DAVIT {$uniqueSuffix}",
            'email' => "alidavit_{$uniqueSuffix}@test.com",
            'password' => bcrypt('password123'),
            'level_id' => $this->levelCompanyAdmin->id,
            'company_id' => $this->companyA->id,
        ]);

        LogActivity::create([
            'user_id' => $targetUser->id,
            'activity' => 'Login Web',
        ]);

        LogActivity::create([
            'user_id' => $this->userB->id,
            'activity' => "CompanyBUnique_{$uniqueSuffix}",
        ]);

        $columns = [
            ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'company', 'name' => 'company', 'searchable' => 'true', 'orderable' => 'false'],
            ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'false'],
            ['data' => 'activity', 'name' => 'activity', 'searchable' => 'true', 'orderable' => 'true'],
            ['data' => 'attachment', 'name' => 'attachment', 'searchable' => 'false', 'orderable' => 'false'],
        ];

        // 1. Search by user name ("ALI DAVIT ...") returns matching log rows
        $nameSearchRes = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'company_id' => (string) $this->companyA->id,
                'columns' => $columns,
                'search' => ['value' => "ALI DAVIT {$uniqueSuffix}", 'regex' => 'false'],
                'start' => 0,
                'length' => 25,
            ])
        );
        $nameSearchRes->assertOk();
        $this->assertCount(1, $nameSearchRes->json('data'));
        $this->assertSame("ALI DAVIT {$uniqueSuffix}", $nameSearchRes->json('data.0.name'));

        // 2. Search by company B name finds Company B's row for Super Admin
        $companySearchRes = $this->actingAs($this->superAdmin)->getJson(
            route('admin.log-activity.index', [
                'columns' => $columns,
                'search' => ['value' => $this->companyB->name, 'regex' => 'false'],
                'start' => 0,
                'length' => 25,
            ])
        );
        $companySearchRes->assertOk();
        $returnedActivities = collect($companySearchRes->json('data'))->pluck('activity')->all();
        $this->assertContains("CompanyBUnique_{$uniqueSuffix}", $returnedActivities);

        // 3. Company Admin A searching Company B's name cannot leak Company B's rows
        $tenantBlockedRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'columns' => $columns,
                'search' => ['value' => $this->companyB->name, 'regex' => 'false'],
                'start' => 0,
                'length' => 25,
            ])
        );
        $tenantBlockedRes->assertOk();
        $this->assertCount(0, $tenantBlockedRes->json('data'));

        // 4. Literal "%" wildcard in search is escaped in name/company filterColumn callbacks
        $wildcardRes = $this->actingAs($this->companyAdminA)->getJson(
            route('admin.log-activity.index', [
                'columns' => $columns,
                'search' => ['value' => 'ALI DAVIT%', 'regex' => 'false'],
                'start' => 0,
                'length' => 25,
            ])
        );
        $wildcardRes->assertOk();
        $this->assertCount(0, $wildcardRes->json('data'));
    }
}
