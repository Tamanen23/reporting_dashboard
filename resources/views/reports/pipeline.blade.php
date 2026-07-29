<x-layouts.app title="Generation Pipeline">
    @if($metrics['active'] > 0)<meta http-equiv="refresh" content="8">@endif
    <header class="page-heading pipeline-heading">
        <div>
            <p class="eyebrow">Operations monitor</p>
            <h1>Generation pipeline</h1>
            <p class="lead">Follow every dashboard from source intake through validation, calculation, rendering and publication.</p>
        </div>
        <span class="pipeline-live"><i></i>{{ $metrics['active'] ? 'Live · refreshes every 8 seconds' : 'No active jobs' }}</span>
    </header>

    <section class="pipeline-metrics">
        <div class="pipeline-metric"><span>Active</span><strong>{{ $metrics['active'] }}</strong><small>Queued or processing</small></div>
        <div class="pipeline-metric success"><span>Successful</span><strong>{{ $metrics['successful'] }}</strong><small>Published generations</small></div>
        <div class="pipeline-metric danger"><span>Failed</span><strong>{{ $metrics['failed'] }}</strong><small>Requires attention</small></div>
        <div class="pipeline-metric warning"><span>Warnings</span><strong>{{ $metrics['warnings'] }}</strong><small>Recorded audit flags</small></div>
    </section>

    <section class="panel pipeline-panel">
        <form class="pipeline-filters" method="get">
            <div class="field"><label for="pipeline-status">Status</label><select id="pipeline-status" name="status"><option value="">All statuses</option>@foreach(\App\Domain\Reports\Enums\ReportStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ str($status->value)->replace('_',' ')->title() }}</option>@endforeach</select></div>
            <div class="field"><label for="pipeline-report">Dashboard</label><select id="pipeline-report" name="report_code"><option value="">All dashboards</option>@foreach($definitions as $definition)<option value="{{ $definition->code }}" @selected(request('report_code') === $definition->code)>{{ $definition->name }}</option>@endforeach</select></div>
            <button class="button secondary" type="submit">Apply filters</button>
            @if(request()->hasAny(['status','report_code']))<a class="button-link" href="{{ route('reports.pipeline') }}">Clear</a>@endif
        </form>

        <div class="pipeline-list">
            @forelse($generations as $generation)
                @php
                    $status = $generation->status->value;
                    $currentStage = $generation->current_stage?->value;
                    $stageKeys = array_keys($stages);
                    $currentIndex = array_search($currentStage, $stageKeys, true);
                    $terminalSuccess = in_array($status, ['completed','completed_with_warnings'], true);
                    $latestEvents = $generation->events->sortByDesc('occurred_at')->take(6);
                @endphp
                <article class="pipeline-run pipeline-run-{{ str($status)->slug() }}">
                    <header class="pipeline-run-head">
                        <div>
                            <span class="pipeline-run-type">{{ $generation->reportDefinition->name }}</span>
                            <h2>{{ optional($generation->reporting_period_start)->format('d M Y') }} → {{ optional($generation->reporting_period_end)->format('d M Y') }}</h2>
                            <code>{{ $generation->uuid }}</code>
                        </div>
                        <div class="pipeline-run-state">
                            <span class="badge status-{{ str($status)->slug() }}">{{ str($status)->replace('_',' ')->title() }}</span>
                            <strong>{{ $generation->progress_percentage }}%</strong>
                            <span>{{ $generation->updated_at->diffForHumans() }}</span>
                        </div>
                    </header>

                    <div class="pipeline-track" aria-label="Generation stages">
                        @foreach($stages as $key => $label)
                            @php
                                $index = array_search($key, $stageKeys, true);
                                $class = $terminalSuccess || ($currentIndex !== false && $index < $currentIndex) ? 'done' : ($key === $currentStage ? ($status === 'failed' ? 'failed' : 'current') : 'pending');
                            @endphp
                            <div class="pipeline-step {{ $class }}"><i>@if($class === 'done')✓@elseif($class === 'failed')!@else{{ $loop->iteration }}@endif</i><span>{{ $label }}</span></div>
                        @endforeach
                    </div>

                    @if($generation->error_code)
                        <div class="pipeline-error"><strong>{{ str($generation->error_code)->replace('_',' ')->title() }}</strong><span>{{ $generation->error_message }}</span></div>
                    @elseif($generation->warnings_count)
                        <div class="pipeline-warning"><strong>{{ $generation->warnings_count }} warning(s)</strong><span>The report completed or is continuing with recorded audit flags.</span></div>
                    @endif

                    <div class="pipeline-log">
                        <div class="pipeline-log-title"><strong>Latest events</strong><span>{{ $generation->events->count() }} total</span></div>
                        @foreach($latestEvents as $event)
                            <div class="pipeline-log-row level-{{ $event->level->value }}">
                                <time>{{ $event->occurred_at->format('d M H:i:s') }}</time>
                                <span class="pipeline-log-level">{{ $event->level->value }}</span>
                                <strong>{{ str($event->stage?->value ?? 'system')->replace('_',' ')->title() }}</strong>
                                <span>{{ $event->message }}</span>
                            </div>
                        @endforeach
                    </div>
                    <footer class="pipeline-actions">
                        <a href="{{ route('reports.show', $generation) }}">Open full audit trail →</a>
                        @if($generation->status === \App\Domain\Reports\Enums\ReportStatus::Failed)
                            <form method="post" action="{{ route('reports.retry', $generation) }}">@csrf<button type="submit">Retry generation</button></form>
                        @endif
                    </footer>
                </article>
            @empty
                <div class="empty-state"><div class="empty-state-icon">⌁</div><h2>No matching pipeline runs</h2><p>Change the filters or generate a new report.</p></div>
            @endforelse
        </div>
        {{ $generations->links() }}
    </section>
</x-layouts.app>
