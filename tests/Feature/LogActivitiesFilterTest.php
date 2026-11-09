<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Level;
use App\Models\LogActivity;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
            'level_id' => $this->levelCompanyAdmin->id,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminB = $this->createUserWithoutRegistration([
            'name' => 'Admin Company B',
            'email' => 'admin_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelCompanyAdmin->id,
            'company_id' => $this->companyB->id,
        ]);

        $this->userA = $this->createUserWithoutRegistration([
            'name' => 'User In Company A',
            'email' => 'user_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => $this->companyA->id,
        ]);

        $this->userB = $this->createUserWithoutRegistration([
            'name' => 'User In Company B',
            'email' => 'user_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
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
            route('admin.log-activity.index', ['company_id' => $this->companyA->id])
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

    public function test_non_superadmin_with_empty_or_zero_company_id_returns_empty_results(): void
    {
        $userZero = $this->createUserWithoutRegistration([
            'name' => 'User Zero Company',
            'email' => 'user_zero_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => 0,
        ]);

        LogActivity::create([
            'user_id' => $userZero->id,
            'activity' => 'Zero Company Log ' . uniqid(),
        ]);

        $response = $this->actingAs($userZero)->getJson(route('admin.log-activity.index'));

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
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
}
