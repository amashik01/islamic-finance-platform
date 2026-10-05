<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Scaffolding screen for sections that arrive in later phases. Keeps navigation and authorization real. */
class PlaceholderController extends Controller
{
    public function __invoke(Request $request)
    {
        $route = $request->route();

        return view('portal.placeholder', [
            'portal' => $route->defaults['portal'],
            'title' => $route->defaults['title'],
            'phase' => $route->defaults['phase'] ?? null,
        ]);
    }
}
