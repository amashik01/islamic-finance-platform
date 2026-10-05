<?php

namespace App\Domain\Aqd;

/** Builds the field specifications an AqdDefinition is made of. A spec carries everything the UI and the server need. */
final class Field
{
    /**
     * @param  array{what?: string, islamic?: string, valid?: string, invalid?: string, rules?: list<string>, required?: bool, options?: array<string,string>, when?: array<string,mixed>, max?: int, default?: mixed, help?: string}  $o
     * @return array<string, mixed>
     */
    public static function make(string $key, string $label, string $type, array $o = []): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'required' => $o['required'] ?? true, 'what' => $o['what'] ?? null, 'islamic' => $o['islamic'] ?? null, 'valid' => $o['valid'] ?? null,
            'invalid' => $o['invalid'] ?? null, 'rules' => $o['rules'] ?? [], 'options' => $o['options'] ?? [], 'when' => $o['when'] ?? null, 'max' => $o['max'] ?? null, 'default' => $o['default'] ?? null, 'help' => $o['help'] ?? null];
    }

    public static function text(string $k, string $l, array $o = []): array
    {
        return self::make($k, $l, 'text', $o);
    }

    public static function area(string $k, string $l, array $o = []): array
    {
        return self::make($k, $l, 'textarea', $o);
    }

    public static function money(string $k, string $l, array $o = []): array
    {
        return self::make($k, $l, 'money', $o);
    }

    public static function percent(string $k, string $l, array $o = []): array
    {
        return self::make($k, $l, 'percent', $o);
    }

    public static function select(string $k, string $l, array $options, array $o = []): array
    {
        return self::make($k, $l, 'select', ['options' => $options] + $o);
    }

    public static function check(string $k, string $l, array $o = []): array
    {
        return self::make($k, $l, 'checkbox', $o);
    }

    public static function date(string $k, string $l, array $o = []): array
    {
        return self::make($k, $l, 'date', $o);
    }

    public static function number(string $k, string $l, array $o = []): array
    {
        return self::make($k, $l, 'number', $o);
    }
}
