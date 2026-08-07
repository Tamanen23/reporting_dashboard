<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_offers_remember_me_and_password_recovery(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Remember me')
            ->assertSee(route('password.request'));
    }

    public function test_remember_me_creates_a_persistent_login_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ValidPassword!123')]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'ValidPassword!123',
            'remember' => '1',
        ])->assertRedirect(route('reports.create'))
            ->assertCookie(auth()->guard()->getRecallerName());

        self::assertNotNull($user->fresh()->remember_token);
    }

    public function test_user_can_request_and_complete_a_password_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('success');

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        self::assertIsString($token);
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePassword!456',
            'password_confirmation' => 'NewSecurePassword!456',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('success');

        self::assertTrue(Hash::check('NewSecurePassword!456', $user->fresh()->password));
    }

    public function test_password_request_does_not_disclose_unknown_accounts(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'unknown@example.com'])
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();
    }
}
