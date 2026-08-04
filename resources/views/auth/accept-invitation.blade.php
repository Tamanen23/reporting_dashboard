<x-layouts.app title="Create password">
    <div class="login-wrap">
        <section class="panel login-card">
            <div class="login-brand"><img src="{{ asset('betnabiso-logo.jpeg') }}" alt=""></div>
            <p class="eyebrow">Account invitation</p>
            <h1>Create your password</h1>
            <p class="lead">Welcome, {{ $invitation->user->name }}. Set the password for <strong>{{ $invitation->user->email }}</strong>.</p>
            <form method="post" action="{{ route('invitations.update', ['token' => $token]) }}" style="margin-top:28px">
                @csrf
                <div class="field" style="margin-bottom:18px">
                    <label for="password">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
                    <p class="field-help">At least 12 characters with uppercase, lowercase, a number and a symbol.</p>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                </div>
                <div class="actions"><button class="button" type="submit">Create password →</button></div>
            </form>
            <p class="login-help">This invitation expires {{ $invitation->expires_at->diffForHumans() }} and can be used only once.</p>
        </section>
    </div>
</x-layouts.app>
