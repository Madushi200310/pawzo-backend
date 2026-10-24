<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureUserIsAdmin
{
    private const AUDIT_ATTRIBUTE = 'pawzo_admin_audit';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $user->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        if (in_array(
            $request->method(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        )) {
            $route = $request->route();
            $targets = [];

            foreach ($route?->parameters() ?? [] as $name => $value) {
                // Never record token/code/password route parameter values.
                if (preg_match('/token|code|password|secret/i', $name)) {
                    continue;
                }

                if ($value instanceof Model) {
                    $targets[$name] = [
                        'model' => class_basename($value),
                        'id' => $value->getKey(),
                    ];
                } elseif (
                    is_int($value)
                    || (is_string($value) && ctype_digit($value))
                ) {
                    $targets[$name] = ['id' => (int) $value];
                }
            }

            $request->attributes->set(self::AUDIT_ATTRIBUTE, [
                'admin_id' => (int) $user->id,
                'method' => $request->method(),
                'route' => $route?->uri() ?? 'unknown',
                'action' => $route?->getActionName() ?? 'unknown',
                'targets' => $targets ?: null,
            ]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $audit = $request->attributes->get(self::AUDIT_ATTRIBUTE);

        if (! is_array($audit)) {
            return;
        }

        // Prevent duplicate recording if termination is invoked twice.
        $request->attributes->remove(self::AUDIT_ATTRIBUTE);

        try {
            AdminAuditLog::create(array_merge($audit, [
                'response_status' => $response->getStatusCode(),
                'created_at' => now(),
            ]));
        } catch (Throwable $exception) {
            // An audit storage failure cannot undo an already-finished action.
            // Record a minimal operational error without request contents.
            Log::error('Admin audit log could not be stored.', [
                'admin_id' => $audit['admin_id'],
                'exception_class' => get_class($exception),
            ]);
        }
    }
}