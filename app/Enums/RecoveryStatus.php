<?php

namespace App\Enums;

enum RecoveryStatus: string
{
    case None = 'NONE';
    case Reminded = 'REMINDED';
    case InRecovery = 'IN_RECOVERY';
    case WrittenOff = 'WRITTEN_OFF';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Reminded => 'Reminded',
            self::InRecovery => 'In recovery',
            self::WrittenOff => 'Written off',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
