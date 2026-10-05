<?php

namespace App\Enums;

enum ManagerRecoveryStatus: string
{
    case Suspected = 'SUSPECTED';
    case UnderReview = 'UNDER_REVIEW';
    case FaultEstablished = 'FAULT_ESTABLISHED';
    case LiabilityRecognized = 'LIABILITY_RECOGNIZED';
    case Open = 'OPEN';   // legacy
    case Partial = 'PARTIAL';
    case Recovered = 'RECOVERED';
    case WrittenOff = 'WRITTEN_OFF';

    public function label(): string
    {
        return match ($this) {
            self::Suspected => 'Suspected (allegation only)',
            self::UnderReview => 'Under review',
            self::FaultEstablished => 'Fault established',
            self::LiabilityRecognized => 'Liability recognised',
            self::Open => 'Open (legacy)',
            self::Partial => 'Partially recovered',
            self::Recovered => 'Recovered',
            self::WrittenOff => 'Written off',
        };
    }
}
