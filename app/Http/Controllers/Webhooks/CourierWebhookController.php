<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Webhooks\Inbound\AccountRouter;
use App\Webhooks\Inbound\ReceiptIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Courier webhooks. Stateless, CSRF-free, throttled per IP. Authenticate, store the receipt, answer:
 * the courier's payload is processed after the response (AfterResponse), so even SPX's 3 second limit is met.
 *
 * QuadX answers: 200 for a known account (matched, unmatched, duplicate or truncated alike: a courier must not see
 * failures it did not cause); unrouted 404; a missing token or another courier's token 403.
 * SPX answers 200 to everything that is JSON (see spx()), and 400 otherwise.
 */
final class CourierWebhookController extends Controller
{
    /** Unverified SPX requests stored per IP per minute (diagnostics, not a throttle: the answer is the same). */
    public const UNVERIFIED_PER_MINUTE = 300;

    /** All unverified pushes stored per minute, from every address together; the per-IP cap alone can be spread out. */
    public const UNVERIFIED_GLOBAL_PER_MINUTE = 600;

    public function __construct(private readonly AccountRouter $router, private readonly ReceiptIngestor $ingestor) {}

    public function quadx(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if (! is_string($token) || $token === '' || strlen($token) > AccountRouter::TOKEN_MAX) {
            $this->ingestor->rejected('quadx', null);

            return $this->answer(403);
        }

        $account = $this->router->quadx($token);
        if ($account === null) {
            $other = $this->router->byToken($token);
            if ($other !== null) {
                $this->ingestor->rejected('quadx', $other);

                return $this->answer(403);
            }

            $this->ingestor->unrouted('quadx', $request);

            return $this->answer(404);
        }

        $this->ingestor->accept($account, $request, 'token');

        return $this->answer(200);
    }

    /**
     * SPX stops pushing after repeated rejections (4.1 "Web Hook response"), so anything that could be SPX is answered
     * 200: a verified push is stored and processed; an unverified one (bad signature, stale timestamp, unknown token
     * or app id) is stored as rejected or unrouted for diagnosis and never processed. Only a body that is not JSON at
     * all is answered 400. Unverified requests are stored at most UNVERIFIED_PER_MINUTE per IP; past that they are
     * answered the same but not kept.
     */
    public function spx(Request $request, ?string $token = null): JsonResponse
    {
        $route = $this->router->routeSpx($token, $request);

        if ($route['verified']) {
            $this->ingestor->accept($route['account'], $request, $route['routed_by']);

            return $this->answer(200);
        }

        $json = is_array(json_decode($request->getContent(), true));
        $account = $route['account'];
        $reason = $json ? $route['problem'] : 'body is not JSON';

        if ($this->storesUnverified($request)) {
            $receipt = $account === null && $json
                ? $this->ingestor->unrouted('spx', $request, $reason)
                : $this->ingestor->rejected('spx', $account, $request, $reason, $route['routed_by']);

            Log::warning($receipt->status === 'unrouted' ? 'SPX webhook unrouted' : 'SPX webhook rejected', [
                'receipt' => $receipt->id,
                'account' => $account?->public_id,
                'reason' => $reason,
            ]);
        }

        // The same 200 as a processed push: the answer tells a sender nothing about why.
        return $this->answer($json ? 200 : 400);
    }

    /** Whether this unverified SPX request is still within its IP's storage budget for the minute. */
    private function storesUnverified(Request $request): bool
    {
        $key = 'spx-webhook-unverified:ip:'.$this->ipBucket((string) $request->ip());
        $global = 'spx-webhook-unverified:all';
        if (RateLimiter::tooManyAttempts($key, self::UNVERIFIED_PER_MINUTE) || RateLimiter::tooManyAttempts($global, self::UNVERIFIED_GLOBAL_PER_MINUTE)) {
            return false;
        }
        RateLimiter::hit($global, 60);
        if (RateLimiter::hit($key, 60) === self::UNVERIFIED_PER_MINUTE) {
            Log::warning('SPX webhook: unverified requests from one IP over the per-minute limit; further ones this minute are not stored', ['ip' => $request->ip()]);
        }

        return true;
    }

    /** IPv4 as is; IPv6 by its /64 network, since one host can hold a whole /64. */
    private function ipBucket(string $ip): string
    {
        if (! str_contains($ip, ':') || ($packed = @inet_pton($ip)) === false) {
            return $ip;
        }

        return bin2hex(substr($packed, 0, 8)).'::/64';
    }

    private function answer(int $status): JsonResponse
    {
        return response()->json(['ok' => $status === 200], $status);
    }
}
