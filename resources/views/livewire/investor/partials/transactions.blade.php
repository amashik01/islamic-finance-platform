@php
    use App\Enums\TransactionType as T;
    // Direction from the investor's point of view
    $credit = [T::Deposit, T::PrincipalReturn, T::ProfitDistribution, T::Refund];
@endphp
@if($transactions->isEmpty())
    <x-ui.empty-state title="No transactions yet." message="Deposits, investments, returns and withdrawals appear here." />
@else
    <div class="table-wrap hidden md:block"><table class="table"><thead><tr><th>Date</th><th>Reference</th><th>Type</th><th>Project</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th>Status</th></tr></thead><tbody>
        @foreach($transactions as $t)
            @php $isCredit = in_array($t->type, $credit, true); @endphp
            <tr wire:key="t-{{ $t->id }}"><td>{{ $t->posted_at?->format('d M Y') }}</td><td class="font-mono text-xs">{{ $t->reference }}</td><td>{{ $t->type->label() }}</td><td>{{ $t->project?->title ?? '—' }}</td>
                <td class="text-right">{{ $isCredit ? '' : \App\Support\Money\Money::minor($t->amount, $t->currency)->format() }}</td>
                <td class="text-right">{{ $isCredit ? \App\Support\Money\Money::minor($t->amount, $t->currency)->format() : '' }}</td><td><x-status-badge :status="$t->status" /></td></tr>
        @endforeach
    </tbody></table></div>
    <div class="space-y-3 md:hidden">@foreach($transactions as $t)@php $isCredit = in_array($t->type, $credit, true); @endphp
        <div class="rounded-control border border-ink-100 p-3"><div class="flex justify-between"><span class="font-medium">{{ $t->type->label() }}</span><span class="font-medium">{{ $isCredit ? '+' : '−' }} {{ \App\Support\Money\Money::minor($t->amount, $t->currency)->format() }}</span></div><p class="text-xs text-ink-500">{{ $t->posted_at?->format('d M Y') }} · {{ $t->project?->title ?? $t->reference }}</p></div>
    @endforeach</div>
    <div class="mt-4">{{ $transactions->links() }}</div>
@endif
