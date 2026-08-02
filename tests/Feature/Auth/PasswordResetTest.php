<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // SECURITY: public password reset is disabled — passwords are reset by an admin/director.
    public function test_forgot_password_screen_is_disabled(): void
    {
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_reset_link_cannot_be_requested(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_reset_password_endpoints_are_disabled(): void
    {
        $this->get('/reset-password/some-token')->assertNotFound();
        $this->post('/reset-password', [
            'token' => 'x', 'email' => 'a@b.kz', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertNotFound();
    }
}
