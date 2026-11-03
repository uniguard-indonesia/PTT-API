<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Level;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected User $superAdmin;
    protected User $companyAdmin;
    protected User $otherCompanyAdmin;
    protected Level $operatorLevel;
    protected Company $companyA;
    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        // Sync postgres sequences safely with table MAX(id)
        try {
            DB::statement("SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users))");
            DB::statement("SELECT setval('levels_id_seq', (SELECT COALESCE(MAX(id), 1) FROM levels))");
            DB::statement("SELECT setval('servers_id_seq', (SELECT COALESCE(MAX(id), 1) FROM servers))");
            DB::statement("SELECT setval('companies_id_seq', (SELECT COALESCE(MAX(id), 1) FROM companies))");
        } catch (\Throwable $e) {
            // Not pgsql or sequence missing
        }

        $this->companyA = Company::firstOrCreate(
            ['id' => 1],
            ['name' => 'Company A', 'email' => 'compA@test.com', 'phone' => '12345', 'address' => 'Test A', 'timezone_id' => 1]
        );
        $this->companyB = Company::firstOrCreate(
            ['id' => 2],
            ['name' => 'Company B', 'email' => 'compB@test.com', 'phone' => '67890', 'address' => 'Test B', 'timezone_id' => 1]
        );

        $this->operatorLevel = Level::firstOrCreate(
            ['name' => 'Operator Level Test'],
            ['menu' => 'Dashboard']
        );

        $this->superAdmin = User::create([
            'name' => 'Super Admin Test',
            'email' => 'superadmin_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_SUPER_ADMIN,
            'company_id' => $this->companyA->id,
        ]);

        $this->companyAdmin = User::create([
            'name' => 'Company Admin A',
            'email' => 'company_admin_a_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->operatorLevel->id,
            'company_id' => $this->companyA->id,
        ]);

        $this->otherCompanyAdmin = User::create([
            'name' => 'Company Admin B',
            'email' => 'company_admin_b_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => $this->operatorLevel->id,
            'company_id' => $this->companyB->id,
        ]);
    }

    public function test_user_model_is_super_admin_and_constants(): void
    {
        $this->assertSame(0, User::LEVEL_SUPER_ADMIN);

        $super = new User(['level_id' => 0]);
        $this->assertTrue($super->isSuperAdmin());

        $nonSuper = new User(['level_id' => 1]);
        $this->assertFalse($nonSuper->isSuperAdmin());

        $nullLevel = new User(['level_id' => null]);
        $this->assertFalse($nullLevel->isSuperAdmin());
    }

    public function test_scope_manageable_by_restrictions(): void
    {
        // Super Admin sees all
        $superManageable = User::manageableBy($this->superAdmin)->pluck('id');
        $this->assertTrue($superManageable->contains($this->superAdmin->id));
        $this->assertTrue($superManageable->contains($this->companyAdmin->id));
        $this->assertTrue($superManageable->contains($this->otherCompanyAdmin->id));

        // Company Admin A only sees company A with level_id > 0 (excludes superadmins and company B)
        $companyAManageable = User::manageableBy($this->companyAdmin)->pluck('id');
        $this->assertFalse($companyAManageable->contains($this->superAdmin->id));
        $this->assertTrue($companyAManageable->contains($this->companyAdmin->id));
        $this->assertFalse($companyAManageable->contains($this->otherCompanyAdmin->id));

        // Null actor yields 0 results (fail closed)
        $this->assertSame(0, User::manageableBy(null)->count());

        // Non-superadmin with null or 0 company_id yields 0 results (fail closed)
        $orphanActor = new User(['level_id' => $this->operatorLevel->id, 'company_id' => null]);
        $this->assertSame(0, User::manageableBy($orphanActor)->count());

        $zeroCoActor = new User(['level_id' => $this->operatorLevel->id, 'company_id' => 0]);
        $this->assertSame(0, User::manageableBy($zeroCoActor)->count());
    }

    public function test_server_model_hides_password_in_serialization(): void
    {
        $server = new Server([
            'name' => 'Test Server',
            'host' => '127.0.0.1',
            'port' => 64738,
            'password' => 'secret_server_pass',
        ]);

        $array = $server->toArray();
        $this->assertArrayNotHasKey('password', $array);

        $json = json_encode($server);
        $this->assertStringNotContainsString('secret_server_pass', $json);
    }

    public function test_company_admin_cannot_create_super_admin(): void
    {
        $response = $this->actingAs($this->companyAdmin)->postJson(route('admin.user.store'), [
            'name' => 'Malicious User',
            'email' => 'malicious_' . uniqid() . '@test.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'level_id' => User::LEVEL_SUPER_ADMIN,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('level_id');
    }

    public function test_company_admin_cannot_create_user_for_other_company(): void
    {
        $response = $this->actingAs($this->companyAdmin)->postJson(route('admin.user.store'), [
            'name' => 'Other Company User',
            'email' => 'other_co_' . uniqid() . '@test.com',
            'password' => 'password123',
            'company_id' => $this->companyB->id,
            'level_id' => $this->operatorLevel->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('company_id');
    }

    public function test_company_admin_cannot_view_or_edit_unauthorized_user(): void
    {
        // Company Admin A tries to edit user in Company B -> 404
        $response = $this->actingAs($this->companyAdmin)->getJson(
            route('admin.user.edit', $this->otherCompanyAdmin->id)
        );
        $response->assertStatus(404);

        // Company Admin A tries to edit Super Admin -> 404
        $response = $this->actingAs($this->companyAdmin)->getJson(
            route('admin.user.edit', $this->superAdmin->id)
        );
        $response->assertStatus(404);
    }

    public function test_prevent_self_escalation_on_user_update(): void
    {
        // Company Admin attempts to change their own level to 0 -> 422 (not in allowlist)
        $response = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.update', $this->companyAdmin->id),
            [
                'name' => 'Company Admin A',
                'email' => $this->companyAdmin->email,
                'company_id' => $this->companyA->id,
                'level_id' => User::LEVEL_SUPER_ADMIN,
            ]
        );
        $response->assertStatus(422);

        // If another level exists, attempting to change own level returns 403
        $secondLevel = Level::where('id', '<>', $this->companyAdmin->level_id)
            ->where('id', '>', User::LEVEL_SUPER_ADMIN)
            ->first();
        if (!$secondLevel) {
            $secondLevel = Level::create(['name' => 'Supervisor_' . uniqid(), 'menu' => 'Dashboard']);
        }
        $response2 = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.update', $this->companyAdmin->id),
            [
                'name' => 'Company Admin A',
                'email' => $this->companyAdmin->email,
                'company_id' => $this->companyA->id,
                'level_id' => $secondLevel->id,
            ]
        );
        $response2->assertStatus(403);
        $response2->assertJson(['message' => 'You cannot modify your own level.']);

        // Attempting to change own company returns 422
        $response3 = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.update', $this->companyAdmin->id),
            [
                'name' => 'Company Admin A',
                'email' => $this->companyAdmin->email,
                'company_id' => $this->companyB->id,
                'level_id' => $this->companyAdmin->level_id,
            ]
        );
        $response3->assertStatus(422);
    }

    public function test_prevent_self_deletion_on_user_destroy(): void
    {
        $response = $this->actingAs($this->companyAdmin)->deleteJson(
            route('admin.user.destroy', $this->companyAdmin->id)
        );

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You cannot delete your own account.']);
    }

    public function test_reset_password_validates_and_invalidates_tokens(): void
    {
        // Create subordinate user
        $subordinate = User::create([
            'name' => 'Subordinate User',
            'email' => 'subordinate_' . uniqid() . '@test.com',
            'password' => bcrypt('oldpassword'),
            'company_id' => $this->companyA->id,
            'level_id' => $this->operatorLevel->id,
        ]);

        // Create Sanctum tokens for subordinate
        $token1 = $subordinate->createToken('device-1')->plainTextToken;
        $token2 = $subordinate->createToken('device-2')->plainTextToken;
        $this->assertCount(2, $subordinate->tokens);

        // Short password fails
        $badResponse = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.reset', $subordinate->id),
            ['new_password' => '123']
        );
        $badResponse->assertStatus(422);
        $badResponse->assertJsonValidationErrors('new_password');

        $missingConfirmationResponse = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.reset', $subordinate->id),
            ['new_password' => 'newpassword123']
        );
        $missingConfirmationResponse->assertStatus(422);
        $missingConfirmationResponse->assertJsonValidationErrors('confirm_password');

        $mismatchedResponse = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.reset', $subordinate->id),
            ['new_password' => 'newpassword123', 'confirm_password' => 'differentpassword']
        );
        $mismatchedResponse->assertStatus(422);
        $mismatchedResponse->assertJsonValidationErrors('confirm_password');

        // Valid reset
        $goodResponse = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.reset', $subordinate->id),
            ['new_password' => 'newpassword123', 'confirm_password' => 'newpassword123']
        );
        $goodResponse->assertStatus(200);

        // Tokens should now be invalidated
        $subordinate->refresh();
        $this->assertCount(0, $subordinate->tokens);
    }

    public function test_level_resource_protected_by_superadmin_middleware(): void
    {
        // Company admin is blocked
        $response = $this->actingAs($this->companyAdmin)->getJson(route('admin.level.index'));
        $response->assertStatus(403);

        // Super admin is allowed
        $superResponse = $this->actingAs($this->superAdmin)->getJson(route('admin.level.index'));
        $superResponse->assertStatus(200);
    }

    public function test_server_edit_blocked_for_non_superadmin(): void
    {
        $server = Server::create([
            'name' => 'Test Server Edit ' . uniqid(),
            'host' => '127.0.0.1',
            'port' => 64738,
            'password' => 'pass',
            'user_id' => $this->superAdmin->id,
        ]);

        // Company admin edit attempt -> 403
        $response = $this->actingAs($this->companyAdmin)->getJson(
            route('admin.server.edit', $server->id)
        );
        $response->assertStatus(403);
        $response->assertJson(['status' => 'error']);

        // Super admin edit attempt -> 200
        $superResponse = $this->actingAs($this->superAdmin)->getJson(
            route('admin.server.edit', $server->id)
        );
        $superResponse->assertStatus(200);
    }

    public function test_super_admin_cannot_delete_own_account(): void
    {
        $response = $this->actingAs($this->superAdmin)->deleteJson(
            route('admin.user.destroy', $this->superAdmin->id)
        );

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You cannot delete your own account.']);
    }

    public function test_user_self_update_without_level_change_succeeds(): void
    {
        $newName = 'Updated Name Self ' . uniqid();
        $response = $this->actingAs($this->companyAdmin)->putJson(
            route('admin.user.update', $this->companyAdmin->id),
            [
                'name' => $newName,
                'email' => $this->companyAdmin->email,
                'company_id' => $this->companyAdmin->company_id,
                'level_id' => $this->companyAdmin->level_id,
            ]
        );

        $response->assertStatus(200);
        $this->companyAdmin->refresh();
        $this->assertSame($newName, $this->companyAdmin->name);
    }

    public function test_update_user_email_unique_validation(): void
    {
        // Reusing another user's email should fail validation (422)
        $response = $this->actingAs($this->superAdmin)->putJson(
            route('admin.user.update', $this->companyAdmin->id),
            [
                'name' => 'Company Admin',
                'email' => $this->otherCompanyAdmin->email, // duplicate
                'company_id' => $this->companyAdmin->company_id,
                'level_id' => $this->companyAdmin->level_id,
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_regenerate_certificate_scoping_and_404(): void
    {
        // Non-superadmin cannot regenerate certificate for other company user
        $response = $this->actingAs($this->companyAdmin)->postJson(
            route('admin.user.regenerate-certificate', $this->otherCompanyAdmin->id)
        );
        $response->assertStatus(404);

        // Non-superadmin cannot regenerate certificate for super admin
        $response2 = $this->actingAs($this->companyAdmin)->postJson(
            route('admin.user.regenerate-certificate', $this->superAdmin->id)
        );
        $response2->assertStatus(404);
    }

    public function test_index_datatable_scopes_manageable_users(): void
    {
        $response = $this->actingAs($this->companyAdmin)->getJson(
            route('admin.user.index')
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $emails = collect($data)->pluck('email');

        // Should include self (Company A)
        $this->assertTrue($emails->contains($this->companyAdmin->email));
        // Should NOT include Super Admin
        $this->assertFalse($emails->contains($this->superAdmin->email));
        // Should NOT include Company B Admin
        $this->assertFalse($emails->contains($this->otherCompanyAdmin->email));
    }

    public function test_super_admin_can_create_user_with_any_allowed_level(): void
    {
        $newEmail = 'new_super_' . uniqid() . '@test.com';
        $response = $this->actingAs($this->superAdmin)->postJson(route('admin.user.store'), [
            'name' => 'New Super',
            'email' => $newEmail,
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'level_id' => User::LEVEL_SUPER_ADMIN,
        ]);

        $response->assertStatus(200);
        $user = User::where('email', $newEmail)->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperAdmin());
    }

    public function test_photo_validation_rejects_non_image_files(): void
    {
        $maliciousFile = UploadedFile::fake()->create('malicious.php', 10, 'application/x-php');

        $response = $this->actingAs($this->superAdmin)->postJson(route('admin.user.store'), [
            'name' => 'Attacker',
            'email' => 'attacker_' . uniqid() . '@test.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'level_id' => User::LEVEL_SUPER_ADMIN,
            'photo' => $maliciousFile,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('photo');
        $this->assertArrayHasKey('fields', $response->json());
        $this->assertArrayHasKey('errors', $response->json());
    }
}
