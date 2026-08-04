<x-layouts.app title="Users">
    <div class="page-narrow user-admin">
        <header class="page-heading">
            <div>
                <p class="eyebrow">Administration</p>
                <h1>User access</h1>
                <p class="lead">Create a user and share a single-use password setup link.</p>
            </div>
        </header>

        @if(session('invitation_url'))
            <section class="panel invitation-result" aria-labelledby="invitation-created">
                <div>
                    <p class="eyebrow">Invitation created</p>
                    <h2 id="invitation-created">Copy this link now</h2>
                    <p>The link expires in 24 hours and is displayed only on this page load.</p>
                </div>
                <div class="invitation-copy">
                    <input id="invitation-url" type="text" readonly value="{{ session('invitation_url') }}" aria-label="Invitation URL">
                    <button class="button" id="copy-invitation" type="button">Copy link</button>
                </div>
            </section>
        @endif

        <section class="panel section-card">
            <div class="section-head"><div><h2>Invite a user</h2><p>The recipient will create their own password before they can sign in.</p></div></div>
            <form method="post" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="form-grid">
                    <div class="field"><label for="name">Full name</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="off"></div>
                    <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="off"></div>
                </div>
                <div class="actions"><button class="button" type="submit">Create invitation →</button></div>
            </form>
        </section>

        <section class="panel section-card">
            <div class="section-head"><div><h2>Users</h2><p>{{ $users->count() }} registered account{{ $users->count() === 1 ? '' : 's' }}</p></div></div>
            <div class="user-list">
                @foreach($users as $user)
                    @php
                        $invitation = $user->invitation;
                        $state = $user->email_verified_at ? 'Active' : (($invitation?->expires_at?->isPast()) ? 'Expired' : 'Invited');
                    @endphp
                    <article class="user-row">
                        <div><strong>{{ $user->name }}</strong><span>{{ $user->email }}</span></div>
                        <span class="user-role">{{ $user->is_admin ? 'Administrator' : 'Report user' }}</span>
                        <span class="status-pill status-{{ Str::slug($state) }}">{{ $state }}</span>
                        <time>{{ $user->created_at?->format('d M Y H:i') }}</time>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
    @if(session('invitation_url'))
        <script>
            document.getElementById('copy-invitation').addEventListener('click', async event => {
                await navigator.clipboard.writeText(document.getElementById('invitation-url').value);
                event.currentTarget.textContent = 'Copied ✓';
            });
        </script>
    @endif
</x-layouts.app>
