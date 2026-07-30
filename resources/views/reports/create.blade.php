<x-layouts.app title="Generate report">
    <div class="page-narrow">
        <header class="page-heading">
            <div><p class="eyebrow">Report automation</p><h1>Generate a report</h1><p class="lead">Choose a dashboard, define its reporting window and upload the corresponding source workbook.</p></div>
        </header>
        <section class="panel">
            <div class="panel-header"><div><h2>Report configuration</h2><p>Fields and source requirements update automatically for each dashboard.</p></div><span class="badge">Step 1 of 1</span></div>
            <form id="report-generation-form" method="post" action="{{ route('reports.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="form-grid">
                    <div class="field full">
                        <label for="report_code">Dashboard type</label>
                        <select id="report_code" name="report_code" required>
                            <option value="">Select a dashboard</option>
                            @foreach($definitions as $definition)
                                <option value="{{ $definition->code }}" @selected(old('report_code') === $definition->code) @disabled(!$definition->is_active)>
                                    {{ $definition->name }}{{ $definition->is_active ? '' : ' — coming later' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="field-help">The dashboard determines which workbook structure and calculations will be used.</p>
                    </div>
                    <div class="field"><label for="report_date">Report date</label><input id="report_date" name="report_date" type="date" value="{{ old('report_date', now()->format('Y-m-d')) }}" required></div>
                    <div class="field"><label for="reporting_period_start">Period start</label><input id="reporting_period_start" name="reporting_period_start" type="date" value="{{ old('reporting_period_start') }}" required></div>
                    <div class="field"><label for="reporting_period_end">Period end</label><input id="reporting_period_end" name="reporting_period_end" type="date" value="{{ old('reporting_period_end') }}" required></div>
                    <div class="field" id="excluded-dates"><label for="excluded_date">Excluded date <span class="optional">Optional</span></label><input id="excluded_date" name="excluded_dates[]" type="date" value="{{ old('excluded_dates.0') }}"><p class="field-help">Use only when a specific day must be omitted from calculations.</p></div>
                    <div class="form-section" id="dynamic-inputs"><p class="muted tiny">Select a dashboard to see its required source file.</p></div>
                </div>
                <div class="actions"><button id="generate-report-button" class="button" type="submit"><span class="button-label">Generate report</span> <span class="button-icon" aria-hidden="true">→</span></button><span class="muted tiny">Processing continues safely in the background.</span></div>
            </form>
        </section>
    </div>
    <script>
        const definitions = @json($definitionPayload);
        const overallSources = @json($overallSources);
        const overallSourceCodes = @json($overallSourceCodes);
        const overallSnapshots = @json($overallSnapshots);
        const previousSnapshot = @json(old('source_snapshot'));
        const previousAcknowledgement = @json(old('acknowledge_source_warnings') === '1');
        const select = document.getElementById('report_code');
        const target = document.getElementById('dynamic-inputs');
        const periodStart = document.getElementById('reporting_period_start');
        const periodEnd = document.getElementById('reporting_period_end');
        const generationForm = document.getElementById('report-generation-form');
        const generateButton = document.getElementById('generate-report-button');
        const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
        const sourceLabels = {
            registration_results: 'Registration report',
            payment_bonus_results: 'Deposits, Withdrawals and Bonus report',
            cash_operations_results: 'Cash Operations report',
            player_activity_results: 'Player Activity and Retention report',
        };
        function renderInputs() {
            const definition = definitions.find(item => item.code === select.value);
            if (!definition || !definition.active) {
                target.innerHTML = '<p class="muted tiny">Select an active dashboard to see its required source file.</p>';
                return;
            }
            if (definition.code === 'overall_performance_dashboard') {
                const start = periodStart.value;
                const end = periodEnd.value;
                const matches = overallSnapshots.filter(snapshot => snapshot.period_start === start && snapshot.displayed_end === end);
                const completePeriods = [...new Map(overallSnapshots.map(snapshot => [`${snapshot.period_start}|${snapshot.displayed_end}`, snapshot])).values()];
                const periodButtons = completePeriods.map(snapshot => `<button type="button" class="period-preset" data-start="${snapshot.period_start}" data-end="${snapshot.displayed_end}">${snapshot.period_start} → ${snapshot.displayed_end}</button>`).join('');
                const options = matches.map((snapshot, index) => `<option value="${snapshot.id}" ${snapshot.id === previousSnapshot ? 'selected' : ''}>Snapshot ${index + 1} · ${snapshot.completed_at} · ${snapshot.warnings} warning(s) · ${snapshot.files.join(', ')}</option>`).join('');
                const hidden = Object.keys(overallSourceCodes).map(key => `<input type="hidden" name="source_generations[${key}]" data-source-key="${key}">`).join('');
                target.innerHTML = '<div class="form-section-title"><span>↳</span> Source snapshot</div><p class="field-help">One snapshot locks a checksum-compatible Registration, Payments, Cash Operations and three-workbook Player Activity chain. Duplicate generations using identical files are collapsed.</p>' + (periodButtons ? `<div class="period-presets"><strong>Available complete periods</strong>${periodButtons}</div>` : '<p class="field-help">There is currently no checksum-compatible complete snapshot.</p>') + `<div class="field full source-selector"><label for="source-snapshot">Production data snapshot</label><select id="source-snapshot" name="source_snapshot" required><option value="">${start && end ? 'Select a compatible snapshot' : 'Choose an available period first'}</option>${options}</select>${hidden}<p class="field-help">${matches.length} distinct checksum snapshot(s); duplicate generations are hidden.</p><label class="source-ack" id="snapshot-warning-ack" hidden><input type="checkbox" name="acknowledge_source_warnings" value="1"> <span></span></label><div class="source-selection-detail" hidden></div></div>`;
                target.querySelectorAll('.period-preset').forEach(button => button.addEventListener('click', () => {
                    periodStart.value = button.dataset.start;
                    periodEnd.value = button.dataset.end;
                    renderInputs();
                }));
                document.getElementById('source-snapshot').addEventListener('change', event => {
                    const snapshot = overallSnapshots.find(item => item.id === event.target.value);
                    const detail = event.target.closest('.source-selector').querySelector('.source-selection-detail');
                    const acknowledgement = document.getElementById('snapshot-warning-ack');
                    const checkbox = acknowledgement.querySelector('input');
                    if (!snapshot) { detail.hidden = true; detail.innerHTML = ''; acknowledgement.hidden = true; checkbox.required = false; return; }
                    Object.entries(snapshot.generations).forEach(([key, uuid]) => {
                        target.querySelector(`[data-source-key="${key}"]`).value = uuid;
                    });
                    acknowledgement.hidden = snapshot.warnings === 0;
                    checkbox.required = snapshot.warnings > 0;
                    acknowledgement.querySelector('span').textContent = `Required: I inspected and acknowledge the ${snapshot.warnings} warning(s) recorded across this snapshot.`;
                    detail.hidden = false;
                    detail.innerHTML = `<span>Snapshot ${snapshot.id.slice(0, 12)} · ${snapshot.files.map(escapeHtml).join(' · ')}</span>` + Object.entries(snapshot.inspection_urls).map(([label,url]) => `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">Inspect ${escapeHtml(label)} ↗</a>`).join('');
                });
                if (previousSnapshot && matches.some(snapshot => snapshot.id === previousSnapshot)) {
                    const snapshotSelect = document.getElementById('source-snapshot');
                    snapshotSelect.dispatchEvent(new Event('change'));
                    document.querySelector('#snapshot-warning-ack input').checked = previousAcknowledgement;
                }
                return;
            }
            target.innerHTML = '<div class="form-section-title"><span>↑</span> Source workbook</div>' + definition.inputs.map(input => {
                const accept = input.extensions.map(extension => '.' + extension).join(',');
                return `<div class="upload-box"><span class="upload-icon" aria-hidden="true">↥</span><div class="upload-copy"><strong>${escapeHtml(input.label)}${input.required ? '' : ' <small>(conditional)</small>'}</strong><span>${escapeHtml(input.description || 'Choose the corresponding source file')}</span><span class="selected-file" hidden></span></div><input id="input-${input.key}" name="inputs[${input.key}]" type="file" accept="${escapeHtml(accept)}" aria-label="${escapeHtml(input.label)}" ${input.required ? 'required' : ''}></div>`;
            }).join('');
            target.querySelectorAll('input[type=file]').forEach(input => input.addEventListener('change', () => {
                const selected = input.closest('.upload-box').querySelector('.selected-file');
                selected.textContent = input.files[0]?.name || '';
                selected.hidden = !input.files[0];
            }));
        }
        select.addEventListener('change', renderInputs);
        periodStart.addEventListener('change', renderInputs);
        periodEnd.addEventListener('change', renderInputs);
        generationForm.addEventListener('submit', event => {
            if (generationForm.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }
            generationForm.dataset.submitting = 'true';
            generateButton.disabled = true;
            generateButton.setAttribute('aria-busy', 'true');
            generateButton.querySelector('.button-label').textContent = 'Submitting report…';
            generateButton.querySelector('.button-icon').textContent = '';
        });
        window.addEventListener('pageshow', () => {
            generationForm.dataset.submitting = 'false';
            generateButton.disabled = false;
            generateButton.removeAttribute('aria-busy');
            generateButton.querySelector('.button-label').textContent = 'Generate report';
            generateButton.querySelector('.button-icon').textContent = '→';
        });
        renderInputs();
    </script>
</x-layouts.app>
