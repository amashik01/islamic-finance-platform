<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case Manager = 'MANAGER';
    case Staff = 'STAFF';
    case Investor = 'INVESTOR';
    case Business = 'BUSINESS';
    case Wakil = 'WAKIL';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Staff => 'Staff',
            self::Investor => 'Investor',
            self::Business => 'Business',
            self::Wakil => 'Wakil',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
