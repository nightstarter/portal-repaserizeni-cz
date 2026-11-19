<?php

namespace Tests\Unit;

use App\Integrations\Pohoda\BridgeCanonicalRequest;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class BridgeCanonicalRequestTest extends TestCase
{
    public function test_canonical_string_is_byte_exact_and_query_is_rfc3986_sorted(): void
    {
        $request = Request::create('/api/internal/v1/commands?z=last&a=hello%20world&nested%5Bb%5D=2&nested%5Ba%5D=1', 'GET', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '');
        $canonical = BridgeCanonicalRequest::canonicalString($request, '1700000000', '0123456789abcdef0123456789abcdef');

        self::assertSame(implode("\n", [
            'GET', '/api/internal/v1/commands',
            'a=hello%20world&nested%5Ba%5D=1&nested%5Bb%5D=2&z=last',
            '1700000000', '0123456789abcdef0123456789abcdef', hash('sha256', ''),
        ]), $canonical);
        self::assertSame(
            '98ca040e1cb2df045afc2d0a64d21b1a7463fb05ae28487210427ff06c8ea7f4',
            hash_hmac('sha256', $canonical, str_repeat('A', 32)),
        );
    }
}
