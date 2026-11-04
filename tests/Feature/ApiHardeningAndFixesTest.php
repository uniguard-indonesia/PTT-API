<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Company;
use App\Models\Level;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ApiHardeningAndFixesTest extends TestCase
{
    use DatabaseTransactions;

    protected User $superAdmin;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = Timezone::firstOrCreate(
            ['id' => 1],
            ['name' => 'Asia/Jakarta', 'code' => 'WIB']
        );

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            ['name' => 'Company A', 'email' => 'companyA@test.com', 'timezone_id' => $tz->id]
        );

        $level1 = Level::find(1);
        if (!$level1) {
            DB::table('levels')->insert([
                'id' => 1,
                'name' => 'Role Level 1',
                'menu' => 'Dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->superAdmin = User::create([
            'name' => 'Admin Test',
            'email' => 'adm_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => User::LEVEL_SUPER_ADMIN,
            'company_id' => $this->company->id,
            'position_id' => 0,
        ]);
    }

    public function test_unauthenticated_api_request_without_accept_header_returns_json_401(): void
    {
        // Calling /api/position without Accept header and without Bearer token
        $response = $this->get('/api/position');

        // MUST be 401 JSON, NOT 302 redirect
        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJson([
            'status' => 'error',
            'message' => 'Unauthenticated.',
        ]);
    }

    public function test_user_with_zero_position_id_handled_cleanly(): void
    {
        $user = User::create([
            'name' => 'Zero Pos User',
            'email' => 'zeropos_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->company->id,
            'position_id' => 0,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/position?id_user=' . $user->id);
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame($user->id, $data['id']);
        $this->assertNull($data['position_id']);
        $this->assertNull($data['latitude']);
        $this->assertNull($data['longitude']);
        $this->assertNull($data['position']);
    }

    public function test_certificate_store_validates_mimes(): void
    {
        $user = User::create([
            'name' => 'Cert User',
            'email' => 'cert_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->company->id,
        ]);

        $fakePhpFile = UploadedFile::fake()->createWithContent(
            'malicious.php',
            "-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"
        );

        $response = $this->actingAs($user, 'sanctum')->post('/api/certificates', [
            'certificate' => $fakePhpFile,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('certificate');
    }

    public function test_certificate_store_replaces_old_and_keeps_new_file(): void
    {
        $user = User::create([
            'name' => 'Cert User 2',
            'email' => 'cert2_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->company->id,
        ]);

        $certDir = public_path('certificates');
        if (!is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }

        $file1Name = 'cert1_' . uniqid() . '.crt';
        $file2Name = 'cert2_' . uniqid() . '.crt';

        $file1 = UploadedFile::fake()->createWithContent($file1Name, "-----BEGIN CERTIFICATE-----\ntest1\n-----END CERTIFICATE-----");
        $resp1 = $this->actingAs($user, 'sanctum')->post('/api/certificates', [
            'certificate' => $file1,
        ], ['Accept' => 'application/json']);
        $resp1->assertStatus(200);

        $certificatePath1 = Certificate::where('user_id', $user->id)->value('certificate_path');
        $this->assertStringStartsWith('certificates/', $certificatePath1);
        $this->assertNotSame($file1Name, basename($certificatePath1));
        $path1 = public_path($certificatePath1);
        $this->assertFileExists($path1);

        // Upload second certificate
        $file2 = UploadedFile::fake()->createWithContent($file2Name, "-----BEGIN CERTIFICATE-----\ntest2\n-----END CERTIFICATE-----");
        $resp2 = $this->actingAs($user, 'sanctum')->post('/api/certificates', [
            'certificate' => $file2,
        ], ['Accept' => 'application/json']);
        $resp2->assertStatus(200);

        $certificatePath2 = Certificate::where('user_id', $user->id)->value('certificate_path');
        $this->assertStringStartsWith('certificates/', $certificatePath2);
        $this->assertNotSame($certificatePath1, $certificatePath2);
        $path2 = public_path($certificatePath2);
        // The NEW file MUST exist and NOT be deleted by unlink bug
        $this->assertFileExists($path2);
        // The OLD file MUST be deleted
        $this->assertFileDoesNotExist($path1);

        // Cleanup
        if (file_exists($path2)) {
            @unlink($path2);
        }
    }

    public function test_certificate_store_preserves_file_referenced_by_another_user(): void
    {
        $user = User::create([
            'name' => 'Cert Owner',
            'email' => 'cert_owner_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->company->id,
        ]);
        $otherUser = User::create([
            'name' => 'Other Cert Owner',
            'email' => 'other_cert_owner_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'level_id' => 1,
            'company_id' => $this->company->id,
        ]);
        $this->assertNotSame($user->id, $otherUser->id);

        $sharedPath = 'certificates/shared_' . uniqid() . '.crt';
        if (!is_dir(public_path('certificates'))) {
            mkdir(public_path('certificates'), 0755, true);
        }
        $fullSharedPath = public_path($sharedPath);
        file_put_contents($fullSharedPath, 'shared certificate');
        Certificate::updateOrCreate(['user_id' => $user->id], ['certificate_path' => $sharedPath]);
        Certificate::updateOrCreate(['user_id' => $otherUser->id], ['certificate_path' => $sharedPath]);

        $upload = UploadedFile::fake()->createWithContent(
            'replacement.crt',
            "-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"
        );
        $response = $this->actingAs($user, 'sanctum')->post('/api/certificates', [
            'certificate' => $upload,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $this->assertFileExists($fullSharedPath);
        $this->assertNotSame($sharedPath, $user->fresh()->certificate->certificate_path);

        $newFilePath = public_path($user->fresh()->certificate->certificate_path);
        if (file_exists($newFilePath)) {
            unlink($newFilePath);
        }
        unlink($fullSharedPath);
    }

    public function test_auth_login_server_error_sanitizes_message(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Login failed due to an unexpected error.', ['event' => 'auth.login']);

        Auth::shouldReceive('attempt')
            ->once()
            ->andThrow(new \Exception('SQLSTATE[HY000]: Database connection failed password=secret'));

        $ctrl = new \App\Http\Controllers\API\AuthController();
        $request = \Illuminate\Http\Request::create('/api/auth/login', 'POST', [
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);

        $response = $ctrl->login($request);
        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Failed to login, please try again later.', $data['message']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('Database connection failed', $response->getContent());

        // Restore clean Auth facade instance for subsequent tests
        Auth::clearResolvedInstances();
    }
}
