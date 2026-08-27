#!/usr/bin/php -q
<?php
$bootstrap_settings['freepbx_auth'] = false;
$restrict_mods = ['exunity' => true, 'core' => true, 'voicemail' => true];
if (!@include_once(getenv('FREEPBX_CONF') ?: '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}
try {
	\FreePBX::Exunity()->notifyVoicemail($argv);
} catch (Throwable $e) {
	error_log('exunity_vmnotify: ' . $e->getMessage());
}
