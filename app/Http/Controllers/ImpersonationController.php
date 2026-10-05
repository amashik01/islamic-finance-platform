<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DEVELOPMENT CONVENIENCE ONLY. "Log in as" lets an administrator open a session as an investor, business, Wakil or staff user to
 * see what they see. It works only when APP_ENV=local, so it can never be used in production. Administrators and managers cannot be
 * impersonated, every start and stop is audited, and a banner with a way back shows for the whole session.
 */
class ImpersonationController extends Controller
{
    public static function enabled(): bool
    {
        return app()->environment('local');
    }

    public function start(Request $request, User $user, AuditLogger $audit)
    {
        abort_unless(self::enabled(), 404);
        $admin = $request->user();
        abort_unless($admin->hasRole('ADMIN'), 403);
        abort_if($request->session()->has('impersonator_id'), 403, 'Return to your own account first.');
        abort_if($user->id === $admin->id, 422, 'You are already signed in as this user.');
        abort_if($user->hasAnyRole(['ADMIN', 'MANAGER']), 403, 'Administrators and managers cannot be impersonated.');

        $audit->record('impersonation.started', $user, null, ['impersonator_id' => $admin->id, 'target_id' => $user->id]);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonator_id', $admin->id);

        return redirect()->route($user->homeRoute());
    }

    public function stop(Request $request, AuditLogger $audit)
    {
        abort_unless(self::enabled(), 404);
        $adminId = $request->session()->get('impersonator_id');
        abort_unless($adminId, 403);
        $admin = User::findOrFail($adminId);
        $target = $request->user();
        $audit->record('impersonation.stopped', $target, null, ['impersonator_id' => $admin->id, 'target_id' => $target->id]);
        Auth::login($admin);
        $request->session()->forget('impersonator_id');
        $request->session()->regenerate();

        return redirect()->route('admin.users');
    }
}
