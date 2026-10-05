@use('App\Support\Money\Money')
<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-dashboard.stat-card label="Outstanding" :value="$outstanding->format()" hint="What you still owe across Murabaha sales." />
        <x-dashboard.stat-card label="Overdue installments" :value="number_format($overdue)" hint="Installments past their due date." />
    </div>
    <x-ui.card title="Murabaha payment schedule" subtitle="Fixed installments of the agreed sale price. The price does not increase.">
        @forelse($schedules as $s)
            @if($loop->first)<div class="table-wrap"><table class="table"><thead><tr><th>Project</th><th>#</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead><tbody>@endif
            <tr><td>{{ $s->receivable->sale->murabahaContract->contract->project->title }}</td><td>{{ $s->sequence }}</td><td>{{ $s->due_date->format('d M Y') }}</td><td>{{ Money::minor($s->amount)->format() }}</td><td>{{ Money::minor($s->paid_amount)->format() }}</td><td><x-status-badge :status="$s->status" /></td></tr>
            @if($loop->last)</tbody></table></div>@endif
        @empty<x-ui.empty-state title="No payments due." message="Murabaha installment schedules appear here after an asset sale is completed." />@endforelse
        <p class="mt-3 text-xs text-ink-500">To record a payment, transfer funds and send the proof to the platform team; payments are posted after verification.</p>
    </x-ui.card>
</div>
