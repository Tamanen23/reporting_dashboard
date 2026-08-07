<x-layouts.app title="Recycle bin">
    <header class="page-heading">
        <div><p class="eyebrow">Report retention</p><h1>Recycle bin</h1><p class="lead">Deleted reports remain recoverable for 30 days before permanent removal.</p></div>
        <a class="button secondary" href="{{ route('reports.index') }}">← Report history</a>
    </header>
    <section class="panel">
        <div class="history-toolbar"><div><h2>Deleted reports</h2><p class="muted tiny">{{ $generations->total() }} {{ \Illuminate\Support\Str::plural('report', $generations->total()) }}</p></div></div>
        <div class="history-list">
            @forelse($generations as $generation)
                <div class="history-item trash-item">
                    <div class="history-report"><strong>{{ $generation->reportDefinition->name }}</strong><span>Deleted {{ $generation->deleted_at->diffForHumans() }}{{ auth()->user()->is_admin ? ' · '.$generation->user->email : '' }}</span></div>
                    <div class="history-period"><span class="history-cell-label">Reporting period</span><span class="history-value">{{ optional($generation->reporting_period_start)->format('d M') }} → {{ optional($generation->reporting_period_end)->format('d M Y') }}</span></div>
                    <div class="history-status"><span class="history-cell-label">Permanent removal</span><span class="history-value">{{ optional($generation->purge_after)->format('d M Y H:i') }}</span></div>
                    <form method="post" action="{{ route('reports.restore', $generation->uuid) }}">@csrf<button class="button secondary" type="submit">Restore</button></form>
                </div>
            @empty
                <div class="empty-state"><div class="empty-state-icon">♲</div><h2>Recycle bin is empty</h2><p>Deleted reports will appear here during their 30-day recovery period.</p></div>
            @endforelse
        </div>
        {{ $generations->links() }}
    </section>
</x-layouts.app>
