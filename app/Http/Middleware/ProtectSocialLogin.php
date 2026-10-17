<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ProtectSocialLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! preg_match(
            '#^api/auth/(google|facebook)/(redirect|callback)$#',
            $request->path(),
            $matches,
        )) {
            return $next($request);
        }

        $provider = $matches[1];
        $operation = $matches[2];

        // Add cookie/session handling only to browser social-login routes.
        return app(Pipeline::class)
            ->send($request)
            ->through([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
            ])
            ->then(function (Request $request) use (
                $next,
                $provider,
                $operation,
            ) {
                $key = 'pawzo_oauth.'.$provider;

                if ($operation === 'callback') {
                    // Consume state even if this attempt is invalid.
                    $stored = $request->session()->pull($key);
                    $received = $request->query('state');

                    $valid = is_array($stored)
                        && isset($stored['state'], $stored['expires_at'])
                        && is_string($stored['state'])
                        && is_string($received)
                        && $received !== ''
                        && is_int($stored['expires_at'])
                        && $stored['expires_at'] > now()->timestamp
                        && hash_equals($stored['state'], $received);

                    if (! $valid) {
                        return response()->json([
                            'message' => 'Invalid or expired social login request. Please start login again.',
                            'error' => 'invalid_oauth_state',
                        ], 401)->header('Cache-Control', 'no-store');
                    }

                    $request->session()->regenerate();

                    $response = $next($request);
                    $response->headers->set('Cache-Control', 'no-store');

                    return $response;
                }

                $request->session()->regenerate();

                $state = Str::random(64);

                $request->session()->put($key, [
                    'state' => $state,
                    'expires_at' => now()->addMinutes(10)->timestamp,
                ]);

                $response = $next($request);

                if (
                    $response instanceof JsonResponse
                    && $response->isSuccessful()
                ) {
                    $data = $response->getData(true);

                    if (
                        is_array($data)
                        && isset($data['redirect_url'])
                        && is_string($data['redirect_url'])
                    ) {
                        $data['redirect_url'] = $this->withState(
                            $data['redirect_url'],
                            $state,
                        );

                        $response->setData($data);
                    }
                }

                $response->headers->set('Cache-Control', 'no-store');

                return $response;
            });
    }

    private function withState(string $url, string $state): string
    {
        $fragmentParts = explode('#', $url, 2);
        $urlParts = explode('?', $fragmentParts[0], 2);

        $query = [];
        parse_str($urlParts[1] ?? '', $query);
        $query['state'] = $state;

        $result = $urlParts[0].'?'.http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        if (isset($fragmentParts[1])) {
            $result .= '#'.$fragmentParts[1];
        }

        return $result;
    }
}