<?php
namespace FreePBX\modules\Exunity;

class Phonebook
{
	private $mod;
	private $freepbx;

	public function __construct($mod, $freepbx)
	{
		$this->mod = $mod;
		$this->freepbx = $freepbx;
	}

	public function handleHttpRequest(): void
	{
		if (!$this->isEnabled()) {
			http_response_code(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo "Phonebook disabled\n";
			return;
		}
		$token = (string) ($_GET['k'] ?? $_GET['token'] ?? '');
		if ($token === '' || !hash_equals($this->token(), $token)) {
			http_response_code(403);
			header('Content-Type: text/plain; charset=utf-8');
			echo "Forbidden\n";
			return;
		}
		$rendered = $this->render($this->requestFormat());
		$hash = hash('sha256', $rendered['body']);
		$etag = '"' . $hash . '"';
		$ifNone = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
		if ($ifNone !== '' && ($ifNone === $etag || str_contains($ifNone, $hash))) {
			http_response_code(304);
			header('ETag: ' . $etag);
			header('Cache-Control: private, max-age=60');
			return;
		}
		header('Content-Type: ' . $rendered['content_type']);
		header('ETag: ' . $etag);
		header('Cache-Control: private, max-age=60');
		echo $rendered['body'];
	}

	public function isEnabled(): bool
	{
		$stored = $this->mod->getConfig('phonebook_enabled');
		if ($stored === false || $stored === null || $stored === '') {
			return true;
		}
		return $stored === 'yes';
	}

	public function includeExtensions(): bool
	{
		$stored = $this->mod->getConfig('phonebook_include_extensions');
		if ($stored === false || $stored === null || $stored === '') {
			return true;
		}
		return $stored === 'yes';
	}

	public function bookName(): string
	{
		$name = trim((string) $this->mod->getConfig('phonebook_name'));
		return $name !== '' ? $name : 'Company';
	}

	public function token(): string
	{
		$token = trim((string) $this->mod->getConfig('phonebook_token'));
		if ($token === '') {
			$token = bin2hex(random_bytes(16));
			$this->mod->setConfig('phonebook_token', $token);
		}
		return $token;
	}

	public function urlFor(string $format): string
	{
		if (!$this->isEnabled()) {
			return '';
		}
		$base = rtrim($this->mod->provisionBaseUrl(), '/');
		$file = match ($format) {
			'grandstream' => 'grandstream.xml',
			'fanvil' => 'fanvil.xml',
			'microsip', 'json' => 'microsip.json',
			default => 'yealink.xml',
		};
		return $base . '/phonebook/' . $file . '?k=' . rawurlencode($this->token());
	}

	public function urlForVendor(?string $vendorCode): string
	{
		$code = strtolower((string) $vendorCode);
		return match ($code) {
			'grandstream' => $this->urlFor('grandstream'),
			'fanvil' => $this->urlFor('fanvil'),
			'microsip' => $this->urlFor('microsip'),
			default => $this->urlFor('yealink'),
		};
	}

	/** @return array<string,string> */
	public function publicUrls(): array
	{
		return [
			'yealink' => $this->urlFor('yealink'),
			'grandstream' => $this->urlFor('grandstream'),
			'fanvil' => $this->urlFor('fanvil'),
			'microsip' => $this->urlFor('microsip'),
		];
	}

	/** @return list<array{id:string,name:string,type:string}> */
	public function listPublicGroups(): array
	{
		$out = [];
		foreach ($this->cmGroups() as $g) {
			if ((int) ($g['owner'] ?? -1) !== -1) {
				continue;
			}
			$out[] = [
				'id' => (string) ($g['id'] ?? ''),
				'name' => (string) ($g['name'] ?? ''),
				'type' => (string) ($g['type'] ?? ''),
			];
		}
		return $out;
	}

	/** @return list<string> */
	public function selectedGroupIds(): array
	{
		$stored = $this->mod->getConfig('phonebook_groups');
		if ($stored === false || $stored === null || $stored === '') {
			$ids = [];
			foreach ($this->listPublicGroups() as $g) {
				$ids[] = $g['id'];
			}
			return $ids;
		}
		if (is_string($stored)) {
			$stored = json_decode($stored, true);
		}
		if (!is_array($stored)) {
			return [];
		}
		$out = [];
		foreach ($stored as $id) {
			$id = preg_replace('/\D+/', '', (string) $id);
			if ($id !== '') {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @return list<array{name:string,numbers:list<string>,mobile:string,other:string,first:string,last:string}>
	 */
	public function collectContacts(): array
	{
		$contacts = [];
		$seenNumbers = [];
		foreach ($this->selectedGroupIds() as $gid) {
			foreach ($this->entriesForGroup((int) $gid) as $entry) {
				$parsed = $this->contactFromEntry($entry);
				if ($parsed === null) {
					continue;
				}
				$contacts[] = $parsed;
				foreach ($parsed['numbers'] as $num) {
					$seenNumbers[$num] = true;
				}
			}
		}
		if ($this->includeExtensions()) {
			foreach ($this->coreExtensionContacts($seenNumbers) as $parsed) {
				$contacts[] = $parsed;
			}
		}
		usort($contacts, static function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});
		if (count($contacts) > 2000) {
			$contacts = array_slice($contacts, 0, 2000);
		}
		return $contacts;
	}

	/** @return array{body:string,content_type:string} */
	public function render(string $format): array
	{
		$contacts = $this->collectContacts();
		return match ($format) {
			'grandstream' => [
				'body' => $this->renderGrandstream($contacts),
				'content_type' => 'application/xml; charset=utf-8',
			],
			'fanvil' => [
				'body' => $this->renderFanvil($contacts),
				'content_type' => 'application/xml; charset=utf-8',
			],
			'microsip', 'json' => [
				'body' => $this->renderMicrosip($contacts),
				'content_type' => 'application/json; charset=utf-8',
			],
			'microsip-xml' => [
				'body' => $this->renderMicrosipXml($contacts),
				'content_type' => 'application/xml; charset=utf-8',
			],
			default => [
				'body' => $this->renderYealink($contacts),
				'content_type' => 'application/xml; charset=utf-8',
			],
		};
	}

	private function requestFormat(): string
	{
		$q = strtolower(trim((string) ($_GET['format'] ?? $_GET['phonebook'] ?? '')));
		if (in_array($q, ['yealink', 'grandstream', 'fanvil', 'json', 'microsip'], true)) {
			return $q === 'json' ? 'microsip' : $q;
		}
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
		if (preg_match('~/phonebook/(yealink|grandstream|fanvil|microsip|contacts)(?:\.(xml|json))?$~i', $path, $m)) {
			$name = strtolower($m[1]);
			$ext = strtolower($m[2] ?? '');
			if ($name === 'contacts' || $name === 'microsip') {
				return $ext === 'xml' ? 'microsip-xml' : 'microsip';
			}
			return $name;
		}
		return 'yealink';
	}

	/** @return list<array<string,mixed>> */
	private function cmGroups(): array
	{
		try {
			if (!$this->freepbx->Modules->checkStatus('contactmanager')) {
				return [];
			}
			return $this->freepbx->Contactmanager->getGroups(true) ?: [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	/** @return array<string,array<string,mixed>> */
	private function entriesForGroup(int $groupId): array
	{
		if ($groupId < 1) {
			return [];
		}
		try {
			if (!$this->freepbx->Modules->checkStatus('contactmanager')) {
				return [];
			}
			return $this->freepbx->Contactmanager->getEntriesByGroupID($groupId) ?: [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	/** @param array<string,mixed> $entry */
	private function contactFromEntry(array $entry): ?array
	{
		$numbers = [];
		$mobile = '';
		$other = '';
		foreach ($entry['numbers'] ?? [] as $row) {
			$num = $this->cleanNumber((string) ($row['number'] ?? ''));
			if ($num === '') {
				continue;
			}
			$numbers[] = $num;
			$type = strtolower((string) ($row['type'] ?? ''));
			if ($mobile === '' && in_array($type, ['cell', 'mobile', 'cell-phone'], true)) {
				$mobile = $num;
			} elseif ($other === '' && !in_array($type, ['internal', 'exten', 'extension'], true) && $num !== $mobile) {
				$other = $num;
			}
		}
		$def = $this->cleanNumber((string) ($entry['default_extension'] ?? ''));
		if ($def !== '') {
			array_unshift($numbers, $def);
		}
		$numbers = array_values(array_unique(array_filter($numbers)));
		if ($numbers === []) {
			return null;
		}
		$name = $this->entryName($entry);
		if ($name === '') {
			$name = $numbers[0];
		}
		$first = trim((string) ($entry['fname'] ?? ''));
		$last = trim((string) ($entry['lname'] ?? ''));
		if ($first === '' && $last === '') {
			$parts = preg_split('/\s+/', $name, 2) ?: [];
			$first = $parts[0] ?? $name;
			$last = $parts[1] ?? '';
		}
		if ($mobile === '' && isset($numbers[1])) {
			$mobile = $numbers[1];
		}
		return [
			'name' => $this->clip($name, 99),
			'first' => $this->clip($first !== '' ? $first : $name, 64),
			'last' => $this->clip($last, 64),
			'numbers' => $numbers,
			'mobile' => $mobile,
			'other' => $other,
		];
	}

	/** @param array<string,bool> $seenNumbers */
	private function coreExtensionContacts(array &$seenNumbers): array
	{
		$out = [];
		try {
			$users = $this->freepbx->Core->getAllUsers() ?: [];
		} catch (\Throwable $e) {
			return [];
		}
		foreach ($users as $user) {
			$ext = $this->cleanNumber((string) ($user['extension'] ?? ''));
			if ($ext === '' || isset($seenNumbers[$ext])) {
				continue;
			}
			$seenNumbers[$ext] = true;
			$name = trim((string) ($user['name'] ?? ''));
			if ($name === '') {
				$name = $ext;
			}
			$out[] = [
				'name' => $this->clip($name, 99),
				'first' => $this->clip($name, 64),
				'last' => '',
				'numbers' => [$ext],
				'mobile' => '',
				'other' => '',
			];
		}
		return $out;
	}

	/** @param array<string,mixed> $entry */
	private function entryName(array $entry): string
	{
		$display = trim((string) ($entry['displayname'] ?? ''));
		if ($display !== '') {
			return $display;
		}
		$full = trim(trim((string) ($entry['fname'] ?? '')) . ' ' . trim((string) ($entry['lname'] ?? '')));
		if ($full !== '') {
			return $full;
		}
		return trim((string) ($entry['company'] ?? ''));
	}

	private function cleanNumber(string $raw): string
	{
		$num = trim($raw);
		if ($num === '' || $num === 'none' || $num === 'nouser') {
			return '';
		}
		return $num;
	}

	private function clip(string $s, int $max): string
	{
		if (function_exists('mb_substr')) {
			return mb_substr($s, 0, $max);
		}
		return substr($s, 0, $max);
	}

	private function x(string $s): string
	{
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	/** @param list<array{name:string,numbers:list<string>,mobile:string,other:string,first:string,last:string}> $contacts */
	private function renderYealink(array $contacts): string
	{
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<YealinkIPPhoneDirectory>\n";
		foreach ($contacts as $c) {
			$xml .= "  <DirectoryEntry>\n";
			$xml .= '    <Name>' . $this->x($c['name']) . "</Name>\n";
			foreach ($c['numbers'] as $num) {
				$xml .= '    <Telephone>' . $this->x($num) . "</Telephone>\n";
			}
			$xml .= "  </DirectoryEntry>\n";
		}
		$xml .= "</YealinkIPPhoneDirectory>\n";
		return $xml;
	}

	/** @param list<array{name:string,numbers:list<string>,mobile:string,other:string,first:string,last:string}> $contacts */
	private function renderGrandstream(array $contacts): string
	{
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<AddressBook>\n";
		foreach ($contacts as $c) {
			$xml .= "  <Contact>\n";
			$xml .= '    <FirstName>' . $this->x($c['first']) . "</FirstName>\n";
			$xml .= '    <LastName>' . $this->x($c['last']) . "</LastName>\n";
			foreach ($c['numbers'] as $i => $num) {
				$type = $i === 0 ? 'Work' : ($num === $c['mobile'] ? 'Cell' : 'Other');
				$xml .= '    <Phone type="' . $type . "\">\n";
				$xml .= '      <phonenumber>' . $this->x($num) . "</phonenumber>\n";
				$xml .= "      <accountindex>1</accountindex>\n";
				$xml .= "    </Phone>\n";
			}
			$xml .= "  </Contact>\n";
		}
		$xml .= "</AddressBook>\n";
		return $xml;
	}

	/** @param list<array{name:string,numbers:list<string>,mobile:string,other:string,first:string,last:string}> $contacts */
	private function renderFanvil(array $contacts): string
	{
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<PhoneBook>\n";
		$xml .= '  <Group name="' . $this->x($this->bookName()) . "\">\n";
		foreach ($contacts as $c) {
			$tel = $c['numbers'][0] ?? '';
			$mobile = $c['mobile'] !== '' && $c['mobile'] !== $tel ? $c['mobile'] : '';
			$other = $c['other'] !== '' && $c['other'] !== $tel && $c['other'] !== $mobile ? $c['other'] : '';
			$xml .= "    <Contact>\n";
			$xml .= '      <Name>' . $this->x($c['name']) . "</Name>\n";
			$xml .= '      <Telephone>' . $this->x($tel) . "</Telephone>\n";
			$xml .= '      <Mobile>' . $this->x($mobile) . "</Mobile>\n";
			$xml .= '      <Other>' . $this->x($other) . "</Other>\n";
			$xml .= "    </Contact>\n";
		}
		$xml .= "  </Group>\n</PhoneBook>\n";
		return $xml;
	}

	/** @param list<array{name:string,numbers:list<string>,mobile:string,other:string,first:string,last:string}> $contacts */
	private function microsipItems(array $contacts): array
	{
		$items = [];
		$seen = [];
		foreach ($contacts as $c) {
			$number = $c['numbers'][0] ?? '';
			if ($number === '' || isset($seen[$number])) {
				continue;
			}
			$seen[$number] = true;
			$mobile = ($c['mobile'] !== '' && $c['mobile'] !== $number) ? $c['mobile'] : '';
			$items[] = [
				'number' => $number,
				'name' => $c['name'],
				'firstname' => $c['first'],
				'lastname' => $c['last'],
				'phone' => $number,
				'mobile' => $mobile,
				'email' => '',
				'address' => '',
				'city' => '',
				'state' => '',
				'zip' => '',
				'comment' => '',
				'presence' => 0,
				'starred' => 0,
				'info' => '',
			];
		}
		return $items;
	}

	/** @param list<array{name:string,numbers:list<string>,mobile:string,other:string,first:string,last:string}> $contacts */
	private function renderMicrosip(array $contacts): string
	{
		$json = json_encode([
			'refresh' => 300,
			'silent' => 1,
			'items' => $this->microsipItems($contacts),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		return ($json !== false ? $json : '{"refresh":300,"silent":1,"items":[]}') . "\n";
	}

	/** @param list<array{name:string,numbers:list<string>,mobile:string,other:string,first:string,last:string}> $contacts */
	private function renderMicrosipXml(array $contacts): string
	{
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<contacts refresh=\"300\" silent=\"1\">\n";
		foreach ($this->microsipItems($contacts) as $item) {
			$xml .= '<contact';
			foreach ($item as $key => $val) {
				$xml .= ' ' . $key . '="' . $this->x((string) $val) . '"';
			}
			$xml .= "/>\n";
		}
		$xml .= "</contacts>\n";
		return $xml;
	}
}
