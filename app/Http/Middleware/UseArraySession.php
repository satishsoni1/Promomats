<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * A throwaway, in-memory session for the mobile API. The app's mobile actions run
 * the very same web controller methods (so every rule stays identical), and those
 * methods flash their result with back()->with(...) / withErrors(...). This gives
 * them a session to flash into for the length of one request; nothing is stored
 * and no cookie is sent (the array driver is never persisted).
 */
class UseArraySession
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.driver' => 'array']);

        // Start every request from an empty store (long-lived workers and the test
        // client reuse the container), and make redirects flash into this same store.
        $store = app('session')->driver('array');
        $store->flush();
        app()->instance('session.store', $store);
        app('redirect')->setSession($store);

        return app(StartSession::class)->handle($request, $next);
    }
}
