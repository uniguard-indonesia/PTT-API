<?php

namespace Tests\Feature;

use App\Http\Controllers\API\PositionController;
use App\Models\Company;
use App\Models\Level;
use App\Models\LogActivity;
use App\Models\Position;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PositionActivityLoggingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Company $company;
    protected Timezone $timezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timezone = Timezone::firstOrCreate(
            ['id' => 1],
            ['name' => 'Asia/Jakarta', 'code' => 'Asia/Jakarta']
        );

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Test Company',
                'email' => 'company_' . uniqid() . '@test.com',
                'timezone_id' => $this->timezone->id,
                'expire_date' => now()->addYear(),
                'created_by' => 'Test',
                'updated_by' => 'Test',
            ]
        );

        $this->user = User::withoutEvents(function () {
            return User::create([
                'name' => 'Field Device User',
                'email' => 'device_' . uniqid() . '@test.com',
                'password' => bcrypt('password123'),
                'level_id' => User::LEVEL_USER,
                'company_id' => $this->company->id,
            ]);
        });
    }

    protected function tearDown(): void
    {
        LogActivity::flushEventListeners();
        parent::tearDown();
    }

    public function test_post_position_creates_log_activity_with_coordinates_format(): void
    {
        $lat = '-6.208800';
        $lng = '106.845600';

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/position', [
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $expectedActivity = "Updating Location ({$lat}, {$lng})";

        $this->assertDatabaseHas('log_activities', [
            'user_id' => $this->user->id,
            'activity' => $expectedActivity,
        ]);
    }

    public function test_post_positions_plural_endpoint_also_logs_coordinates(): void
    {
        $lat = '-31.950500';
        $lng = '115.860500';

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/positions', [
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $expectedActivity = "Updating Location ({$lat}, {$lng})";

        $this->assertDatabaseHas('log_activities', [
            'user_id' => $this->user->id,
            'activity' => $expectedActivity,
        ]);
    }

    public function test_log_activities_admin_datatable_cleans_location_text_and_exposes_validated_coordinates(): void
    {
        LogActivity::create([
            'user_id' => $this->user->id,
            'activity' => 'Updating Location (-6.208800, 106.845600)',
        ]);

        LogActivity::create([
            'user_id' => $this->user->id,
            'activity' => 'Updating Location',
        ]);

        $companyAdmin = User::withoutEvents(function () {
            return User::create([
                'name' => 'Company Admin',
                'email' => 'admin_' . uniqid() . '@test.com',
                'password' => bcrypt('password123'),
                'level_id' => User::LEVEL_COMPANY_ADMIN,
                'company_id' => $this->company->id,
            ]);
        });

        $response = $this->actingAs($companyAdmin)->getJson(route('admin.log-activity.index'));

        $response->assertOk();
        $rows = collect($response->json('data'));

        $withCoords = $rows->first(fn ($row) => $row['latitude'] !== null);
        $this->assertNotNull($withCoords);
        $this->assertSame('Updating Location', $withCoords['activity']);
        $this->assertEquals(-6.2088, $withCoords['latitude']);
        $this->assertEquals(106.8456, $withCoords['longitude']);
        $this->assertStringNotContainsString('-6.208800', $withCoords['activity']);

        $withoutCoords = $rows->first(fn ($row) => $row['activity'] === 'Updating Location' && $row['latitude'] === null);
        $this->assertNotNull($withoutCoords);
        $this->assertNull($withoutCoords['latitude']);
        $this->assertNull($withoutCoords['longitude']);

        $this->actingAs($this->user)->getJson(route('admin.log-activity.index'))->assertForbidden();
    }

    public function test_extract_location_data_validates_bounds_and_handles_edge_cases(): void
    {
        // Valid positive/negative, zero, and boundary coordinates
        $validCases = [
            ['Updating Location (-6.208800, 106.845600)', 'Updating Location', -6.2088, 106.8456],
            ['Updating Location (0, 0)', 'Updating Location', 0.0, 0.0],
            ['Updating Location (-0.0, -0)', 'Updating Location', 0.0, 0.0],
            ['Updating Location (-90, -180)', 'Updating Location', -90.0, -180.0],
            ['Updating Location (90, 180)', 'Updating Location', 90.0, 180.0],
            ['  Updating Location ( +35.6762 , 139.6503 )  ', 'Updating Location', 35.6762, 139.6503],
        ];

        foreach ($validCases as [$input, $expectedActivity, $expectedLat, $expectedLng]) {
            $parsed = LogActivity::extractLocationData($input);
            $this->assertSame($expectedActivity, $parsed['activity'], "Failed on {$input}");
            $this->assertSame($expectedLat, $parsed['latitude'], "Failed lat on {$input}");
            $this->assertSame($expectedLng, $parsed['longitude'], "Failed lng on {$input}");
        }

        // Plain fallback, out-of-range, non-finite, malformed, and XSS strings
        $invalidOrPlainCases = [
            'Updating Location',
            'Updating Location (90.0001, 106.845600)',
            'Updating Location (-90.0001, 106.845600)',
            'Updating Location (-6.208800, 180.0001)',
            'Updating Location (-6.208800, -180.0001)',
            'Updating Location (1e309, 0)',
            'Updating Location (NaN, 106.845600)',
            'Updating Location (Infinity, 106.845600)',
            'Updating Location (-6.208800)',
            'Updating Location (<script>alert(1)</script>, 106.845600)',
            'User Logged In',
        ];

        foreach ($invalidOrPlainCases as $input) {
            $parsed = LogActivity::extractLocationData($input);
            $this->assertSame($input, $parsed['activity']);
            $this->assertNull($parsed['latitude']);
            $this->assertNull($parsed['longitude']);
        }

        $nullParsed = LogActivity::extractLocationData(null);
        $this->assertSame('', $nullParsed['activity']);
        $this->assertNull($nullParsed['latitude']);
        $this->assertNull($nullParsed['longitude']);
    }

    public function test_admin_datatable_rejects_out_of_range_and_preserves_other_activities(): void
    {
        LogActivity::create([
            'user_id' => $this->user->id,
            'activity' => 'Updating Location (95.000000, 200.000000)',
        ]);
        LogActivity::create([
            'user_id' => $this->user->id,
            'activity' => 'Updating Location (0, 0)',
        ]);
        LogActivity::create([
            'user_id' => $this->user->id,
            'activity' => 'Updating Location (<script>alert(1)</script>, 106.8)',
        ]);

        $companyAdmin = User::withoutEvents(function () {
            return User::create([
                'name' => 'Company Admin Bounds',
                'email' => 'admin_bounds_' . uniqid() . '@test.com',
                'password' => bcrypt('password123'),
                'level_id' => User::LEVEL_COMPANY_ADMIN,
                'company_id' => $this->company->id,
            ]);
        });

        $response = $this->actingAs($companyAdmin)->getJson(route('admin.log-activity.index'));
        $response->assertOk();

        $rows = collect($response->json('data'));

        $outOfRangeRow = $rows->firstWhere('activity', 'Updating Location (95.000000, 200.000000)');
        $this->assertNotNull($outOfRangeRow);
        $this->assertNull($outOfRangeRow['latitude']);
        $this->assertNull($outOfRangeRow['longitude']);

        $zeroRow = $rows->first(fn ($row) => $row['activity'] === 'Updating Location' && $row['latitude'] !== null);
        $this->assertNotNull($zeroRow);
        $this->assertEquals(0, $zeroRow['latitude']);
        $this->assertEquals(0, $zeroRow['longitude']);

        $xssRow = $rows->firstWhere('activity', e('Updating Location (<script>alert(1)</script>, 106.8)'));
        $this->assertNotNull($xssRow);
        $this->assertNull($xssRow['latitude']);
        $this->assertNull($xssRow['longitude']);
    }

    public function test_admin_datatable_returns_escaped_special_characters_and_parses_historical_quotes_cleanly(): void
    {
        $specialCompany = Company::create([
            'name' => 'O\'Reilly & Sons <West>',
            'email' => 'special_' . uniqid() . '@test.com',
            'timezone_id' => $this->timezone->id,
            'expire_date' => now()->addYear(),
            'created_by' => 'Test',
            'updated_by' => 'Test',
        ]);

        $specialUser = User::withoutEvents(function () use ($specialCompany) {
            return User::create([
                'name' => 'Alice "O\'Connor" <Team> & Co',
                'email' => 'alice_special_' . uniqid() . '@test.com',
                'password' => bcrypt('password123'),
                'level_id' => User::LEVEL_USER,
                'company_id' => $specialCompany->id,
            ]);
        });

        $admin = User::withoutEvents(function () use ($specialCompany) {
            return User::create([
                'name' => 'Admin Special',
                'email' => 'admin_special_' . uniqid() . '@test.com',
                'password' => bcrypt('password123'),
                'level_id' => User::LEVEL_COMPANY_ADMIN,
                'company_id' => $specialCompany->id,
            ]);
        });

        LogActivity::create([
            'user_id' => $specialUser->id,
            'activity' => 'Updating Location (-6.208800, 106.845600)',
        ]);
        LogActivity::create([
            'user_id' => $specialUser->id,
            'activity' => "User's Login & <Check>",
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.log-activity.index'));
        $response->assertOk();

        $rows = collect($response->json('data'));
        $locRow = $rows->firstWhere('activity', 'Updating Location');
        $this->assertNotNull($locRow);
        $this->assertEquals(-6.2088, $locRow['latitude']);
        $this->assertEquals(106.8456, $locRow['longitude']);
        $this->assertSame(e($specialCompany->name), $locRow['company']);
        $this->assertSame(e($specialUser->name), $locRow['name']);

        $otherRow = $rows->firstWhere('activity', e("User's Login & <Check>"));
        $this->assertNotNull($otherRow);
        $this->assertNull($otherRow['latitude']);
        $this->assertNull($otherRow['longitude']);
    }

    public function test_log_activities_view_includes_local_leaflet_modal_https_tiles_and_invalidatesize(): void
    {
        $companyAdmin = User::withoutEvents(function () {
            return User::create([
                'name' => 'Company Admin View',
                'email' => 'admin_view_' . uniqid() . '@test.com',
                'password' => bcrypt('password123'),
                'level_id' => User::LEVEL_COMPANY_ADMIN,
                'company_id' => $this->company->id,
            ]);
        });

        $response = $this->actingAs($companyAdmin)->get(route('admin.log-activity.index'));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('assets/plugins/leaflet/leaflet.css', $html);
        $this->assertStringContainsString('assets/plugins/leaflet/leaflet.js', $html);
        $this->assertStringContainsString('id="locationMapModal"', $html);
        $this->assertStringContainsString('id="activityLocationMap"', $html);
        $this->assertStringContainsString('data-dismiss="modal"', $html);
        $this->assertStringContainsString('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}&hl=en', $html);
        $this->assertStringContainsString('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', $html);
        $this->assertStringContainsString('shown.bs.modal', $html);
        $this->assertStringContainsString('hidden.bs.modal', $html);
        $this->assertStringContainsString('invalidateSize()', $html);
        $this->assertStringNotContainsString('maps.google.com', $html);
    }

    public function test_activity_logging_fallback_when_coordinates_empty(): void
    {
        $messageEmpty = PositionController::formatActivityMessage('', '');
        $this->assertSame('Updating Location', $messageEmpty);

        $messageNull = PositionController::formatActivityMessage(null, null);
        $this->assertSame('Updating Location', $messageNull);

        $messagePartial = PositionController::formatActivityMessage('-6.208800', null);
        $this->assertSame('Updating Location', $messagePartial);
    }

    public function test_activity_logging_length_safety_within_255_chars(): void
    {
        $longLat = str_repeat('1', 200);
        $longLng = str_repeat('2', 200);

        $formatted = PositionController::formatActivityMessage($longLat, $longLng);
        $this->assertLessThanOrEqual(255, mb_strlen($formatted));
        $this->assertStringStartsWith('Updating Location (', $formatted);
    }

    public function test_position_creation_succeeds_even_if_activity_logging_throws_exception(): void
    {
        LogActivity::creating(function () {
            throw new \Exception('Simulated database deadlock or failure in log_activities table');
        });

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/position', [
            'latitude' => '-6.208800',
            'longitude' => '106.845600',
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'success']);

        $this->user->refresh();
        $this->assertNotNull($this->user->position_id);
        $this->assertDatabaseHas('positions', [
            'id' => $this->user->position_id,
            'user_id' => $this->user->id,
            'latitude' => '-6.208800',
            'longitude' => '106.845600',
        ]);
    }
}
