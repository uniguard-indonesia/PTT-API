<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Level;
use App\Models\Position;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardMarkersTest extends TestCase
{
    use DatabaseTransactions;

    protected User $superAdmin;
    protected User $companyAdminA;
    protected User $companyAdminB;
    protected User $regularUserA;
    protected Company $companyA;
    protected Company $companyB;
    protected Timezone $timezoneA;
    protected Timezone $timezoneB;
    protected Level $levelCompanyAdmin;
    protected Level $levelUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timezoneA = Timezone::firstOrCreate(
            ['code' => 'Australia/Perth'],
            ['name' => 'Perth Timezone']
        );

        $this->timezoneB = Timezone::firstOrCreate(
            ['code' => 'Australia/Sydney'],
            ['name' => 'Sydney Timezone']
        );

        $this->companyA = Company::create([
            'name' => 'Company A Test ' . uniqid(),
            'email' => 'comp_a_' . uniqid() . '@test.com',
            'timezone_id' => $this->timezoneA->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);

        $this->companyB = Company::create([
            'name' => 'Company B Test ' . uniqid(),
            'email' => 'comp_b_' . uniqid() . '@test.com',
            'timezone_id' => $this->timezoneB->id,
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
            ['name' => 'Regular User', 'menu' => 'Dashboard']
        );

        $this->superAdmin = User::create([
            'name' => 'Super Admin Test',
            'email' => 'superadmin_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_SUPER_ADMIN,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminA = User::create([
            'name' => 'Admin A Test',
            'email' => 'admin_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelCompanyAdmin->id,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminB = User::create([
            'name' => 'Admin B Test',
            'email' => 'admin_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelCompanyAdmin->id,
            'company_id' => $this->companyB->id,
        ]);

        $this->regularUserA = User::create([
            'name' => 'User A Test',
            'email' => 'user_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_super_admin_can_view_markers_across_all_companies(): void
    {
        // Device A in Company A
        $posA = Position::create([
            'user_id' => $this->companyAdminA->id,
            'latitude' => '-31.9505',
            'longitude' => '115.8605',
        ]);
        $this->companyAdminA->update(['position_id' => $posA->id]);

        // Device B in Company B
        $posB = Position::create([
            'user_id' => $this->companyAdminB->id,
            'latitude' => '-33.8688',
            'longitude' => '151.2093',
        ]);
        $this->companyAdminB->update(['position_id' => $posB->id]);

        $response = $this->actingAs($this->superAdmin)->getJson(route('auth.dashboard.markers'));

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $returnedUserIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->companyAdminA->id, $returnedUserIds);
        $this->assertContains($this->companyAdminB->id, $returnedUserIds);
    }

    public function test_company_admin_is_scoped_to_their_company_devices_only(): void
    {
        // Device A in Company A
        $posA = Position::create([
            'user_id' => $this->companyAdminA->id,
            'latitude' => '-31.9505',
            'longitude' => '115.8605',
        ]);
        $this->companyAdminA->update(['position_id' => $posA->id]);

        // Device B in Company B
        $posB = Position::create([
            'user_id' => $this->companyAdminB->id,
            'latitude' => '-33.8688',
            'longitude' => '151.2093',
        ]);
        $this->companyAdminB->update(['position_id' => $posB->id]);

        $response = $this->actingAs($this->companyAdminA)->getJson(route('auth.dashboard.markers'));

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $returnedUserIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->companyAdminA->id, $returnedUserIds);
        $this->assertNotContains($this->companyAdminB->id, $returnedUserIds);
    }

    public function test_regular_user_is_scoped_to_their_company_devices_only(): void
    {
        // Device A in Company A
        $posA = Position::create([
            'user_id' => $this->regularUserA->id,
            'latitude' => '-31.9505',
            'longitude' => '115.8605',
        ]);
        $this->regularUserA->update(['position_id' => $posA->id]);

        // Device B in Company B
        $posB = Position::create([
            'user_id' => $this->companyAdminB->id,
            'latitude' => '-33.8688',
            'longitude' => '151.2093',
        ]);
        $this->companyAdminB->update(['position_id' => $posB->id]);

        $response = $this->actingAs($this->regularUserA)->getJson(route('auth.dashboard.markers'));

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $returnedUserIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->regularUserA->id, $returnedUserIds);
        $this->assertNotContains($this->companyAdminB->id, $returnedUserIds);
    }

    public function test_devices_without_valid_coordinates_are_excluded(): void
    {
        // User with valid position
        $posValid = Position::create([
            'user_id' => $this->companyAdminA->id,
            'latitude' => '-31.9505',
            'longitude' => '115.8605',
        ]);
        $this->companyAdminA->update(['position_id' => $posValid->id]);

        // User with null latitude
        $posNullLat = Position::create([
            'user_id' => $this->regularUserA->id,
            'latitude' => null,
            'longitude' => '115.8605',
        ]);
        $userNullLat = User::create([
            'name' => 'Null Lat User',
            'email' => 'null_lat_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => $this->companyA->id,
            'position_id' => $posNullLat->id,
        ]);

        // User with empty string longitude
        $posEmptyLng = Position::create([
            'user_id' => $this->regularUserA->id,
            'latitude' => '-31.9505',
            'longitude' => '',
        ]);
        $userEmptyLng = User::create([
            'name' => 'Empty Lng User',
            'email' => 'empty_lng_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => $this->companyA->id,
            'position_id' => $posEmptyLng->id,
        ]);

        // User without position in positions table
        $userNoPos = User::create([
            'name' => 'No Pos User',
            'email' => 'no_pos_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => $this->companyA->id,
            'position_id' => 0,
        ]);

        $response = $this->actingAs($this->companyAdminA)->getJson(route('auth.dashboard.markers'));

        $response->assertOk();
        $returnedUserIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->companyAdminA->id, $returnedUserIds);
        $this->assertNotContains($userNullLat->id, $returnedUserIds);
        $this->assertNotContains($userEmptyLng->id, $returnedUserIds);
        $this->assertNotContains($userNoPos->id, $returnedUserIds);
    }
}