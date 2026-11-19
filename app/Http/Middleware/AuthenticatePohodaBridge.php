<?php

namespace App\Http\Middleware;

use App\Application\Audit\AuditLogger;
use App\Integrations\Pohoda\BridgeCanonicalRequest;
use App\Models\BridgeNonce;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePohodaBridge
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > max(1024, (int) config('integrations.pohoda_bridge.max_body_bytes'))) {
            return new JsonResponse(['error' => ['code' => 'REQUEST_TOO_LARGE']], 413);
        }
        $allowlist = config('integrations.pohoda_bridge.ip_allowlist', []);
        if ($allowlist !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowlist)) {
            return $this->reject($request);
        }

        $keyId = (string) $request->header('X-Bridge-Key-Id', '');
        $timestamp = (string) $request->header('X-Bridge-Timestamp', '');
        $nonce = (string) $request->header('X-Bridge-Nonce', '');
        $signature = (string) $request->header('X-Bridge-Signature', '');
        $secret = $this->secretFor($keyId);
        $tolerance = min(300, max(1, (int) config('integrations.pohoda_bridge.timestamp_tolerance', 300)));
        if ($secret === null
            || ! preg_match('/^\d{10}$/', $timestamp)
            || abs(now()->timestamp - (int) $timestamp) > $tolerance
            || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)
            || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->reject($request);
        }

        $expected = hash_hmac('sha256', BridgeCanonicalRequest::canonicalString($request, $timestamp, $nonce), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->reject($request);
        }

        BridgeNonce::query()->where('expires_at', '<', now())->delete();
        try {
            BridgeNonce::query()->create([
                'key_id' => $keyId,
                'nonce' => $nonce,
                'used_at' => now(),
                'expires_at' => now()->addSeconds($tolerance * 2),
            ]);
        } catch (QueryException) {
            return $this->reject($request);
        }

        $request->attributes->set('bridge_key_id', $keyId);

        return $next($request);
    }

    private function secretFor(string $keyId): ?string
    {
        $pairs = [
            [(string) config('integrations.pohoda_bridge.key_id'), config('integrations.pohoda_bridge.secret')],
            [(string) config('integrations.pohoda_bridge.previous_key_id'), config('integrations.pohoda_bridge.previous_secret')],
        ];
        foreach ($pairs as [$configuredId, $encoded]) {
            if ($configuredId === '' || ! hash_equals($configuredId, $keyId) || ! is_string($encoded)) {
                continue;
            }
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false && strlen($decoded) >= 32) {
                return $decoded;
            }
        }

        return null;
    }

    private function reject(Request $request): JsonResponse
    {
        // Do not persist attacker-controlled headers (especially User-Agent) in
        // the audit trail. Only the source IP is useful for this aggregate event.
        $safeRequest = Request::create('/', 'GET', server: ['REMOTE_ADDR' => (string) $request->ip()]);
        $this->auditLogger->record('POHODA_BRIDGE_AUTH_REJECTED', request: $safeRequest);

        return new JsonResponse(['error' => ['code' => 'BRIDGE_AUTH_FAILED']], 401);
    }
}
