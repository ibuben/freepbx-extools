<?php
namespace FreePBX\modules\Exunity;

use PDO;

class Provision
{
	private $mod;

	public function __construct($mod)
	{
		$this->mod = $mod;
	}

	public function handleRequest(): void
	{
		if ($this->isPhonebookRequest()) {
			$this->mod->phonebook()->handleHttpRequest();
			return;
		}
		$cfgBasename = $_GET['mac'] ?? $this->extractCfgBasename() ?? '';
		$ip = $this->clientIp();
		$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$mac = $this->resolveMac($cfgBasename !== '' ? $cfgBasename : null, $userAgent);
		if (!$mac) {
			http_response_code(400);
			header('Content-Type: text/plain; charset=utf-8');
			echo "Invalid MAC address\n";
			return;
		}

		$uaInfo = $this->parseClientInfo($userAgent);
		if (empty($uaInfo['mac'])) {
			$uaInfo['mac'] = $mac;
		}
		$phone = $this->mod->registerPhoneFromProvision($mac, $ip, $uaInfo, $userAgent);
		$rendered = $this->mod->renderPhoneConfig($phone);
		$result = $this->mod->finalizeProvisionConfig($phone, $rendered);

		$phoneId = (int) $phone['id'];
		if (!empty($result['pending'])) {
			if (!empty($result['changed'])) {
				$this->mod->markProvisionDelivery($phoneId, $result['hash'], _('Waiting for SIP assignment'));
			} else {
				$this->mod->setPhoneProvisionStatus($phoneId, _('Waiting for SIP assignment'));
			}
		} elseif (!empty($result['changed'])) {
			$this->mod->markProvisionDelivery($phoneId, $result['hash'], _('Configuration updated'));
		} else {
			$this->mod->setPhoneProvisionStatus($phoneId, _('Unchanged'));
		}

		$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
		if (empty($result['changed']) && $ifNoneMatch !== '' && ($ifNoneMatch === $result['etag'] || str_contains($ifNoneMatch, $result['hash']))) {
			http_response_code(304);
			header('ETag: ' . $result['etag']);
			header('Cache-Control: private, max-age=0');
			return;
		}

		header('Content-Type: ' . $result['content_type']);
		header('ETag: ' . ($result['etag'] ?? ''));
		header('Cache-Control: private, max-age=0');
		echo $result['body'];
	}

	private function isPhonebookRequest(): bool
	{
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
		if (preg_match('~/provision/phonebook(?:/|\.xml|\.json|$)~i', $path)) {
			return true;
		}
		return isset($_GET['phonebook']);
	}

	public function extractCfgBasename(): ?string
	{
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$path = parse_url($uri, PHP_URL_PATH) ?? $uri;
		if (preg_match('~/([^/?#]+)\.cfg~i', $path, $m)) {
			return $m[1];
		}
		if (preg_match('~/cfg([a-f0-9]{12})(?:\.xml)?$~i', $path, $m)) {
			return $m[1];
		}
		return null;
	}

	public function clientIp(): string
	{
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
		}
		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	public function resolveMac(?string $cfgBasename, string $userAgent): ?string
	{
		if ($cfgBasename) {
			$mac = $this->mod->normalizeMac($cfgBasename);
			if ($mac) {
				return $mac;
			}
		}
		$uaInfo = $this->mod->parsePhoneUserAgent($userAgent);
		return $uaInfo['mac'] ?? null;
	}

	public function parseClientInfo(string $userAgent): array
	{
		$info = $this->mod->parsePhoneUserAgent($userAgent);
		$model = trim((string) ($_GET['model'] ?? ''));
		$version = trim((string) ($_GET['version'] ?? ''));
		$mac = trim((string) ($_GET['mac'] ?? ''));
		if (strcasecmp($model, 'MicroSIP') === 0) {
			$info['vendor_code'] = 'microsip';
			$info['vendor_name'] = 'MicroSIP';
			$info['model'] = 'MicroSIP';
		}
		if ($version !== '') {
			$info['firmware'] = $version;
		}
		if ($mac !== '') {
			$normalized = $this->mod->normalizeMac($mac);
			if ($normalized) {
				$info['mac'] = $normalized;
			}
		}
		return $info;
	}
}
