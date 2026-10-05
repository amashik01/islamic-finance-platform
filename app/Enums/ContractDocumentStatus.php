<?php

namespace App\Enums;

enum ContractDocumentStatus: string
{
    case Draft = 'DRAFT';                       // generated for review; not yet open for signature
    case PendingSignature = 'PENDING_SIGNATURE';
    case Executed = 'EXECUTED';
    case Superseded = 'SUPERSEDED';             // replaced by an amendment / newer version; kept forever
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft — pending Shariah review',
            self::PendingSignature => 'Awaiting signature',
            self::Executed => 'Executed',
            self::Superseded => 'Superseded',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Executed, self::Superseded], true);
    }
}
