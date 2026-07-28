<x-layouts.app title="Report history">
    <section class="panel"><h1>Report history</h1>
        <table><thead><tr><th>Period</th><th>Report</th><th>Status</th><th>Progress</th><th>Created</th><th></th></tr></thead><tbody>
        @forelse($generations as $generation)<tr>
            <td>{{ optional($generation->reporting_period_start)->format('d M Y') }} — {{ optional($generation->reporting_period_end)->format('d M Y') }}</td>
            <td>{{ $generation->reportDefinition->name }}</td><td><span class="badge">{{ str($generation->status->value)->replace('_',' ')->title() }}</span></td>
            <td>{{ $generation->progress_percentage }}%</td><td>{{ $generation->created_at->format('d M Y H:i') }}</td>
            <td><a class="button secondary" href="{{ route('reports.show',$generation) }}">Open</a></td>
        </tr>@empty<tr><td colspan="6">No reports generated yet.</td></tr>@endforelse
        </tbody></table>{{ $generations->links() }}
    </section>
</x-layouts.app>
