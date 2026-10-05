<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }} · {{ config('app.name') }}</title>
<style>body{font:13px/1.45 system-ui,sans-serif;color:#1d1c1a;margin:2rem}h1{font-size:20px;margin:0}p{color:#5b5851}table{border-collapse:collapse;width:100%;margin-top:1rem}th,td{border:1px solid #dedbd3;padding:6px 8px;text-align:left}th{background:#f7f6f3}.note{font-size:11px;margin-top:1.5rem}@media print{button{display:none}}</style></head>
<body><button onclick="window.print()">Print / Save as PDF</button>
<h1>{{ config('app.name') }} — {{ $title }}</h1><p>Generated {{ $generated->format('d M Y H:i') }} ({{ config('app.timezone') }})</p>
<table><thead><tr>@foreach($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead><tbody>@forelse($rows as $r)<tr>@foreach($r as $c)<td>{{ $c }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($headers) }}">No data.</td></tr>@endforelse</tbody></table>
<p class="note">{{ config('finance.shariah_disclaimer') }} Figures are taken from the ledger at the time of generation.</p></body></html>
