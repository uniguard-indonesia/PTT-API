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
            ['name' => 'Asia/Jakarta', 'code' => 'WIB']
        );

        $this->company = Company::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Company', 'email' => 'company_' . uniqid() . '@test.com', 'timezone_id' => $this->timezone->id]
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

        // 1. No FontAwesome icon classes on password toggle buttons or reset modal password inputs
        $this->assertStringNotContainsString('fa-regular fa-eye-slash', $content, 'FontAwesome fa-regular fa-eye-slash must be replaced with Themify icon');
        $this->assertStringNotContainsString('fas fa-eye-slash', $content, 'fas fa-eye-slash must not be used in showPassword or inputs');

        // 2. Standardized Themify icons for password toggles (ti-lock for masked, ti-eye for revealed)
        $this->assertStringContainsString('ti-lock', $content, 'Themify ti-lock icon must be used for masked password state');
        $this->assertStringContainsString('ti-eye', $content, 'Themify ti-eye icon must be used for revealed password state');

        // 3. Accessibility: aria-label dynamically updated on toggle and present initially
        $this->assertStringContainsString('aria-label="Show password"', $content, 'Initial toggle button must have aria-label for accessibility');
        $this->assertStringContainsString('Hide password', $content, 'showPassword must toggle aria-label to Hide password when revealed');

        // 4. Modal state reset: resets password input types to password and icons on open/close
        $this->assertMatchesRegularExpression('/resetPasswordVisibility|\.attr\([\'"]type[\'"],\s*[\'"]password[\'"]\)/', $content);
        $this->assertMatchesRegularExpression('/(?:#resetModal|#basicModal)[^;]+(?:hide\.bs\.modal|hidden\.bs\.modal|show\.bs\.modal)/', $content, 'Must attach reset listener on modal open/close');
    }
}

