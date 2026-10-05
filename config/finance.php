<?php

return [
    'default_currency' => env('FINANCE_CURRENCY', 'BDT'),

    // Add more currencies here; amounts are always stored as integer minor units.
    'currencies' => [
        'BDT' => ['name' => 'Bangladeshi Taka', 'symbol' => '৳', 'code_prefix' => 'BDT', 'minor_units' => 2],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'code_prefix' => 'USD', 'minor_units' => 2],
    ],

    'limits' => [
        'min_investment' => 500000,       // BDT 5,000.00 in paisa
        'min_withdrawal' => 100000,       // BDT 1,000.00
        'max_withdrawal' => 50000000,     // BDT 500,000.00
    ],

    'documents' => [
        'max_kb' => 5120,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        // Only these can be previewed inline; everything else downloads as an attachment.
        'inline_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
    ],

    // KYC: categories each party must have uploaded before submission.
    'kyc_required' => [
        'investor' => ['KYC'],
        'business' => ['KYC', 'BUSINESS_REGISTRATION'],
    ],

    'shariah_disclaimer' => 'Shariah compliance depends on the specific contractual structure, transaction sequence, underlying assets, documentation and qualified scholarly review. This platform is software and does not act as a religious authority.',
];
