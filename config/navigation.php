<?php

/*
 * Sidebar definitions. Each item: [label, route name, icon, optional permission].
 * Permission-gated items are hidden for convenience only; routes enforce access server-side.
 */
return [
    'admin' => [
        'COMMAND CENTER' => [['Dashboard', 'admin.dashboard', 'home']],
        'USERS' => [['Investors', 'admin.investors', 'users', 'investors.view'], ['Businesses', 'admin.businesses', 'building', 'businesses.view'], ['Staff', 'admin.users', 'users', 'users.view']],
        'VERIFICATION' => [['KYC', 'admin.kyc', 'shield', 'kyc.view'], ['Wakils', 'admin.kyc.wakils', 'shield', 'kyc.view'], ['Documents', 'admin.documents', 'folder']],
        'PROJECTS' => [['All Projects', 'admin.projects', 'briefcase', 'projects.view'], ['Pending Review', 'admin.projects.pending', 'clipboard', 'projects.review']],
        'CONTRACTS' => [['Mudarabah', 'admin.contracts.mudarabah', 'document', 'contracts.view'], ['Musharakah', 'admin.contracts.musharakah', 'document', 'contracts.view'], ['Murabaha', 'admin.contracts.murabaha', 'document', 'contracts.view']],
        'FINANCE' => [['Investments', 'admin.investments', 'chart', 'investments.view'], ['Wallets', 'admin.wallets', 'wallet', 'wallet.view'], ['Ledger', 'admin.ledger', 'scale', 'ledger.view'], ['Deposits', 'admin.deposits', 'cash', 'deposits.view'], ['Withdrawals', 'admin.withdrawals', 'swap', 'withdrawals.view'], ['Settlements', 'admin.settlements', 'clipboard', 'settlements.view']],
        'COMPLIANCE' => [['Shariah Review', 'admin.shariah-reviews', 'shield', 'shariah.review'], ['Audit Logs', 'admin.audit-logs', 'document', 'audit.view']],
        'INSIGHTS' => [['Reports', 'admin.reports', 'chart', 'reports.view'], ['Settings', 'admin.settings', 'cog', 'settings.manage']],
    ],
    'investor' => [
        '' => [['Dashboard', 'investor.dashboard', 'home'], ['My Wallet', 'investor.wallet', 'wallet'], ['Opportunities', 'investor.opportunities', 'briefcase'], ['My Investments', 'investor.investments', 'chart'], ['Contracts', 'investor.contracts', 'document'], ['Transactions', 'investor.transactions', 'swap'], ['Profit & Returns', 'investor.reports', 'chart'], ['Withdrawals', 'investor.withdrawals', 'cash'], ['Documents', 'investor.documents', 'folder'], ['Notifications', 'investor.notifications', 'bell'], ['Profile', 'investor.profile', 'users'], ['Settings', 'investor.settings', 'cog']],
    ],
    'business' => [
        '' => [['Dashboard', 'business.dashboard', 'home'], ['Projects', 'business.projects', 'briefcase'], ['Create Project', 'business.projects.create', 'clipboard'], ['Funding', 'business.funding', 'cash'], ['Contracts', 'business.contracts', 'document'], ['Payments', 'business.payments', 'swap'], ['Settlements', 'business.settlements', 'scale'], ['Documents', 'business.documents', 'folder'], ['Reports', 'business.reports', 'chart'], ['Notifications', 'business.notifications', 'bell'], ['Profile', 'business.profile', 'users'], ['Settings', 'business.settings', 'cog']],
    ],
];
