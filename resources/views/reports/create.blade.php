<x-layouts.app title="Generate report">
    <section class="panel">
        <h1>Generate a report</h1>
        <p class="muted">Select the report explicitly. The registered definition controls its processor, template and required files.</p>
        <form method="post" action="{{ route('reports.store') }}" enctype="multipart/form-data">@csrf
            <div class="grid">
                <div class="full"><label for="report_code">Report Type</label>
                    <select id="report_code" name="report_code" required>
                        <option value="">Select a report type</option>
                        @foreach($definitions as $definition)
                            <option value="{{ $definition->code }}" @selected(old('report_code') === $definition->code) @disabled(!$definition->is_active)>
                                {{ $definition->name }}{{ $definition->is_active ? '' : ' — coming later' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div><label for="report_date">Report Date</label><input id="report_date" name="report_date" type="date" value="{{ old('report_date', now()->format('Y-m-d')) }}" required></div>
                <div><label for="reporting_period_start">Reporting Period Start</label><input id="reporting_period_start" name="reporting_period_start" type="date" value="{{ old('reporting_period_start') }}" required></div>
                <div><label for="reporting_period_end">Reporting Period End</label><input id="reporting_period_end" name="reporting_period_end" type="date" value="{{ old('reporting_period_end') }}" required></div>
                <div id="excluded-dates"><label for="excluded_date">Excluded Date <span class="muted">(optional)</span></label><input id="excluded_date" name="excluded_dates[]" type="date" value="{{ old('excluded_dates.0') }}"></div>
                <div class="full" id="dynamic-inputs"><p class="muted">Choose a report type to display its required uploads.</p></div>
            </div>
            <div class="actions"><button>Generate</button></div>
        </form>
    </section>
    <script>
        const definitions = @json($definitionPayload);
        const select = document.getElementById('report_code'), target = document.getElementById('dynamic-inputs');
        function renderInputs() {
            const definition = definitions.find(item => item.code === select.value);
            if (!definition || !definition.active) { target.innerHTML='<p class="muted">Choose an active report type.</p>'; return; }
            target.innerHTML = definition.inputs.map(input => `<label for="input-${input.key}">${input.label}</label><input id="input-${input.key}" name="inputs[${input.key}]" type="file" accept="${input.extensions.map(x=>'.'+x).join(',')}" required><p class="muted">${input.description ?? ''}</p>`).join('');
        }
        select.addEventListener('change', renderInputs); renderInputs();
    </script>
</x-layouts.app>
