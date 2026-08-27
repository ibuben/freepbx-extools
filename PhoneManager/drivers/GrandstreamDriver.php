<?php

namespace PhoneManager;

class GrandstreamDriver extends AbstractDriver
{
    public function getCode(): string
    {
        return 'grandstream';
    }

    public function getName(): string
    {
        return 'Grandstream';
    }

    public function detect(string $ip, string $user = 'admin', string $pass = 'admin'): bool
    {
        foreach (['http', 'https'] as $scheme) {
            $resp = $this->httpGet("{$scheme}://{$ip}/", $user, $pass);
            if ($resp['code'] === 200 || $resp['code'] === 401) {
                $body = strtolower($resp['body']);
                if (str_contains($body, 'grandstream') || str_contains($body, 'gxp') || str_contains($body, 'grp')) {
                    return true;
                }
                if (str_contains($body, 'webapp.nocache.js')) {
                    return true;
                }
            }
            $resp = $this->httpGet("{$scheme}://{$ip}/cgi-bin/api.values.get?P331", $user, $pass);
            if ($resp['code'] === 200 && $this->isGrandstreamSuccess($resp)) {
                return true;
            }
        }

        return $this->openSession($ip, $user, $pass) !== null;
    }

    public function getInfo(string $ip, string $user = 'admin', string $pass = 'admin'): array
    {
        $info = [
            'vendor' => $this->getCode(),
            'model' => null,
            'mac' => null,
            'firmware' => null,
        ];

        foreach (['http', 'https'] as $scheme) {
            $session = $this->openSession($ip, $user, $pass, $scheme);
            if ($session === null) {
                continue;
            }

            $values = $this->getValues($ip, $session, ['P331', 'P332', 'P333'], $scheme);
            if ($values === null) {
                continue;
            }

            if (!empty($values['P331'])) {
                $info['mac'] = $this->normalizeMac((string) $values['P331']);
            }
            if (!empty($values['P332'])) {
                $info['model'] = (string) $values['P332'];
            }
            if (!empty($values['P333'])) {
                $info['firmware'] = (string) $values['P333'];
            }

            if ($info['mac'] || $info['model']) {
                break;
            }
        }

        return $info;
    }

    public function provision(string $ip, array $config, string $user = 'admin', string $pass = 'admin'): array
    {
        $session = $this->openSession($ip, $user, $pass);
        if ($session === null) {
            return [
                'success' => false,
                'message' => 'Не удалось авторизоваться на телефоне. Проверьте пароль admin в карточке телефона.',
            ];
        }

        if ($this->provisionViaAutoPull($ip, $config, $session)) {
            return [
                'success' => true,
                'message' => 'Запрошена загрузка конфигурации, телефон перезагружается...',
            ];
        }

        if ($this->provisionViaParameters($ip, $config, $session)) {
            return [
                'success' => true,
                'message' => 'SIP-параметры отправлены на телефон, перезагрузка...',
            ];
        }

        return [
            'success' => false,
            'message' => 'Не удалось запустить автонастройку Grandstream. Проверьте пароль admin и доступность телефона.',
        ];
    }

    public function generateCfg(array $config): string
    {
        $macRaw = strtolower(str_replace(':', '', $config['mac_raw'] ?? $config['mac_address'] ?? ''));
        $params = $this->buildSipParams($config);

        if (!empty($config['provision_url'])) {
            $params['P237'] = $config['provision_url'];
            $params['P212'] = '1';
            $params['P194'] = '1';
        }

        $pXml = '';
        foreach ($params as $name => $value) {
            $pXml .= '    <' . $name . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . ">\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<gs_provision version="1">' . "\n"
            . '  <mac>' . htmlspecialchars($macRaw, ENT_XML1) . "</mac>\n"
            . "  <config version=\"1\">\n"
            . $pXml
            . "  </config>\n"
            . "</gs_provision>\n";
    }

    public function cfgContentType(): string
    {
        return 'application/xml; charset=utf-8';
    }

    private function provisionViaAutoPull(string $ip, array $config, array $session): bool
    {
        $provisionUrl = trim($config['provision_url'] ?? '');
        if ($provisionUrl === '') {
            $macRaw = strtolower(str_replace(':', '', $config['mac_raw'] ?? $config['mac_address'] ?? ''));
            $base = rtrim($config['provision_base_url'] ?? 'http://pmp.c1.uz', '/');
            if ($macRaw !== '') {
                $provisionUrl = $base . '/cfg' . $macRaw . '.xml';
            }
        }
        if ($provisionUrl === '') {
            return false;
        }

        $params = [
            'P237' => $provisionUrl,
            'P212' => '1',
            'P194' => '1',
        ];

        if (!$this->postValues($ip, $session, $params)) {
            return false;
        }

        return $this->triggerProvisionOrReboot($ip, $session);
    }

    private function provisionViaParameters(string $ip, array $config, array $session): bool
    {
        $params = $this->buildSipParams($config);
        if (!$this->postValues($ip, $session, $params)) {
            return false;
        }

        return $this->sysOperation($ip, $session, 'REBOOT');
    }

    /** @return array<string, string> */
    private function buildSipParams(array $config): array
    {
        $params = [
            'P271' => '1',
            'P270' => $config['sip_extension'] ?? '',
            'P272' => $config['sip_password'] ?? '',
            'P47' => $config['sip_server'] ?? '',
            'P48' => (string) ($config['sip_port'] ?? 5060),
            'P277' => $config['display_name'] ?? ($config['sip_extension'] ?? ''),
            'P1362' => $this->mapLanguage($config['language'] ?? 'ru'),
        ];

        if (!empty($config['admin_password'])) {
            $params['P2'] = $config['admin_password'];
        }

        $tz = $this->mapTimezone($config['timezone'] ?? 'Asia/Tashkent');
        if ($tz !== null) {
            $params['P64'] = $tz;
        }

        $book = trim((string) ($config['phonebook_grandstream_url'] ?? $config['phonebook_url'] ?? ''));
        if ($book !== '') {
            $params['P330'] = '1';
            $params['P331'] = $book;
            $params['P332'] = '60';
        }

        return $params;
    }

    private function triggerProvisionOrReboot(string $ip, array $session): bool
    {
        if ($this->sysOperation($ip, $session, 'PROV')) {
            return true;
        }

        return $this->sysOperation($ip, $session, 'REBOOT');
    }

    /** @param array<string, string> $params */
    private function postValues(string $ip, array $session, array $params): bool
    {
        $scheme = $session['scheme'];
        $payload = http_build_query($params) . '&sid=' . rawurlencode($session['sid']);
        $resp = $this->sessionRequest(
            "{$scheme}://{$ip}/cgi-bin/api.values.post",
            $session,
            $payload
        );

        return $this->isGrandstreamSuccess($resp);
    }

    /** @param list<string> $keys */
    private function getValues(string $ip, array $session, array $keys, ?string $scheme = null): ?array
    {
        $scheme = $scheme ?? $session['scheme'];
        $resp = $this->sessionRequest(
            "{$scheme}://{$ip}/cgi-bin/api.values.get",
            $session,
            'request=' . rawurlencode(implode(':', $keys))
        );

        if (!$this->isGrandstreamSuccess($resp)) {
            return null;
        }

        $json = $this->decodeGrandstreamJson($resp['body']);
        $body = $json['body'] ?? null;
        if (!is_array($body)) {
            return null;
        }

        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                $values[$key] = (string) $body[$key];
            }
        }

        return $values;
    }

    private function sysOperation(string $ip, array $session, string $request): bool
    {
        $scheme = $session['scheme'];
        $resp = $this->sessionRequest(
            "{$scheme}://{$ip}/cgi-bin/api-sys_operation",
            $session,
            'request=' . rawurlencode($request) . '&sid=' . rawurlencode($session['sid'])
        );

        if ($this->isGrandstreamSuccess($resp)) {
            return true;
        }

        $json = $this->decodeGrandstreamJson($resp['body']);
        $body = strtolower((string) ($json['body'] ?? ''));
        return $body === 'savereboot' || $body === 'success';
    }

    private function openSession(string $ip, string $user, string $pass, ?string $preferredScheme = null): ?array
    {
        $schemes = $preferredScheme !== null ? [$preferredScheme] : ['http', 'https'];
        foreach ($schemes as $scheme) {
            $session = $this->loginViaDoLogin($ip, $pass, $scheme);
            if ($session !== null) {
                return $session;
            }

            $session = $this->loginViaToken($ip, $user, $pass, $scheme);
            if ($session !== null) {
                return $session;
            }
        }

        return null;
    }

    private function loginViaDoLogin(string $ip, string $pass, string $scheme): ?array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'gs_');
        if ($cookieFile === false) {
            return null;
        }

        try {
            $resp = $this->curlRequest(
                "{$scheme}://{$ip}/cgi-bin/dologin",
                [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => 'password=' . rawurlencode($pass),
                    CURLOPT_COOKIEJAR => $cookieFile,
                    CURLOPT_COOKIEFILE => $cookieFile,
                    CURLOPT_HTTPHEADER => ["Referer: {$scheme}://{$ip}/"],
                ]
            );

            if (!$this->isGrandstreamSuccess($resp)) {
                return null;
            }

            $json = $this->decodeGrandstreamJson($resp['body']);
            $sid = $this->extractSid($json);
            if ($sid === null) {
                return null;
            }

            return [
                'scheme' => $scheme,
                'sid' => $sid,
                'cookie_file' => $cookieFile,
            ];
        } catch (\Throwable) {
            @unlink($cookieFile);
            return null;
        }
    }

    private function loginViaToken(string $ip, string $user, string $pass, string $scheme): ?array
    {
        $realmResp = $this->curlRequest("{$scheme}://{$ip}/cgi-bin/loginrealm");
        if ($realmResp['code'] !== 200) {
            return null;
        }

        $realm = trim($realmResp['body']);
        if ($realm === '' || str_contains(strtolower($realm), '<html')) {
            return null;
        }

        $secret = md5($user . ':' . $realm . ':' . $pass);
        $loginUrl = "{$scheme}://{$ip}/cgi-bin/login?Username=" . rawurlencode($user)
            . '&Secret=' . rawurlencode($secret);
        $resp = $this->curlRequest($loginUrl);
        if (!$this->isGrandstreamSuccess($resp) && $resp['code'] !== 200) {
            return null;
        }

        $json = $this->decodeGrandstreamJson($resp['body']);
        $sid = $this->extractSid($json);
        if ($sid === null) {
            return null;
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'gs_');
        if ($cookieFile === false) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'sid' => $sid,
            'cookie_file' => $cookieFile,
        ];
    }

    private function sessionRequest(string $url, array $session, string $postFields): array
    {
        return $this->curlRequest($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_COOKIEFILE => $session['cookie_file'],
            CURLOPT_COOKIEJAR => $session['cookie_file'],
            CURLOPT_HTTPHEADER => ["Referer: {$session['scheme']}://" . parse_url($url, PHP_URL_HOST) . '/'],
        ]);
    }

    /** @param array<int, int|string|array<int, string>> $extraOpts */
    private function curlRequest(string $url, array $extraOpts = []): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->pingTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ] + $extraOpts;
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

    private function isGrandstreamSuccess(array $resp): bool
    {
        if ($resp['code'] !== 200) {
            return false;
        }

        $json = $this->decodeGrandstreamJson($resp['body']);
        if ($json === null) {
            return $this->isPhoneApiSuccess($resp);
        }

        return ($json['response'] ?? '') === 'success';
    }

    /** @return array<string, mixed>|null */
    private function decodeGrandstreamJson(string $body): ?array
    {
        $body = trim($body);
        if ($body === '' || $body[0] !== '{') {
            return null;
        }

        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    /** @param array<string, mixed> $json */
    private function extractSid(array $json): ?string
    {
        $body = $json['body'] ?? null;
        if (is_string($body) && $body !== '') {
            return $body;
        }
        if (is_array($body) && !empty($body['sid'])) {
            return (string) $body['sid'];
        }
        if (!empty($json['sid'])) {
            return (string) $json['sid'];
        }

        return null;
    }

    private function mapLanguage(string $lang): string
    {
        return match ($lang) {
            'en' => 'en',
            'ru' => 'ru',
            default => 'ru',
        };
    }

    private function mapTimezone(string $tz): ?string
    {
        $map = [
            'Asia/Tashkent' => 'UZT-5',
            'Europe/Moscow' => 'MSK-3',
            'UTC' => 'UTC',
        ];

        return $map[$tz] ?? null;
    }
}
