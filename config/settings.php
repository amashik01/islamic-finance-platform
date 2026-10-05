<?php

/*
 * Settings registry: group => key => [label, type, default, rules]. Add an entry to add a setting;
 * the admin page, validation, audit trail and caching all pick it up automatically.
 * Money settings are entered in major units (BDT) and stored as decimal strings.
 */
return [
    'platform' => [
        'name' => ['Platform name', 'text', env('APP_NAME', 'Amanah Capital'), ['required', 'string', 'max:80']],
        'contact_email' => ['Contact email', 'text', 'support@example.com', ['required', 'email', 'max:120']],
        'contact_phone' => ['Contact phone', 'text', '', ['nullable', 'string', 'max:40']],
        'timezone' => ['Timezone', 'text', 'Asia/Dhaka', ['required', 'timezone:all']],
    ],
    'finance' => [
        'min_investment' => ['Minimum investment (BDT)', 'money', '5000', ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/']],
        'min_withdrawal' => ['Minimum withdrawal (BDT)', 'money', '1000', ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/']],
        'max_withdrawal' => ['Maximum single withdrawal (BDT)', 'money', '500000', ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/']],
    ],
    'notifications' => [
        'database_enabled' => ['In-app notifications', 'bool', '1', ['boolean']],
        'email_enabled' => ['Email notifications (requires mail configuration)', 'bool', '0', ['boolean']],
    ],
    'security' => [
        'session_minutes' => ['Session lifetime (minutes)', 'text', '120', ['required', 'integer', 'between:5,1440']],
        'login_attempts' => ['Failed logins before lockout', 'text', '5', ['required', 'integer', 'between:3,20']],
    ],
    'shariah' => [
        'wakalah_requires_acceptance' => ['Wakalah appointments need the Wakil\'s explicit acceptance (turn off only on written Shariah-board approval)', 'bool', '1', ['boolean']],
        'wakalah_requires_review' => ['Each Wakalah appointment needs its own Shariah review (turn off only on written Shariah-board approval)', 'bool', '1', ['boolean']],
        'platform_role' => ['Approved platform role (UNSET blocks live money; see docs/shariah/OPEN_SCHOLAR_QUESTIONS.md)', 'text', 'UNSET', ['required', 'in:UNSET,WAKIL_ARRANGER,MUDARIB,DIRECT_ARRANGER,PRINCIPAL_INVESTOR,OTHER_APPROVED']],
    ],
    'compliance' => [
        'kyc_required_investor' => ['Investor KYC required to invest', 'bool', '1', ['boolean']],
        'kyc_required_business' => ['Business verification required to submit projects', 'bool', '1', ['boolean']],
    ],
];
