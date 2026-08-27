<?php
namespace FreePBX\modules\Exunity;

class VoicemailTelegram
{
	private $mod;
	private $freepbx;

	public function __construct($mod, $freepbx)
	{
		$this->mod = $mod;
		$this->freepbx = $freepbx;
	}

	public function handleCli(array $argv): void
	{
		$context = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($argv[1] ?? 'default'));
		$mailbox = preg_replace('/\D+/', '', (string) ($argv[2] ?? ''));
		$new = (int) ($argv[3] ?? 0);
		if ($mailbox === '' || $new < 1) {
			return;
		}
		if (($this->mod->getAllSettings()['tg_vm'] ?? 'yes') !== 'yes') {
			return;
		}
		usleep(400000);
		$this->notifyMailbox($context !== '' ? $context : 'default', $mailbox);
	}

	public function scriptPath(): string
	{
		$dir = $this->freepbx->Config->get('AMPBIN') ?: '/var/lib/asterisk/bin';
		return rtrim((string) $dir, '/') . '/exunity_vmnotify.sh';
	}

	public function ensureScript(): void
	{
		$srcPhp = __DIR__ . '/agi-bin/exunity_vmnotify.php';
		if (!is_file($srcPhp)) {
			return;
		}
		$dest = $this->scriptPath();
		$dir = dirname($dest);
		if (!is_dir($dir)) {
			return;
		}
		$body = "#!/bin/bash\n"
			. "nohup php -q " . escapeshellarg($srcPhp) . ' "$@" >/dev/null 2>&1 &' . "\n"
			. "exit 0\n";
		$existing = is_file($dest) ? (string) file_get_contents($dest) : '';
		if ($existing !== $body) {
			file_put_contents($dest, $body);
		}
		@chmod($dest, 0755);
		if (function_exists('posix_getpwnam')) {
			$pw = posix_getpwnam('asterisk');
			if ($pw) {
				@chown($dest, 'asterisk');
				@chgrp($dest, 'asterisk');
			}
		}
	}

	public function ensureExternNotify(): void
	{
		$this->ensureScript();
		if (($this->mod->getAllSettings()['tg_vm'] ?? 'yes') !== 'yes') {
			$this->clearOurExternNotify();
			return;
		}
		try {
			if (!$this->freepbx->Modules->checkStatus('voicemail')) {
				return;
			}
			$vm = $this->freepbx->Voicemail->getVoicemail(false);
			$current = trim((string) ($vm['general']['externnotify'] ?? ''));
			$ours = $this->scriptPath();
			if ($current !== '' && !str_contains($current, 'exunity_vmnotify')) {
				return;
			}
			if ($current === $ours) {
				return;
			}
			$vm['general']['externnotify'] = $ours;
			$this->freepbx->Voicemail->saveVoicemail($vm, true);
		} catch (\Throwable $e) {
			dbug('exunity vmnotify: ' . $e->getMessage());
		}
	}

	public function clearOurExternNotify(): void
	{
		try {
			if (!$this->freepbx->Modules->checkStatus('voicemail')) {
				return;
			}
			$vm = $this->freepbx->Voicemail->getVoicemail(false);
			$current = trim((string) ($vm['general']['externnotify'] ?? ''));
			if ($current === '' || !str_contains($current, 'exunity_vmnotify')) {
				return;
			}
			unset($vm['general']['externnotify']);
			$this->freepbx->Voicemail->saveVoicemail($vm, true);
		} catch (\Throwable $e) {
			// voicemail module may be absent during uninstall
		}
	}

	private function notifyMailbox(string $context, string $mailbox): void
	{
		$row = $this->mod->getExtenTelegram($mailbox);
		if (empty($row['enabled']) || ($row['chatid'] ?? '') === '') {
			return;
		}
		$meta = $this->latestMessage($context, $mailbox);
		if ($meta === null) {
			return;
		}
		$caption = $this->mod->renderTelegramTemplate('vm', [
			'callerid' => $meta['callerid'],
			'callername' => $meta['callername'],
			'extension' => $mailbox,
			'did' => '',
			'diversion' => '',
			'diversion_reason' => '',
			'rdnis' => '',
			'duration' => (string) $meta['duration'],
			'time' => $meta['time'] !== '' ? $meta['time'] : date('Y-m-d H:i:s'),
		]);
		$this->mod->sendTelegramVoice($row['chatid'], $meta['audio'], $caption);
	}

	/** @return array{audio:string,callerid:string,callername:string,duration:int,time:string}|null */
	private function latestMessage(string $context, string $mailbox): ?array
	{
		$spool = $this->freepbx->Config->get('ASTSPOOLDIR') ?: '/var/spool/asterisk';
		$inbox = rtrim((string) $spool, '/') . '/voicemail/' . $context . '/' . $mailbox . '/INBOX';
		if (!is_dir($inbox)) {
			$inbox = rtrim((string) $spool, '/') . '/voicemail/default/' . $mailbox . '/INBOX';
		}
		if (!is_dir($inbox)) {
			return null;
		}
		$txts = glob($inbox . '/msg*.txt') ?: [];
		if ($txts === []) {
			return null;
		}
		usort($txts, static function ($a, $b) {
			return filemtime($a) <=> filemtime($b);
		});
		$txt = end($txts);
		$base = preg_replace('/\.txt$/i', '', (string) $txt);
		$audio = $this->audioFor($base);
		if ($audio === null) {
			return null;
		}
		$info = $this->parseMessageTxt((string) $txt);
		$info['audio'] = $audio;
		return $info;
	}

	private function audioFor(string $base): ?string
	{
		foreach (['wav', 'WAV', 'wav49', 'gsm', 'mp3', 'ogg'] as $ext) {
			$path = $base . '.' . $ext;
			if (is_file($path) && filesize($path) > 44) {
				return $path;
			}
		}
		return null;
	}

	/** @return array{callerid:string,callername:string,duration:int,time:string} */
	private function parseMessageTxt(string $path): array
	{
		$out = [
			'callerid' => '',
			'callername' => '',
			'duration' => 0,
			'time' => '',
		];
		$raw = @file_get_contents($path);
		if (!is_string($raw) || $raw === '') {
			return $out;
		}
		if (preg_match('/^callerid=(.*)$/mi', $raw, $m)) {
			$cid = trim($m[1]);
			if (preg_match('/^"([^"]*)"\s*<([^>]+)>/', $cid, $p)) {
				$out['callername'] = $p[1];
				$out['callerid'] = $p[2];
			} elseif (preg_match('/<([^>]+)>/', $cid, $p)) {
				$out['callerid'] = $p[1];
				$out['callername'] = trim(str_replace($p[0], '', $cid), " \t\"'");
			} else {
				$out['callerid'] = trim($cid, " \t\"'");
			}
		}
		if (preg_match('/^duration=(\d+)/mi', $raw, $m)) {
			$out['duration'] = (int) $m[1];
		}
		if (preg_match('/^origdate=(.*)$/mi', $raw, $m)) {
			$out['time'] = trim($m[1]);
		}
		return $out;
	}
}
