<x-layouts.app title="Reports">
    <header class="page-heading">
        <div><p class="eyebrow">Reporting workspace</p><h1>Generated reports</h1><p class="lead">Track processing, review completed dashboards and download verified outputs.</p></div>
        <a class="button" href="{{ route('reports.create') }}"><span aria-hidden="true">＋</span> New report</a>
    </header>
    <section class="panel">
        <div class="history-toolbar"><div><h2>Report history</h2><p class="muted tiny">{{ $generations->total() }} {{ \Illuminate\Support\Str::plural('report', $generations->total()) }}</p></div><a class="button secondary" href="{{ route('reports.trash') }}">Recycle bin</a></div>
        <div class="history-list">
            @forelse($generations as $generation)
                @php $statusClass = 'status-'.str($generation->status->value)->slug(); @endphp
                <a class="history-item" href="{{ route('reports.show', $generation) }}">
                    <div class="history-report"><strong>{{ $generation->reportDefinition->name }}</strong><span>Created {{ $generation->created_at->diffForHumans() }}</span></div>
                    <div class="history-period"><span class="history-cell-label">Reporting period</span><span class="history-value">{{ optional($generation->reporting_period_start)->format('d M') }} → {{ optional($generation->reporting_period_end)->format('d M Y') }}</span></div>
                    <div class="history-status"><span class="history-cell-label">Status</span><span class="history-value"><span class="badge {{ $statusClass }}">{{ str($generation->status->value)->replace('_',' ')->title() }}</span></span></div>
                    <div class="history-progress"><span class="history-cell-label">Progress</span><span class="history-value">{{ $generation->progress_percentage }}%</span><div class="mini-progress"><span style="width:{{ $generation->progress_percentage }}%"></span></div></div>
                    <span class="chevron" aria-hidden="true">›</span>
                </a>
            @empty
                <div class="empty-state"><div class="empty-state-icon">▤</div><h2>No reports yet</h2><p>Generate your first dashboard to see its processing status and outputs here.</p><a class="button" href="{{ route('reports.create') }}">Generate first report</a></div>
            @endforelse
        </div>
        {{ $generations->links() }}
    </section>
</x-layouts.app>
