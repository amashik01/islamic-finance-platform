<?php

namespace App\Livewire\Tables;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reusable server-side table: search, sort, filters, pagination, row actions with confirmation.
 * Subclasses declare columns()/query() and (optionally) filters() and perform().
 */
abstract class DataTable extends Component
{
    use WithPagination;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    public int $perPage = 15;

    /** @var array<string, string> */
    #[Url]
    public array $filter = [];

    /** @var array{action: string, id: int, label: string, needsReason: bool, tone: string}|null */
    public ?array $confirming = null;

    public string $reason = '';

    public ?string $error = null;

    public ?string $notice = null;

    /** Page title shown in the card header. */
    abstract protected function heading(): string;

    /** @return array<string, array{label: string, sortable?: bool, render: \Closure}> */
    abstract protected function columns(): array;

    abstract protected function query(): Builder;

    /** Columns searched with LIKE. @return list<string> */
    protected function searchable(): array
    {
        return [];
    }

    /** @return array<string, array{label: string, options: array<string,string>, apply: \Closure}> */
    protected function filters(): array
    {
        return [];
    }

    /** Blade view rendering the per-row action buttons ($row available). */
    protected function actionsView(): ?string
    {
        return null;
    }

    protected function emptyTitle(): string
    {
        return 'Nothing to show yet.';
    }

    protected function defaultSort(): string
    {
        return 'id';
    }

    /** Authorisation for the whole table; override with permission checks. */
    protected function authorizeTable(): void {}

    /** Run a confirmed row action. Throw FinancialException for user-safe errors. */
    protected function perform(string $action, int $id, ?string $reason): void {}

    public function mount(): void
    {
        $this->authorizeTable();
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'perPage']) || str_starts_with($name, 'filter')) {
            $this->resetPage();
        }
    }

    public function sortBy(string $key): void
    {
        $this->dir = ($this->sort === $key && $this->dir === 'asc') ? 'desc' : 'asc';
        $this->sort = $key;
        $this->resetPage();
    }

    /** Opens the confirmation modal; nothing happens until confirmed. */
    public function ask(string $action, int $id, string $label, bool $needsReason = false, string $tone = 'primary'): void
    {
        $this->authorizeTable();
        $this->reset('reason', 'error');
        $this->confirming = compact('action', 'id', 'label', 'needsReason', 'tone');
        $this->dispatch('open-modal', 'confirm-action');
    }

    public function confirm(): void
    {
        $this->authorizeTable();
        abort_unless($this->confirming, 422);
        if ($this->confirming['needsReason'] && trim($this->reason) === '') {
            $this->addError('reason', 'Please give a reason.');

            return;
        }
        try {
            $this->perform($this->confirming['action'], $this->confirming['id'], trim($this->reason) ?: null);
            $this->notice = $this->confirming['label'].' — done.';
            $this->dispatch('close-modal', 'confirm-action');
            $this->reset('confirming', 'reason');
        } catch (\App\Exceptions\FinancialException $e) {
            $this->error = $e->getMessage();
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->error = 'You are not allowed to do that.';
        }
    }

    private function rows(): LengthAwarePaginator
    {
        $q = $this->query();
        if ($this->search !== '' && $this->searchable()) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
            $q->where(function (Builder $w) use ($term) {
                foreach ($this->searchable() as $col) {
                    str_contains($col, '.')
                        ? $w->orWhereHas(explode('.', $col)[0], fn ($r) => $r->where(explode('.', $col)[1], 'like', $term))
                        : $w->orWhere($col, 'like', $term);
                }
            });
        }
        foreach ($this->filters() as $key => $f) {
            $value = $this->filter[$key] ?? '';
            if ($value !== '' && isset($f['options'][$value])) {
                ($f['apply'])($q, $value);
            }
        }
        $cols = $this->columns();
        $sort = isset($cols[$this->sort]) && ($cols[$this->sort]['sortable'] ?? false) ? $this->sort : $this->defaultSort();
        $q->orderBy($sort, $this->dir === 'asc' ? 'asc' : 'desc');

        return $q->paginate(in_array($this->perPage, [10, 15, 25, 50]) ? $this->perPage : 15);
    }

    protected string $layout = 'components.admin-layout';

    public function render()
    {
        return view('livewire.tables.data-table', [
            'rows' => $this->rows(),
            'columns' => $this->columns(),
            'filters' => $this->filters(),
            'heading' => $this->heading(),
            'actionsView' => $this->actionsView(),
            'emptyTitle' => $this->emptyTitle(),
            'searchable' => (bool) $this->searchable(),
        ])->layout($this->layout, ['title' => $this->heading()]);
    }

    protected static function badge(\BackedEnum|string $status): HtmlString
    {
        return new HtmlString(\Illuminate\Support\Facades\Blade::render('<x-status-badge :status="$s" />', ['s' => $status]));
    }

    protected static function money(int $minor, string $currency = 'BDT'): string
    {
        return \App\Support\Money\Money::minor($minor, $currency)->format();
    }

    /** Helper for column render closures that output markup safely. */
    protected static function html(string $html): HtmlString
    {
        return new HtmlString($html);
    }
}
