<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Transport;

use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;

/**
 * Default cURL-based HTTP transport.
 *
 * No dependency beyond ext-curl, mirroring very-code-com/suus-php and very-code-com/dpd-de-php.
 * Response headers are collected through a header callback rather than by prepending them to
 * the body, so a PDF payload stays byte-exact.
 */
final class CurlTransport implements TransportInterface
{
    public function send(TransportRequest $request): TransportResponse
    {
        $ch = curl_init($request->url);

        if ($ch === false) {
            throw new CargoboardTransportException('curl_init() failed. Is ext-curl installed?');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        $options = [
            CURLOPT_CUSTOMREQUEST  => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => $request->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $request->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => $request->timeout,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }

                // cURL requires the number of bytes handled, not the number of bytes stored.
                return strlen($line);
            },
        ];

        if ($request->method === 'POST') {
            $options[CURLOPT_POST] = true;
            // Always send a body on POST: Cargoboard's cancel endpoint takes no payload, but a
            // POST without Content-Length is rejected by some proxies in front of the API.
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        }

        curl_setopt_array($ch, $options);

        $body     = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $body === false) {
            throw new CargoboardTransportException(
                "cURL error #{$errNo} calling Cargoboard ({$request->method} {$request->url}): {$errMsg}"
            );
        }

        return new TransportResponse($httpCode, (string) $body, $responseHeaders);
    }
}
