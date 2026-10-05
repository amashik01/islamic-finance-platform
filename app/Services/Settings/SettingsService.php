<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SettingsService
{
    private const CACHE_KEY = 'platform-settings';

    public function __construct(private AuditLogger $audit) {}

    /** @return array<string, string> dotted key => stored value */
    private function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('settings') ? Setting::pluck('value', 'key')->all() : []);
    }

    public function definition(string $key): array
    {
        [$group, $name] = explode('.', $key, 2);

        return config("settings.$group.$name") ?? throw new \InvalidArgumentException("Unknown setting [$key].");
    }

    public function get(string $key): string
    {
        return (string) ($this->stored()[$key] ?? $this->definition($key)[2]);
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    /** A money setting in minor units (paisa). */
    public function minor(string $key): int
    {
        return Money::parse($this->get($key))->minor;
    }

    /** @return array<string, array<string, array>> */
    public function groups(): array
    {
        return config('settings');
    }

    /** @param array<string, string|bool|null> $values dotted key => value */
    public function save(array $values, string $reason = 'Settings updated'): void
    {
        $changes = [];
        foreach ($values as $key => $value) {
            $new = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            $old = $this->get($key);
            if ($old !== $new) {
                Setting::updateOrCreate(['key' => $key], ['group' => explode('.', $key)[0], 'value' => $new]);
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }
        Cache::forget(self::CACHE_KEY);
        if ($changes) {
            $this->audit->record('settings.updated', null, array_map(fn ($c) => $c['old'], $changes), array_map(fn ($c) => $c['new'], $changes), $reason);
        }
    }
}
