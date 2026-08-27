<?php
namespace FreePBX\modules\Exunity;

class CallHistory
{
	private $mod;
	private $freepbx;
	/** @var array<string,string> */
	private $extNames = [];
	/** @var array<string,string> */
	private $queueNames = [];
	/** @var array<string,string> */
	private $rgNames = [];

	private const JUNK_DST = [
		's' => true, 't' => true, 'i' => true, 'h' => true, 'hangup' => true,
		'start' => true, 'talk' => true, 'in' => true, 'out' => true,
		'from-did-direct' => true, 'ext-local' => true, 'ext-queues' => true,
		'from-queue' => true, 'macro-dial' => true, 'macro-dial-one' => true,
		'playback' => true, 'noloop' => true, 'recordcheck' => true,
		'bye' => true, 'failed' => true, 'busy' => true, 'no-answer' => true,
	];

	public function __construct($mod, $freepbx)
	{
		$this->mod = $mod;
		$this->freepbx = $freepbx;
		$this->loadDirectories();
	}

	public function listCalls(array $req): array
	{
		$from = $this->parseDate($req['date_from'] ?? '', date('Y-m-d', strtotime('-6 days')) . ' 00:00:00', true);
		$to = $this->parseDate($req['date_to'] ?? '', date('Y-m-d') . ' 23:59:59', false);
		$direction = (string) ($req['direction'] ?? 'all');
		$q = trim((string) ($req['search'] ?? $req['q'] ?? ''));
		$offset = max(0, (int) ($req['offset'] ?? 0));
		$limit = (int) ($req['limit'] ?? 50);
		if ($limit < 10) {
			$limit = 10;
		}
		if ($limit > 200) {
			$limit = 200;
		}

		$raw = $this->fetchRaw($from, $to, $q);
		$calls = [];
		foreach ($this->collapse($raw) as $call) {
			if ($direction === 'missed') {
				if ($call['status'] !== 'missed') {
					continue;
				}
			} elseif ($direction !== 'all' && $call['direction'] !== $direction) {
				continue;
			}
			if ($q !== '' && !$this->callMatchesSearch($call, $q)) {
				continue;
			}
			$calls[] = $call;
		}

		$total = count($calls);
		$page = array_slice($calls, $offset, $limit);
		$rows = [];
		foreach ($page as $call) {
			$rows[] = $this->present($call);
		}
		return ['total' => $total, 'rows' => $rows];
	}

	public function findRecordingPath(string $uniqueid): string
	{
		$uniqueid = trim($uniqueid);
		if ($uniqueid === '') {
			return '';
		}
		$db = $this->mod->cdrDatabase();
		if (!$db) {
			return '';
		}
		$talk = $db->prepare('SELECT MAX(billsec) FROM cdr WHERE (uniqueid = :uid OR linkedid = :uid) AND disposition = "ANSWERED"');
		$talk->execute([':uid' => $uniqueid]);
		if ((int) $talk->fetchColumn() < 3) {
			return '';
		}
		$sth = $db->prepare('SELECT recordingfile FROM cdr WHERE (uniqueid = :uid OR linkedid = :uid) AND recordingfile IS NOT NULL AND recordingfile <> "" ORDER BY billsec DESC LIMIT 1');
		$sth->execute([':uid' => $uniqueid]);
		$file = (string) $sth->fetchColumn();
		return $this->recordingPathOnDisk($file);
	}

	private function recordingExistsOnDisk(string $file): bool
	{
		return $this->recordingPathOnDisk($file) !== '';
	}

	private function recordingPathOnDisk(string $file): string
	{
		static $cache = [];
		$file = trim($file);
		if ($file === '') {
			return '';
		}
		if (array_key_exists($file, $cache)) {
			return $cache[$file];
		}
		foreach ($this->mod->recordingPathsFromCdr($file) as $path) {
			if ($this->mod->isSafeRecordingPath($path) && is_file($path) && filesize($path) > 44) {
				return $cache[$file] = $path;
			}
		}
		return $cache[$file] = '';
	}

	public function streamRecording(string $uniqueid, bool $download = false): bool
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		@ini_set('zlib.output_compression', '0');
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}

		$path = $this->findRecordingPath($uniqueid);
		if ($path === '' || !is_readable($path)) {
			header('HTTP/1.0 404 Not Found');
			echo 'Not found';
			return true;
		}
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$mime = match ($ext) {
			'mp3' => 'audio/mpeg',
			'wav', 'wave' => 'audio/wav',
			'gsm' => 'audio/x-gsm',
			'ogg', 'oga' => 'audio/ogg',
			'm4a' => 'audio/mp4',
			default => 'application/octet-stream',
		};
		$size = filesize($path);
		header('Content-Type: ' . $mime);
		header('Accept-Ranges: bytes');
		header('Cache-Control: private, max-age=0, must-revalidate');
		$filename = basename($path);
		if ($download) {
			header('Content-Disposition: attachment; filename="' . $filename . '"');
		} else {
			header('Content-Disposition: inline; filename="' . $filename . '"');
		}

		$start = 0;
		$end = $size - 1;
		if (!$download && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
			$start = (int) $m[1];
			if ($m[2] !== '') {
				$end = (int) $m[2];
			}
			if ($start > $end || $start >= $size) {
				http_response_code(416);
				header('Content-Range: bytes */' . $size);
				return true;
			}
			http_response_code(206);
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
		}
		$length = $end - $start + 1;
		header('Content-Length: ' . $length);
		$fp = fopen($path, 'rb');
		if ($fp === false) {
			return true;
		}
		if ($start > 0) {
			fseek($fp, $start);
		}
		$left = $length;
		while ($left > 0 && !feof($fp)) {
			$chunk = fread($fp, min(8192, $left));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			$left -= strlen($chunk);
		}
		fclose($fp);
		return true;
	}

	private function fetchRaw(string $from, string $to, string $q): array
	{
		$db = $this->mod->cdrDatabase();
		if (!$db) {
			return [];
		}
		$sql = 'SELECT calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata,
				duration, billsec, disposition, uniqueid, recordingfile, cnum, cnam,
				outbound_cnum, outbound_cnam, dst_cnam, did, linkedid, sequence
			FROM cdr
			WHERE calldate >= :from AND calldate <= :to';
		$params = [':from' => $from, ':to' => $to];
		if ($q !== '') {
			$sql .= ' AND (src LIKE :q OR dst LIKE :q OR cnum LIKE :q OR did LIKE :q
				OR clid LIKE :q OR outbound_cnum LIKE :q OR dstchannel LIKE :q OR channel LIKE :q)';
			$params[':q'] = '%' . $q . '%';
		}
		$sql .= ' ORDER BY calldate DESC LIMIT 15000';
		$sth = $db->prepare($sql);
		$sth->execute($params);
		return $sth->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	private function collapse(array $rows): array
	{
		$byLink = [];
		$fileToLinks = [];
		foreach ($rows as $i => $row) {
			$lid = trim((string) ($row['linkedid'] ?? ''));
			if ($lid === '') {
				$lid = trim((string) ($row['uniqueid'] ?? '')) ?: ('row' . $i);
			}
			$byLink[$lid][] = $row;
			$file = $this->recordingKey($row['recordingfile'] ?? '');
			if ($file !== '') {
				$fileToLinks[$file][$lid] = true;
			}
		}

		$used = [];
		$calls = [];
		foreach ($byLink as $lid => $legs) {
			if (isset($used[$lid])) {
				continue;
			}
			$related = [$lid => true];
			$files = [];
			foreach ($legs as $leg) {
				$file = $this->recordingKey($leg['recordingfile'] ?? '');
				if ($file !== '') {
					$files[$file] = true;
				}
			}
			$changed = true;
			while ($changed) {
				$changed = false;
				foreach (array_keys($files) as $file) {
					foreach (array_keys($fileToLinks[$file] ?? []) as $other) {
						if (isset($related[$other])) {
							continue;
						}
						$related[$other] = true;
						$changed = true;
						foreach ($byLink[$other] as $leg) {
							$f = $this->recordingKey($leg['recordingfile'] ?? '');
							if ($f !== '') {
								$files[$f] = true;
							}
						}
					}
				}
			}

			$all = [];
			foreach (array_keys($related) as $id) {
				$used[$id] = true;
				foreach ($byLink[$id] as $leg) {
					$all[] = $leg;
				}
			}
			$call = $this->summarize($all);
			if ($call !== null) {
				$calls[] = $call;
			}
		}

		usort($calls, static function ($a, $b) {
			return strcmp($b['calldate'], $a['calldate']);
		});
		return $calls;
	}

	private function summarize(array $legs): ?array
	{
		if (!$legs) {
			return null;
		}
		$meaningful = [];
		foreach ($legs as $leg) {
			if ($this->isMeaningfulLeg($leg)) {
				$meaningful[] = $leg;
			}
		}
		if (!$meaningful) {
			return null;
		}

		$best = $meaningful[0];
		$bestScore = -99999;
		foreach ($meaningful as $leg) {
			$score = $this->scoreLeg($leg);
			if ($score > $bestScore) {
				$bestScore = $score;
				$best = $leg;
			}
		}

		$answered = [];
		$recording = '';
		$recordingUid = '';
		$maxBill = 0;
		$maxDur = 0;
		$minDate = $best['calldate'];
		$busy = false;
		$failed = false;
		$queueNum = '';
		foreach ($legs as $leg) {
			if ($leg['calldate'] < $minDate) {
				$minDate = $leg['calldate'];
			}
			$bill = (int) $leg['billsec'];
			$dur = (int) $leg['duration'];
			if ($bill > $maxBill) {
				$maxBill = $bill;
			}
			if ($dur > $maxDur) {
				$maxDur = $dur;
			}
			if ($leg['disposition'] === 'ANSWERED' && $bill > 0) {
				$answered[] = $leg;
			}
			if ($leg['disposition'] === 'BUSY') {
				$busy = true;
			}
			if (in_array($leg['disposition'], ['FAILED', 'CONGESTION'], true)) {
				$failed = true;
			}
			$rec = trim((string) ($leg['recordingfile'] ?? ''));
			if ($rec !== '' && $recording === '') {
				$recording = $rec;
				$recordingUid = (string) $leg['uniqueid'];
			}
			if ($rec !== '' && $bill >= (int) ($best['billsec'] ?? 0)) {
				$recording = $rec;
				$recordingUid = (string) $leg['uniqueid'];
			}
			$qn = $this->queueNumberFromLeg($leg);
			if ($qn !== '') {
				$queueNum = $qn;
			}
		}

		$from = $this->extractCaller($legs, $best);
		$to = $this->extractCallee($legs, $answered, $best, $queueNum);
		$direction = $this->detectDirection($from['num'], $to['num'], $legs, $queueNum);
		$talk = $maxBill;
		$status = 'missed';
		if ($talk > 0 || $answered) {
			$status = 'answered';
		} elseif ($busy) {
			$status = 'busy';
		} elseif ($failed && $talk === 0) {
			$status = 'failed';
		}

		$playable = $status === 'answered'
			&& $talk >= 3
			&& $recordingUid !== ''
			&& $this->recordingExistsOnDisk($recording);

		return [
			'calldate' => $minDate,
			'direction' => $direction,
			'status' => $status,
			'from_num' => $from['num'],
			'from_name' => $from['name'],
			'to_num' => $to['num'],
			'to_name' => $to['name'],
			'via' => $to['via'],
			'duration_sec' => $status === 'answered' ? $talk : $maxDur,
			'talk_sec' => $talk,
			'recording_uid' => $playable ? $recordingUid : '',
			'has_recording' => $playable,
		];
	}

	private function extractCaller(array $legs, array $best): array
	{
		$candidates = $legs;
		usort($candidates, function ($a, $b) {
			return $this->callerScore($b) <=> $this->callerScore($a);
		});
		foreach ($candidates as $leg) {
			$num = $this->firstNonEmpty([
				$leg['cnum'] ?? '',
				$leg['src'] ?? '',
				$this->clidNumber($leg['clid'] ?? ''),
			]);
			$num = $this->cleanNumber($num);
			if ($num === '' || $this->isJunkNumber($num) || $this->isLocalChannelNumber($num)) {
				continue;
			}
			if ($this->isQueueRingLeg($leg) && !$this->isExternal($num)) {
				continue;
			}
			$name = $this->firstNonEmpty([
				$leg['cnam'] ?? '',
				$this->clidName($leg['clid'] ?? ''),
				$this->nameFor($num),
			]);
			return ['num' => $num, 'name' => $name];
		}
		$num = $this->cleanNumber((string) ($best['src'] ?? ''));
		return ['num' => $num, 'name' => $this->nameFor($num)];
	}

	private function extractCallee(array $legs, array $answered, array $best, string $queueNum): array
	{
		$agent = $this->findAnsweredAgent($legs, $queueNum);
		$agentName = $agent !== '' ? $this->nameFor($agent) : '';

		if ($queueNum !== '') {
			$qName = $this->queueNames[$queueNum] ?? '';
			if ($agent !== '') {
				return [
					'num' => $agent . '@' . $queueNum,
					'name' => $agentName,
					'via' => '',
				];
			}
			return ['num' => $queueNum, 'name' => $qName, 'via' => ''];
		}

		$outbound = $this->firstNonEmpty([
			$best['dst'] ?? '',
		]);
		foreach ($legs as $leg) {
			$dst = $this->cleanNumber((string) $leg['dst']);
			if ($dst === '' || $this->isJunkNumber($dst) || $this->isQueueRingLeg($leg)) {
				continue;
			}
			if ($this->isExtension($dst) || $this->isExternal($dst) || isset($this->rgNames[$dst])) {
				$name = $this->firstNonEmpty([
					$leg['dst_cnam'] ?? '',
					$leg['outbound_cnam'] ?? '',
					$this->nameFor($dst),
				]);
				return ['num' => $dst, 'name' => $name, 'via' => ''];
			}
		}

		$dst = $this->cleanNumber((string) $outbound);
		if ($this->isJunkNumber($dst)) {
			$dst = $this->agentFromLeg($best);
		}
		return ['num' => $dst, 'name' => $this->nameFor($dst), 'via' => ''];
	}

	private function detectDirection(string $from, string $to, array $legs, string $queueNum): string
	{
		$hasDid = false;
		$hasOutbound = false;
		foreach ($legs as $leg) {
			if (trim((string) ($leg['did'] ?? '')) !== '') {
				$hasDid = true;
			}
			if (trim((string) ($leg['outbound_cnum'] ?? '')) !== '') {
				$hasOutbound = true;
			}
			$ctx = strtolower((string) ($leg['dcontext'] ?? ''));
			if (str_contains($ctx, 'from-pstn') || str_contains($ctx, 'from-trunk') || str_contains($ctx, 'from-did') || str_contains($ctx, 'ext-did')) {
				$hasDid = true;
			}
		}
		if ($queueNum !== '') {
			return 'incoming';
		}
		$fromInt = $this->isExtension($from);
		$toInt = $this->isExtension($to) || isset($this->queueNames[$to]) || isset($this->rgNames[$to]);
		if ($fromInt && $toInt) {
			return 'internal';
		}
		if ($hasOutbound || ($fromInt && $this->isExternal($to))) {
			return 'outgoing';
		}
		if ($hasDid || $this->isExternal($from) || (!$fromInt && $toInt)) {
			return 'incoming';
		}
		if ($fromInt) {
			return 'outgoing';
		}
		return 'incoming';
	}

	private function scoreLeg(array $leg): int
	{
		$score = 0;
		$bill = (int) $leg['billsec'];
		$dst = $this->cleanNumber((string) ($leg['dst'] ?? ''));
		if (($leg['disposition'] ?? '') === 'ANSWERED') {
			$score += 80;
		}
		$score += min(50, $bill);
		if (trim((string) ($leg['recordingfile'] ?? '')) !== '') {
			$score += 20;
		}
		if ($this->isJunkNumber($dst)) {
			$score -= 80;
		}
		if ($this->isQueueRingLeg($leg) && $bill === 0) {
			$score -= 90;
		}
		if ($this->isExtension($dst)) {
			$score += 25;
		}
		if (isset($this->queueNames[$dst]) && ($leg['lastapp'] ?? '') === 'Queue') {
			$score += 5;
		}
		if (str_contains((string) ($leg['channel'] ?? ''), 'Local/') && $bill === 0) {
			$score -= 20;
		}
		return $score;
	}

	private function callerScore(array $leg): int
	{
		$score = 0;
		if (trim((string) ($leg['did'] ?? '')) !== '') {
			$score += 40;
		}
		if (trim((string) ($leg['cnum'] ?? '')) !== '') {
			$score += 20;
		}
		if ($this->isQueueRingLeg($leg)) {
			$score -= 50;
		}
		if ($this->isJunkNumber((string) ($leg['src'] ?? ''))) {
			$score -= 40;
		}
		return $score;
	}

	private function isMeaningfulLeg(array $leg): bool
	{
		$bill = (int) $leg['billsec'];
		if ($bill > 0) {
			return true;
		}
		if (trim((string) ($leg['recordingfile'] ?? '')) !== '') {
			return true;
		}
		if ($this->isQueueRingLeg($leg) && ($leg['disposition'] ?? '') !== 'ANSWERED') {
			return false;
		}
		$dst = $this->cleanNumber((string) ($leg['dst'] ?? ''));
		$app = (string) ($leg['lastapp'] ?? '');
		if ($app === 'Queue') {
			return true;
		}
		if ($app === 'Dial' && !$this->isJunkNumber($dst)) {
			return true;
		}
		if ($this->isExtension($dst) || isset($this->queueNames[$dst]) || isset($this->rgNames[$dst]) || $this->isExternal($dst)) {
			return true;
		}
		if (trim((string) ($leg['did'] ?? '')) !== '') {
			return true;
		}
		return false;
	}

	private function isQueueRingLeg(array $leg): bool
	{
		$ctx = strtolower((string) ($leg['dcontext'] ?? ''));
		$ch = (string) ($leg['channel'] ?? '');
		$dstch = (string) ($leg['dstchannel'] ?? '');
		return str_contains($ctx, 'from-queue')
			|| str_contains($ch, '@from-queue')
			|| str_contains($dstch, '@from-queue');
	}

	private function queueNumberFromLeg(array $leg): string
	{
		$dst = $this->cleanNumber((string) ($leg['dst'] ?? ''));
		if (($leg['lastapp'] ?? '') === 'Queue') {
			$data = (string) ($leg['lastdata'] ?? '');
			if (preg_match('/^([0-9]+)/', $data, $m)) {
				return $m[1];
			}
			if (isset($this->queueNames[$dst])) {
				return $dst;
			}
			return $dst;
		}
		$ctx = strtolower((string) ($leg['dcontext'] ?? ''));
		if (str_contains($ctx, 'ext-queues') && isset($this->queueNames[$dst])) {
			return $dst;
		}
		if (isset($this->queueNames[$dst]) && ($leg['lastapp'] ?? '') !== 'Dial') {
			return $dst;
		}
		return '';
	}

	private function findAnsweredAgent(array $legs, string $queueNum): string
	{
		$ordered = $legs;
		usort($ordered, static function ($a, $b) {
			return ((int) ($b['billsec'] ?? 0)) <=> ((int) ($a['billsec'] ?? 0));
		});
		foreach ($ordered as $leg) {
			if (($leg['disposition'] ?? '') !== 'ANSWERED' || (int) ($leg['billsec'] ?? 0) <= 0) {
				continue;
			}
			$ext = $this->agentFromLeg($leg, $queueNum);
			if ($ext !== '') {
				return $ext;
			}
		}
		return '';
	}

	private function agentFromLeg(array $leg, string $queueNum = ''): string
	{
		foreach ([$leg['dstchannel'] ?? '', $leg['channel'] ?? ''] as $ch) {
			$ext = $this->fromQueueLocalExten((string) $ch);
			if ($ext !== '' && $ext !== $queueNum && !$this->isQueueNumber($ext)) {
				return $ext;
			}
		}
		$dst = $this->cleanNumber((string) ($leg['dst'] ?? ''));
		if ($dst !== '' && $dst !== $queueNum && !$this->isQueueNumber($dst)
			&& ($this->isExtension($dst) || $this->looksLikeExten($dst))
			&& (($leg['lastapp'] ?? '') === 'Dial' || strtolower((string) ($leg['dcontext'] ?? '')) === 'ext-local')) {
			return $dst;
		}
		foreach ([$leg['dstchannel'] ?? '', $leg['channel'] ?? ''] as $ch) {
			$ext = $this->channelExten((string) $ch);
			if ($ext !== '' && $ext !== $queueNum && !$this->isQueueNumber($ext)
				&& ($this->isExtension($ext) || $this->looksLikeExten($ext))) {
				return $ext;
			}
		}
		return '';
	}

	private function fromQueueLocalExten(string $channel): string
	{
		if (preg_match('#Local/([^@/;]+)@from-queue#i', $channel, $m)) {
			return $this->cleanNumber($m[1]);
		}
		return '';
	}

	private function isQueueNumber(string $num): bool
	{
		return $num !== '' && isset($this->queueNames[$num]);
	}

	private function looksLikeExten(string $num): bool
	{
		return $num !== '' && preg_match('/^[0-9]{2,8}$/', $num);
	}

	private function channelExten(string $channel): string
	{
		if ($channel === '') {
			return '';
		}
		if (preg_match('#(?:PJSIP|SIP|IAX2|DAHDI|Local)/([^@/\-;]+)#', $channel, $m)) {
			return $this->cleanNumber($m[1]);
		}
		return '';
	}

	private function present(array $call): array
	{
		$dirMap = [
			'incoming' => [_('Incoming'), 'in'],
			'outgoing' => [_('Outgoing'), 'out'],
			'internal' => [_('Internal'), 'int'],
		];
		$stMap = [
			'answered' => [_('Answered'), 'ok'],
			'missed' => [_('Missed'), 'miss'],
			'busy' => [_('Busy'), 'busy'],
			'failed' => [_('Failed'), 'fail'],
		];
		[$dirLabel, $dirClass] = $dirMap[$call['direction']] ?? [_('Call'), 'int'];
		[$stLabel, $stClass] = $stMap[$call['status']] ?? [_('Call'), 'miss'];
		$uid = $call['recording_uid'];

		$rec = '';
		if ($call['has_recording']) {
			$rec = '<div class="ex-rec">'
				. '<button type="button" class="btn btn-sm ex-play" data-uid="' . $this->h($uid) . '" title="' . $this->h(_('Play')) . '"><i class="fa fa-play"></i></button>'
				. '<a class="btn btn-sm btn-default" href="ajax.php?module=exunity&amp;command=dlcdr&amp;uid=' . rawurlencode($uid) . '" title="' . $this->h(_('Download')) . '"><i class="fa fa-download"></i></a>'
				. '</div>';
		}

		return [
			'calldate' => $call['calldate'],
			'time' => date('d.m.Y H:i:s', strtotime($call['calldate'])),
			'direction_html' => '<span class="ex-pill ex-dir-' . $dirClass . '">' . $this->h($dirLabel) . '</span>',
			'from_html' => $this->partyHtml($call['from_name'], $call['from_num'], ''),
			'to_html' => $this->partyHtml($call['to_name'], $call['to_num'], $call['via']),
			'duration' => $this->formatDuration((int) $call['duration_sec']),
			'status_html' => '<span class="ex-pill ex-st-' . $stClass . '">' . $this->h($stLabel) . '</span>',
			'recording_html' => $rec,
			'direction' => $call['direction'],
			'status' => $call['status'],
		];
	}

	private function partyHtml(string $name, string $num, string $via): string
	{
		$html = '<div class="ex-party">';
		if ($name !== '' && $name !== $num) {
			$html .= '<div class="ex-party-name">' . $this->h($name) . '</div>';
		}
		if ($num !== '') {
			$html .= '<div class="ex-party-num">' . $this->h($num) . '</div>';
		}
		if ($via !== '') {
			$html .= '<div class="ex-party-via">' . $this->h($via) . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	private function callMatchesSearch(array $call, string $q): bool
	{
		$hay = strtolower(implode(' ', [
			$call['from_num'], $call['from_name'], $call['to_num'], $call['to_name'], $call['via'],
		]));
		return str_contains($hay, strtolower($q));
	}

	private function loadDirectories(): void
	{
		try {
			foreach ($this->freepbx->Core->listUsers(true) as $u) {
				$ext = (string) ($u[0] ?? '');
				if ($ext !== '') {
					$this->extNames[$ext] = (string) ($u[1] ?? '');
				}
			}
		} catch (\Throwable $e) {
		}
		try {
			if ($this->freepbx->Modules->checkStatus('queues')) {
				foreach ($this->freepbx->Queues->listQueues(true) as $q) {
					$num = (string) ($q[0] ?? '');
					if ($num !== '') {
						$this->queueNames[$num] = (string) ($q[1] ?? '');
					}
				}
			}
		} catch (\Throwable $e) {
		}
		try {
			$rows = $this->freepbx->Database->query('SELECT grpnum, description FROM ringgroups')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
			foreach ($rows as $row) {
				$this->rgNames[(string) $row['grpnum']] = (string) $row['description'];
			}
		} catch (\Throwable $e) {
		}
	}

	private function isExtension(string $num): bool
	{
		return $num !== '' && isset($this->extNames[$num]);
	}

	private function isExternal(string $num): bool
	{
		$num = $this->cleanNumber($num);
		if ($num === '' || $this->isExtension($num) || isset($this->queueNames[$num]) || isset($this->rgNames[$num]) || $this->isJunkNumber($num)) {
			return false;
		}
		if (str_starts_with($num, '+') || str_starts_with($num, '00')) {
			return true;
		}
		return strlen(preg_replace('/\D/', '', $num)) >= 7;
	}

	private function isJunkNumber(string $num): bool
	{
		$n = strtolower($this->cleanNumber($num));
		return $n === '' || isset(self::JUNK_DST[$n]);
	}

	private function isLocalChannelNumber(string $num): bool
	{
		return str_contains($num, 'Local/') || str_contains($num, '@from-queue');
	}

	private function nameFor(string $num): string
	{
		if ($num === '') {
			return '';
		}
		return $this->extNames[$num] ?? $this->queueNames[$num] ?? $this->rgNames[$num] ?? '';
	}

	private function recordingKey(string $file): string
	{
		$file = trim($file);
		return $file === '' ? '' : strtolower(basename($file));
	}

	private function cleanNumber(string $num): string
	{
		$num = trim($num);
		$num = preg_replace('/#.*$/', '', $num) ?? $num;
		if (str_contains($num, '/')) {
			$num = basename(str_replace('\\', '/', $num));
		}
		return trim($num);
	}

	private function clidNumber(string $clid): string
	{
		if (preg_match('/<([^>]+)>/', $clid, $m)) {
			return trim($m[1]);
		}
		return trim($clid);
	}

	private function clidName(string $clid): string
	{
		if (preg_match('/^"?([^"<]*)"?\s*</', $clid, $m)) {
			return trim($m[1], " \t\"");
		}
		return '';
	}

	private function firstNonEmpty(array $vals): string
	{
		foreach ($vals as $v) {
			$v = trim((string) $v);
			if ($v !== '') {
				return $v;
			}
		}
		return '';
	}

	private function parseDate(string $value, string $fallback, bool $start): string
	{
		$value = trim($value);
		if ($value === '') {
			return $fallback;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $value . ($start ? ' 00:00:00' : ' 23:59:59');
		}
		$ts = strtotime($value);
		return $ts ? date('Y-m-d H:i:s', $ts) : $fallback;
	}

	private function formatDuration(int $sec): string
	{
		$sec = max(0, $sec);
		$h = intdiv($sec, 3600);
		$m = intdiv($sec % 3600, 60);
		$s = $sec % 60;
		if ($h > 0) {
			return sprintf('%d:%02d:%02d', $h, $m, $s);
		}
		return sprintf('%d:%02d', $m, $s);
	}

	private function h(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
