<?php

namespace App\Enums;

enum ManagerRecoveryStatus: string
{
    case Open = 'OPEN';
    case Partial = 'PARTIAL';
    case Recovered = 'RECOVERED';
    case WrittenOff = 'WRITTEN_OFF';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Partial => 'Partially recovered',
            self::Recovered => 'Recovered',
            self::WrittenOff => 'Written off',
        };
    }
}
