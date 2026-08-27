#!/usr/bin/php -q
<?php
$queue = $argv[1] ?? '';
$caller = $argv[2] ?? '';

$bootstrap_settings['freepbx_auth'] = false;
$restrict_mods = ['exunity' => true, 'core' => true, 'queues' => true];
if (!@include_once(getenv('FREEPBX_CONF') ?: '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}

$agidir = \FreePBX::Config()->get('ASTAGIDIR');
if (is_file($agidir . '/phpagi.php')) {
	require_once $agidir . '/phpagi.php';
}

$agent = '';
$dial = '';
$timeout = '15';
try {
	$found = \FreePBX::Exunity()->lookupStickyAgent($queue, $caller);
	$agent = $found['agent'] ?? '';
	$dial = $found['dial'] ?? '';
	$timeout = $found['timeout'] ?? '15';
} catch (Throwable $e) {
	error_log('exunity_sticky: ' . $e->getMessage());
}

if (class_exists('AGI')) {
	$agi = new AGI();
	$agi->set_variable('STICKY_AGENT', $agent);
	$agi->set_variable('STICKY_DIAL', $dial);
	$agi->set_variable('STICKY_TIMEOUT', $timeout);
	exit(0);
}
echo $agent . ' ' . $dial . ' ' . $timeout . "\n";
