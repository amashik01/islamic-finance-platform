<?php

namespace App\Livewire\Admin;

use App\Services\Settings\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.admin-layout')]
#[Title('Settings')]
class Settings extends Component
{
    /** @var array<string, array<string, mixed>> group => name => value */
    public array $values = [];

    public ?string $notice = null;

    public function mount(SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        foreach ($settings->groups() as $group => $items) {
            foreach ($items as $name => [$label, $type]) {
                $this->values[$group][$name] = $type === 'bool' ? $settings->bool("$group.$name") : $settings->get("$group.$name");
            }
        }
    }

    public function save(SettingsService $settings): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $rules = [];
        foreach ($settings->groups() as $group => $items) {
            foreach ($items as $name => $def) {
                $rules["values.$group.$name"] = $def[3];
            }
        }
        $this->validate($rules);

        $f = $this->values;
        if (\App\Support\Money\Money::parse((string) $f['finance']['min_withdrawal'])->minor > \App\Support\Money\Money::parse((string) $f['finance']['max_withdrawal'])->minor) {
            $this->addError('values.finance.min_withdrawal', 'The minimum withdrawal cannot exceed the maximum.');

            return;
        }
        $flat = [];
        foreach ($f as $group => $items) {
            foreach ($items as $name => $value) {
                $flat["$group.$name"] = $value;
            }
        }
        $settings->save($flat);
        $this->notice = 'Settings saved.';
    }

    public function render(SettingsService $settings)
    {
        return view('livewire.admin.settings', ['groups' => $settings->groups()]);
    }
}
