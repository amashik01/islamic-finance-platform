<?php

namespace App\Livewire\Admin;

use App\Enums\KycStatus;
use App\Livewire\Tables\DataTable;
use App\Models\Business;
use App\Models\Investor;
use App\Services\Kyc\KycService;
use Illuminate\Database\Eloquent\Builder;

class KycTable extends DataTable
{
    /** 'investors' or 'businesses' */
    public string $party = 'investors';

    protected function heading(): string
    {
        return 'KYC verification — '.($this->party === 'investors' ? 'Investors' : 'Businesses');
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('kyc.view'), 403);
    }

    private function model(): string
    {
        return $this->party === 'investors' ? Investor::class : Business::class;
    }

    protected function query(): Builder
    {
        return $this->model()::query()->with('user')->withCount('documents');
    }

    protected function searchable(): array
    {
        return ['user.name', 'user.email'];
    }

    protected function filters(): array
    {
        return ['status' => ['label' => 'Status', 'options' => KycStatus::options(), 'apply' => fn ($q, $v) => $q->where('kyc_status', $v)]];
    }

    protected function emptyTitle(): string
    {
        return 'No verification submissions yet.';
    }

    protected function columns(): array
    {
        return [
            'name' => ['label' => 'Name', 'render' => fn ($p) => $this->party === 'businesses' ? $p->name : $p->user->name],
            'email' => ['label' => 'Email', 'render' => fn ($p) => $p->user->email],
            'documents' => ['label' => 'Documents', 'render' => fn ($p) => $p->documents_count],
            'kyc_status' => ['label' => 'Status', 'sortable' => true, 'render' => fn ($p) => self::badge($p->kyc_status)],
            'created_at' => ['label' => 'Registered', 'sortable' => true, 'render' => fn ($p) => $p->created_at->format('d M Y')],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.kyc-actions';
    }

    protected function perform(string $action, int $id, ?string $reason): void
    {
        app(KycService::class)->review($this->model()::findOrFail($id), auth()->user(), $action === 'approve', $reason);
    }

    public function documents(int $id): array
    {
        return $this->model()::findOrFail($id)->documents()->latest()->get()->all();
    }
}
