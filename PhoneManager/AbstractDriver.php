<?php

namespace PhoneManager;

abstract class AbstractDriver implements PhoneDriverInterface
{
    protected int $httpTimeout;
    protected int $pingTimeout;

    public function __construct(array $config = [])
    {
        $this->httpTimeout = $config['http_timeout'] ?? 10;
        $this->pingTimeout = $config['ping_timeout'] ?? 2;
    }

    public function ping(string $ip): bool
    {
        $cmd = sprintf('ping -c 1 -W %d %s 2>/dev/null', $this->pingTimeout, escapeshellarg($ip));
        exec($cmd, $output, $code);
        return $code === 0;
    }

    protected function httpGet(string $url, ?string $user = null, ?string $pass = null, array $headers = []): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->pingTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($user !== null) {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
            $opts[CURLOPT_UNRESTRICTED_AUTH] = true;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'code' => (int) $httpCode,
            'body' => $body !== false ? $body : '',
            'error' => $error,
        ];
    }

    protected function httpPost(string $url, $data, ?string $user = null, ?string $pass = null, array $headers = []): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->pingTimeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($user !== null) {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
            $opts[CURLOPT_UNRESTRICTED_AUTH] = true;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'code' => (int) $httpCode,
            'body' => $body !== false ? $body : '',
            'error' => $error,
        ];
    }

    protected function phoneApiGet(string $url, ?string $user = null, ?string $pass = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->pingTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        if ($user !== null) {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
            $opts[CURLOPT_UNRESTRICTED_AUTH] = true;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'code' => (int) $httpCode,
            'body' => $body !== false ? $body : '',
            'error' => $error,
        ];
    }

    protected function isPhoneApiSuccess(array $resp): bool
    {
        if ($resp['code'] !== 200) {
            return false;
        }
        $body = strtolower($resp['body']);
        if ($body === '') {
            return false;
        }
        if (str_contains($body, '401') || str_contains($body, 'not authorized') || str_contains($body, 'authorization required')) {
            return false;
        }
        if (str_contains($body, '<html') && (str_contains($body, 'error') || str_contains($body, 'forbidden'))) {
            return false;
        }
        return true;
    }

    protected function isFanvilActionSuccess(array $resp): bool
    {
        if (!$this->isPhoneApiSuccess($resp)) {
            return false;
        }
        return str_contains(strtolower($resp['body']), 'request success');
    }

    protected function normalizeMac(?string $mac): ?string
    {
        if ($mac === null || $mac === '') {
            return null;
        }
        $mac = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        if (strlen($mac) !== 12) {
            return $mac ?: null;
        }
        return implode(':', str_split($mac, 2));
    }
}
