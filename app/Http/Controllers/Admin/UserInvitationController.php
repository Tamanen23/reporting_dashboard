<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class UserInvitationController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->with('invitation')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.users.index', compact('users'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);
        $token = Str::random(64);

        DB::transaction(function () use ($request, $validated, $token): void {
            $user = User::query()->create([
                'name' => trim($validated['name']),
                'email' => Str::lower(trim($validated['email'])),
                'password' => Str::random(64),
                'is_admin' => false,
            ]);
            UserInvitation::query()->create([
                'user_id' => $user->id,
                'invited_by' => $request->user()->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes((int) config('auth.invitation_expire', 1440)),
            ]);
        });

        return back()
            ->with('success', 'User created. Copy the invitation link now; it is shown only once.')
            ->with('invitation_url', URL::route('invitations.show', ['token' => $token]));
    }
}
