<?php

namespace App\Livewire\Admin;

use App\Enums\DepositStatus;
use App\Enums\KycStatus;
use App\Enums\ProjectStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Deposit;
use App\Models\Investor;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use Livewire\Component;

/** Admin topbar: permission-aware search plus a count of items that need action. */
class GlobalSearch extends Component
{
    public string $q = '';

    private function like(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->q)).'%';
    }

    /** @return list<array{group: string, label: string, sub: string, url: string}> */
    public function results(): array
    {
        $u = auth()->user();
        if (mb_strlen(trim($this->q)) < 2 || ! $u?->isStaffMember()) {
            return [];
        }
        $like = $this->like();
        $out = [];
        if ($u->can('users.view')) {
            foreach (User::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))->limit(4)->get() as $r) {
                $out[] = ['group' => 'Users', 'label' => $r->name, 'sub' => $r->email, 'url' => route('admin.users', ['search' => $r->email])];
            }
        }
        if ($u->can('businesses.view')) {
            foreach (Business::where('name', 'like', $like)->limit(4)->get() as $r) {
                $out[] = ['group' => 'Businesses', 'label' => $r->name, 'sub' => $r->industry ?? '', 'url' => route('admin.businesses', ['search' => $r->name])];
            }
        }
        if ($u->can('projects.view')) {
            foreach (Project::where('title', 'like', $like)->limit(4)->get() as $r) {
                $out[] = ['group' => 'Projects', 'label' => $r->title, 'sub' => $r->contract_type->label().' · '.$r->status->label(), 'url' => route('admin.projects.show', $r)];
            }
        }
        if ($u->can('contracts.view')) {
            foreach (Contract::where('contract_number', 'like', $like)->limit(4)->get() as $r) {
                $out[] = ['group' => 'Contracts', 'label' => $r->contract_number, 'sub' => $r->contract_type->label(), 'url' => route('admin.contracts.show', $r)];
            }
        }
        if ($u->can('ledger.view')) {
            foreach (Transaction::where('reference', 'like', $like)->limit(4)->get() as $r) {
                $out[] = ['group' => 'Transactions', 'label' => $r->reference, 'sub' => $r->type->label(), 'url' => route('admin.ledger', ['search' => $r->reference])];
            }
        }

        return $out;
    }

    /** Items waiting on this staff member, limited to what they may act on. */
    public function pending(): int
    {
        $u = auth()->user();
        $n = 0;
        $u->can('withdrawals.approve') && $n += Withdrawal::whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview])->count();
        $u->can('deposits.verify') && $n += Deposit::where('status', DepositStatus::Pending)->count();
        $u->can('kyc.review') && $n += Investor::where('kyc_status', KycStatus::Pending)->count() + Business::where('kyc_status', KycStatus::Pending)->count();
        $u->can('projects.review') && $n += Project::where('status', ProjectStatus::Review)->count();

        return $n;
    }

    public function render()
    {
        return view('livewire.admin.global-search', ['results' => $this->results(), 'pending' => $this->pending()]);
    }
}
