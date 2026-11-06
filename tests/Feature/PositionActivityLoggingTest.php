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
            ['name' => 'Asia/Jakarta', 'code' => 'WIB']
        );

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Company', 'email' => 'company_' . uniqid() . '@test.com', 'timezone_id' => $this->timezone->id]
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

    public function test_log_activities_admin_datatable_displays_coordinates_in_activity_column(): void
    {
        $expectedActivity = 'Updating Location (-6.208800, 106.845600)';

        LogActivity::create([
            'user_id' => $this->user->id,
            'activity' => $expectedActivity,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('admin.log-activity.index'));

        $response->assertOk();
        $activities = collect($response->json('data'))->pluck('activity')->all();

        $this->assertContains($expectedActivity, $activities);
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
