<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Level;
use App\Models\Position;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PositionAndLevelIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $superAdmin;
    protected User $companyAdminA;
    protected User $companyAdminB;
    protected User $userWithoutCompany;
    protected Company $companyA;
    protected Company $companyB;
    protected Timezone $timezone;
    protected Level $level1;
    protected Level $level2;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::statement("SELECT setval('users_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM users), (SELECT last_value FROM users_id_seq)))");
            DB::statement("SELECT setval('levels_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM levels), (SELECT last_value FROM levels_id_seq)))");
            DB::statement("SELECT setval('positions_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM positions), (SELECT last_value FROM positions_id_seq)))");
            DB::statement("SELECT setval('companies_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM companies), (SELECT last_value FROM companies_id_seq)))");
        } catch (\Throwable $e) {
            // Sequence not applicable
        }

        $this->timezone = Timezone::firstOrCreate(
            ['id' => 1],
            ['name' => 'Asia/Jakarta', 'code' => 'WIB']
        );

        $this->companyA = Company::firstOrCreate(
            ['id' => 1],
            ['name' => 'Company A', 'email' => 'companyA@test.com', 'timezone_id' => $this->timezone->id]
        );

        $this->companyB = Company::firstOrCreate(
            ['id' => 2],
            ['name' => 'Company B', 'email' => 'companyB@test.com', 'timezone_id' => $this->timezone->id]
        );

        $this->level1 = Level::find(1);
        if (!$this->level1) {
            DB::table('levels')->insert([
                'id' => 1,
                'name' => 'Role Level 1',
                'menu' => 'Dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->level1 = Level::find(1);
        }

        $this->level2 = Level::find(2);
        if (!$this->level2) {
            DB::table('levels')->insert([
                'id' => 2,
                'name' => 'Role Level 2',
                'menu' => 'Dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->level2 = Level::find(2);
        }

        $this->superAdmin = User::create([
            'name' => 'Super Admin Test',
            'email' => 'superadmin_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_SUPER_ADMIN,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminA = User::create([
            'name' => 'Company Admin A',
            'email' => 'admin_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->level1->id,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdminB = User::create([
            'name' => 'Company Admin B',
            'email' => 'admin_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->level2->id,
            'company_id' => $this->companyB->id,
        ]);

        $this->userWithoutCompany = User::create([
            'name' => 'Orphan User',
            'email' => 'orphan_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->level1->id,
            'company_id' => 0,
        ]);
    }

    public function test_user_positions_has_many_relation_and_tenant_scope(): void
    {
        $pos1 = Position::create([
            'user_id' => $this->companyAdminA->id,
            'latitude' => '-6.200000',
            'longitude' => '106.816666',
        ]);
        $pos2 = Position::create([
            'user_id' => $this->companyAdminA->id,
            'latitude' => '-6.210000',
            'longitude' => '106.820000',
        ]);

        $this->companyAdminA->refresh();
        $positions = $this->companyAdminA->positions;

        // Verify hasMany returns positions for this user
        $this->assertCount(2, $positions);
        $this->assertTrue($positions->contains($pos1->id));
        $this->assertTrue($positions->contains($pos2->id));

        // Verify scopeInTenantOf
        $inCompanyA = User::inTenantOf($this->companyAdminA)->pluck('id');
        $this->assertTrue($inCompanyA->contains($this->companyAdminA->id));
        $this->assertFalse($inCompanyA->contains($this->companyAdminB->id));

        // Fail-closed for null actor
        $this->assertSame(0, User::inTenantOf(null)->count());

        // Fail-closed for non-superadmin without company_id
        $this->assertSame(0, User::inTenantOf($this->userWithoutCompany)->count());

        // Super Admin sees all
        $superScope = User::inTenantOf($this->superAdmin)->pluck('id');
        $this->assertTrue($superScope->contains($this->companyAdminA->id));
        $this->assertTrue($superScope->contains($this->companyAdminB->id));
    }

    public function test_level_scope_assignable_by_single_source_of_truth(): void
    {
        // Null actor yields 0 assignable levels
        $this->assertSame(0, Level::assignableBy(null)->count());

        // User without company yields 0 assignable levels
        $this->assertSame(0, Level::assignableBy($this->userWithoutCompany)->count());

        // Super Admin can assign all levels from levels table
        $superLevels = Level::assignableBy($this->superAdmin)->pluck('id');
        $this->assertTrue($superLevels->contains(1));
        $this->assertTrue($superLevels->contains(2));

        // Company Admin A can assign standard levels (including level 1!)
        $companyLevels = Level::assignableBy($this->companyAdminA)->pluck('id');
        $this->assertTrue($companyLevels->contains(1), 'Level 1 must be assignable by company admin');
        $this->assertTrue($companyLevels->contains(2));
        $this->assertFalse($companyLevels->contains(User::LEVEL_SUPER_ADMIN));
    }

    public function test_user_index_view_levels_dropdown(): void
    {
        // 1. Super Admin view: prepends virtual Super Admin option (id = 0)
        $superResponse = $this->actingAs($this->superAdmin)->get(route('admin.user.index'));
        $superResponse->assertStatus(200);
        $superLevels = $superResponse->viewData('levels');
        $this->assertSame(0, (int) $superLevels->first()->id);
        $this->assertSame('Super Admin', $superLevels->first()->name);
        $superLevelIds = collect($superLevels)->pluck('id')->map(function ($id) {
            return (int) $id;
        });
        $this->assertTrue($superLevelIds->contains(1));

        // 2. Company Admin view: does NOT contain Super Admin option (0), but DOES contain level 1
        $adminResponse = $this->actingAs($this->companyAdminA)->get(route('admin.user.index'));
        $adminResponse->assertStatus(200);
        $adminLevels = $adminResponse->viewData('levels');
        $adminLevelIds = collect($adminLevels)->pluck('id')->map(function ($id) {
            return (int) $id;
        });
        $this->assertFalse($adminLevelIds->contains(0));
        $this->assertTrue($adminLevelIds->contains(1), 'Dropdown must include level 1 for non-superadmin');
    }

    public function test_store_and_update_validation_accepts_level_1_for_company_admin(): void
    {
        $newEmail = 'new_level1_' . uniqid() . '@test.com';

        // Company Admin creates a subordinate user with Level 1 -> 200 (not rejected)
        $response = $this->actingAs($this->companyAdminA)->postJson(route('admin.user.store'), [
            'name' => 'Level 1 User',
            'email' => $newEmail,
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'level_id' => 1,
        ]);
        $response->assertStatus(200);

        $createdUser = User::where('email', $newEmail)->first();
        $this->assertNotNull($createdUser);
        $this->assertSame(1, (int) $createdUser->level_id);

        // Company Admin updates user with Level 2 -> 200
        $updateResponse = $this->actingAs($this->companyAdminA)->putJson(
            route('admin.user.update', $createdUser->id),
            [
                'name' => 'Level 2 User',
                'email' => $createdUser->email,
                'company_id' => $this->companyA->id,
                'level_id' => 2,
            ]
        );
        $updateResponse->assertStatus(200);
        $createdUser->refresh();
        $this->assertSame(2, (int) $createdUser->level_id);
    }

    public function test_mobile_api_positions_scoped_to_tenant(): void
    {
        // Create subordinate users with positions
        $subA = User::create([
            'name' => 'Sub A',
            'email' => 'suba_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->companyA->id,
        ]);
        $subB = User::create([
            'name' => 'Sub B',
            'email' => 'subb_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->companyB->id,
        ]);

        $posA = Position::create([
            'user_id' => $subA->id,
            'latitude' => '-6.100000',
            'longitude' => '106.800000',
        ]);
        $posB = Position::create([
            'user_id' => $subB->id,
            'latitude' => '-7.100000',
            'longitude' => '107.800000',
        ]);

        // Company Admin A calls GET /api/positions
        $responseA = $this->actingAs($this->companyAdminA, 'sanctum')->getJson('/api/positions');
        $responseA->assertStatus(200);
        $dataA = $responseA->json('data');
        $idsA = collect($dataA)->pluck('id');

        $this->assertTrue($idsA->contains($posA->id));
        $this->assertFalse($idsA->contains($posB->id));

        // Company Admin B calls GET /api/positions
        $responseB = $this->actingAs($this->companyAdminB, 'sanctum')->getJson('/api/positions');
        $responseB->assertStatus(200);
        $dataB = $responseB->json('data');
        $idsB = collect($dataB)->pluck('id');

        $this->assertFalse($idsB->contains($posA->id));
        $this->assertTrue($idsB->contains($posB->id));

        // User without company gets empty list (fail-closed)
        $orphanResp = $this->actingAs($this->userWithoutCompany, 'sanctum')->getJson('/api/positions');
        $orphanResp->assertStatus(200);
        $this->assertEmpty($orphanResp->json('data'));

        // Super Admin sees both positions
        $superResp = $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/positions');
        $superResp->assertStatus(200);
        $superIds = collect($superResp->json('data'))->pluck('id');
        $this->assertTrue($superIds->contains($posA->id));
        $this->assertTrue($superIds->contains($posB->id));

        // Super Admin can filter by company_id
        $filteredResp = $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/positions?company_id=' . $this->companyA->id);
        $filteredResp->assertStatus(200);
        $filteredIds = collect($filteredResp->json('data'))->pluck('id');
        $this->assertTrue($filteredIds->contains($posA->id));
        $this->assertFalse($filteredIds->contains($posB->id));
    }

    public function test_mobile_api_get_last_position_user_scoped_and_idor_prevention(): void
    {
        $userA = User::create([
            'name' => 'Target A',
            'email' => 'targeta_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->companyA->id,
        ]);
        $posA = Position::create([
            'user_id' => $userA->id,
            'latitude' => '-6.111111',
            'longitude' => '106.222222',
        ]);
        $userA->update(['position_id' => $posA->id]);

        $userB = User::create([
            'name' => 'Target B',
            'email' => 'targetb_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->companyB->id,
        ]);
        $posB = Position::create([
            'user_id' => $userB->id,
            'latitude' => '-7.333333',
            'longitude' => '107.444444',
        ]);
        $userB->update(['position_id' => $posB->id]);

        // 1. Company Admin A queries same-company user -> 200
        $respSame = $this->actingAs($this->companyAdminA, 'sanctum')->getJson('/api/position?id_user=' . $userA->id);
        $respSame->assertStatus(200);
        $data = $respSame->json('data');

        // Check user.id is NOT overwritten by positions.id
        $this->assertSame($userA->id, $data['id']);
        $this->assertSame($posA->id, $data['position_id']);
        $this->assertSame('-6.111111', $data['latitude']);
        $this->assertSame('106.222222', $data['longitude']);

        // Check company_name and timezone_name are properly populated
        $this->assertSame($this->companyA->name, $data['company_name']);
        $expectedTz = optional($this->companyA->fresh(['timezone'])->timezone)->name ?? 'UTC';
        $this->assertSame($expectedTz, $data['timezone_name']);

        // Check sensitive fields are NOT exposed
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);

        // 2. IDOR Attempt: Company Admin A queries other company user -> 404
        $respIDOR = $this->actingAs($this->companyAdminA, 'sanctum')->getJson('/api/position?id_user=' . $userB->id);
        $respIDOR->assertStatus(404);

        // 3. Super Admin queries other company user -> 200
        $respSuper = $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/position?id_user=' . $userB->id);
        $respSuper->assertStatus(200);
        $this->assertSame($userB->id, $respSuper->json('data.id'));

        // 4. Listing mode without id_user: only returns users belonging to company A
        $respList = $this->actingAs($this->companyAdminA, 'sanctum')->getJson('/api/position');
        $respList->assertStatus(200);
        $listIds = collect($respList->json('data'))->pluck('id');
        $this->assertTrue($listIds->contains($userA->id));
        $this->assertFalse($listIds->contains($userB->id));

        // 5. User without company -> 404 on single user, empty on list
        $respOrphanSingle = $this->actingAs($this->userWithoutCompany, 'sanctum')->getJson('/api/position?id_user=' . $userA->id);
        $respOrphanSingle->assertStatus(404);

        $respOrphanList = $this->actingAs($this->userWithoutCompany, 'sanctum')->getJson('/api/position');
        $respOrphanList->assertStatus(200);
        $this->assertEmpty($respOrphanList->json('data'));

        // 6. Robustness against non-numeric and negative id_user inputs -> 404 (not 500)
        $respAlpha = $this->actingAs($this->companyAdminA, 'sanctum')->getJson('/api/position?id_user=not_a_number');
        $respAlpha->assertStatus(404);

        $respNegative = $this->actingAs($this->companyAdminA, 'sanctum')->getJson('/api/position?id_user=-1');
        $respNegative->assertStatus(404);

        // 7. Robustness against non-numeric company_id query parameter -> 200 (not 500)
        $respBadCompanySuper = $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/positions?company_id=invalid');
        $respBadCompanySuper->assertStatus(200);

        // 8. Timezone fallback to UTC when user has no company assigned
        $respNoCompany = $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/position?id_user=' . $this->userWithoutCompany->id);
        $respNoCompany->assertStatus(200);
        $this->assertSame('UTC', $respNoCompany->json('data.timezone_name'));
    }

    public function test_mobile_api_store_position_validation_and_transaction(): void
    {
        // 1. Invalid latitude (> 90) -> 422
        $respBadLat = $this->actingAs($this->companyAdminA, 'sanctum')->postJson('/api/position', [
            'latitude' => 95.1234,
            'longitude' => 106.8456,
        ]);
        $respBadLat->assertStatus(422);
        $respBadLat->assertJsonValidationErrors('latitude');

        // 2. Invalid longitude (< -180) -> 422
        $respBadLng = $this->actingAs($this->companyAdminA, 'sanctum')->postJson('/api/position', [
            'latitude' => -6.2088,
            'longitude' => -185.1234,
        ]);
        $respBadLng->assertStatus(422);
        $respBadLng->assertJsonValidationErrors('longitude');

        // 3. Non-numeric coordinates -> 422
        $respNonNumeric = $this->actingAs($this->companyAdminA, 'sanctum')->postJson('/api/position', [
            'latitude' => 'invalid_lat',
            'longitude' => 'invalid_lng',
        ]);
        $respNonNumeric->assertStatus(422);

        // 4. Valid coordinates with attempted spoofed user_id -> 200, user_id bound to actor
        $respValid = $this->actingAs($this->companyAdminA, 'sanctum')->postJson('/api/position', [
            'latitude' => '-6.208800',
            'longitude' => '106.845600',
            'user_id' => 99999, // spoof attempt
        ]);
        $respValid->assertStatus(200);

        $this->companyAdminA->refresh();
        $this->assertNotNull($this->companyAdminA->position_id);

        $createdPos = Position::find($this->companyAdminA->position_id);
        $this->assertNotNull($createdPos);
        $this->assertSame($this->companyAdminA->id, (int) $createdPos->user_id, 'user_id must be bound to actor');
        $this->assertSame('-6.208800', $createdPos->latitude);
        $this->assertSame('106.845600', $createdPos->longitude);
    }
}
