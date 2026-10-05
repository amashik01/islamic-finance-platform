<?php

namespace App\Domain\Aqd;

use App\Enums\ContractType;
use App\Exceptions\FinancialException;
use Illuminate\Validation\Rule;

/**
 * One Islamic contract (aqd) as data: its steps, its fields, the Islamic guidance for each Shariah-sensitive field, and the
 * server-side rules. The wizards render from this and the server validates from this, so the UI and the rules cannot drift
 * apart. Each aqd has its own definition: a Mudarabah form is never a relabelled Murabaha form.
 */
abstract class AqdDefinition
{
    private const MONEY = 'regex:/^\d{1,12}(\.\d{1,2})?$/';

    private const PCT = 'regex:/^\d{1,3}(\.\d{1,2})?$/';

    abstract public function type(): ContractType;

    /** Bump when fields or rules change; stored with the terms so an old project keeps the form it was made with. */
    abstract public function version(): string;

    /** @return list<array{key: string, title: string, intro: string, fields: list<array<string, mixed>>}> */
    abstract public function steps(): array;

    /** Keys that must never be accepted for this aqd, whatever their value (a forged request, an old client). */
    abstract public function prohibitedKeys(): array;

    /** Aqd-specific structural rules that always apply on the server. @param array<string, mixed> $d */
    abstract protected function structural(array $d): void;

    /** @param array<string, mixed> $form @return array<string, mixed> the flat input ProjectBuilder understands, plus aqd_terms */
    abstract public function toBuilderInput(array $form): array;

    /** The wizard step after which the financial core is complete enough to save a draft. */
    abstract public function persistFromStep(): int;

    /** Fields every project shares: identification only. No contract terms live here. */
    protected function identification(): array
    {
        return [
            Field::text('title', 'Project name', ['what' => 'A clear public name for the project.', 'valid' => 'Dhaka Dairy Cold-Chain Expansion', 'invalid' => 'Guaranteed 20% returns', 'max' => 150]),
            Field::area('description', 'Description', ['what' => 'Describe the real business activity (at least 30 characters).', 'max' => 5000, 'help' => 'At least 30 characters.']),
            Field::text('industry', 'Industry', ['max' => 80]),
            Field::area('purpose', 'Purpose of the funds', ['what' => 'What the capital will be used for.', 'max' => 1000]),
            Field::number('duration_months', 'Expected duration (months)', ['what' => 'Planned term of the contract.', 'valid' => '12']),
            Field::select('risk_level', 'Risk level', \App\Enums\RiskLevel::options(), ['default' => 'MEDIUM']),
            Field::area('key_risks', 'Key risks', ['what' => 'Be honest about what could go wrong. Participants see this.', 'islamic' => 'Capital is exposed to the real risks of the business; nothing is guaranteed.', 'max' => 3000]),
            Field::date('closing_at', 'Funding closing date', ['required' => false]),
        ];
    }

    /** @return array<string, array<string, mixed>> key => spec for every field of every step */
    public function fieldMap(): array
    {
        $m = [];
        foreach ($this->steps() as $s) {
            foreach ($s['fields'] as $f) {
                $m[$f['key']] = $f;
            }
        }

        return $m;
    }

    /** @return array<string, string> key => label (for messages) */
    public function labels(): array
    {
        return collect($this->fieldMap())->map(fn ($f) => $f['label'])->all();
    }

    /** True when the field's condition on other answers holds. @param array<string, mixed> $form */
    public function applies(array $field, array $form): bool
    {
        foreach ($field['when'] ?? [] as $k => $v) {
            if ((is_array($v) ? ! in_array($form[$k] ?? null, $v, true) : ($form[$k] ?? null) != $v)) {
                return false;
            }
        }

        return true;
    }

    /** Laravel rules for one step, keyed "form.field". @param array<string, mixed> $form @return array<string, array> */
    public function rules(int $step, array $form): array
    {
        $out = [];
        foreach ($this->steps()[$step - 1]['fields'] ?? [] as $f) {
            if (! $this->applies($f, $form)) {
                continue;
            }
            $r = [$f['required'] ? 'required' : 'nullable'];
            $r = array_merge($r, match ($f['type']) {
                'money' => [self::MONEY],
                'percent' => [self::PCT],
                'number' => ['integer', 'min:0', 'max:100000'],
                'date' => ['date'],
                'checkbox' => $f['required'] ? ['accepted'] : ['boolean'],
                'select' => [Rule::in(array_keys($f['options']))],
                'textarea' => ['string', 'max:'.($f['max'] ?? 3000)],
                default => ['string', 'max:'.($f['max'] ?? 255)],
            });
            $out['form.'.$f['key']] = $r;
        }

        return $out;
    }

    /**
     * Rules that ALWAYS apply on the server, whatever the client sent: forbidden keys, guarantee wording, aqd structure.
     *
     * @param  array<string, mixed>  $d
     */
    public function assertShariahRules(array $d): void
    {
        foreach ($this->prohibitedKeys() as $key => $why) {
            if (array_key_exists($key, $d) && filled($d[$key])) {
                throw new FinancialException("'$key' is not part of a ".$this->type()->label().' contract. '.$why);
            }
        }
        $texts = [];
        foreach ($d as $k => $v) {
            if (is_string($v) || is_array($v)) {
                $texts[$k] = $v;
            }
        }
        if ($msgs = ShariahTextGuard::scan($texts, $this->labels())) {
            throw new FinancialException($msgs[0]);
        }
        $this->structural($d);
    }

    /** @param array<string, mixed> $terms @return list<string> labels of required, applicable fields that are blank */
    public function missing(array $terms): array
    {
        $out = [];
        foreach ($this->fieldMap() as $f) {
            if (! $f['required'] || ! $this->applies($f, $terms) || in_array($f['key'], $this->commonKeys(), true) || in_array($f['key'], $this->typedKeys(), true)) {
                continue;
            }
            $v = $terms[$f['key']] ?? null;
            if ($v === null || $v === '' || $v === false || $v === []) {
                $out[] = $f['label'];
            }
        }

        return $out;
    }

    /** @return list<string> form keys stored in the typed contract tables (money, ratios, text columns), not in aqd_terms */
    public function typedKeys(): array
    {
        return [];
    }

    /** @return list<string> keys of the shared identification fields (stored on the project, not in aqd_terms) */
    public function commonKeys(): array
    {
        return collect($this->identification())->pluck('key')->all();
    }

    /**
     * The form values of an existing project: identification from the project, typed terms from the contract tables, the rest from aqd_terms.
     *
     * @return array<string, mixed>
     */
    public function fromProject(\App\Models\Project $p): array
    {
        $m = fn (?int $v) => $v === null ? '' : \App\Support\Money\Money::minor($v)->toDecimal();
        $pct = fn (?int $bps) => $bps === null ? '' : rtrim(\App\Support\Percent::format($bps), '%');
        $base = ['title' => $p->title, 'description' => $p->description, 'industry' => (string) $p->industry, 'purpose' => (string) $p->purpose, 'key_risks' => (string) $p->key_risks,
            'risk_level' => $p->risk_level->value, 'duration_months' => (string) $p->duration_months, 'closing_at' => $p->closing_at?->format('Y-m-d') ?? '', 'minimum_amount' => $m($p->minimum_amount)];
        $wakalah = ['wakil_id' => (string) ($p->wakil_id ?? ''), 'wakalah_roles' => $p->currentWakalahAppointments()->whereNotNull('wakalah_role')->pluck('wakalah_role')->map(fn ($r) => $r->value)->values()->all(),
            'muwakkil' => (string) ($p->currentWakalahAppointments()->first()?->muwakkil ?? ''), 'wakalah_scope' => (string) ($p->currentWakalahAppointments()->first()?->scope ?? ''),
            'wakalah_authority' => $p->currentWakalahAppointments()->get()->flatMap(fn ($a) => $a->authority ?? [])->unique()->values()->all()];

        return $base + $wakalah + ($p->contract?->aqd_terms ?? []) + ($p->contract ? $this->typedFrom($p->contract, $m, $pct) : []);
    }

    /** @return array<string, mixed> */
    protected function typedFrom(\App\Models\Contract $c, \Closure $m, \Closure $pct): array
    {
        return [];
    }

    /** The terms stored on the contract: every non-identification field. @param array<string, mixed> $form @return array<string, mixed> */
    protected function termsFrom(array $form): array
    {
        $keys = collect($this->fieldMap())->keys()->diff($this->commonKeys())->diff($this->typedKeys())->all();

        return collect($form)->only($keys)->all();
    }
}
