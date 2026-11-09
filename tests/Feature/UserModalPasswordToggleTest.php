<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Level;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserModalPasswordToggleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $superAdmin;
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

        $this->superAdmin = User::withoutEvents(function () {
            return User::create([
                'name' => 'Super Admin Test',
                'email' => 'superadmin_' . uniqid() . '@test.com',
                'password' => bcrypt('password123'),
                'level_id' => User::LEVEL_SUPER_ADMIN,
                'company_id' => $this->company->id,
            ]);
        });
    }

    public function test_user_view_standardizes_password_icons_and_toggle_behavior(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.user.index'));

        $response->assertOk()
            ->assertViewIs('pages.admin.user');

        $content = $response->getContent();

        // 1. Use eye icons that match the password visibility state.
        $this->assertStringNotContainsString('ti-lock', $content, 'Password visibility controls must not use a lock icon');
        $this->assertStringContainsString('fa fa-eye-slash', $content, 'Visible passwords must show an eye-slash icon');

        // 2. Masked passwords use an eye icon that indicates they can be revealed.
        $this->assertStringContainsString('fa fa-eye', $content, 'Masked passwords must show an eye icon');

        // 3. Accessibility: aria-label dynamically updated on toggle and present initially
        $this->assertStringContainsString('aria-label="Show password"', $content, 'Initial toggle button must have aria-label for accessibility');
        $this->assertStringContainsString('Hide password', $content, 'showPassword must toggle aria-label to Hide password when revealed');
        $this->assertStringContainsString('#basicModal .modal-header h5', $content);
        $this->assertStringContainsString('#resetModal .modal-header h5', $content);
        $this->assertStringNotContainsString("$('.modal-header h5')", $content);

        // 4. Modal state reset: resets password input types to password and icons on open/close
        $this->assertMatchesRegularExpression('/resetPasswordVisibility|\.attr\([\'"]type[\'"],\s*[\'"]password[\'"]\)/', $content);
        $this->assertMatchesRegularExpression('/(?:#resetModal|#basicModal)[^;]+(?:hide\.bs\.modal|hidden\.bs\.modal|show\.bs\.modal)/', $content, 'Must attach reset listener on modal open/close');
    }

    public function test_server_password_toggle_matches_user_toggle_accessibility_and_reset_behavior(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.server.index'));

        $response->assertOk()
            ->assertViewIs('pages.admin.server');

        $content = $response->getContent();

        $this->assertStringNotContainsString('ti-lock', $content);
        $this->assertStringContainsString('fa fa-eye', $content);
        $this->assertStringContainsString('fa fa-eye-slash', $content);
        $this->assertStringContainsString('aria-label="Show password"', $content);
        $this->assertStringContainsString('Hide password', $content);
        $this->assertStringContainsString("resetPasswordVisibility('#basicModal')", $content);
        $this->assertMatchesRegularExpression('/#basicModal[^;]+(?:hide\.bs\.modal|show\.bs\.modal)/', $content);
    }
}
