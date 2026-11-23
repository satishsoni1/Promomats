<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a web controller action for the mobile app and turns its browser-style
 * result into JSON:
 *  - redirect + flashed errors  -> 422 {message, errors}
 *  - redirect + flashed status  -> {ok, message, document_id?}
 *  - JSON (wantsJson branches)  -> passed through
 * Validation errors, 403s and 404s are already rendered as JSON by Laravel
 * because the app always sends Accept: application/json.
 */
trait RelaysWebActions
{
    protected function relay(callable $action, int $successStatus = 200, ?Document $document = null): Response
    {
        $response = $action();

        if ($response instanceof JsonResponse) {
            return $successStatus !== 200 && $response->getStatusCode() === 200
                ? $response->setStatusCode($successStatus)
                : $response;
        }

        if ($response instanceof RedirectResponse) {
            $errors = session('errors');
            if ($errors instanceof ViewErrorBag && $errors->any()) {
                return response()->json([
                    'message' => $errors->first(),
                    'errors' => $errors->getBag('default')->toArray(),
                ], 422);
            }

            $payload = ['ok' => true, 'message' => session('status')];
            // Where the web would have gone next, e.g. the new job after creating it.
            if ($document) {
                $payload['document_id'] = $document->id;
            } elseif (preg_match('#/documents/(\d+)(?:[/?\#]|$)#', $response->getTargetUrl(), $m)) {
                $payload['document_id'] = (int) $m[1];
            }
            if (isset($payload['document_id'])) {
                // The job's status after the action, so the app can update in place.
                $payload['status'] = Document::whereKey($payload['document_id'])->value('status');
            }

            return response()->json($payload, $successStatus);
        }

        return $response;
    }
}
