<?php

namespace PhoneManager;

class YealinkDriver extends AbstractDriver
{
    public function getCode(): string
    {
        return 'yealink';
    }

    public function getName(): string
    {
        return 'Yealink';
    }

    public function detect(string $ip, string $user = 'admin', string $pass = 'admin'): bool
    {
        foreach (['http', 'https'] as $scheme) {
            $resp = $this->httpGet("{$scheme}://{$ip}/", $user, $pass);
            if ($resp['code'] === 200 || $resp['code'] === 401) {
                $body = strtolower($resp['body']);
                if (str_contains($body, 'yealink') || str_contains($body, 'sip-t') || str_contains($body, 'sip-t4')) {
                    return true;
                }
            }

            $resp = $this->configManagerRequest($ip, ['action' => 'getConfig', 'phone_setting.product_name' => ''], $user, $pass, $scheme);
            if ($this->isConfigManagerSuccess($resp)) {
                return true;
            }

            $resp = $this->actionRequest($ip, ['key' => 'OK'], $user, $pass, $scheme);
            if ($this->isPhoneApiSuccess($resp)) {
                return true;
            }
        }

        return false;
    }

    public function getInfo(string $ip, string $user = 'admin', string $pass = 'admin'): array
    {
        $info = [
            'vendor' => $this->getCode(),
            'model' => null,
            'mac' => null,
            'firmware' => null,
        ];

        $keys = [
            'phone_setting.product_name' => 'model',
            'network.mac' => 'mac',
            'phone_setting.firmware' => 'firmware',
        ];

        foreach (['http', 'https'] as $scheme) {
            $query = ['action' => 'getConfig'];
            foreach (array_keys($keys) as $configKey) {
                $query[$configKey] = '';
            }
            $resp = $this->configManagerRequest($ip, $query, $user, $pass, $scheme);
            if (!$this->isConfigManagerSuccess($resp)) {
                continue;
            }

            $values = $this->parseConfigManagerBody($resp['body']);
            foreach ($keys as $configKey => $field) {
                if (empty($values[$configKey])) {
                    continue;
                }
                if ($field === 'mac') {
                    $info['mac'] = $this->normalizeMac($values[$configKey]);
                } else {
                    $info[$field] = $values[$configKey];
                }
            }

            if ($info['mac'] || $info['model']) {
                break;
            }
        }

        return $info;
    }

    public function provision(string $ip, array $config, string $user = 'admin', string $pass = 'admin'): array
    {
        if (!$this->canAuthenticate($ip, $user, $pass)) {
            return [
                'success' => false,
                'message' => 'Не удалось авторизоваться на телефоне. Проверьте admin_user и admin_pass.',
            ];
        }

        if ($this->provisionViaAutoPull($ip, $config, $user, $pass)) {
            return [
                'success' => true,
                'message' => 'Запрошена загрузка конфигурации Yealink...',
            ];
        }

        if ($this->provisionViaParameters($ip, $config, $user, $pass)) {
            return [
                'success' => true,
                'message' => 'SIP-параметры отправлены на телефон Yealink...',
            ];
        }

        return [
            'success' => false,
            'message' => 'Не удалось запустить автонастройку Yealink',
        ];
    }

    public function generateCfg(array $config): string
    {
        return $this->buildCfgBody($config);
    }

    public function cfgContentType(): string
    {
        return 'text/plain; charset=utf-8';
    }

    private function provisionViaAutoPull(string $ip, array $config, string $user, string $pass): bool
    {
        $base = rtrim($config['provision_base_url'] ?? 'http://pmp.c1.uz', '/');
        $serverUrl = $base . '/';

        $params = [
            'static.auto_provision.server.url' => $serverUrl,
            'static.auto_provision.mode' => '1',
            'static.auto_provision.pnp_enable' => '0',
        ];

        if (!$this->setConfigs($ip, $params, $user, $pass)) {
            return false;
        }

        foreach (['http', 'https'] as $scheme) {
            $autoP = $this->actionRequest($ip, ['key' => 'AutoP'], $user, $pass, $scheme);
            if ($this->isYealinkActionSuccess($autoP)) {
                return true;
            }
        }

        return false;
    }

    private function provisionViaParameters(string $ip, array $config, string $user, string $pass): bool
    {
        $params = $this->buildAccountParams($config);
        if (!$this->setConfigs($ip, $params, $user, $pass)) {
            return false;
        }

        foreach (['http', 'https'] as $scheme) {
            $reboot = $this->actionRequest($ip, ['key' => 'Reboot'], $user, $pass, $scheme);
            if ($this->isYealinkActionSuccess($reboot)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function buildAccountParams(array $config): array
    {
        $params = [
            'account.1.enable' => '1',
            'account.1.label' => $config['display_name'] ?? ($config['sip_extension'] ?? ''),
            'account.1.display_name' => $config['display_name'] ?? ($config['sip_extension'] ?? ''),
            'account.1.user_name' => $config['sip_extension'] ?? '',
            'account.1.auth_name' => $config['sip_extension'] ?? '',
            'account.1.password' => $config['sip_password'] ?? '',
            'account.1.sip_server.1.address' => $config['sip_server'] ?? '',
            'account.1.sip_server.1.port' => (string) ($config['sip_port'] ?? 5060),
            'static.lang.wui' => $this->mapLanguage($config['language'] ?? 'ru'),
            'static.lang.gui' => $this->mapLanguage($config['language'] ?? 'ru'),
        ];

        $tz = $this->mapTimezone($config['timezone'] ?? 'Asia/Tashkent');
        if ($tz !== null) {
            $params['local_time.time_zone'] = $tz;
        }

        if (!empty($config['admin_password'])) {
            $params['security.user_password'] = $config['admin_password'];
        }

        $book = trim((string) ($config['phonebook_yealink_url'] ?? $config['phonebook_url'] ?? ''));
        if ($book !== '') {
            $params['remote_phonebook.data.1.url'] = $book;
            $params['remote_phonebook.data.1.name'] = (string) ($config['phonebook_name'] ?? 'Company');
            $params['search_in_dialing.remote_phonebook.enable'] = '1';
        }

        return $params;
    }

    private function buildCfgBody(array $config): string
    {
        $lines = [
            'account.1.enable = 1',
            'account.1.label = ' . ($config['display_name'] ?? ($config['sip_extension'] ?? '')),
            'account.1.display_name = ' . ($config['display_name'] ?? ($config['sip_extension'] ?? '')),
            'account.1.user_name = ' . ($config['sip_extension'] ?? ''),
            'account.1.auth_name = ' . ($config['sip_extension'] ?? ''),
            'account.1.password = ' . ($config['sip_password'] ?? ''),
            'account.1.sip_server.1.address = ' . ($config['sip_server'] ?? ''),
            'account.1.sip_server.1.port = ' . (int) ($config['sip_port'] ?? 5060),
            'static.lang.wui = ' . $this->mapLanguage($config['language'] ?? 'ru'),
            'static.lang.gui = ' . $this->mapLanguage($config['language'] ?? 'ru'),
        ];

        $tz = $this->mapTimezone($config['timezone'] ?? 'Asia/Tashkent');
        if ($tz !== null) {
            $lines[] = 'local_time.time_zone = ' . $tz;
        }

        if (!empty($config['admin_password'])) {
            $lines[] = 'security.user_password = ' . $config['admin_password'];
        }

        if (!empty($config['phonebook_yealink_url']) || !empty($config['phonebook_url'])) {
            $lines[] = 'remote_phonebook.data.1.url = ' . ($config['phonebook_yealink_url'] ?? $config['phonebook_url']);
            $lines[] = 'remote_phonebook.data.1.name = ' . ($config['phonebook_name'] ?? 'Company');
            $lines[] = 'search_in_dialing.remote_phonebook.enable = 1';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, string> $params */
    private function setConfigs(string $ip, array $params, string $user, string $pass): bool
    {
        $applied = 0;
        foreach (['http', 'https'] as $scheme) {
            $query = ['action' => 'setConfig'];
            foreach ($params as $name => $value) {
                if ($value === '' && $name !== 'account.1.enable') {
                    continue;
                }
                $query[$name] = $value;
            }
            $resp = $this->configManagerRequest($ip, $query, $user, $pass, $scheme);
            if ($this->isConfigManagerSuccess($resp)) {
                $applied++;
                break;
            }
        }

        return $applied > 0;
    }

    private function canAuthenticate(string $ip, string $user, string $pass): bool
    {
        foreach (['http', 'https'] as $scheme) {
            $resp = $this->configManagerRequest($ip, [
                'action' => 'getConfig',
                'phone_setting.product_name' => '',
            ], $user, $pass, $scheme);
            if ($this->isConfigManagerSuccess($resp)) {
                return true;
            }

            $resp = $this->actionRequest($ip, ['key' => 'OK'], $user, $pass, $scheme);
            if ($this->isPhoneApiSuccess($resp)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $query */
    private function configManagerRequest(string $ip, array $query, string $user, string $pass, string $scheme): array
    {
        $url = sprintf(
            '%s://%s:%s@%s/cgi-bin/configManager.cgi?%s',
            $scheme,
            rawurlencode($user),
            rawurlencode($pass),
            $ip,
            http_build_query($query)
        );

        return $this->phoneApiGet($url);
    }

    /** @param array<string, string> $query */
    private function actionRequest(string $ip, array $query, string $user, string $pass, string $scheme): array
    {
        $url = sprintf(
            '%s://%s:%s@%s/cgi-bin/ConfigManApp.com?%s',
            $scheme,
            rawurlencode($user),
            rawurlencode($pass),
            $ip,
            http_build_query($query)
        );

        return $this->phoneApiGet($url);
    }

    private function isConfigManagerSuccess(array $resp): bool
    {
        if (!$this->isPhoneApiSuccess($resp)) {
            return false;
        }

        $body = strtolower(trim($resp['body']));
        if ($body === 'ok') {
            return true;
        }

        return str_contains($body, '=');
    }

    private function isYealinkActionSuccess(array $resp): bool
    {
        if (!$this->isPhoneApiSuccess($resp)) {
            return false;
        }

        $body = strtolower($resp['body']);
        return str_contains($body, 'request success')
            || str_contains($body, 'success')
            || trim($body) === 'ok';
    }

    /** @return array<string, string> */
    private function parseConfigManagerBody(string $body): array
    {
        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $values[$key] = trim($value, "\"' ");
        }

        return $values;
    }

    private function mapLanguage(string $lang): string
    {
        return match ($lang) {
            'en' => 'English',
            'ru' => 'Russian',
            default => 'Russian',
        };
    }

    private function mapTimezone(string $tz): ?string
    {
        $map = [
            'Asia/Tashkent' => '+5',
            'Europe/Moscow' => '+3',
            'UTC' => '0',
        ];

        return $map[$tz] ?? null;
    }
}
