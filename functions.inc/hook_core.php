<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function exunity_configpageinit($pagename)
{
	global $currentcomponent;
	try {
		\FreePBX::Exunity()->printThemeLoader();
	} catch (\Throwable $e) {
		// theme overlay is optional
	}
	$action = $_REQUEST['action'] ?? null;
	$extdisplay = $_REQUEST['extdisplay'] ?? null;
	$tech_hardware = $_REQUEST['tech_hardware'] ?? null;

	if (in_array($pagename, ['queues', 'ringgroups', 'routing'], true)) {
		\FreePBX::Exunity()->printExtenPickerLoader();
	}
	if ($pagename === 'queues') {
		\FreePBX::Exunity()->processQueueStickyRequest();
		\FreePBX::Exunity()->printQueueStickyLoader();
	}
	if ($pagename === 'routing') {
		\FreePBX::Exunity()->printRoutingCidLoader();
	}

	if ($pagename !== 'extensions') {
		return true;
	}

	if ($tech_hardware != null || $extdisplay != '' || $action == 'add') {
		$currentcomponent->addoptlistitem('exunity_tg_enable', 'no', _('No'));
		$currentcomponent->addoptlistitem('exunity_tg_enable', 'yes', _('Yes'));
		$currentcomponent->setoptlistopts('exunity_tg_enable', 'sort', false);
		$currentcomponent->addguifunc('exunity_extensions_configpageload');
		if (!empty($action)) {
			$currentcomponent->addprocessfunc('exunity_extensions_configprocess');
		}
	}
}

function exunity_extensions_configpageload()
{
	global $currentcomponent;
	$extdisplay = $_REQUEST['extdisplay'] ?? '';
	$row = \FreePBX::Exunity()->getExtenTelegram($extdisplay);
	$enable = !empty($row['enabled']) ? 'yes' : 'no';
	$currentcomponent->addguielem('eX Telegram', new gui_selectbox(
		'exunity_tg_enable',
		$currentcomponent->getoptlist('exunity_tg_enable'),
		$enable,
		_('Notify incoming calls'),
		_('Send a Telegram message when this extension starts ringing. New voicemail recordings are sent to the same chat.'),
		false
	));
	$currentcomponent->addguielem('eX Telegram', new gui_textbox(
		'exunity_tg_chatid',
		$row['chatid'] ?? '',
		_('Telegram chat ID'),
		_('Numeric chat ID of the user or group that should receive notifications'),
		'',
		'',
		true
	));
}

function exunity_extensions_configprocess()
{
	$action = $_REQUEST['action'] ?? null;
	$extension = $_REQUEST['extdisplay'] ?? $_REQUEST['extension'] ?? null;
	if ($action === 'add') {
		$extension = $_REQUEST['extension'] ?? $extension;
	}
	if ($action === 'del' && $extension) {
		\FreePBX::Exunity()->delUser($extension);
		return;
	}
	if (in_array($action, ['add', 'edit'], true) && $extension) {
		$enabled = (($_REQUEST['exunity_tg_enable'] ?? 'no') === 'yes');
		$chatid = $_REQUEST['exunity_tg_chatid'] ?? '';
		\FreePBX::Exunity()->saveExtenTelegram($extension, $enabled, $chatid);
	}
}
