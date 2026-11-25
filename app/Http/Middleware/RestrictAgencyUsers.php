<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * External Content Agency logins (MOM 24 Nov) work only in their own workspace:
 * the jobs the Brand Manager sent to their agency, the files and comments on them,
 * and their own task actions. Everything else (library, reports, search, active
 * workflow, approvals, projects, ...) is off limits - they "cannot see workflow
 * updates". Which jobs they can open is enforced by Document::scopeVisibleTo().
 */
class RestrictAgencyUsers
{
    public const ALLOWED_ROUTES = [
        'agency.*',
        'documents.show',
        'documents.versions.view',
        'documents.versions.download',
        'reference-attachments.download',
        'work-tasks.take',
        'work-tasks.assign',
        'work-tasks.complete',
        'notifications.*',
        'profile.*',
        'password.*',
        'verification.*',
        'logout',
        'helpdesk.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route()?->getName();

        if (! $user || $route === null || ! $user->loadMissing('roles')->isAgencyUser() || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('agency.index');
        }

        abort(403, 'Content agency logins can only work on the jobs sent to their agency.');
    }
}
