<?php

namespace PhoneManager;

class FanvilDriver extends AbstractDriver
{
    public function getCode(): string
    {
        return 'fanvil';
    }

    public function getName(): string
    {
        return 'Fanvil';
    }

    public function detect(string $ip, string $user = 'admin', string $pass = 'admin'): bool
    {
        foreach (['http', 'https'] as $scheme) {
            $url = "{$scheme}://{$ip}/";
            $resp = $this->httpGet($url);
            if ($resp['code'] === 200 || $resp['code'] === 401) {
                $body = strtolower($resp['body']);
                if (str_contains($body, 'fanvil') || str_contains($body, 'x5u') || str_contains($body, 'x6u')) {
                    return true;
                }
            }
            $resp = $this->configManRequest($ip, ['request' => 'GetParameter', 'name' => 'DeviceModel'], $user, $pass, $scheme);
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

        $params = [
            'DeviceModel' => 'model',
            'MACAddress' => 'mac',
            'SoftwareVersion' => 'firmware',
        ];

        foreach (['http', 'https'] as $scheme) {
            $found = false;
            foreach ($params as $param => $key) {
                $resp = $this->configManRequest($ip, ['request' => 'GetParameter', 'name' => $param], $user, $pass, $scheme);
                if ($this->isPhoneApiSuccess($resp)) {
                    $value = trim(strip_tags($resp['body']));
                    if ($value === '') {
                        continue;
                    }
                    if ($key === 'mac') {
                        $info['mac'] = $this->normalizeMac($value);
                    } else {
                        $info[$key] = $value;
                    }
                    $found = true;
                }
            }
            if ($found) {
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
                'message' => 'Конфигурация загружена, телефон перезагружается...',
            ];
        }

        if ($this->provisionViaParameters($ip, $config, $user, $pass)) {
            return [
                'success' => true,
                'message' => 'SIP-параметры отправлены на телефон, перезагрузка...',
            ];
        }

        return [
            'success' => false,
            'message' => 'Не удалось запустить автопровиженинг на телефоне',
        ];
    }

    public function generateCfg(array $config): string
    {
        return $this->buildConfigXml(
            $config['sip_extension'] ?? '',
            $config['sip_password'] ?? '',
            $config['sip_server'] ?? '',
            (int) ($config['sip_port'] ?? 5060),
            $config['display_name'] ?? ($config['sip_extension'] ?? ''),
            $config['timezone'] ?? 'Asia/Tashkent',
            $config['language'] ?? 'ru',
            $config['admin_password'] ?? ''
        );
    }

    public function cfgContentType(): string
    {
        return 'application/xml; charset=utf-8';
    }

    private function canAuthenticate(string $ip, string $user, string $pass): bool
    {
        foreach (['http', 'https'] as $scheme) {
            $resp = $this->configManRequest($ip, ['request' => 'GetParameter', 'name' => 'DeviceModel'], $user, $pass, $scheme);
            if ($this->isPhoneApiSuccess($resp) && trim(strip_tags($resp['body'])) !== '') {
                return true;
            }
        }
        return false;
    }

    private function provisionViaAutoPull(string $ip, array $config, string $user, string $pass): bool
    {
        $provisionUrl = trim($config['provision_url'] ?? '');
        if ($provisionUrl === '') {
            $info = $this->getInfo($ip, $user, $pass);
            $macRaw = strtolower(str_replace(':', '', $info['mac'] ?? ''));
            $base = rtrim($config['provision_base_url'] ?? 'http://pmp.c1.uz', '/');
            if ($macRaw !== '') {
                $provisionUrl = $base . '/' . $macRaw . '.cfg';
            }
        }
        if ($provisionUrl !== '') {
            $this->setParameter($ip, 'Enable Auto Provision', '1', $user, $pass);
            $this->setParameter($ip, 'Server Address', $provisionUrl, $user, $pass);
            $this->setParameter($ip, 'Protocol Type', 'HTTP', $user, $pass);
        }

        foreach (['http', 'https'] as $scheme) {
            $autoP = $this->configManRequest($ip, ['key' => 'AutoP'], $user, $pass, $scheme);
            if (!$this->isPhoneApiSuccess($autoP)) {
                continue;
            }

            sleep(2);
            if ($this->rebootPhoneSilently($ip, $user, $pass, $scheme)) {
                return true;
            }
        }

        return false;
    }

    private function provisionViaParameters(string $ip, array $config, string $user, string $pass): bool
    {
        $params = [
            'SIP1 Phone Number' => $config['sip_extension'] ?? '',
            'SIP1 Display Name' => $config['display_name'] ?? ($config['sip_extension'] ?? ''),
            'SIP1 Register Addr' => $config['sip_server'] ?? '',
            'SIP1 Register Port' => (string) ($config['sip_port'] ?? 5060),
            'SIP1 Password' => $config['sip_password'] ?? '',
            'SIP1 Enable' => '1',
            'Local Time Zone' => $config['timezone'] ?? 'Asia/Tashkent',
            'Web Language' => $this->mapLanguage($config['language'] ?? 'ru'),
        ];

        if (!empty($config['admin_password'])) {
            $params['Admin Password'] = $config['admin_password'];
        }

        $applied = 0;
        foreach ($params as $name => $value) {
            if ($value === '' && $name !== 'SIP1 Enable') {
                continue;
            }
            $resp = $this->setParameter($ip, $name, $value, $user, $pass);
            if ($this->isPhoneApiSuccess($resp)) {
                $applied++;
            }
        }

        if ($applied === 0) {
            return false;
        }

        return $this->rebootPhoneSilently($ip, $user, $pass);
    }

    private function rebootPhoneSilently(string $ip, string $user, string $pass, ?string $scheme = null): bool
    {
        $schemes = $scheme !== null ? [$scheme] : ['http', 'https'];
        foreach ($schemes as $currentScheme) {
            $confirmed = $this->configManRequest($ip, ['key' => 'Reboot;OK'], $user, $pass, $currentScheme);
            if ($this->isFanvilActionSuccess($confirmed)) {
                return true;
            }

            $reboot = $this->configManRequest($ip, ['key' => 'Reboot'], $user, $pass, $currentScheme);
            if ($this->isPhoneApiSuccess($reboot)) {
                usleep(500000);
                $ok = $this->configManRequest($ip, ['key' => 'OK'], $user, $pass, $currentScheme);
                if ($this->isFanvilActionSuccess($ok)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function setParameter(string $ip, string $name, string $value, string $user, string $pass): array
    {
        foreach (['http', 'https'] as $scheme) {
            $resp = $this->configManRequest($ip, [
                'request' => 'SetParameter',
                'name' => $name,
                'value' => $value,
            ], $user, $pass, $scheme);
            if ($resp['code'] > 0) {
                return $resp;
            }
        }

        return ['code' => 0, 'body' => '', 'error' => 'No connection'];
    }

    private function configManRequest(string $ip, array $query, string $user, string $pass, string $scheme): array
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

    private function buildConfigXml(string $extension, string $password, string $server, int $port, string $displayName, string $timezone, string $language, string $adminPassword): string
    {
        $lang = htmlspecialchars($this->mapLanguage($language), ENT_XML1);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<sys>';
        $xml .= '<sip line="1">';
        $xml .= '<Enable>1</Enable>';
        $xml .= '<PhoneNumber>' . htmlspecialchars($extension, ENT_XML1) . '</PhoneNumber>';
        $xml .= '<DisplayName>' . htmlspecialchars($displayName, ENT_XML1) . '</DisplayName>';
        $xml .= '<RegisterAddr>' . htmlspecialchars($server, ENT_XML1) . '</RegisterAddr>';
        $xml .= '<RegisterPort>' . $port . '</RegisterPort>';
        $xml .= '<Password>' . htmlspecialchars($password, ENT_XML1) . '</Password>';
        $xml .= '</sip>';
        $xml .= '<web>';
        $xml .= '<Language>' . $lang . '</Language>';
        $xml .= '</web>';
        $xml .= '<time>';
        $xml .= '<TimeZone>' . htmlspecialchars($timezone, ENT_XML1) . '</TimeZone>';
        $xml .= '</time>';
        if ($adminPassword !== '') {
            $xml .= '<admin>';
            $xml .= '<Password>' . htmlspecialchars($adminPassword, ENT_XML1) . '</Password>';
            $xml .= '</admin>';
        }
        $xml .= '</sys>';
        return $xml;
    }

    private function mapLanguage(string $lang): string
    {
        return match ($lang) {
            'en' => 'English',
            'ru' => 'Russian',
            'uz' => 'English',
            default => 'Russian',
        };
    }
}
