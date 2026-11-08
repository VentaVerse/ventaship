<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Webhooks\Inbound\AccountRouter;
use App\Webhooks\Inbound\ReceiptIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Courier webhooks (base plan §3). Stateless, CSRF-free, throttled per IP. Authenticate, store the receipt, answer:
 * the courier's payload is processed later on the `inbound` queue, so even SPX's 3 second limit is met.
 *
 * Answers: 200 for a known account (matched, unmatched, duplicate or truncated alike: a courier must not see
 * failures it did not cause); unrouted 404 (QuadX) / 401 (SPX); a missing token, another courier's token, or a
 * failed SPX signature 403 (QuadX) / 401 (SPX).
 */
final class CourierWebhookController extends Controller
{
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

    public function spx(Request $request, ?string $token = null): JsonResponse
    {
        $route = $this->router->routeSpx($token, $request);

        if ($route['account'] === null) {
            $this->ingestor->unrouted('spx', $request);

            return $this->answer(401);
        }

        if (! $route['verified']) {
            $this->ingestor->rejected('spx', $route['account']);

            return $this->answer(401);
        }

        $this->ingestor->accept($route['account'], $request, $route['routed_by']);

        return $this->answer(200);
    }

    private function answer(int $status): JsonResponse
    {
        return response()->json(['ok' => $status === 200], $status);
    }
}
