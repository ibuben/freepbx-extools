<?php

namespace PhoneManager;

/**
 * JSON auto-provision для кастомного MicroSIP.
 * Клиент запрашивает /{MAC}.cfg?mac=&client=&version=&model=MicroSIP
 * Аккаунт сопоставляется по label: существующий обновляется, новый — добавляется.
 */
class MicroSIPDriver extends AbstractDriver
{
    public function getCode(): string
    {
        return 'microsip';
    }

    public function getName(): string
    {
        return 'MicroSIP';
    }

    public function detect(string $ip, string $user = 'admin', string $pass = 'admin'): bool
    {
        return false;
    }

    public function getInfo(string $ip, string $user = 'admin', string $pass = 'admin'): array
    {
        return [
            'vendor' => $this->getCode(),
            'model' => 'MicroSIP',
            'mac' => null,
            'firmware' => null,
        ];
    }

    public function provision(string $ip, array $config, string $user = 'admin', string $pass = 'admin'): array
    {
        $url = trim($config['provision_url'] ?? '');
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Не задан URL провиженинга для MicroSIP',
            ];
        }

        return [
            'success' => true,
            'message' => 'JSON готов. MicroSIP загрузит /{MAC}.cfg (provisionUrl / DHCP option 43): ' . $url,
        ];
    }

    public function generateCfg(array $config): string
    {
        $json = json_encode(
            $this->buildProvisionPayload($config),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return ($json !== false ? $json : '{}') . "\n";
    }

    public function cfgContentType(): string
    {
        return 'application/json; charset=utf-8';
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function buildProvisionPayload(array $config): array
    {
        $payload = ['account' => $this->buildAccount($config)];
        $url = trim((string) ($config['phonebook_microsip_url'] ?? $config['phonebook_url'] ?? ''));
        if ($url !== '') {
            $payload['usersDirectory'] = $url;
        }
        return $payload;
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function buildAccount(array $config): array
    {
        $extension = (string) ($config['sip_extension'] ?? '');
        $displayName = trim((string) ($config['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $extension;
        }

        $label = trim((string) ($config['account_label'] ?? ''));
        if ($label === '') {
            $label = 'Office';
        }

        $authId = trim((string) ($config['sip_auth_id'] ?? ''));

        return [
            'label' => $label,
            'server' => $this->formatServer($config),
            'proxy' => (string) ($config['sip_proxy'] ?? ''),
            'domain' => $this->formatDomain($config),
            'username' => $extension,
            'password' => (string) ($config['sip_password'] ?? ''),
            'authID' => $authId,
            'displayName' => $displayName,
            'transport' => $this->mapTransport((string) ($config['sip_transport'] ?? 'udp')),
            'srtp' => (string) ($config['srtp'] ?? ''),
            'registerRefresh' => (int) ($config['register_refresh'] ?? 300),
            'keepAlive' => (int) ($config['keep_alive'] ?? 15),
            'publish' => false,
            'ice' => false,
            'allowRewrite' => true,
            'disableSessionTimer' => false,
            'voicemailNumber' => (string) ($config['voicemail'] ?? ''),
            'dialingPrefix' => (string) ($config['dialing_prefix'] ?? ''),
            'dialPlan' => (string) ($config['dial_plan'] ?? ''),
            'hideCID' => false,
            'publicAddr' => (string) ($config['public_addr'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $config */
    private function formatServer(array $config): string
    {
        $host = trim((string) ($config['sip_server'] ?? ''));
        $port = (int) ($config['sip_port'] ?? 5060);
        if ($host === '') {
            return '';
        }
        if ($port > 0 && $port !== 5060 && !str_contains($host, ':')) {
            return $host . ':' . $port;
        }

        return $host;
    }

    /** @param array<string, mixed> $config */
    private function formatDomain(array $config): string
    {
        $domain = trim((string) ($config['sip_domain'] ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        $host = trim((string) ($config['sip_server'] ?? ''));
        if (str_contains($host, ':')) {
            return explode(':', $host, 2)[0];
        }

        return $host;
    }

    private function mapTransport(string $transport): string
    {
        return match (strtolower(trim($transport))) {
            'tcp' => 'tcp',
            'tls' => 'tls',
            'udp+tcp', 'tcp+udp' => 'udp+tcp',
            default => 'udp',
        };
    }
}
