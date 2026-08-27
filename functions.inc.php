<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

include_once __DIR__ . '/functions.inc/hook_core.php';
include_once __DIR__ . '/Job.php';

function exunity_destinations()
{
	global $module_page;
	if ($module_page === 'exunity_tgdest') {
		return false;
	}
	$extens = [];
	foreach (\FreePBX::Exunity()->listTgDests() as $row) {
		$extens[] = [
			'destination' => 'app-exunity-tg,' . $row['id'] . ',1',
			'description' => _('eX Telegram') . ': ' . $row['description'],
		];
	}
	return $extens;
}

function exunity_getdest($exten)
{
	return ['app-exunity-tg,' . $exten . ',1'];
}

function exunity_getdestinfo($dest)
{
	if (!str_starts_with(trim((string) $dest), 'app-exunity-tg,')) {
		return false;
	}
	$parts = explode(',', (string) $dest);
	$id = $parts[1] ?? '';
	$row = \FreePBX::Exunity()->getTgDest($id);
	if (!$row) {
		return [];
	}
	return [
		'description' => sprintf(_('eX Telegram: %s'), $row['description']),
		'edit_url' => 'config.php?display=exunity_tgdest&view=form&id=' . urlencode((string) $id),
	];
}

function exunity_check_destinations($dest = true)
{
	$destlist = [];
	if (is_array($dest) && empty($dest)) {
		return $destlist;
	}
	foreach (\FreePBX::Exunity()->listTgDests() as $row) {
		if ($dest !== true && !in_array($row['dest'], (array) $dest, true)) {
			continue;
		}
		$destlist[] = [
			'dest' => $row['dest'],
			'description' => sprintf(_('eX Telegram: %s'), $row['description']),
			'edit_url' => 'config.php?display=exunity_tgdest&view=form&id=' . $row['id'],
		];
	}
	return $destlist;
}
