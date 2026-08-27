<?php
namespace FreePBX\modules\Exunity;

class StickyAgent
{
	private $mod;
	private $freepbx;

	public function __construct($mod, $freepbx)
	{
		$this->mod = $mod;
		$this->freepbx = $freepbx;
	}

	public function lookup(string $queue, string $caller): array
	{
		$empty = ['agent' => '', 'dial' => '', 'timeout' => '15'];
		$settings = $this->mod->getAllSettings();
		$queue = preg_replace('/\D+/', '', $queue) ?? '';
		if ($queue === '' || !$this->mod->isStickyEnabledForQueue($queue)) {
			return $empty;
		}
		$timeout = (int) ($settings['sticky_timeout'] ?? 15);
		if ($timeout < 5) {
			$timeout = 5;
		}
		if ($timeout > 60) {
			$timeout = 60;
		}
		$empty['timeout'] = (string) $timeout;
		$callerDigits = $this->digits($caller);
		if ($queue === '' || strlen($callerDigits) < 7) {
			return $empty;
		}
		$members = $this->queueMemberExtens($queue);
		$agent = $this->lastAgentFromCdr($callerDigits, $queue, $members, (int) ($settings['sticky_days'] ?? 90));
		if ($agent === '' || $agent === $queue) {
			return $empty;
		}
		if ($this->isAgentPausedOrOffline($queue, $agent)) {
			return $empty;
		}
		$dial = $this->dialString($agent);
		if ($dial === '') {
			return $empty;
		}
		return ['agent' => $agent, 'dial' => $dial, 'timeout' => (string) $timeout];
	}

	public function lastAgentFromCdr(string $callerDigits, string $queue, array $members, int $days): string
	{
		$db = $this->mod->cdrDatabase();
		if (!$db || $callerDigits === '') {
			return '';
		}
		if ($days < 1) {
			$days = 90;
		}
		$tail = substr($callerDigits, -8);
		$sth = $db->prepare(
			'SELECT calldate, src, dst, cnum, outbound_cnum, dcontext, lastapp, lastdata, channel, dstchannel, billsec, disposition
			FROM cdr
			WHERE calldate >= DATE_SUB(NOW(), INTERVAL :days DAY)
				AND disposition = "ANSWERED"
				AND billsec >= 3
				AND (src LIKE :q OR dst LIKE :q OR cnum LIKE :q OR outbound_cnum LIKE :q)
			ORDER BY calldate DESC
			LIMIT 80'
		);
		$sth->execute([':days' => $days, ':q' => '%' . $tail]);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		foreach ($rows as $row) {
			$agent = $this->agentFromRow($row, $callerDigits, $queue, $members);
			if ($agent !== '') {
				return $agent;
			}
		}
		return '';
	}

	private function agentFromRow(array $row, string $callerDigits, string $queue, array $members): string
	{
		$src = $this->digits((string) ($row['src'] ?? ''));
		$dst = $this->digits((string) ($row['dst'] ?? ''));
		$cnum = $this->digits((string) ($row['cnum'] ?? ''));
		$ocnum = $this->digits((string) ($row['outbound_cnum'] ?? ''));
		$callerIsSrc = $this->sameNumber($callerDigits, $src) || $this->sameNumber($callerDigits, $cnum);
		$callerIsDst = $this->sameNumber($callerDigits, $dst) || $this->sameNumber($callerDigits, $ocnum);
		if (!$callerIsSrc && !$callerIsDst) {
			return '';
		}

		$localAgent = $this->fromQueueLocal((string) ($row['dstchannel'] ?? ''))
			?: $this->fromQueueLocal((string) ($row['channel'] ?? ''));

		$agent = '';
		if ($callerIsSrc) {
			if ($localAgent !== '' && $localAgent !== $queue) {
				$agent = $localAgent;
			} elseif ($dst !== '' && $dst !== $queue && $this->looksLikeExten($dst)
				&& (($row['lastapp'] ?? '') === 'Dial' || strtolower((string) ($row['dcontext'] ?? '')) === 'ext-local')) {
				$agent = $dst;
			}
		} elseif ($callerIsDst && $this->looksLikeExten($src) && $src !== $queue) {
			$agent = $src;
		}

		if ($agent === '') {
			return '';
		}
		if ($this->rowTouchesQueue($row, $queue)) {
			return $agent;
		}
		if ($members && in_array($agent, $members, true)) {
			return $agent;
		}
		if (!$members && $localAgent !== '') {
			return $agent;
		}
		return '';
	}

	private function rowTouchesQueue(array $row, string $queue): bool
	{
		if ($queue === '') {
			return false;
		}
		$dst = $this->digits((string) ($row['dst'] ?? ''));
		$data = (string) ($row['lastdata'] ?? '');
		$app = (string) ($row['lastapp'] ?? '');
		if ($app === 'Queue' && ($dst === $queue || str_starts_with($data, $queue))) {
			return true;
		}
		$ch = (string) ($row['channel'] ?? '') . ' ' . (string) ($row['dstchannel'] ?? '');
		return str_contains($ch, '@from-queue') && $dst === $queue;
	}

	public function queueMemberExtens(string $queue): array
	{
		$out = [];
		try {
			$sth = $this->freepbx->Database->prepare('SELECT data FROM queues_details WHERE id = ? AND keyword = ?');
			$sth->execute([$queue, 'member']);
			foreach ($sth->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $data) {
				$ext = $this->memberExten((string) $data);
				if ($ext !== '') {
					$out[$ext] = true;
				}
			}
		} catch (\Throwable $e) {
		}
		try {
			$astman = $this->freepbx->astman;
			if ($astman && !empty($astman->connected())) {
				$res = $astman->Command('queue show ' . $queue);
				$text = is_array($res) ? (string) ($res['data'] ?? '') : (string) $res;
				if (preg_match_all('/^\s*(\d+)\s+\(/m', $text, $m)) {
					foreach ($m[1] as $ext) {
						$out[$ext] = true;
					}
				}
				if (preg_match_all('#Local/([^@/\s]+)@from-queue#', $text, $m)) {
					foreach ($m[1] as $ext) {
						$out[$ext] = true;
					}
				}
			}
		} catch (\Throwable $e) {
		}
		return array_keys($out);
	}

	private function isAgentPausedOrOffline(string $queue, string $agent): bool
	{
		try {
			$astman = $this->freepbx->astman;
			if ($astman && !empty($astman->connected())) {
				$res = $astman->Command('queue show ' . $queue);
				$text = is_array($res) ? (string) ($res['data'] ?? '') : (string) $res;
				if (preg_match('/^\s*' . preg_quote($agent, '/') . '\s+\([^\n]*paused/im', $text)) {
					return true;
				}
			}
		} catch (\Throwable $e) {
		}
		return false;
	}

	private function dialString(string $agent): string
	{
		try {
			$dev = $this->freepbx->Core->getDevice($agent);
			if (!empty($dev['dial'])) {
				return (string) $dev['dial'];
			}
		} catch (\Throwable $e) {
		}
		return 'PJSIP/' . $agent;
	}

	private function memberExten(string $data): string
	{
		if (preg_match('#Local/([^@/,]+)@from-queue#', $data, $m)) {
			return $this->digits($m[1]);
		}
		if (preg_match('#(?:PJSIP|SIP|IAX2)/([^@/,]+)#', $data, $m)) {
			return $this->digits($m[1]);
		}
		if (preg_match('/^(\d+)/', $data, $m)) {
			return $m[1];
		}
		return '';
	}

	private function fromQueueLocal(string $channel): string
	{
		if (preg_match('#Local/([^@/;]+)@from-queue#i', $channel, $m)) {
			return $this->digits($m[1]);
		}
		return '';
	}

	private function looksLikeExten(string $num): bool
	{
		return $num !== '' && preg_match('/^[0-9]{2,8}$/', $num);
	}

	private function sameNumber(string $a, string $b): bool
	{
		if ($a === '' || $b === '') {
			return false;
		}
		if ($a === $b) {
			return true;
		}
		$len = min(strlen($a), strlen($b));
		if ($len < 8) {
			return false;
		}
		return str_ends_with($a, $b) || str_ends_with($b, $a);
	}

	private function digits(string $n): string
	{
		return preg_replace('/\D+/', '', $n) ?? '';
	}
}
