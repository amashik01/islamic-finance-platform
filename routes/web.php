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

Route::get('/reports/{scope}/{report}', \App\Http\Controllers\ReportController::class)
    ->middleware(['auth', 'verified', 'throttle:20,1'])->name('reports.download');

/* Private documents are only ever served through this authorised route. */
Route::get('/documents/{document}', \App\Http\Controllers\DocumentController::class)
    ->middleware(['auth', 'verified', 'throttle:60,1'])->name('documents.show');

/* ------------------------------- Investor portal ------------------------------ */
Route::prefix('investor')->name('investor.')->middleware(['auth', 'verified', 'role:INVESTOR'])->group(function () {
    Route::get('/', \App\Livewire\Investor\Dashboard::class)->name('dashboard');
    Route::view('/profile', 'portal.profile', ['portal' => 'investor'])->name('profile');
    Route::get('/wallet', \App\Livewire\Investor\Wallet::class)->name('wallet');
    Route::get('/opportunities', \App\Livewire\Investor\Opportunities::class)->name('opportunities');
    Route::get('/investments', \App\Livewire\Investor\Investments::class)->name('investments');
    Route::get('/investments/{investment}', \App\Livewire\Investor\InvestmentDetails::class)->name('investments.show');
    Route::get('/contracts', \App\Livewire\Investor\Contracts::class)->name('contracts');
    Route::get('/transactions', \App\Livewire\Investor\Transactions::class)->name('transactions');
    Route::get('/withdrawals', \App\Livewire\Investor\Withdrawals::class)->name('withdrawals');
    Route::get('/documents', \App\Livewire\Portal\DocumentManager::class)->name('documents');
    Route::get('/notifications', \App\Livewire\Portal\NotificationCenter::class)->name('notifications');
    Route::view('/reports', 'portal.reports', ['portal' => 'investor'])->name('reports');
    Route::get('/settings', Soon::class)->defaults('portal', 'investor')->defaults('title', 'Settings')->defaults('phase', 'Phase 14')->name('settings');
});

/* ------------------------------- Business portal ------------------------------ */
Route::prefix('business')->name('business.')->middleware(['auth', 'verified', 'role:BUSINESS'])->group(function () {
    Route::get('/', \App\Livewire\Business\Dashboard::class)->name('dashboard');
    Route::view('/profile', 'portal.profile', ['portal' => 'business'])->name('profile');
    Route::get('/projects', \App\Livewire\Business\Projects::class)->name('projects');
    Route::get('/projects/create', \App\Livewire\Business\ProjectWizard::class)->name('projects.create');
    Route::get('/projects/{project}/edit', \App\Livewire\Business\ProjectWizard::class)->name('projects.edit');
    Route::get('/projects/{project}', \App\Livewire\Business\ProjectDetails::class)->name('projects.show');
    Route::get('/funding', \App\Livewire\Business\Funding::class)->name('funding');
    Route::get('/contracts', \App\Livewire\Business\Contracts::class)->name('contracts');
    Route::get('/payments', \App\Livewire\Business\Payments::class)->name('payments');
    Route::get('/settlements', \App\Livewire\Business\Settlements::class)->name('settlements');
    Route::get('/documents', \App\Livewire\Portal\DocumentManager::class)->name('documents');
    Route::get('/notifications', \App\Livewire\Portal\NotificationCenter::class)->name('notifications');
    Route::view('/reports', 'portal.reports', ['portal' => 'business'])->name('reports');
    foreach (['settings' => ['Settings', 'Phase 14']] as $uri => [$title, $phase]) {
        Route::get("/$uri", Soon::class)->defaults('portal', 'business')->defaults('title', $title)->defaults('phase', $phase)->name($uri);
    }
});

/* -------------------------------- Admin portal -------------------------------- */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'role:ADMIN|MANAGER|STAFF'])->group(function () {
    Route::redirect('/', '/admin/dashboard');
    Route::get('/dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');
    Route::view('/profile', 'portal.profile', ['portal' => 'admin'])->name('profile');

    $pages = [
        // uri => [route name, component, permission|null]
        'users' => ['users', \App\Livewire\Admin\UsersTable::class, 'users.view'],
        'investors' => ['investors', \App\Livewire\Admin\InvestorsTable::class, 'investors.view'],
        'businesses' => ['businesses', \App\Livewire\Admin\BusinessesTable::class, 'businesses.view'],
        'kyc' => ['kyc', \App\Livewire\Admin\KycTable::class, 'kyc.view'],
        'kyc/businesses' => ['kyc.businesses', \App\Livewire\Admin\BusinessKycTable::class, 'kyc.view'],
        'kyc/wakils' => ['kyc.wakils', \App\Livewire\Admin\WakilKycTable::class, 'kyc.view'],
        'documents' => ['documents', \App\Livewire\Admin\DocumentsTable::class, null],
        'projects' => ['projects', \App\Livewire\Admin\ProjectsTable::class, 'projects.view'],
        'projects/pending' => ['projects.pending', \App\Livewire\Admin\PendingProjectsTable::class, 'projects.review'],
        'projects/{project}' => ['projects.show', \App\Livewire\Admin\ProjectReview::class, 'projects.view'],
        'contracts/mudarabah' => ['contracts.mudarabah', \App\Livewire\Admin\MudarabahContractsTable::class, 'contracts.view'],
        'contracts/musharakah' => ['contracts.musharakah', \App\Livewire\Admin\MusharakahContractsTable::class, 'contracts.view'],
        'contracts/murabaha' => ['contracts.murabaha', \App\Livewire\Admin\MurabahaContractsTable::class, 'contracts.view'],
        'contracts/{contract}' => ['contracts.show', \App\Livewire\Admin\ContractDetails::class, 'contracts.view'],
        'investments' => ['investments', \App\Livewire\Admin\InvestmentsTable::class, 'investments.view'],
        'wallets' => ['wallets', \App\Livewire\Admin\WalletsTable::class, 'wallet.view'],
        'ledger' => ['ledger', \App\Livewire\Admin\LedgerTable::class, 'ledger.view'],
        'deposits' => ['deposits', \App\Livewire\Admin\DepositsTable::class, 'deposits.view'],
        'withdrawals' => ['withdrawals', \App\Livewire\Admin\WithdrawalsTable::class, 'withdrawals.view'],
        'settlements' => ['settlements', \App\Livewire\Admin\SettlementsTable::class, 'settlements.view'],
        'shariah-reviews' => ['shariah-reviews', \App\Livewire\Admin\ShariahReviewsTable::class, 'shariah.review'],
        'audit-logs' => ['audit-logs', \App\Livewire\Admin\AuditLogsTable::class, 'audit.view'],
    ];
    Route::get('/notifications', \App\Livewire\Portal\NotificationCenter::class)->name('notifications');
    foreach ($pages as $uri => [$name, $component, $permission]) {
        $route = Route::get("/$uri", $component)->name($name);
        if ($permission) {
            $route->middleware("permission:$permission");
        }
    }

    // Arrive in later phases; access control is already enforced.
    Route::view('/reports', 'portal.reports', ['portal' => 'admin'])->middleware('permission:reports.view')->name('reports');
    Route::get('/settings', \App\Livewire\Admin\Settings::class)->middleware('permission:settings.manage')->name('settings');
});

Route::middleware('auth')->get('/profile', function () {
    $u = auth()->user();

    return redirect()->route($u->isStaffMember() ? 'admin.profile' : ($u->isBusiness() ? 'business.profile' : 'investor.profile'));
})->name('profile');

Route::post('/logout', function (\App\Livewire\Actions\Logout $logout) {
    $logout();

    return redirect('/');
})->middleware('auth')->name('logout');

require __DIR__.'/auth.php';
