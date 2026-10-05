<?php

use App\Models\ShariahRule;
use App\Services\Shariah\ShariahRuleRegistry;

it('every rule has the mandatory traceability fields and a known classification and verification level', function () {
    $classes = ['MANDATORY', 'PROHIBITED', 'PERMISSIBLE', 'RECOMMENDED', 'DISPUTED', 'POLICY_CHOICE', 'REQUIRES_SCHOLAR_REVIEW'];
    foreach (app(ShariahRuleRegistry::class)->all() as $code => $r) {
        expect($r['title'])->not->toBe('')->and($r['rule_text'])->not->toBe('')->and($r['source_name'])->not->toBe('')
            ->and($classes)->toContain($r['classification'])
            ->and(['TEXT_READ', 'VIA_SUMMARY', 'SECONDARY', 'UNVERIFIED'])->toContain($r['verification']);
    }
});

it('no invented clause numbers: an UNVERIFIED rule carries no clause reference and a TEXT_READ rule carries one', function () {
    foreach (app(ShariahRuleRegistry::class)->all() as $code => $r) {
        if ($r['verification'] === 'UNVERIFIED') {
            expect($r['clause_reference'])->toBeNull("$code is UNVERIFIED but cites a clause");
        }
        if ($r['verification'] === 'TEXT_READ') {
            expect($r['clause_reference'])->not->toBeNull("$code is TEXT_READ without a clause");
        }
    }
});

it('the registry writes UNDER_REVIEW rows, is idempotent, and never marks a rule APPROVED', function () {
    $reg = app(ShariahRuleRegistry::class);
    expect($reg->sync())->toBe($reg->all()->count())->and($reg->sync())->toBe(0);
    expect(ShariahRule::where('status', 'APPROVED')->count())->toBe(0)->and(ShariahRule::where('status', 'UNDER_REVIEW')->count())->toBe($reg->all()->count());
});

it('a changed rule becomes a new version and the old version is superseded, not rewritten', function () {
    $reg = app(ShariahRuleRegistry::class);
    $reg->sync();
    config(['shariah_rules' => collect(config('shariah_rules'))->map(fn ($r) => $r['code'] === 'MUD-PROFIT-RATIO' ? ['title' => 'Edited title'] + $r : $r)->all()]);
    expect($reg->sync())->toBe(1);
    expect(ShariahRule::where('code', 'MUD-PROFIT-RATIO')->orderBy('version')->pluck('status', 'version')->all())->toBe([1 => 'SUPERSEDED', 2 => 'UNDER_REVIEW']);
});

it('the platform never claims certification or affirmatively offers guarantees in its views or code', function () {
    $patterns = [
        '/100% halal/i', '/100% shariah/i', '/certified by shariah/i', '/shariah[- ]certified/i', '/fatwa approved/i', '/islamically guaranteed/i',
        '/\b(offers?|earn(s|ing)?|with|get|enjoy|provides?|promises?)\s+(a\s+)?guaranteed\s+(return|profit|principal)/i',
    ];
    $hits = [];
    foreach ([base_path('resources/views'), base_path('app')] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $text = file_get_contents($f->getPathname());
            foreach ($patterns as $p) {
                if (preg_match($p, $text, $m)) {
                    $hits[] = $f->getPathname().': '.$m[0];
                }
            }
        }
    }
    expect($hits)->toBe([]);
});

it('every referenced rule code in the generated docs exists in the registry', function () {
    $md = file_get_contents(base_path('docs/shariah/SHARIAH_RULE_MATRIX.md'));
    foreach (app(ShariahRuleRegistry::class)->all()->keys() as $code) {
        expect($md)->toContain("`$code`");
    }
});
