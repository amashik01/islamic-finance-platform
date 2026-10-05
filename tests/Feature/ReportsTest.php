<?php

use App\Enums\UserRole;
use App\Http\Controllers\ReportController;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\InvestmentService;
use App\Support\Money\Money;

it('exports an investor statement as CSV containing only their own rows', function () {
    $a = makeInvestor(10000000);
    $b = makeInvestor(10000000);
    $svc = app(InvestmentService::class);
    $svc->invest($a, makeProject(['title' => 'Alpha Project']), Money::minor(1000000), 'r1');
    $svc->invest($b, makeProject(['title' => 'Beta Project']), Money::minor(1000000), 'r2');

    $res = $this->actingAs($a->user)->get(route('reports.download', ['investor', 'portfolio']));
    $res->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $csv = $res->streamedContent();
    expect($csv)->toContain('Alpha Project')->and($csv)->toContain('10000.00')->and($csv)->not->toContain('Beta Project');
});

it('includes principal and profit as separate statements after settlement', function () {
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    $inv = makeInvestor(10000000);
    app(InvestmentService::class)->invest($inv, $project, Money::minor(10000000), 'rs');
    app(SettlementService::class)->settle($contract->fresh(), Money::minor(2000000), User::factory()->create());

    $profit = $this->actingAs($inv->user)->get(route('reports.download', ['investor', 'profit']))->streamedContent();
    $principal = $this->actingAs($inv->user)->get(route('reports.download', ['investor', 'principal-returns']))->streamedContent();
    expect($profit)->toContain('14000.00')->and($profit)->not->toContain('100000.00')
        ->and($principal)->toContain('100000.00')->and($principal)->not->toContain('14000.00');
});

it('denies reports outside the user\'s role or permissions', function () {
    $inv = makeInvestor();
    $this->actingAs($inv->user)->get(route('reports.download', ['admin', 'capital-flow']))->assertForbidden();
    $this->actingAs($inv->user)->get(route('reports.download', ['business', 'funding']))->assertForbidden();
    $this->actingAs(makeBusiness()->user)->get(route('reports.download', ['investor', 'portfolio']))->assertForbidden();
    $staff = User::factory()->create();
    $staff->assignRole(UserRole::Staff->value);
    $this->actingAs($staff)->get(route('reports.download', ['admin', 'capital-flow']))->assertForbidden();   // staff lacks reports.view
    $this->actingAs($inv->user)->get(route('reports.download', ['investor', 'nope']))->assertForbidden();
});

it('lets managers export admin reports and renders a print view', function () {
    seedRoles();
    $m = User::factory()->create();
    $m->assignRole(UserRole::Manager->value);
    makeInvestor(1000000);
    $this->actingAs($m)->get(route('reports.download', ['admin', 'investors']))->assertOk();
    $this->actingAs($m)->get(route('reports.download', ['admin', 'capital-flow', 'format' => 'print']))->assertOk()->assertSee('Print / Save as PDF')->assertSee('Shariah');
    expect(\App\Models\AuditLog::where('action', 'report.exported')->count())->toBe(2);
});

it('neutralises spreadsheet formula injection in exports', function () {
    expect(ReportController::safe('=HYPERLINK("http://evil")'))->toStartWith("'")
        ->and(ReportController::safe('-5000.00'))->toBe('-5000.00')
        ->and(ReportController::safe('Normal'))->toBe('Normal')
        ->and(ReportController::safe('@cmd'))->toStartWith("'");
});

it('shows report centres and dashboard charts', function () {
    $inv = makeInvestor(10000000);
    $this->actingAs($inv->user)->get('/investor/reports')->assertOk()->assertSee('Portfolio statement');
    $this->actingAs($inv->user)->get('/investor')->assertOk()->assertSee('Portfolio overview')->assertSee('7D');
    $this->actingAs(makeBusiness()->user)->get('/business/reports')->assertOk()->assertSee('Funding report');
    seedRoles();
    $a = User::factory()->create();
    $a->assignRole('ADMIN');
    $this->actingAs($a)->get('/admin/dashboard')->assertOk()->assertSee('Capital flow')->assertSee('Contract distribution')->assertSee('Project lifecycle')->assertSee('Monthly activity');
});
