<?php

namespace App\Operations;

use App\Operations\Contracts\TlsCertificateInspector;

final class NativeTlsCertificateInspector implements TlsCertificateInspector
{
    public function inspect(string $host, int $port, int $timeoutSeconds): array
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'disable_compression' => true,
        ]]);
        $socket = @stream_socket_client(
            sprintf('tls://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            max(1, $timeoutSeconds),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            return ['ok' => false, 'code' => $errorCode === 0 ? 'TLS_VERIFY_FAILED' : 'TLS_CONNECTION_FAILED', 'days_remaining' => null];
        }
        $parameters = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
        $parsed = is_resource($certificate) || is_object($certificate) ? openssl_x509_parse($certificate) : false;
        $expires = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;
        if (! is_int($expires)) {
            return ['ok' => false, 'code' => 'TLS_CERTIFICATE_INVALID', 'days_remaining' => null];
        }

        return ['ok' => true, 'code' => 'OK', 'days_remaining' => (int) floor(($expires - time()) / 86400)];
    }
}
