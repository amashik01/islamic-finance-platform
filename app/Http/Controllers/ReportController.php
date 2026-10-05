<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __invoke(Request $request, string $scope, string $report, ReportService $reports, AuditLogger $audit)
    {
        $data = $reports->build($scope, $report, $request->user());   // 403 unless the user may run it
        $audit->record('report.exported', null, null, ['scope' => $scope, 'report' => $report, 'format' => $request->query('format', 'csv')]);

        if ($request->query('format') === 'print') {
            return view('reports.print', $data + ['generated' => now()]);
        }

        $filename = $scope.'-'.$report.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads it correctly
            fputcsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, array_map([self::class, 'safe'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @ at the start of a text cell). */
    public static function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) && ! is_numeric($v) ? "'".$v : $v;
    }
}
