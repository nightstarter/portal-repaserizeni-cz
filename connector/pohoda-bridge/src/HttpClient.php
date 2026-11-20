<?php

namespace PortalRepase\Bridge;

final class HttpClient
{
    private $config;
    private $transport;

    public function __construct(array $config, $transport = null)
    {
        $this->config = $config;
        $this->transport = $transport;
        if (strpos((string) $config['portal_base_url'], 'https://') !== 0) {
            throw new BridgeException('PORTAL_HTTPS_REQUIRED');
        }
    }

    public function signed($method, $path, array $query, $rawBody)
    {
        $timestamp = time();
        $nonce = Util::nonce();
        $secret = base64_decode((string) $this->config['bridge_secret_base64'], true);
        if ($secret === false || strlen($secret) < 32) {
            throw new BridgeException('BRIDGE_SECRET_TOO_SHORT');
        }
        $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Bridge-Key-Id: '.$this->config['bridge_key_id'],
            'X-Bridge-Timestamp: '.$timestamp,
            'X-Bridge-Nonce: '.$nonce,
            'X-Bridge-Signature: '.CanonicalRequest::signature($secret, $method, $path, $query, $timestamp, $nonce, $rawBody),
        );

        return $this->send($method, $path, $query, $rawBody, $headers);
    }

    public function publicHead($path)
    {
        return $this->send('HEAD', $path, array(), '', array('Accept: application/json'));
    }

    private function send($method, $path, array $query, $rawBody, array $headers)
    {
        $queryString = CanonicalRequest::query($query);
        $url = rtrim($this->config['portal_base_url'], '/').$path.($queryString === '' ? '' : '?'.$queryString);
        $request = array('method' => strtoupper($method), 'url' => $url, 'headers' => $headers, 'body' => $rawBody, 'verify_peer' => true, 'verify_host' => 2);
        if (is_callable($this->transport)) {
            return call_user_func($this->transport, $request);
        }
        if (! function_exists('curl_init')) {
            throw new BridgeException('CURL_EXTENSION_MISSING');
        }
        $responseHeaders = array();
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) $this->config['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => (int) $this->config['request_timeout_seconds'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $line) use (&$responseHeaders) {
                $length = strlen($line);
                $position = strpos($line, ':');
                if ($position !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                }
                return $length;
            },
        ));
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $body = curl_exec($curl);
        if ($body === false) {
            curl_close($curl);
            throw new BridgeException('HTTPS_TRANSPORT_FAILED');
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return array('status' => $status, 'headers' => $responseHeaders, 'body' => $body);
    }

    public static function shouldRetry($status)
    {
        return (int) $status === 408 || (int) $status === 429 || (int) $status >= 500;
    }

    public static function retryAfter(array $response, $fallback)
    {
        $value = isset($response['headers']['retry-after']) ? $response['headers']['retry-after'] : null;
        if (is_string($value) && ctype_digit($value)) {
            return max(1, (int) $value);
        }

        return max(1, (int) $fallback);
    }
}
