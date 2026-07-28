<x-layouts.app title="Report details">
    @php
        $outputDetails = [
            'dashboard_html' => ['label' => 'Interactive Dashboard', 'type' => 'HTML', 'description' => 'Open the browser-ready dashboard'],
            'pdf' => ['label' => 'Presentation Report', 'type' => 'PDF', 'description' => 'Print-ready, single-page report'],
            'png' => ['label' => 'Dashboard Image', 'type' => 'PNG', 'description' => 'High-resolution dashboard image'],
            'calculated_results' => ['label' => 'Calculated Results', 'type' => 'JSON', 'description' => 'KPIs, trends and executive insights'],
            'registration_dataset' => ['label' => 'Registration Dataset', 'type' => 'PARQUET', 'description' => 'Validated prepared registration data'],
            'payment_dataset' => ['label' => 'Payment Transactions', 'type' => 'PARQUET', 'description' => 'Validated deposits and withdrawals'],
            'bonus_dataset' => ['label' => 'Bonus Summary', 'type' => 'PARQUET', 'description' => 'Workbook-derived aggregate bonus data'],
            'betting_dataset' => ['label' => 'Betting Dataset', 'type' => 'PARQUET', 'description' => 'Validated Cash Operations transactions'],
            'reconciliation_report' => ['label' => 'Reconciliation Report', 'type' => 'JSON', 'description' => 'Calculation integrity checks'],
            'validation_log' => ['label' => 'Validation Log', 'type' => 'JSON', 'description' => 'Excluded records and validation reasons'],
            'manifest' => ['label' => 'Report Manifest', 'type' => 'JSON', 'description' => 'Versions, checksums and provenance'],
        ];
        $outputOrder = array_flip(array_keys($outputDetails));
        $displayOutputs = $generation->outputs
            ->filter(fn ($output) => isset($outputDetails[$output->metadata['artifact_key'] ?? $output->output_type->value]))
            ->sortBy(fn ($output) => $outputOrder[$output->metadata['artifact_key'] ?? $output->output_type->value] ?? 99);
        $context = $generation->processing_metadata['reporting_context'] ?? [];
        $excludedDates = $context['excluded_dates'] ?? [];
        $statusLabel = str($generation->status->value)->replace('_', ' ')->title();
        $stageLabel = $generation->current_stage
            ? str($generation->current_stage->value)->replace('_', ' ')->title()
            : 'Pending';
        $latestAttemptStart = $generation->events
            ->where('event_code', 'PROCESSING_STARTED')
            ->max('occurred_at');
        $timelineEvents = $generation->events
            ->filter(fn ($event) => $latestAttemptStart === null || $event->occurred_at->greaterThanOrEqualTo($latestAttemptStart))
            ->sortByDesc('occurred_at')
            ->values();
        $archivedEventCount = $generation->events->count() - $timelineEvents->count();
    @endphp

    <style>
        .report-shell{display:grid;gap:22px}
        .report-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#191919 0%,#101010 65%);border:1px solid #393939;border-radius:14px;padding:30px}
        .report-hero:before{content:"";position:absolute;inset:0 auto 0 0;width:5px;background:linear-gradient(#f5c431,#9f7410)}
        .hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:24px}
        .eyebrow{margin:0 0 9px;color:#e8b526;font-size:12px;font-weight:900;letter-spacing:1.7px;text-transform:uppercase}
        .report-hero h1{font-size:34px;margin:0 0 10px}.generation-id{display:inline-flex;align-items:center;gap:8px;color:#9e9e9e;font-family:ui-monospace,SFMono-Regular,monospace;font-size:12px}
        .history-link{border:1px solid #494949;border-radius:8px;padding:10px 14px;color:#ddd;text-decoration:none;font-size:13px;font-weight:700}.history-link:hover{border-color:#d7a928;color:#f2c544}
        .summary-grid{display:grid;grid-template-columns:1.15fr 1fr;gap:16px;margin-top:26px}
        .summary-card{background:#0d0d0d;border:1px solid #333;border-radius:11px;padding:20px}
        .summary-label{display:block;color:#8f8f8f;font-size:11px;font-weight:900;letter-spacing:1.4px;text-transform:uppercase;margin-bottom:12px}
        .status-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
        .status-pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #80681e;background:#2a2412;color:#f4cf61;border-radius:999px;padding:8px 12px;font-size:13px;font-weight:800}
        .status-pill:before{content:"";width:8px;height:8px;border-radius:50%;background:#e7b82e;box-shadow:0 0 0 4px #e7b82e22}
        .progress-copy{color:#ccc;font-size:13px}.progress-refined{height:6px;margin-top:17px;background:#282828;border-radius:999px;overflow:hidden}.progress-refined span{display:block;height:100%;background:linear-gradient(90deg,#a5770c,#efc23c);border-radius:inherit}
        .period-value{font-size:20px;font-weight:800;margin-bottom:9px}.period-meta{display:flex;flex-wrap:wrap;gap:8px 18px;color:#aaa;font-size:13px}.period-meta strong{color:#ddd}
        .rule-note{display:flex;gap:11px;align-items:flex-start;border:1px solid #604f20;background:#1c180d;color:#d8c68b;border-radius:10px;padding:14px 16px;font-size:13px;line-height:1.45}
        .rule-note b{color:#f0c44e}
        .section-card{background:#121212;border:1px solid #343434;border-radius:14px;padding:26px}
        .section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:20px}.section-head h2{margin:0;color:#f3f0e7;font-size:22px}.section-head p{margin:5px 0 0;color:#8f8f8f;font-size:13px}
        .output-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .output-card{min-height:144px;display:flex;flex-direction:column;text-decoration:none;color:#f2f2f2;border:1px solid #383838;background:linear-gradient(145deg,#1a1a1a,#111);border-radius:11px;padding:17px;transition:.18s ease}
        .output-card:hover{transform:translateY(-2px);border-color:#c69720;background:#1b1810}
        .file-type{width:max-content;border:1px solid #54451c;border-radius:5px;padding:4px 7px;color:#e8b526;background:#211c0e;font-size:9px;font-weight:900;letter-spacing:1px}
        .output-card strong{font-size:15px;margin:14px 0 5px}.output-card small{color:#888;line-height:1.35;flex:1}.file-meta{display:flex;justify-content:space-between;align-items:center;margin-top:13px;color:#aaa;font-size:11px}.download-mark{color:#e8b526;font-size:17px}
        .timeline{position:relative;display:grid;gap:0;margin-left:7px}.timeline:before{content:"";position:absolute;left:6px;top:11px;bottom:11px;width:1px;background:#393939}
        .timeline-item{position:relative;display:grid;grid-template-columns:145px 180px 1fr;gap:18px;padding:0 0 22px 28px}
        .timeline-item:last-child{padding-bottom:0}.timeline-dot{position:absolute;left:0;top:5px;width:13px;height:13px;border:3px solid #121212;border-radius:50%;background:#5b5b5b;box-shadow:0 0 0 1px #555}.timeline-item.warning .timeline-dot{background:#e4b52b;box-shadow:0 0 0 1px #e4b52b}
        .timeline-time{color:#888;font-size:12px}.timeline-stage{font-size:13px;font-weight:800}.timeline-level{display:block;color:#8b8b8b;font-size:10px;text-transform:uppercase;letter-spacing:1px;margin-top:4px}.timeline-message{color:#d7d7d7;font-size:13px;line-height:1.45}
        @media(max-width:980px){.output-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.timeline-item{grid-template-columns:120px 150px 1fr}}
        @media(max-width:700px){.report-hero,.section-card{padding:21px}.hero-top{display:block}.history-link{display:inline-block;margin-top:18px}.summary-grid{grid-template-columns:1fr}.output-grid{grid-template-columns:1fr}.timeline-item{grid-template-columns:1fr;gap:5px;padding-left:27px}.section-head{display:block}}
    </style>

    <div class="report-shell">
        <section class="report-hero">
            <div class="hero-top">
                <div>
                    <p class="eyebrow">Generated report</p>
                    <h1>{{ $generation->reportDefinition->name }}</h1>
                    <span class="generation-id">ID&nbsp; {{ $generation->uuid }}</span>
                </div>
                <a class="history-link" href="{{ route('reports.index') }}">← Report history</a>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <span class="summary-label">Generation status</span>
                    <div class="status-row">
                        <span class="status-pill">{{ $statusLabel }}</span>
                        <span class="progress-copy">{{ $generation->progress_percentage }}% · {{ $stageLabel }}</span>
                    </div>
                    <div class="progress-refined"><span style="width:{{ $generation->progress_percentage }}%"></span></div>
                </div>
                <div class="summary-card">
                    <span class="summary-label">Reporting period</span>
                    <div class="period-value">{{ optional($generation->reporting_period_start)->format('d M Y') }} <span style="color:#666">→</span> {{ optional($generation->reporting_period_end)->format('d M Y') }}</div>
                    <div class="period-meta">
                        <span>Report date <strong>{{ optional($generation->reporting_date)->format('d M Y') }}</strong></span>
                        <span>Excluded dates <strong>{{ $excludedDates ? implode(', ', array_map(fn ($date) => \Carbon\Carbon::parse($date)->format('d M Y'), $excludedDates)) : 'None' }}</strong></span>
                    </div>
                </div>
            </div>
        </section>

        @if($generation->status === \App\Domain\Reports\Enums\ReportStatus::CompletedWithWarnings)
            <div class="rule-note"><span>◆</span><span><b>Completed successfully with provisional rules.</b> The outputs are verified; the warning records configurable business decisions that still require formal approval.</span></div>
        @endif
        @if($generation->error_message)<div class="notice error"><strong>{{ $generation->error_code }}</strong><br>{{ $generation->error_message }}</div>@endif

        <section class="section-card">
            <div class="section-head"><div><h2>Report files</h2><p>Download the dashboard or inspect its supporting data and audit records.</p></div><span class="muted">{{ $displayOutputs->count() }} files</span></div>
            <div class="output-grid">
                @forelse($displayOutputs as $output)
                    @php
                        $artifactKey = $output->metadata['artifact_key'] ?? $output->output_type->value;
                        $detail = $outputDetails[$artifactKey];
                        $size = $output->size_bytes >= 1048576
                            ? number_format($output->size_bytes / 1048576, 1).' MB'
                            : number_format($output->size_bytes / 1024, 1).' KB';
                    @endphp
                    <a class="output-card" href="{{ route('reports.download',[$generation,$output]) }}">
                        <span class="file-type">{{ $detail['type'] }}</span>
                        <strong>{{ $detail['label'] }}</strong>
                        <small>{{ $detail['description'] }}</small>
                        <span class="file-meta"><span>{{ $size }}</span><span class="download-mark">↓</span></span>
                    </a>
                @empty
                    <p class="muted">Outputs will appear here after processing.</p>
                @endforelse
            </div>
            @if($generation->status === \App\Domain\Reports\Enums\ReportStatus::Failed)<form method="post" action="{{ route('reports.retry',$generation) }}" style="margin-top:18px">@csrf<button>Retry generation</button></form>@endif
        </section>

        <section class="section-card">
            <div class="section-head">
                <div>
                    <h2>Processing timeline</h2>
                    <p>
                        Latest processing attempt.
                        @if($archivedEventCount > 0)
                            {{ $archivedEventCount }} resolved earlier {{ \Illuminate\Support\Str::plural('event', $archivedEventCount) }} remain stored in the audit record.
                        @endif
                    </p>
                </div>
            </div>
            <div class="timeline">
                @foreach($timelineEvents as $event)
                    <div class="timeline-item {{ $event->level->value }}">
                        <span class="timeline-dot"></span>
                        <div class="timeline-time">{{ $event->occurred_at->format('d M Y') }}<br>{{ $event->occurred_at->format('H:i:s') }}</div>
                        <div class="timeline-stage">{{ $event->stage ? str($event->stage->value)->replace('_', ' ')->title() : 'Pending' }}<span class="timeline-level">{{ str($event->level->value)->title() }}</span></div>
                        <div class="timeline-message">{{ $event->message }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
    @if(!$generation->status->isTerminal())<script>setTimeout(()=>location.reload(),4000)</script>@endif
</x-layouts.app>
