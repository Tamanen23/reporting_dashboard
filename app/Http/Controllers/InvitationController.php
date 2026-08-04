<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

final class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = $this->resolve($token);

        return view('auth.accept-invitation', ['invitation' => $invitation, 'token' => $token]);
    }

    public function update(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolve($token);
        $validated = $request->validate([
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        DB::transaction(function () use ($invitation, $validated): void {
            $invitation->user->forceFill([
                'password' => $validated['password'],
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();
            $invitation->update(['accepted_at' => now()]);
        });

        if (Auth::check()) {
            Auth::logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('success', 'Your password has been created. Sign in with your email and new password.');
    }

    private function resolve(string $token): UserInvitation
    {
        abort_unless(strlen($token) === 64, 404);
        $invitation = UserInvitation::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();
        abort_if($invitation->accepted_at !== null || $invitation->expires_at->isPast(), 410);

        return $invitation;
    }
}
