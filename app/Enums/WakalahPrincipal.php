<?php

namespace App\Enums;

/**
 * The Muwakkil (principal) of a Wakalah. NEVER assumed: every appointment names it, and the choice is Shariah-reviewed
 * (rule WAK-PRINCIPAL-ROLE). Which party is the principal changes ownership, risk and liability, so the platform offers
 * only the options an aqd role allows and records the decision.
 */
enum WakalahPrincipal: string
{
    case Platform = 'PLATFORM';
    case Business = 'BUSINESS';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform (as seller / financier)',
            self::Business => 'Business (purchase orderer / buyer)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
