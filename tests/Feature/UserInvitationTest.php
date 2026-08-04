<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_manage_users(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Invite a user');
    }

    public function test_administrator_creates_user_and_single_use_invitation(): void
    {
        $administrator = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($administrator)->post(route('admin.users.store'), [
            'name' => 'New Report User',
            'email' => 'NEW.USER@example.com',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('invitation_url');
        $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();
        self::assertFalse($user->is_admin);
        self::assertNull($user->email_verified_at);
        $invitation = $user->invitation()->firstOrFail();
        self::assertSame($administrator->id, $invitation->invited_by);
        self::assertTrue($invitation->expires_at->isFuture());
        self::assertTrue($invitation->expires_at->lessThanOrEqualTo(now()->addDay()));
    }

    public function test_recipient_sets_password_then_must_log_in_normally(): void
    {
        $user = User::factory()->unverified()->create();
        $token = str_repeat('a', 64);
        $invitation = UserInvitation::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);
        $password = 'Secure!Reporting2026';

        $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee($user->email);
        $this->post(route('invitations.update', ['token' => $token]), [
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertRedirect(route('login'));

        $user->refresh();
        self::assertTrue(Hash::check($password, $user->password));
        self::assertNotNull($user->email_verified_at);
        self::assertNotNull($invitation->fresh()->accepted_at);
        $this->assertGuest();
        $this->get(route('invitations.show', ['token' => $token]))->assertStatus(410);
    }

    public function test_expired_invitation_cannot_be_used(): void
    {
        $token = str_repeat('b', 64);
        UserInvitation::query()->create([
            'user_id' => User::factory()->unverified()->create()->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('invitations.show', ['token' => $token]))->assertStatus(410);
    }
}
