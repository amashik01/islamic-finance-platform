<?php

use App\Http\Controllers\Portal\PlaceholderController as Soon;
use App\Http\Controllers\Public\PageController;
use Illuminate\Support\Facades\Route;

/* ---------------------------------- Public ---------------------------------- */
Route::view('/', 'public.home')->name('home');
Route::view('/how-it-works', 'public.how-it-works')->name('how-it-works');
Route::get('/opportunities', [PageController::class, 'opportunities'])->name('opportunities');
Route::get('/opportunities/{project:slug}', [PageController::class, 'opportunity'])->name('opportunities.show');
Route::view('/for-businesses', 'public.for-businesses')->name('for-businesses');
Route::view('/islamic-finance', 'public.islamic-finance')->name('islamic-finance');
Route::view('/about', 'public.about')->name('about');
Route::view('/faq', 'public.faq')->name('faq');
Route::view('/contact', 'public.contact')->name('contact');
Route::get('/legal/{page}', [PageController::class, 'legal'])->name('legal');

/* Post-login landing: send each user to their own portal. */
Route::get('/dashboard', fn () => redirect()->route(auth()->user()->homeRoute()))
    ->middleware(['auth', 'verified'])->name('dashboard');

/* ------------------------------- Investor portal ------------------------------ */
Route::prefix('investor')->name('investor.')->middleware(['auth', 'verified', 'role:INVESTOR'])->group(function () {
    Route::get('/', \App\Livewire\Investor\Dashboard::class)->name('dashboard');
    Route::view('/profile', 'portal.profile', ['portal' => 'investor'])->name('profile');
    foreach ([
        'wallet' => ['My Wallet', 'Phase 7-8'], 'opportunities' => ['Opportunities', 'Phase 8'], 'investments' => ['My Investments', 'Phase 8'],
        'contracts' => ['Contracts', 'Phase 8'], 'transactions' => ['Transactions', 'Phase 8'], 'withdrawals' => ['Withdrawals', 'Phase 8'],
        'documents' => ['Documents', 'Phase 3'], 'notifications' => ['Notifications', 'Phase 14'], 'settings' => ['Settings', 'Phase 14'],
    ] as $uri => [$title, $phase]) {
        Route::get("/$uri", Soon::class)->defaults('portal', 'investor')->defaults('title', $title)->defaults('phase', $phase)->name($uri);
    }
});

/* ------------------------------- Business portal ------------------------------ */
Route::prefix('business')->name('business.')->middleware(['auth', 'verified', 'role:BUSINESS'])->group(function () {
    Route::get('/', \App\Livewire\Business\Dashboard::class)->name('dashboard');
    Route::view('/profile', 'portal.profile', ['portal' => 'business'])->name('profile');
    foreach ([
        'projects' => ['Projects', 'Phase 9'], 'projects/create' => ['Create Project', 'Phase 9'], 'funding' => ['Funding', 'Phase 9'],
        'contracts' => ['Contracts', 'Phase 9'], 'payments' => ['Payments', 'Phase 9'], 'settlements' => ['Settlements', 'Phase 9'],
        'documents' => ['Documents', 'Phase 3'], 'reports' => ['Reports', 'Phase 12'], 'notifications' => ['Notifications', 'Phase 14'], 'settings' => ['Settings', 'Phase 14'],
    ] as $uri => [$title, $phase]) {
        Route::get("/$uri", Soon::class)->defaults('portal', 'business')->defaults('title', $title)->defaults('phase', $phase)->name(str_replace('/', '.', $uri));
    }
});

/* -------------------------------- Admin portal -------------------------------- */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'role:ADMIN|MANAGER|STAFF'])->group(function () {
    Route::redirect('/', '/admin/dashboard');
    Route::get('/dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');
    Route::view('/profile', 'portal.profile', ['portal' => 'admin'])->name('profile');
    foreach ([
        // uri => [route name, title, permission|null, phase]
        'users' => ['users', 'Staff', 'users.view', 'Phase 10'],
        'investors' => ['investors', 'Investors', 'investors.view', 'Phase 10'],
        'businesses' => ['businesses', 'Businesses', 'businesses.view', 'Phase 10'],
        'kyc' => ['kyc', 'KYC', 'kyc.view', 'Phase 3'],
        'documents' => ['documents', 'Documents', null, 'Phase 3'],
        'projects' => ['projects', 'All Projects', 'projects.view', 'Phase 10'],
        'projects/pending' => ['projects.pending', 'Pending Review', 'projects.review', 'Phase 10'],
        'contracts/mudarabah' => ['contracts.mudarabah', 'Mudarabah Contracts', 'contracts.view', 'Phase 4'],
        'contracts/musharakah' => ['contracts.musharakah', 'Musharakah Contracts', 'contracts.view', 'Phase 5'],
        'contracts/murabaha' => ['contracts.murabaha', 'Murabaha Contracts', 'contracts.view', 'Phase 6'],
        'investments' => ['investments', 'Investments', 'investments.view', 'Phase 10'],
        'wallets' => ['wallets', 'Wallets', 'wallet.view', 'Phase 10'],
        'ledger' => ['ledger', 'Ledger', 'ledger.view', 'Phase 10'],
        'deposits' => ['deposits', 'Deposits', 'deposits.view', 'Phase 10'],
        'withdrawals' => ['withdrawals', 'Withdrawals', 'withdrawals.view', 'Phase 10'],
        'settlements' => ['settlements', 'Settlements', 'settlements.view', 'Phase 10'],
        'shariah-reviews' => ['shariah-reviews', 'Shariah Review', 'shariah.review', 'Phase 10'],
        'reports' => ['reports', 'Reports', 'reports.view', 'Phase 12'],
        'audit-logs' => ['audit-logs', 'Audit Logs', 'audit.view', 'Phase 10'],
        'settings' => ['settings', 'Settings', 'settings.manage', 'Phase 14'],
        'notifications' => ['notifications', 'Notifications', null, 'Phase 14'],
    ] as $uri => [$name, $title, $permission, $phase]) {
        $route = Route::get("/$uri", Soon::class)->defaults('portal', 'admin')->defaults('title', $title)->defaults('phase', $phase)->name($name);
        if ($permission) {
            $route->middleware("permission:$permission");
        }
    }
});

Route::middleware('auth')->get('/profile', fn () => redirect()->route(auth()->user()->homeRoute() === 'admin.dashboard' ? 'admin.profile' : (auth()->user()->isBusiness() ? 'business.profile' : 'investor.profile')))->name('profile');

Route::post('/logout', function (\App\Livewire\Actions\Logout $logout) {
    $logout();

    return redirect('/');
})->middleware('auth')->name('logout');

require __DIR__.'/auth.php';
