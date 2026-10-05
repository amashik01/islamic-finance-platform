<?php

namespace App\Enums;

enum SignerRole: string
{
    case Mudarib = 'MUDARIB';
    case RabbUlMal = 'RABB_UL_MAL';
    case MusharikBusiness = 'MUSHARIK_BUSINESS';
    case MusharikInvestor = 'MUSHARIK_INVESTOR';
    case Buyer = 'BUYER';
    case Seller = 'SELLER';
    case Wakil = 'WAKIL';
    case Muwakkil = 'MUWAKKIL';

    public function label(): string
    {
        return match ($this) {
            self::Mudarib => 'Mudarib',
            self::RabbUlMal => 'Rabb-ul-Mal (capital provider)',
            self::MusharikBusiness => 'Musharik (business partner)',
            self::MusharikInvestor => 'Musharik (investor partner)',
            self::Buyer => 'Buyer',
            self::Seller => 'Seller',
            self::Wakil => 'Wakil (agent)',
            self::Muwakkil => 'Muwakkil (principal)',
        };
    }
}
