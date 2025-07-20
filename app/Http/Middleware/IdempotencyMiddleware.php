<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     *
     * @throws \Exception
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('X-IDEMPOTENCY');

        if (empty($idempotencyKey)) {
            return $next($request);
        }

        if (! in_array($request->method(), ['POST', 'PUT'])) {
            return $next($request);
        }

        $idempotencyRecord = null;

        try {
            $idempotencyRecord = DB::transaction(function () use ($idempotencyKey, $request) {
                $key = IdempotencyKey::find($idempotencyKey);

                if ($key) {
                    return $key;
                }

                return IdempotencyKey::create([
                    'key' => $idempotencyKey,
                    'request_method' => $request->method(),
                    'request_uri' => $request->fullUrl(),
                    'request_headers' => collect($request->headers->all())->except(['authorization', 'cookie', 'user-agent', 'cache-control', 'postman-token', 'accept-encoding', 'connection'])->toArray(),
                    'request_body' => $request->json()->all() ?? $request->all(),
                    'expires_at' => Carbon::now()->addHours(24),
                    'response_status' => null,
                    'response_body' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('IdempotencyMiddleware: Unique constraint violation caught during key creation. Attempting to retrieve existing key.', ['key' => $idempotencyKey, 'error' => $e->getMessage()]);
            $idempotencyRecord = IdempotencyKey::find($idempotencyKey);

            if (! $idempotencyRecord) {
                Log::error('IdempotencyMiddleware: Failed to retrieve key after unique constraint violation.', ['key' => $idempotencyKey]);
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('IdempotencyMiddleware: Error during idempotency key retrieval/creation.', ['key' => $idempotencyKey, 'error' => $e->getMessage()]);
            throw $e;
        }

        if (! $idempotencyRecord) {
            Log::error('IdempotencyMiddleware: Idempotency record is null after all attempts. This should not happen.', ['key' => $idempotencyKey]);

            return response()->json(['message' => 'Internal Server Error: Idempotency check failed.'], 500);
        }

        if ($idempotencyRecord->response_status !== null) {
            Log::info('IdempotencyMiddleware: Key found with existing response. Checking request match.', ['key' => $idempotencyKey, 'status' => $idempotencyRecord->response_status]);

            $storedRequestMethod = $idempotencyRecord->request_method;
            $storedRequestUri = $idempotencyRecord->request_uri;
            $storedRequestBody = $idempotencyRecord->request_body;

            $normalizedStoredBody = json_encode($storedRequestBody ?? []);
            $normalizedCurrentBody = json_encode($request->json()->all() ?? $request->all());

            if ($storedRequestMethod === $request->method() &&
                $storedRequestUri === $request->fullUrl() &&
                $normalizedStoredBody === $normalizedCurrentBody
            ) {
                Log::info('IdempotencyMiddleware: Request matches. Returning cached response.', ['key' => $idempotencyKey]);

                return response()->json(
                    $idempotencyRecord->response_body ?? [],
                    $idempotencyRecord->response_status
                );
            } else {
                Log::warning('IdempotencyMiddleware: Key used for a different request. Returning 409 Conflict.', ['key' => $idempotencyKey,
                    'stored_method' => $storedRequestMethod, 'current_method' => $request->method(),
                    'stored_uri' => $storedRequestUri, 'current_uri' => $request->fullUrl(),
                    'stored_body_norm' => $normalizedStoredBody, 'current_body_norm' => $normalizedCurrentBody,
                ]);

                return response()->json([
                    'message' => 'Idempotency key already used for a different request.',
                    'idempotency_key' => $idempotencyKey,
                ], 409);
            }
        }

        Log::info('IdempotencyMiddleware: Processing new request for key.', ['key' => $idempotencyKey]);
        try {
            $response = $next($request);

            $idempotencyRecord->response_status = $response->getStatusCode();
            $content = $response->getContent();
            $idempotencyRecord->response_body = json_decode($content, true) ?? [];

            $idempotencyRecord->save();

            Log::info('IdempotencyMiddleware: Request processed and response saved.', ['key' => $idempotencyKey, 'status' => $idempotencyRecord->response_status]);

            return $response;
        } catch (\Exception $e) {
            Log::error('IdempotencyMiddleware: Error during main request execution. Deleting idempotency key.', ['key' => $idempotencyKey, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $idempotencyRecord->delete();
            throw $e;
        }
    }
}
