<?php

declare(strict_types=1);

namespace SystemMonitoring\Support;

final class HttpClient
{
    public function request(string $method, string $url, array $options = []): array
    {
        $method = strtoupper($method);
        $headers = $options['headers'] ?? [];
        $timeout = (int) ($options['timeout'] ?? 30);
        $verifySsl = (bool) ($options['verify_ssl'] ?? true);
        $body = $options['body'] ?? null;
        $downloadTo = $options['download_to'] ?? null;
        $range = $options['range'] ?? null;
        $expectJson = $options['expect_json'] ?? true;

        if ($downloadTo !== null && ! ($options['force_curl'] ?? false)) {
            return $this->streamRequest($method, $url, $headers, $timeout, $body, $downloadTo, $range, $expectJson, $verifySsl);
        }

        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $url, $headers, $timeout, $body, $downloadTo, $range, $expectJson, $verifySsl);
        }

        return $this->streamRequest($method, $url, $headers, $timeout, $body, $downloadTo, $range, $expectJson, $verifySsl);
    }

    public function head(string $url, array $options = []): array
    {
        $options['method'] = 'HEAD';
        return $this->request('HEAD', $url, $options);
    }

    private function curlRequest(
        string $method,
        string $url,
        array $headers,
        int $timeout,
        mixed $body,
        mixed $downloadTo,
        mixed $range,
        bool $expectJson,
        bool $verifySsl
    ): array {
        $handle = curl_init($url);
        $responseHeaders = [];
        $responseBody = '';
        $headerMap = [];
        $fileHandle = null;

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => $downloadTo === null,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders, &$headerMap): int {
                $len = strlen($header);
                $trimmed = trim($header);
                if ($trimmed === '' || ! str_contains($trimmed, ':')) {
                    return $len;
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                $responseHeaders[] = [$name => $value];
                $headerMap[$name] = $value;

                return $len;
            },
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }

        if ($range !== null) {
            curl_setopt($handle, CURLOPT_RANGE, $range);
        }

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        if (is_resource($downloadTo) || $downloadTo instanceof \SplFileObject) {
            $fileHandle = $downloadTo;
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use ($fileHandle): int {
                return fwrite($fileHandle, $data);
            });
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        } elseif (is_string($downloadTo) && $downloadTo !== '') {
            $fileHandle = fopen($downloadTo, 'wb');
            if ($fileHandle === false) {
                return ['ok' => false, 'status' => 0, 'error' => 'Unable to open download destination.'];
            }
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use ($fileHandle): int {
                return fwrite($fileHandle, $data);
            });
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        }

        $result = curl_exec($handle);
        $curlError = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $contentLength = (float) curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($handle);

        if (is_resource($fileHandle)) {
            fclose($fileHandle);
        }

        if ($result === false && $downloadTo === null) {
            return ['ok' => false, 'status' => $status, 'error' => $curlError ?: 'Request failed.'];
        }

        if ($downloadTo === null) {
            $responseBody = is_string($result) ? $result : '';
        }

        $json = null;
        if ($expectJson && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $headerMap,
            'body' => $responseBody,
            'json' => $json,
            'effective_url' => $effectiveUrl,
            'content_length' => $contentLength > 0 ? (int) $contentLength : null,
            'error' => $curlError !== '' ? $curlError : null,
        ];
    }

    private function streamRequest(
        string $method,
        string $url,
        array $headers,
        int $timeout,
        mixed $body,
        mixed $downloadTo,
        mixed $range,
        bool $expectJson,
        bool $verifySsl
    ): array {
        $headerLines = [];
        foreach ($headers as $header) {
            $headerLines[] = $header;
        }

        if ($range !== null) {
            $headerLines[] = 'Range: bytes=' . $range;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'content' => $body,
            ],
            'ssl' => $verifySsl ? [] : [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $resource = fopen($url, 'rb', false, $context);
        if ($resource === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'Request failed.'];
        }

        $meta = stream_get_meta_data($resource);
        $responseBody = '';
        if ($downloadTo === null) {
            $responseBody = stream_get_contents($resource) ?: '';
        } else {
            $destination = is_string($downloadTo) ? fopen($downloadTo, 'wb') : $downloadTo;
            if ($destination === false) {
                fclose($resource);
                return ['ok' => false, 'status' => 0, 'error' => 'Unable to open download destination.'];
            }

            while (! feof($resource)) {
                fwrite($destination, fread($resource, 8192) ?: '');
            }

            if (is_string($downloadTo) && is_resource($destination)) {
                fclose($destination);
            }
        }

        fclose($resource);

        $status = 200;
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
            foreach ($meta['wrapper_data'] as $line) {
                if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d{3})/', (string) $line, $matches) === 1) {
                    $status = (int) $matches[1];
                }
            }
        }

        $json = null;
        if ($expectJson && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => [],
            'body' => $responseBody,
            'json' => $json,
            'effective_url' => $url,
            'content_length' => null,
            'error' => null,
        ];
    }
}
