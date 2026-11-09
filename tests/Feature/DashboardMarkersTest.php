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

    private function createUserWithoutRegistration(array $attributes): User
    {
        return User::withoutEvents(function () use ($attributes) {
            return User::create($attributes);
        });
    }

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

        $this->superAdmin = $this->createUserWithoutRegistration([
            'name' => 'Super Admin Test',
            'email' => 'superadmin_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_SUPER_ADMIN,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminA = $this->createUserWithoutRegistration([
            'name' => 'Admin A Test',
            'email' => 'admin_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelCompanyAdmin->id,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminB = $this->createUserWithoutRegistration([
            'name' => 'Admin B Test',
            'email' => 'admin_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelCompanyAdmin->id,
            'company_id' => $this->companyB->id,
        ]);

        $this->regularUserA = $this->createUserWithoutRegistration([
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
        $userNullLat = $this->createUserWithoutRegistration([
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
        $userEmptyLng = $this->createUserWithoutRegistration([
            'name' => 'Empty Lng User',
            'email' => 'empty_lng_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => $this->companyA->id,
            'position_id' => $posEmptyLng->id,
        ]);

        // User without position in positions table
        $userNoPos = $this->createUserWithoutRegistration([
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

    public function test_dashboard_view_contains_marker_auto_refresh_script_contracts(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('auth.dashboard'));

        $response->assertOk()
            ->assertViewIs('pages.dashboard');

        $content = $response->getContent();

        // 1. Periodic Polling: 10 second interval configured with setInterval
        $this->assertMatchesRegularExpression('/(?:pollInterval(?:Ms)?\s*=\s*10000|setInterval\([^,]+,\s*10000\))/', $content, 'Must configure 10 second polling interval');
        $this->assertStringContainsString('setInterval', $content, 'Must initiate periodic polling using setInterval');

        // 2. Preserve Map Viewport: guard fitBounds/setView with isInitialLoad so subsequent intervals do not reset pan/zoom
        $this->assertMatchesRegularExpression('/isInitialLoad/', $content, 'Must track initial load state to preserve map viewport');
        $this->assertMatchesRegularExpression('/if\s*\(\s*isInitialLoad\s*\)/', $content, 'Must guard fitBounds so it only runs on initial load');

        // 3. Marker State Handling: Update existing marker positions and popups by ID instead of clearing layers
        $this->assertStringNotContainsString('markersLayer.clearLayers()', $content, 'Must not clear all markers on every poll');
        $this->assertMatchesRegularExpression('/markersById|markersMap/', $content, 'Must index markers by ID');
        $this->assertStringContainsString('.setLatLng(', $content, 'Must update existing marker coordinates with setLatLng');
        $this->assertStringContainsString('.setPopupContent(', $content, 'Must update popup timestamp using setPopupContent');

        // 4. Cleanup: clear interval and abort active request on pagehide or beforeunload
        $this->assertStringContainsString('clearInterval', $content, 'Must clear timer interval');
        $this->assertStringContainsString('beforeunload', $content, 'Must clean up on beforeunload');
        $this->assertStringContainsString('pagehide', $content, 'Must clean up on pagehide');
    }

    public function test_dashboard_view_contains_error_handling_and_visibility_pause_contracts(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('auth.dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression('/error:\s*function\s*\(\s*xhr\s*,\s*textStatus/', $content, '$.ajax must define error(xhr, textStatus) handler');
        $this->assertMatchesRegularExpression('/textStatus\s*===\s*[\'"]abort[\'"]/', $content, 'Must ignore aborted polling requests');
        $this->assertMatchesRegularExpression('/status\s*===\s*401/', $content, 'Must handle 401 session expiration');
        $this->assertMatchesRegularExpression('/status\s*===\s*419/', $content, 'Must handle 419 CSRF/session expiration');
        $this->assertMatchesRegularExpression('/textStatus\s*===\s*[\'"]parsererror[\'"]/', $content, 'Must handle parsererror on HTML redirect');
        $this->assertStringContainsString('visibilitychange', $content, 'Must attach Page Visibility API listener');
        $this->assertStringContainsString('document.hidden', $content, 'Must check document.hidden state on visibilitychange');
    }

    public function test_dashboard_map_tiles_use_https_and_english_locale(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('auth.dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringNotContainsString('http://{s}.google.com', $content);
        $this->assertMatchesRegularExpression('/googleTile\s*=\s*L\.tileLayer\([\'"]https:\/\/\{s\}\.google\.com\/vt\/lyrs=s,h&x=\{x\}&y=\{y\}&z=\{z\}&hl=en[\'"]/', $content);
        $this->assertMatchesRegularExpression('/googleTerrain\s*=\s*L\.tileLayer\([\'"]https:\/\/\{s\}\.google\.com\/vt\?lyrs=p&x=\{x\}&y=\{y\}&z=\{z\}&hl=en[\'"]/', $content);
        $this->assertMatchesRegularExpression('/googleSat\s*=\s*L\.tileLayer\([\'"]https:\/\/\{s\}\.google\.com\/vt\?lyrs=s&x=\{x\}&y=\{y\}&z=\{z\}&hl=en[\'"]/', $content);
    }

    public function test_company_admin_cannot_override_company_filter_via_query_parameter(): void
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

        // Company Admin A attempts to tamper by querying Company B's pins
        $tamperedUrl = route('auth.dashboard.markers') . '?company_id=' . $this->companyB->id;
        $response = $this->actingAs($this->companyAdminA)->getJson($tamperedUrl);

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $returnedUserIds = collect($response->json('data'))->pluck('id')->all();

        // Must still only see Company A, and never Company B
        $this->assertContains($this->companyAdminA->id, $returnedUserIds);
        $this->assertNotContains($this->companyAdminB->id, $returnedUserIds);
    }

    public function test_non_superadmin_with_empty_or_zero_company_id_returns_empty_array_immediately(): void
    {
        $pos = Position::create([
            'user_id' => $this->regularUserA->id,
            'latitude' => '-31.9505',
            'longitude' => '115.8605',
        ]);
        $this->regularUserA->update(['position_id' => $pos->id]);

        // User without company (company_id = 0)
        $userWithoutCompany = $this->createUserWithoutRegistration([
            'name' => 'Homeless User',
            'email' => 'homeless_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->levelUser->id,
            'company_id' => 0,
            'position_id' => $pos->id,
        ]);

        $response = $this->actingAs($userWithoutCompany)->getJson(route('auth.dashboard.markers'));

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [],
            ]);

        $this->assertEmpty($response->json('data'));

        // Also test null company_id in-memory attribute
        $userWithoutCompany->company_id = null;
        $responseNull = $this->actingAs($userWithoutCompany)->getJson(route('auth.dashboard.markers'));
        $responseNull->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [],
            ]);
        $this->assertEmpty($responseNull->json('data'));
    }

    public function test_markers_payload_does_not_leak_sensitive_columns(): void
    {
        $pos = Position::create([
            'user_id' => $this->companyAdminA->id,
            'latitude' => '-31.9505',
            'longitude' => '115.8605',
        ]);
        $this->companyAdminA->update(['position_id' => $pos->id]);

        $response = $this->actingAs($this->companyAdminA)->getJson(route('auth.dashboard.markers'));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        foreach ($data as $item) {
            $this->assertArrayNotHasKey('password', $item, 'Response payload must never expose user password hash');
            $this->assertArrayNotHasKey('remember_token', $item, 'Response payload must never expose remember_token');
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('position', $item);
        }
    }

    public function test_super_admin_can_filter_markers_by_company_id_query_parameter(): void
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

        // Super Admin filters explicitly by Company B
        $filterUrl = route('auth.dashboard.markers') . '?company_id=' . $this->companyB->id;
        $response = $this->actingAs($this->superAdmin)->getJson($filterUrl);

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $returnedUserIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->companyAdminB->id, $returnedUserIds);
        $this->assertNotContains($this->companyAdminA->id, $returnedUserIds);
    }

    public function test_super_admin_ignores_non_integer_company_filter_values(): void
    {
        $posA = Position::create([
            'user_id' => $this->companyAdminA->id,
            'latitude' => '-31.9505',
            'longitude' => '115.8605',
        ]);
        $this->companyAdminA->update(['position_id' => $posA->id]);

        $posB = Position::create([
            'user_id' => $this->companyAdminB->id,
            'latitude' => '-33.8688',
            'longitude' => '151.2093',
        ]);
        $this->companyAdminB->update(['position_id' => $posB->id]);

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
                route('auth.dashboard.markers', ['company_id' => $companyId])
            );

            $response->assertOk();
            $returnedUserIds = collect($response->json('data'))->pluck('id')->all();
            $this->assertContains($this->companyAdminA->id, $returnedUserIds);
            $this->assertContains($this->companyAdminB->id, $returnedUserIds);
        }
    }

    public function test_unauthenticated_request_to_markers_is_rejected(): void
    {
        $response = $this->getJson(route('auth.dashboard.markers'));

        $response->assertStatus(401);
    }
}