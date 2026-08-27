#!/usr/bin/php -q
<?php
$mode = $argv[1] ?? '';
$target = $argv[2] ?? '';
$callerid = $argv[3] ?? '';
$callername = $argv[4] ?? '';
$did = $argv[5] ?? '';
$diversion = $argv[6] ?? '';
$diversionReason = $argv[7] ?? '';

$bootstrap_settings['freepbx_auth'] = false;
$restrict_mods = ['exunity' => true, 'core' => true];
if (!@include_once(getenv('FREEPBX_CONF') ?: '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}

$root = \FreePBX::Config()->get('AMPWEBROOT');
$script = $root . '/admin/modules/exunity/agi-bin/exunity_notify.php';

if (php_sapi_name() === 'cli' && empty(getenv('EXUNITY_TG_WORKER'))) {
	$agidir = \FreePBX::Config()->get('ASTAGIDIR');
	if (is_file($agidir . '/phpagi.php')) {
		require_once $agidir . '/phpagi.php';
		$agi = new AGI();
		$fromChannel = exunity_read_diversion($agi);
		if ($fromChannel['number'] !== '') {
			$diversion = $fromChannel['number'];
		}
		if ($fromChannel['reason'] !== '') {
			$diversionReason = $fromChannel['reason'];
		}
	}
	$args = array_map('escapeshellarg', [$mode, $target, $callerid, $callername, $did, $diversion, $diversionReason]);
	putenv('EXUNITY_TG_WORKER=1');
	exec('EXUNITY_TG_WORKER=1 php ' . escapeshellarg($script) . ' ' . implode(' ', $args) . ' > /dev/null 2>&1 &');
	exit(0);
}

try {
	\FreePBX::Exunity()->notifyIncoming($mode, $target, $callerid, $callername, $did, $diversion, $diversionReason);
} catch (Throwable $e) {
	error_log('exunity_notify: ' . $e->getMessage());
}

function exunity_agi_full($agi, string $expr): string
{
	$r = $agi->get_full_variable($expr);
	$data = is_array($r) ? trim((string) ($r['data'] ?? '')) : '';
	if ($data === '' || strcasecmp($data, '(null)') === 0) {
		return '';
	}
	return $data;
}

function exunity_clean_divert_number(string $num): string
{
	$num = trim($num);
	$num = preg_replace('/^(sip:|sips:|tel:)/i', '', $num);
	if (str_contains($num, '@')) {
		$num = explode('@', $num, 2)[0];
	}
	$num = trim($num, " \t\"'<>");
	if ($num === '' || in_array(strtolower($num), ['unknown', '(null)', 'none', '<unknown>'], true)) {
		return '';
	}
	return $num;
}

function exunity_parse_diversion_header(string $header): array
{
	$number = '';
	$reason = '';
	if (preg_match('/<(?:sip|sips|tel):([^@>;]+)/i', $header, $m)) {
		$number = exunity_clean_divert_number($m[1]);
	} elseif (preg_match('/(?:sip|sips|tel):([^@>;]+)/i', $header, $m)) {
		$number = exunity_clean_divert_number($m[1]);
	}
	if (preg_match('/reason=([^;>\s]+)/i', $header, $m)) {
		$reason = trim($m[1], " \t\"'");
	}
	return [$number, $reason];
}

function exunity_read_diversion($agi): array
{
	$number = exunity_clean_divert_number(exunity_agi_full($agi, '${REDIRECTING(from-num)}'));
	$reason = exunity_agi_full($agi, '${REDIRECTING(reason)}');
	if ($number === '') {
		$number = exunity_clean_divert_number(exunity_agi_full($agi, '${CALLERID(rdnis)}'));
	}
	if ($number === '') {
		$number = exunity_clean_divert_number(exunity_agi_full($agi, '${RDNIS}'));
	}
	if ($reason === '' || strcasecmp($reason, 'unknown') === 0) {
		$fpbxReason = exunity_agi_full($agi, '${DIVERSION_REASON}');
		if ($fpbxReason !== '') {
			$reason = $fpbxReason;
		} elseif (strcasecmp($reason, 'unknown') === 0) {
			$reason = '';
		}
	}
	$header = exunity_agi_full($agi, '${PJSIP_HEADER(read,Diversion)}');
	if ($header !== '') {
		[$hdrNum, $hdrReason] = exunity_parse_diversion_header($header);
		if ($number === '' && $hdrNum !== '') {
			$number = $hdrNum;
		}
		if ($reason === '' && $hdrReason !== '') {
			$reason = $hdrReason;
		}
	}
	return ['number' => $number, 'reason' => $reason];
}
