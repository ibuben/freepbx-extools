<?php
namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use PDO;

class Exunity extends FreePBX_Helpers implements BMO
{
	private $db;
	private $FreePBX;

	public function __construct($freepbx = null)
	{
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		require_once __DIR__ . '/Provision.php';
		require_once __DIR__ . '/CallHistory.php';
		require_once __DIR__ . '/StickyAgent.php';
		require_once __DIR__ . '/Phonebook.php';
		require_once __DIR__ . '/VoicemailTelegram.php';
		$this->autoloadPhoneManager();
	}

	private function autoloadPhoneManager(): void
	{
		spl_autoload_register(static function ($class) {
			if (!str_starts_with($class, 'PhoneManager\\')) {
				return;
			}
			$short = substr($class, strlen('PhoneManager\\'));
			$base = __DIR__ . '/PhoneManager/';
			foreach ([$base . $short . '.php', $base . 'drivers/' . $short . '.php'] as $path) {
				if (is_file($path)) {
					require_once $path;
					return;
				}
			}
		});
	}

	public function install()
	{
		$this->seedDefaults();
		$this->installProvisionWebroot();
		$this->ensurePhonebookTemplates();
		$this->phonebook()->token();
		$this->ensureRetentionJob();
		$this->ensureStatsJob();
		$this->maybeSendUsageStats();
		$this->ensureStickyAgi();
		$this->ensureStereoScript();
		$this->ensureVmNotify();
		$this->syncBrandCssCustom();
		try {
			$this->FreePBX->Hooks->updateBMOHooks();
		} catch (\Throwable $e) {
			// hooks cache will refresh on next reload
		}
	}

	public function uninstall()
	{
		try {
			$this->FreePBX->Job->remove('exunity', 'retention');
		} catch (\Throwable $e) {
			// job table may not exist during a broken uninstall
		}
		try {
			$this->FreePBX->Job->remove('exunity', 'stats');
		} catch (\Throwable $e) {
			// job table may not exist during a broken uninstall
		}
		$webroot = $this->FreePBX->Config->get('AMPWEBROOT') ?: '/var/www/html';
		$prov = rtrim($webroot, '/') . '/provision';
		if (is_dir($prov)) {
			@unlink($prov . '/index.php');
			@unlink($prov . '/.htaccess');
		}
		try {
			$this->vmTelegram()->clearOurExternNotify();
		} catch (\Throwable $e) {
			// voicemail hook is optional
		}
	}

	public function doConfigPageInit($page)
	{
		$request = $_REQUEST;
		switch ($page) {
			case 'exunity':
				if (($_POST['action'] ?? '') === 'savesettings') {
					$this->saveSettingsFromRequest($request);
				}
				break;
			case 'exunity_bulk':
			case 'exunity_bulkedit':
				break;
			case 'exunity_tgdest':
				$action = $request['action'] ?? '';
				if ($action === 'save') {
					$id = !empty($request['id']) ? (int) $request['id'] : null;
					$goto = $request[($request['goto0'] ?? '') . '0'] ?? '';
					$this->saveTgDest($id, $request['description'] ?? '', $request['chatid'] ?? '', $goto);
					needreload();
				} elseif ($action === 'delete' && !empty($request['id'])) {
					$this->deleteTgDest((int) $request['id']);
					needreload();
				}
				break;
			case 'exunity_phones':
				$action = $request['action'] ?? '';
				if ($action === 'save' && !empty($request['id'])) {
					$this->updatePhoneExtension((int) $request['id'], trim($request['extension'] ?? ''), (int) ($request['template_id'] ?? 0) ?: null);
				} elseif ($action === 'delete' && !empty($request['id'])) {
					$this->deletePhone((int) $request['id']);
				} elseif ($action === 'autoassign') {
					$this->autoAssignExtensions();
				}
				break;
			case 'exunity_templates':
				$action = $request['action'] ?? '';
				if ($action === 'save') {
					$this->saveTemplate($request);
				} elseif ($action === 'delete' && !empty($request['id'])) {
					$this->deleteTemplate((int) $request['id']);
				}
				break;
		}
	}

	public function ajaxRequest($req, &$setting)
	{
		return in_array($req, ['getphones', 'gettgdests', 'gettemplates', 'bulktest', 'bulksave', 'bulkedittest', 'bulkeditsave', 'tgtest', 'cdrrecpurge', 'getcalls', 'playcdr', 'dlcdr'], true);
	}

	public function ajaxCustomHandler()
	{
		$cmd = $_REQUEST['command'] ?? '';
		if ($cmd === 'playcdr' || $cmd === 'dlcdr') {
			$this->callHistory()->streamRecording((string) ($_REQUEST['uid'] ?? ''), $cmd === 'dlcdr');
			return true;
		}
		return false;
	}

	public function phonebook()
	{
		static $book = null;
		if ($book === null) {
			$book = new \FreePBX\modules\Exunity\Phonebook($this, $this->FreePBX);
		}
		return $book;
	}

	public function vmTelegram()
	{
		static $vm = null;
		if ($vm === null) {
			$vm = new \FreePBX\modules\Exunity\VoicemailTelegram($this, $this->FreePBX);
		}
		return $vm;
	}

	public function notifyVoicemail(array $argv): void
	{
		$this->vmTelegram()->handleCli($argv);
	}

	public function ensureVmNotify(): void
	{
		$this->vmTelegram()->ensureExternNotify();
	}

	private function callHistory()
	{
		static $history = null;
		if ($history === null) {
			$history = new \FreePBX\modules\Exunity\CallHistory($this, $this->FreePBX);
		}
		return $history;
	}

	public function ajaxHandler()
	{
		switch ($_REQUEST['command'] ?? '') {
			case 'getphones':
				return $this->listPhonesForGrid();
			case 'gettgdests':
				$rows = $this->listTgDests();
				foreach ($rows as &$row) {
					$row['actions'] = '<a href="?display=exunity_tgdest&amp;view=form&amp;id=' . (int) $row['id'] . '"><i class="fa fa-edit"></i></a> '
						. '<a href="?display=exunity_tgdest&amp;action=delete&amp;id=' . (int) $row['id'] . '"><i class="fa fa-trash"></i></a>';
				}
				return $rows;
			case 'gettemplates':
				$rows = $this->listTemplates();
				foreach ($rows as &$row) {
					$row['actions'] = '<a href="?display=exunity_templates&amp;view=form&amp;id=' . (int) $row['id'] . '"><i class="fa fa-edit"></i></a> '
						. '<a href="?display=exunity_templates&amp;action=delete&amp;id=' . (int) $row['id'] . '"><i class="fa fa-trash"></i></a>';
				}
				return $rows;
			case 'bulktest':
				return $this->previewBulk($_REQUEST);
			case 'bulksave':
				return $this->createBulk($_REQUEST);
			case 'bulkedittest':
				return $this->previewBulkEdit($_REQUEST);
			case 'bulkeditsave':
				return $this->applyBulkEdit($_REQUEST);
			case 'tgtest':
				return $this->sendTelegramTest(trim($_REQUEST['chatid'] ?? ''));
			case 'cdrrecpurge':
				return $this->runCdrRecordingCleanup();
			case 'getcalls':
				return $this->callHistory()->listCalls($_REQUEST);
			default:
				return ['status' => false];
		}
	}

	public function showPage($page)
	{
		switch ($page) {
			case 'exunity_bulk':
				return load_view(__DIR__ . '/views/bulk.php', ['mod' => $this]);
			case 'exunity_bulkedit':
				return load_view(__DIR__ . '/views/bulkedit.php', [
					'users' => $this->FreePBX->Core->listUsers(),
					'transports' => $this->listPjsipTransports(),
					'default_wss' => $this->defaultWssTransport(),
				]);
			case 'exunity_phones':
				return $this->showPhonesPage();
			case 'exunity_templates':
				return $this->showTemplatesPage();
			case 'exunity_tgdest':
				return $this->showTgDestPage();
			case 'exunity_calls':
				return load_view(__DIR__ . '/views/calls.php');
			default:
				$this->ensureRetentionJob();
				$this->ensureStatsJob();
				return load_view(__DIR__ . '/views/settings.php', [
					'settings' => $this->getAllSettings(),
					'provision_url' => $this->provisionBaseUrl(),
					'retention_last' => $this->getRetentionLastRun(),
					'sticky_queues' => $this->listQueuesForStickyUi(),
					'cm_groups' => $this->phonebook()->listPublicGroups(),
					'phonebook_groups' => $this->phonebook()->selectedGroupIds(),
					'phonebook_urls' => $this->phonebook()->publicUrls(),
					'phonebook_cm_ok' => $this->FreePBX->Modules->checkStatus('contactmanager'),
				]);
		}
	}

	public function getActionBar($request)
	{
		$display = $request['display'] ?? '';
		$view = $request['view'] ?? '';
		$submit = ['submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Submit')]];
		$full = $submit + [
			'reset' => ['name' => 'reset', 'id' => 'reset', 'value' => _('Reset')],
			'delete' => ['name' => 'delete', 'id' => 'delete', 'value' => _('Delete')],
		];
		if ($display === 'exunity') {
			return $submit;
		}
		if (in_array($display, ['exunity_tgdest', 'exunity_templates', 'exunity_phones'], true) && $view === 'form') {
			if (empty($request['id'])) {
				unset($full['delete']);
			}
			return $full;
		}
		return [];
	}

	public static function myDialplanHooks()
	{
		return 600;
	}

	public function printExtenPickerLoader(): void
	{
		static $printed = false;
		if ($printed || (($_REQUEST['view'] ?? '') !== 'form')) {
			return;
		}
		$printed = true;
		try {
			$info = $this->FreePBX->Modules->getInfo('exunity');
			$ver = urlencode((string) ($info['exunity']['version'] ?? '17.0.1'));
		} catch (\Throwable $e) {
			$ver = '17.0.1';
		}
		$i18n = json_encode([
			'available' => _('Available'),
			'selected' => _('Selected'),
			'search' => _('Search'),
			'top' => _('Move to top'),
			'up' => _('Move up'),
			'down' => _('Move down'),
			'bottom' => _('Move to bottom'),
		], JSON_UNESCAPED_UNICODE);
		$css = 'assets/exunity/css/exten-picker.css?load_version=' . $ver . '&picker=4';
		$js = 'assets/exunity/js/exten-picker.js?load_version=' . $ver . '&picker=4';
		echo '<script>(function(){function go(){if(!window.jQuery||!document.body){return setTimeout(go,30);}if(!document.getElementById("exunity-picker-css")){var l=document.createElement("link");l.id="exunity-picker-css";l.rel="stylesheet";l.href=' . json_encode($css) . ';document.head.appendChild(l);}window.exunityPickerI18n=' . $i18n . ';if(!document.getElementById("exunity-picker-js")){var s=document.createElement("script");s.id="exunity-picker-js";s.src=' . json_encode($js) . ';document.body.appendChild(s);}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}})();</script>';
	}

	public function printQueueStickyLoader(): void
	{
		static $printed = false;
		if ($printed || (($_REQUEST['view'] ?? '') !== 'form') || (($_REQUEST['display'] ?? '') !== 'queues')) {
			return;
		}
		$printed = true;
		try {
			$info = $this->FreePBX->Modules->getInfo('exunity');
			$ver = urlencode((string) ($info['exunity']['version'] ?? '17.0.1'));
		} catch (\Throwable $e) {
			$ver = '17.0.1';
		}
		$queue = preg_replace('/\D+/', '', (string) ($_REQUEST['extdisplay'] ?? $_REQUEST['account'] ?? ''));
		$cfg = json_encode([
			'enabled' => $this->isStickyEnabledForQueue($queue),
			'label' => _('Sticky last agent'),
			'yes' => _('Enabled'),
			'no' => _('Disabled'),
			'help' => _('If this caller already talked to an agent of this queue, the next inbound call rings that agent first. Busy, reject, timeout, or offline → continue to the queue. Apply Config after saving.'),
		], JSON_UNESCAPED_UNICODE);
		$js = 'assets/exunity/js/queue-sticky.js?load_version=' . $ver . '&sticky=1';
		echo '<script>(function(){window.exunityStickyQueue=' . $cfg . ';function go(){if(!window.jQuery||!document.body){return setTimeout(go,30);}if(!document.getElementById("exunity-sticky-js")){var s=document.createElement("script");s.id="exunity-sticky-js";s.src=' . json_encode($js) . ';document.body.appendChild(s);}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}})();</script>';
	}

	public function printRoutingCidLoader(): void
	{
		static $printed = false;
		if ($printed || (($_REQUEST['display'] ?? '') !== 'routing') || (($_REQUEST['view'] ?? '') !== 'form')) {
			return;
		}
		$printed = true;
		try {
			$info = $this->FreePBX->Modules->getInfo('exunity');
			$ver = urlencode((string) ($info['exunity']['version'] ?? '17.0.8'));
		} catch (\Throwable $e) {
			$ver = '17.0.8';
		}
		$extens = [];
		try {
			foreach ($this->FreePBX->Core->getAllUsers() ?: [] as $user) {
				$ext = (string) ($user['extension'] ?? '');
				if ($ext === '') {
					continue;
				}
				$name = trim((string) ($user['name'] ?? ''));
				$extens[] = [
					'value' => $ext,
					'label' => $name !== '' ? $ext . ' (' . $name . ')' : $ext,
				];
			}
		} catch (\Throwable $e) {
			$extens = [];
		}
		$i18n = json_encode([
			'title' => _('eX Add CallerIDs'),
			'help' => _('Move extensions to Selected, or paste extra CallerIDs. Every new row uses the same prepend, prefix and match pattern you set above.'),
			'prepend' => _('prepend'),
			'prefix' => _('prefix'),
			'match' => _('match pattern'),
			'cids' => _('CallerIDs'),
			'paste' => _('Or paste extra CallerIDs'),
			'placeholder' => "201\n202\n203",
			'fromLast' => _('Copy template from last row'),
			'skipDup' => _('Skip duplicates'),
			'add' => _('Add rows'),
			'added' => _('Added %d pattern(s). Save the route to keep them.'),
			'none' => _('No CallerIDs to add.'),
			'nodp' => _('Open the Dial Patterns tab first.'),
		], JSON_UNESCAPED_UNICODE);
		$css = 'assets/exunity/css/routing-cid.css?load_version=' . $ver . '&cid=2';
		$js = 'assets/exunity/js/routing-cid.js?load_version=' . $ver . '&cid=2';
		echo '<script>(function(){window.exunityRoutingCidI18n=' . $i18n . ';window.exunityRoutingCidExtens=' . json_encode($extens, JSON_UNESCAPED_UNICODE) . ';function go(){if(!window.jQuery||!document.body){return setTimeout(go,30);}if(!document.getElementById("exunity-routing-cid-css")){var l=document.createElement("link");l.id="exunity-routing-cid-css";l.rel="stylesheet";l.href=' . json_encode($css) . ';document.head.appendChild(l);}if(!document.getElementById("exunity-routing-cid-js")){var s=document.createElement("script");s.id="exunity-routing-cid-js";s.src=' . json_encode($js) . ';document.body.appendChild(s);}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}})();</script>';
	}

	public function queuesHookTabs()
	{
		$queue = preg_replace('/\D+/', '', (string) ($_REQUEST['extdisplay'] ?? $_REQUEST['account'] ?? ''));
		$html = load_view(__DIR__ . '/views/queue_sticky.php', [
			'enabled' => $this->isStickyEnabledForQueue($queue),
		]);
		return [[
			'title' => _('eXTools'),
			'rawname' => 'exunity',
			'content' => $html,
		]];
	}

	public function processQueueStickyRequest(): void
	{
		$action = (string) ($_REQUEST['action'] ?? '');
		$account = preg_replace('/\D+/', '', (string) ($_REQUEST['account'] ?? $_REQUEST['extdisplay'] ?? ''));
		if ($action === 'delete' && $account !== '') {
			$this->setStickyEnabledForQueue($account, false);
			return;
		}
		if (!in_array($action, ['add', 'edit'], true) || !isset($_REQUEST['exunity_sticky_present'])) {
			return;
		}
		if ($account === '') {
			return;
		}
		$this->setStickyEnabledForQueue($account, (($_REQUEST['exunity_sticky_agent'] ?? 'no') === 'yes'));
		needreload();
	}

	public function isStickyEnabledForQueue(string $queue): bool
	{
		$queue = preg_replace('/\D+/', '', $queue);
		return $queue !== '' && in_array($queue, $this->getStickyEnabledQueues(), true);
	}

	public function getStickyEnabledQueues(): array
	{
		$raw = $this->getConfig('sticky_enabled_queues');
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			$raw = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $raw);
		}
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $q) {
			$q = preg_replace('/\D+/', '', (string) $q);
			if ($q !== '') {
				$out[$q] = true;
			}
		}
		return array_map('strval', array_keys($out));
	}

	public function setStickyEnabledForQueue(string $queue, bool $enabled): void
	{
		$queue = preg_replace('/\D+/', '', $queue);
		if ($queue === '') {
			return;
		}
		$set = array_fill_keys($this->getStickyEnabledQueues(), true);
		if ($enabled) {
			$set[$queue] = true;
		} else {
			unset($set[$queue]);
		}
		$this->setConfig('sticky_enabled_queues', array_map('strval', array_keys($set)));
	}

	public function saveStickyEnabledQueues(array $queues): void
	{
		$set = [];
		foreach ($queues as $q) {
			$q = preg_replace('/\D+/', '', (string) $q);
			if ($q !== '') {
				$set[$q] = true;
			}
		}
		$this->setConfig('sticky_enabled_queues', array_map('strval', array_keys($set)));
	}

	public function listQueuesForStickyUi(): array
	{
		$out = [];
		foreach ($this->listQueueNumbersWithNames() as $row) {
			$out[] = [
				'extension' => $row['extension'],
				'name' => $row['name'],
				'sticky' => $this->isStickyEnabledForQueue($row['extension']),
			];
		}
		return $out;
	}

	public function isAdminThemeEnabled(): bool
	{
		$stored = $this->getConfig('ui_theme');
		if ($stored === false || $stored === null || $stored === '') {
			return true;
		}
		return $stored === 'yes';
	}

	public function syncBrandCssCustom(): void
	{
		try {
			$cfg = $this->FreePBX->Config;
			$themeOn = $this->isAdminThemeEnabled();
			$cssNow = (string) $cfg->get('BRAND_CSS_CUSTOM');
			$jsNow = (string) $cfg->get('BRAND_ALT_JS');
			$cssOurs = 'assets/exunity/css/admin-theme.css';
			$jsOurs = 'assets/exunity/js/admin-theme.js';
			if ($themeOn) {
				if ($cssNow === '' || str_contains($cssNow, 'assets/exunity/css/')) {
					if ($cssNow !== $cssOurs) {
						$cfg->set('BRAND_CSS_CUSTOM', $cssOurs, true, true);
					}
				}
				if ($jsNow === '' || str_contains($jsNow, 'assets/exunity/js/')) {
					if ($jsNow !== $jsOurs) {
						$cfg->set('BRAND_ALT_JS', $jsOurs, true, true);
					}
				}
			} else {
				if (str_contains($cssNow, 'assets/exunity/css/')) {
					$cfg->set('BRAND_CSS_CUSTOM', '', true, true);
				}
				if (str_contains($jsNow, 'assets/exunity/js/')) {
					$cfg->set('BRAND_ALT_JS', '', true, true);
				}
			}
		} catch (\Throwable $e) {
		}
	}

	public function generateMainStyles($variables = [])
	{
		if (!$this->isAdminThemeEnabled()) {
			return [];
		}
		return [
			'background-color' => '#101318',
			'text-color' => '#e6edf5',
			'base-color' => '#2b3340',
			'border-color' => '#2b3340',
			'menu-button-background-color' => '#222733',
			'button-background-color' => '#222733',
			'button-text-color' => '#e6edf5',
			'button-border-color' => '#2b3340',
			'button-hover-background-color' => '#2c3340',
			'button-hover-text-color' => '#ffffff',
			'button-active-background-color' => '#115e59',
			'button-active-text-color' => '#ffffff',
			'button-active-border-color' => '#2dd4bf',
			'form-button-background-color' => '#222733',
			'form-button-text-color' => '#e6edf5',
			'submit-button-background-color' => '#115e59',
			'floating-button-background-color' => '#222733',
			'info-background-color' => '#132e2c',
			'info-text-color' => '#99f6e4',
			'panel-background-color' => '#181c23',
			'panel-border-color' => '#2b3340',
			'panel-color' => '#e6edf5',
			'menu-button-active-hover-bg-color' => '#115e59',
			'menu-button-active-hover-color' => '#ffffff',
			'nav-pills-bg-color' => '#115e59',
			'btn-group-bg-color' => '#181c23',
			'footer-content-color' => '#9aa8b6',
			'footer-background-color' => '#101318',
			'nav-li-focus-bg-color' => '#134e4a',
			'table-striped-even-background-color' => '#1c212b',
			'table-striped-even-text-color' => '#e6edf5',
			'table-striped-odd-background-color' => '#181c23',
			'table-striped-odd-text-color' => '#e6edf5',
			'table-th-background-color' => '#222733',
			'table-th-text-color' => '#9aa8b6',
			'table-row-hover-background-color' => '#232a36',
			'table-row-hover-text-color' => '#e6edf5',
			'hr-background-color' => '#2b3340',
			'hr-border-color' => '#2b3340',
			'action-bar-background-color' => '#181c23',
			'action-bar-border-color' => '#2b3340',
			'action-bar-text-color' => '#e6edf5',
			'nav-active-background-color' => '#181c23',
			'nav-active-text-color' => '#2dd4bf',
			'nav-hover-background-color' => '#222733',
			'nav-hover-text-color' => '#2dd4bf',
		];
	}

	public function printThemeLoader(): void
	{
		static $printed = false;
		if ($printed) {
			return;
		}
		$printed = true;
		$this->syncBrandCssCustom();
		if (!$this->isAdminThemeEnabled()) {
			return;
		}
		try {
			$info = $this->FreePBX->Modules->getInfo('exunity');
			$ver = urlencode((string) ($info['exunity']['version'] ?? '17.0.4'));
		} catch (\Throwable $e) {
			$ver = '17.0.4';
		}
		$css = 'assets/exunity/css/admin-theme.css?load_version=' . $ver . '&theme=31';
		$js = 'assets/exunity/js/admin-theme.js?load_version=' . $ver . '&theme=31';
		echo '<script src="' . htmlspecialchars($js, ENT_QUOTES) . '"></script>';
		echo '<script>(function(){document.documentElement.classList.add("exunity-theme");function go(){if(!document.head){return setTimeout(go,20);}if(document.getElementById("exunity-theme-css")){return;}var l=document.createElement("link");l.id="exunity-theme-css";l.rel="stylesheet";l.href=' . json_encode($css) . ';document.head.appendChild(l);}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}})();</script>';
	}

	public function doDialplanHook(&$ext, $engine, $priority)
	{
		if ($engine !== 'asterisk') {
			return;
		}

		foreach ($this->listTgDests() as $row) {
			$id = (int) $row['id'];
			$ext->add('app-exunity-tg', $id, '', new \ext_noop('eXTools Telegram dest ' . $row['description']));
			$ext->add('app-exunity-tg', $id, '', new \ext_agi('exunity_notify.php,dest,' . $id . ',${CALLERID(num)},${CALLERID(name)},${FROM_DID},${REDIRECTING(from-num)},${REDIRECTING(reason)}'));
			if (!empty($row['dest'])) {
				$ext->add('app-exunity-tg', $id, '', new \ext_goto($row['dest']));
			} else {
				$ext->add('app-exunity-tg', $id, '', new \ext_hangup());
			}
		}

		$ext->add('sub-exunity-tg-ring', '_X.', '', new \ext_agi('exunity_notify.php,ring,${EXTEN},${CALLERID(num)},${CALLERID(name)},${FROM_DID},${REDIRECTING(from-num)},${REDIRECTING(reason)}'));
		$ext->add('sub-exunity-tg-ring', '_X.', '', new \ext_return());
		$ext->add('sub-exunity-tg-ring', '_+X.', '', new \ext_agi('exunity_notify.php,ring,${EXTEN},${CALLERID(num)},${CALLERID(name)},${FROM_DID},${REDIRECTING(from-num)},${REDIRECTING(reason)}'));
		$ext->add('sub-exunity-tg-ring', '_+X.', '', new \ext_return());

		foreach ($this->listEnabledTelegramExtens() as $row) {
			$extension = $row['extension'];
			try {
				$ext->splice('from-did-direct', $extension, 1, new \ext_gosub(1, $extension, 'sub-exunity-tg-ring'));
			} catch (\Throwable $e) {
				// context may not exist yet for unused extensions
			}
		}
		$this->addStickyAgentDialplan($ext);
		$this->addStereoRecordingDialplan($ext);
		$this->ensureRetentionJob();
		$this->ensureStatsJob();
		$this->ensureStickyAgi();
		$this->ensureStereoScript();
		$this->ensureVmNotify();
	}

	public function lookupStickyAgent(string $queue, string $caller): array
	{
		return $this->stickyAgent()->lookup($queue, $caller);
	}

	private function stickyAgent()
	{
		static $sticky = null;
		if ($sticky === null) {
			$sticky = new \FreePBX\modules\Exunity\StickyAgent($this, $this->FreePBX);
		}
		return $sticky;
	}

	public function ensureStickyAgi(): void
	{
		$src = __DIR__ . '/agi-bin/exunity_sticky.php';
		$dir = $this->FreePBX->Config->get('ASTAGIDIR') ?: '/var/lib/asterisk/agi-bin';
		$dest = rtrim((string) $dir, '/') . '/exunity_sticky.php';
		if (!is_file($src) || !is_dir($dir)) {
			return;
		}
		$srcData = file_get_contents($src);
		$destData = is_file($dest) ? file_get_contents($dest) : '';
		if ($srcData !== false && $srcData !== $destData) {
			file_put_contents($dest, $srcData);
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

	public function ensureStereoScript(): void
	{
		$src = __DIR__ . '/bin/exunity_mixmon_post.sh';
		$dir = $this->FreePBX->Config->get('AMPBIN') ?: '/var/lib/asterisk/bin';
		$dir = rtrim((string) $dir, '/');
		if (!is_file($src) || !is_dir($dir)) {
			return;
		}
		$srcData = file_get_contents($src);
		foreach (['exunity_mixmon_post.sh', 'exunity_stereo.sh'] as $name) {
			$dest = $dir . '/' . $name;
			$destData = is_file($dest) ? file_get_contents($dest) : '';
			if ($srcData !== false && $srcData !== $destData) {
				file_put_contents($dest, $srcData);
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
		$settings = $this->getAllSettings();
		$br = (int) ($settings['record_mp3_bitrate'] ?? 64);
		if (!in_array($br, [32, 48, 64, 96, 128], true)) {
			$br = 64;
		}
		$conf = 'STEREO=' . ((($settings['stereo_record'] ?? 'no') === 'yes') ? 'yes' : 'no') . "\n"
			. 'MP3=' . ((($settings['record_mp3'] ?? 'no') === 'yes') ? 'yes' : 'no') . "\n"
			. 'MP3_BITRATE=' . $br . "\n";
		$confPath = $dir . '/exunity_mixmon.conf';
		file_put_contents($confPath, $conf);
		@chmod($confPath, 0644);
		if (function_exists('posix_getpwnam')) {
			$pw = posix_getpwnam('asterisk');
			if ($pw) {
				@chown($confPath, 'asterisk');
				@chgrp($confPath, 'asterisk');
			}
		}
	}

	private function stereoPostCommand(): string
	{
		$dir = $this->FreePBX->Config->get('AMPBIN') ?: '/var/lib/asterisk/bin';
		$script = rtrim((string) $dir, '/') . '/exunity_mixmon_post.sh';
		$post = $script . ' ^{MIXMONITOR_FILENAME}';
		$existing = trim((string) $this->FreePBX->Config->get('MIXMON_POST'));
		if ($existing !== '' && !str_contains($existing, 'exunity_mixmon_post.sh') && !str_contains($existing, 'exunity_stereo.sh')) {
			$post .= ' ; ' . $existing;
		}
		return $post;
	}

	private function mixmonPostEnabled(): bool
	{
		$s = $this->getAllSettings();
		return (($s['stereo_record'] ?? 'no') === 'yes') || (($s['record_mp3'] ?? 'no') === 'yes');
	}

	private function addStereoRecordingDialplan(&$ext): void
	{
		if (!$this->mixmonPostEnabled()) {
			return;
		}
		$this->ensureStereoScript();
		$post = $this->stereoPostCommand();
		$stereo = (($this->getAllSettings()['stereo_record'] ?? 'no') === 'yes');
		$legs = '${MIXMON_DIR}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}';
		$opts = $stereo
			? 'a${MONITOR_REC_OPTION}r(' . $legs . '-L.wav)t(' . $legs . '-R.wav)i(${LOCAL_MIXMON_ID})${MIXMON_BEEP}'
			: 'a${MONITOR_REC_OPTION}i(${LOCAL_MIXMON_ID})${MIXMON_BEEP}';
		try {
			$ext->replace('sub-record-check', 'recordcheck', 'monitorcmd', new \ext_mixmonitor(
				'${MIXMON_DIR}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}.${MON_FMT}',
				$opts,
				$post
			));
		} catch (\Throwable $e) {
		}
		$qopts = $stereo
			? '${EVAL(${MONITOR_OPTIONS})}r(${MONITOR_FILENAME}-L.wav)t(${MONITOR_FILENAME}-R.wav)${MIXMON_BEEP}'
			: '${EVAL(${MONITOR_OPTIONS})}${MIXMON_BEEP}';
		try {
			$ext->replace('sub-record-check', 'recq', 3, new \ext_mixmonitor(
				'${MONITOR_FILENAME}.${MON_FMT}',
				$qopts,
				$post
			));
		} catch (\Throwable $e) {
		}
		$one = $stereo
			? 'ar(' . $legs . '-L.wav)t(' . $legs . '-R.wav)i(LOCAL_MIXMON_ID)${MIXMON_BEEP}'
			: 'ai(LOCAL_MIXMON_ID)${MIXMON_BEEP}';
		try {
			$ext->replace('macro-one-touch-record', 's', 'startrec', new \ext_mixmonitor(
				'${MIXMON_DIR}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}.${MON_FMT}',
				$one,
				$post
			));
		} catch (\Throwable $e) {
		}
	}

	private function addStickyAgentDialplan(&$ext): void
	{
		$queues = $this->listStickyQueueNumbers();
		if (!$queues) {
			return;
		}
		$ext->add('sub-exunity-sticky', 's', '', new \ext_noop('eX sticky agent queue ${ARG1}'));
		$ext->add('sub-exunity-sticky', 's', '', new \ext_agi('exunity_sticky.php,${ARG1},${CALLERID(num)}'));
		$ext->add('sub-exunity-sticky', 's', '', new \ext_gotoif('$["${STICKY_AGENT}"=""]', 'sticky-skip'));
		$ext->add('sub-exunity-sticky', 's', '', new \ext_gotoif('$["${DEVICE_STATE(${STICKY_DIAL})}"!="NOT_INUSE"]', 'sticky-skip'));
		$ext->add('sub-exunity-sticky', 's', '', new \ext_noop('eX sticky dial ${STICKY_AGENT} via ${STICKY_DIAL}'));
		$ext->add('sub-exunity-sticky', 's', '', new \ext_dial('${STICKY_DIAL}', '${STICKY_TIMEOUT},tT'));
		$ext->add('sub-exunity-sticky', 's', '', new \ext_gotoif('$["${DIALSTATUS}"="ANSWER"]', 'sticky-done'));
		$ext->add('sub-exunity-sticky', 's', 'sticky-skip', new \ext_return());
		$ext->add('sub-exunity-sticky', 's', 'sticky-done', new \ext_hangup());

		foreach ($queues as $queue) {
			try {
				$ext->splice('ext-queues', $queue, 1, new \ext_gosub(1, 's', 'sub-exunity-sticky', '${EXTEN}'));
			} catch (\Throwable $e) {
			}
		}
	}

	private function listStickyQueueNumbers(): array
	{
		$enabled = array_fill_keys($this->getStickyEnabledQueues(), true);
		if (!$enabled) {
			return [];
		}
		$out = [];
		foreach ($this->listQueueNumbers() as $queue) {
			if (isset($enabled[$queue])) {
				$out[] = $queue;
			}
		}
		return $out;
	}

	private function listQueueNumbers(): array
	{
		$out = [];
		foreach ($this->listQueueNumbersWithNames() as $row) {
			$out[] = $row['extension'];
		}
		return $out;
	}

	private function listQueueNumbersWithNames(): array
	{
		$out = [];
		try {
			$rows = $this->db->query('SELECT extension, descr FROM queues_config ORDER BY extension')->fetchAll(PDO::FETCH_ASSOC) ?: [];
			foreach ($rows as $row) {
				$num = (string) ($row['extension'] ?? '');
				if ($num !== '') {
					$out[] = ['extension' => $num, 'name' => (string) ($row['descr'] ?? '')];
				}
			}
		} catch (\Throwable $e) {
		}
		return $out;
	}

	public function delUser($extension, $unused = null)
	{
		$sth = $this->db->prepare('DELETE FROM exunity_exten WHERE extension = ?');
		$sth->execute([(string) $extension]);
		$sth = $this->db->prepare('UPDATE exunity_phones SET extension = NULL WHERE extension = ?');
		$sth->execute([(string) $extension]);
	}

	public function saveExtenTelegram($extension, $enabled, $chatid): void
	{
		$sth = $this->db->prepare('REPLACE INTO exunity_exten (extension, enabled, chatid) VALUES (?, ?, ?)');
		$sth->execute([(string) $extension, $enabled ? 1 : 0, trim((string) $chatid)]);
	}

	public function getExtenTelegram($extension): array
	{
		$sth = $this->db->prepare('SELECT * FROM exunity_exten WHERE extension = ?');
		$sth->execute([(string) $extension]);
		$row = $sth->fetch(PDO::FETCH_ASSOC);
		return $row ?: ['extension' => $extension, 'enabled' => 0, 'chatid' => ''];
	}

	public function listEnabledTelegramExtens(): array
	{
		return $this->db->query("SELECT * FROM exunity_exten WHERE enabled = 1 AND chatid <> ''")->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function listTgDests(): array
	{
		return $this->db->query('SELECT * FROM exunity_tgdest ORDER BY description')->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function getTgDest($id): ?array
	{
		$sth = $this->db->prepare('SELECT * FROM exunity_tgdest WHERE id = ?');
		$sth->execute([(int) $id]);
		$row = $sth->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function saveTgDest($id, $description, $chatid, $dest): int
	{
		if ($id) {
			$sth = $this->db->prepare('UPDATE exunity_tgdest SET description = ?, chatid = ?, dest = ? WHERE id = ?');
			$sth->execute([$description, $chatid, $dest, $id]);
			return (int) $id;
		}
		$sth = $this->db->prepare('INSERT INTO exunity_tgdest (description, chatid, dest) VALUES (?, ?, ?)');
		$sth->execute([$description, $chatid, $dest]);
		return (int) $this->db->lastInsertId();
	}

	public function deleteTgDest($id): void
	{
		$sth = $this->db->prepare('DELETE FROM exunity_tgdest WHERE id = ?');
		$sth->execute([(int) $id]);
	}

	public function getAllSettings(): array
	{
		$defaults = [
			'tg_token' => '',
			'tg_template_ring' => "Incoming call\nFrom: {{callername}} <{{callerid}}>\n{{if_diversion}}Diverted from: {{diversion}} {{diversion_tg}}\n{{/if_diversion}}To: {{extension}}\nTime: {{time}}",
			'tg_template_missed' => '',
			'tg_template_vm' => "Voicemail\nFrom: {{callername}} <{{callerid}}>\nTo: {{extension}}\nDuration: {{duration}}s\nTime: {{time}}",
			'tg_vm' => 'yes',
			'tg_country_code' => '998',
			'provision_base_url' => $this->defaultProvisionUrl(),
			'provision_sip_host' => '',
			'provision_sip_port' => '5060',
			'provision_sip_transport' => 'udp',
			'default_timezone' => 'Asia/Tashkent',
			'default_language' => 'ru',
			'default_admin_password' => '',
			'account_label' => 'Office',
			'ui_theme' => 'yes',
			'cdr_recording_keep_days' => '0',
			'sticky_timeout' => '15',
			'sticky_days' => '90',
			'stereo_record' => 'no',
			'record_mp3' => 'no',
			'record_mp3_bitrate' => '64',
			'phonebook_enabled' => 'yes',
			'phonebook_include_extensions' => 'yes',
			'phonebook_name' => 'Company',
			'stats_enabled' => 'yes',
		];
		$out = [];
		foreach ($defaults as $k => $v) {
			$stored = $this->getConfig($k);
			$out[$k] = ($stored === false || $stored === null || $stored === '') ? $v : $stored;
		}
		return $out;
	}

	private function saveSettingsFromRequest(array $request): void
	{
		$keys = ['tg_token', 'tg_template_ring', 'tg_template_missed', 'tg_template_vm', 'tg_vm', 'tg_country_code', 'provision_base_url', 'provision_sip_host', 'provision_sip_port', 'provision_sip_transport', 'default_timezone', 'default_language', 'default_admin_password', 'account_label', 'ui_theme', 'cdr_recording_keep_days', 'sticky_timeout', 'sticky_days', 'stereo_record', 'record_mp3', 'record_mp3_bitrate', 'phonebook_enabled', 'phonebook_include_extensions', 'phonebook_name', 'stats_enabled'];
		foreach ($keys as $key) {
			if (array_key_exists($key, $request)) {
				$value = trim((string) $request[$key]);
				if ($key === 'cdr_recording_keep_days') {
					$days = (int) $value;
					if ($days < 0) {
						$days = 0;
					}
					if ($days > 3650) {
						$days = 3650;
					}
					$value = (string) $days;
				}
				if ($key === 'sticky_timeout') {
					$sec = (int) $value;
					if ($sec < 5) {
						$sec = 5;
					}
					if ($sec > 60) {
						$sec = 60;
					}
					$value = (string) $sec;
				}
				if ($key === 'sticky_days') {
					$days = (int) $value;
					if ($days < 1) {
						$days = 1;
					}
					if ($days > 3650) {
						$days = 3650;
					}
					$value = (string) $days;
				}
				if ($key === 'stereo_record') {
					$value = $value === 'yes' ? 'yes' : 'no';
				}
				if ($key === 'record_mp3') {
					$value = $value === 'yes' ? 'yes' : 'no';
				}
				if ($key === 'record_mp3_bitrate') {
					$br = (int) $value;
					if (!in_array($br, [32, 48, 64, 96, 128], true)) {
						$br = 64;
					}
					$value = (string) $br;
				}
				if ($key === 'phonebook_enabled' || $key === 'phonebook_include_extensions' || $key === 'tg_vm' || $key === 'stats_enabled') {
					$value = $value === 'yes' ? 'yes' : 'no';
				}
				if ($key === 'phonebook_name' && $value === '') {
					$value = 'Company';
				}
				$this->setConfig($key, $value);
			}
		}
		if (isset($request['phonebook_groups_present'])) {
			$groups = $request['phonebook_groups'] ?? [];
			if (!is_array($groups)) {
				$groups = [$groups];
			}
			$ids = [];
			foreach ($groups as $id) {
				$id = preg_replace('/\D+/', '', (string) $id);
				if ($id !== '') {
					$ids[] = $id;
				}
			}
			$this->setConfig('phonebook_groups', json_encode(array_values(array_unique($ids))));
			$this->ensurePhonebookTemplates();
			$this->installProvisionWebroot();
			$this->db->query('UPDATE exunity_phones SET provision_config_hash = NULL');
		}
		if (isset($request['sticky_queues_present'])) {
			$qs = $request['sticky_queues'] ?? [];
			if (!is_array($qs)) {
				$qs = [$qs];
			}
			$this->saveStickyEnabledQueues($qs);
		}
		$this->ensureRetentionJob();
		$this->ensureStatsJob();
		$this->maybeSendUsageStats();
		$this->ensureStereoScript();
		$this->syncBrandCssCustom();
		$this->ensureVmNotify();
		needreload();
		try {
			$this->FreePBX->Hooks->updateBMOHooks();
		} catch (\Throwable $e) {
			// next reload will pick up hook changes
		}
	}

	public function ensureRetentionJob(): void
	{
		try {
			if (!class_exists(\FreePBX\modules\Exunity\Job::class, false)) {
				require_once __DIR__ . '/Job.php';
			}
			$this->FreePBX->Job->addClass(
				'exunity',
				'retention',
				\FreePBX\modules\Exunity\Job::class,
				'15 3 * * *',
				1800,
				true
			);
		} catch (\Throwable $e) {
			dbug('exunity retention job: ' . $e->getMessage());
		}
	}

	public function ensureStatsJob(): void
	{
		try {
			if (!class_exists(\FreePBX\modules\Exunity\StatsJob::class, false)) {
				require_once __DIR__ . '/StatsJob.php';
			}
			$this->FreePBX->Job->addClass(
				'exunity',
				'stats',
				\FreePBX\modules\Exunity\StatsJob::class,
				'20 4 1 * *',
				60,
				true
			);
		} catch (\Throwable $e) {
			dbug('exunity stats job: ' . $e->getMessage());
		}
	}

	public function maybeSendUsageStats(): array
	{
		$result = ['status' => true, 'sent' => false, 'message' => 'Usage stats skipped'];
		try {
			if (($this->getAllSettings()['stats_enabled'] ?? 'yes') !== 'yes') {
				$result['message'] = 'Usage stats disabled';
				return $result;
			}
			$last = (int) $this->getConfig('stats_last_sent');
			if ($last > 0 && (time() - $last) < (28 * 86400)) {
				$result['message'] = 'Usage stats already sent this month';
				return $result;
			}
			$payload = [
				'ip' => $this->statsPublicIp(),
				'freepbx_version' => $this->statsFreepbxVersion(),
				'deployment_id' => $this->statsDeploymentId(),
			];
			if (!$this->statsHttpPost('https://exunity.uz/extools_stat.php', $payload)) {
				$result['status'] = false;
				$result['message'] = 'Usage stats send failed';
				return $result;
			}
			$this->setConfig('stats_last_sent', (string) time());
			$result['sent'] = true;
			$result['message'] = 'Usage stats sent';
			return $result;
		} catch (\Throwable $e) {
			$result['status'] = false;
			$result['message'] = 'Usage stats skipped';
			return $result;
		}
	}

	private function statsHttpPost(string $url, array $payload): bool
	{
		$body = http_build_query($payload);
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 8,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/x-www-form-urlencoded',
					'User-Agent: eXTools-exunity',
				],
			]);
			$ok = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			return $ok !== false && $code > 0 && $code < 500;
		}
		$ctx = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: eXTools-exunity\r\n",
				'content' => $body,
				'timeout' => 8,
				'ignore_errors' => true,
			],
		]);
		return @file_get_contents($url, false, $ctx) !== false;
	}

	private function statsPublicIp(): string
	{
		try {
			$ip = (string) $this->FreePBX->Sipsettings->getConfig('externip');
			$ip = trim($ip);
			if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
				return $ip;
			}
		} catch (\Throwable $e) {
		}
		return '';
	}

	private function statsFreepbxVersion(): string
	{
		if (function_exists('get_framework_version')) {
			$ver = (string) get_framework_version();
			if ($ver !== '') {
				return $ver;
			}
		}
		try {
			$info = $this->FreePBX->Modules->getInfo('framework');
			$ver = (string) ($info['framework']['version'] ?? '');
			if ($ver !== '') {
				return $ver;
			}
		} catch (\Throwable $e) {
		}
		return '';
	}

	private function statsDeploymentId(): string
	{
		if (function_exists('sysadmin_get_license')) {
			try {
				$lic = sysadmin_get_license();
				if (is_array($lic) && !empty($lic['deploymentid'])) {
					return trim((string) $lic['deploymentid']);
				}
			} catch (\Throwable $e) {
			}
		}
		return '';
	}

	public function getRetentionLastRun(): array
	{
		$raw = $this->getConfig('cdr_recording_cleanup_last');
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}

	public function runCdrRecordingCleanup(): array
	{
		$days = (int) ($this->getAllSettings()['cdr_recording_keep_days'] ?? 0);
		$result = [
			'status' => true,
			'skipped' => false,
			'days' => $days,
			'cutoff' => null,
			'deleted' => 0,
			'missing' => 0,
			'cleared' => 0,
			'message' => '',
		];
		if ($days <= 0) {
			$result['skipped'] = true;
			$result['message'] = _('Automatic cleanup is off. Set a number of days greater than 0 and save.');
			return $result;
		}

		$cdrdb = $this->cdrDatabase();
		if (!$cdrdb) {
			$result['status'] = false;
			$result['message'] = _('CDR database is not available.');
			return $result;
		}

		$cutoff = date('Y-m-d 00:00:00', strtotime('-' . $days . ' days'));
		$result['cutoff'] = $cutoff;
		$deadline = time() + 1200;
		$batch = 400;

		$select = $cdrdb->prepare(
			'SELECT uniqueid, sequence, recordingfile FROM cdr
			WHERE calldate < :cutoff AND recordingfile IS NOT NULL AND recordingfile <> ""
			LIMIT ' . (int) $batch
		);
		$clear = $cdrdb->prepare('UPDATE cdr SET recordingfile = "" WHERE uniqueid = :uid AND sequence = :seq');
		$fallback = $cdrdb->prepare('UPDATE cdr SET recordingfile = "" WHERE uniqueid = :uid AND recordingfile = :file');

		while (time() < $deadline) {
			$select->execute([':cutoff' => $cutoff]);
			$rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
			if (!$rows) {
				break;
			}
			foreach ($rows as $row) {
				$deletedOne = false;
				foreach ($this->recordingPathsFromCdr((string) $row['recordingfile']) as $path) {
					if (!$this->isSafeRecordingPath($path)) {
						continue;
					}
					if (is_file($path)) {
						if (@unlink($path)) {
							$result['deleted']++;
							$deletedOne = true;
						}
					}
				}
				if (!$deletedOne) {
					$result['missing']++;
				}
				$clear->execute([
					':uid' => $row['uniqueid'],
					':seq' => $row['sequence'],
				]);
				if ($clear->rowCount() < 1) {
					$fallback->execute([
						':uid' => $row['uniqueid'],
						':file' => $row['recordingfile'],
					]);
				}
				$result['cleared']++;
			}
		}

		$result['message'] = sprintf(
			_('Deleted %d recording files. %d already missing. Cleared %d CDR audio links older than %s.'),
			$result['deleted'],
			$result['missing'],
			$result['cleared'],
			$cutoff
		);
		$this->setConfig('cdr_recording_cleanup_last', json_encode([
			'at' => date('Y-m-d H:i:s'),
			'days' => $days,
			'cutoff' => $cutoff,
			'deleted' => $result['deleted'],
			'missing' => $result['missing'],
			'cleared' => $result['cleared'],
		]));
		return $result;
	}

	public function cdrDatabase(): ?PDO
	{
		try {
			if ($this->FreePBX->Modules->checkStatus('cdr') && !empty($this->FreePBX->Cdr->cdrdb)) {
				return $this->FreePBX->Cdr->cdrdb;
			}
		} catch (\Throwable $e) {
			// fall through to a direct connection
		}
		try {
			$host = $this->FreePBX->Config->get('CDRDBHOST') ?: $this->FreePBX->Config->get('AMPDBHOST') ?: 'localhost';
			$name = $this->FreePBX->Config->get('CDRDBNAME') ?: 'asteriskcdrdb';
			$user = $this->FreePBX->Config->get('CDRDBUSER') ?: $this->FreePBX->Config->get('AMPDBUSER');
			$pass = $this->FreePBX->Config->get('CDRDBPASS') ?: $this->FreePBX->Config->get('AMPDBPASS');
			$port = $this->FreePBX->Config->get('CDRDBPORT') ?: $this->FreePBX->Config->get('AMPDBPORT') ?: '3306';
			$dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
			return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function monitorBaseDir(): string
	{
		$mix = trim((string) $this->FreePBX->Config->get('MIXMON_DIR'));
		$spool = rtrim((string) $this->FreePBX->Config->get('ASTSPOOLDIR'), '/') ?: '/var/spool/asterisk';
		$base = $mix !== '' ? rtrim($mix, '/') : $spool . '/monitor';
		$real = realpath($base);
		return $real ?: $base;
	}

	public function recordingPathsFromCdr(string $recordingfile): array
	{
		$recordingfile = trim($recordingfile);
		if ($recordingfile === '') {
			return [];
		}
		$base = $this->monitorBaseDir();
		$name = basename($recordingfile);
		$paths = [];
		if (str_starts_with($recordingfile, '/')) {
			$paths[] = $recordingfile;
		}
		$parts = explode('-', $name);
		if (isset($parts[3]) && preg_match('/^\d{8}/', $parts[3])) {
			$y = substr($parts[3], 0, 4);
			$m = substr($parts[3], 4, 2);
			$d = substr($parts[3], 6, 2);
			$paths[] = $base . '/' . $y . '/' . $m . '/' . $d . '/' . $name;
		}
		$paths[] = $base . '/' . $name;
		if ($name !== $recordingfile && !str_starts_with($recordingfile, '/')) {
			$paths[] = $base . '/' . ltrim($recordingfile, '/');
		}

		$out = [];
		foreach ($paths as $path) {
			$out[] = $path;
			$info = pathinfo($path);
			if (empty($info['filename']) || empty($info['dirname'])) {
				continue;
			}
			foreach (['wav', 'WAV', 'gsm', 'mp3', 'ogg'] as $ext) {
				$out[] = $info['dirname'] . '/' . $info['filename'] . '.' . $ext;
			}
		}
		return array_values(array_unique($out));
	}

	public function isSafeRecordingPath(string $path): bool
	{
		$base = rtrim($this->monitorBaseDir(), '/');
		$norm = str_replace('\\', '/', $path);
		if ($norm === '' || str_contains($norm, "\0") || str_contains($norm, '..')) {
			return false;
		}
		$real = realpath($path);
		if ($real !== false) {
			return $real === $base || str_starts_with($real, $base . '/');
		}
		return str_starts_with($norm, $base . '/');
	}

	public function previewBulk(array $data): array
	{
		[$from, $to, $errors] = $this->parseRange($data);
		if ($errors) {
			return ['status' => false, 'message' => implode(' ', $errors)];
		}
		$existing = [];
		$create = [];
		for ($ext = $from; $ext <= $to; $ext++) {
			if ($this->FreePBX->Core->getUser((string) $ext)) {
				$existing[] = (string) $ext;
			} else {
				$create[] = (string) $ext;
			}
		}
		return [
			'status' => true,
			'from' => $from,
			'to' => $to,
			'create' => $create,
			'existing' => $existing,
			'count' => count($create),
		];
	}

	public function createBulk(array $data): array
	{
		[$from, $to, $errors] = $this->parseRange($data);
		if ($errors) {
			return ['status' => false, 'message' => implode(' ', $errors), 'results' => []];
		}
		$tech = ($data['tech'] ?? 'pjsip') === 'pjsip' ? 'pjsip' : 'pjsip';
		$namePattern = $data['name_pattern'] ?? 'Agent {ext}';
		$secretMode = $data['secret_mode'] ?? 'random';
		$sameSecret = $data['secret'] ?? '';
		$voicemail = !empty($data['voicemail']);
		$outboundcid = $data['outboundcid'] ?? '';
		$skipExisting = !empty($data['skip_existing']);
		$results = [];
		$ok = 0;
		$skip = 0;
		$fail = 0;
		for ($ext = $from; $ext <= $to; $ext++) {
			$extension = (string) $ext;
			if ($this->FreePBX->Core->getUser($extension)) {
				if ($skipExisting) {
					$results[] = ['ext' => $extension, 'status' => 'skipped', 'message' => _('Already exists')];
					$skip++;
					continue;
				}
				$results[] = ['ext' => $extension, 'status' => 'error', 'message' => _('Already exists')];
				$fail++;
				continue;
			}
			$name = str_replace('{ext}', $extension, $namePattern);
			$secret = match ($secretMode) {
				'same' => $sameSecret,
				'pattern' => str_replace('{ext}', $extension, $data['secret_pattern'] ?? '{ext}'),
				default => $this->FreePBX->Core->generateSecret(),
			};
			try {
				$payload = [
					'name' => $name,
					'secret' => $secret,
					'outboundcid' => $outboundcid,
					'emergency_cid' => '',
					'vm' => $voicemail ? 'yes' : 'no',
					'vmpwd' => $voicemail ? str_pad(substr($extension, -4), 4, '0', STR_PAD_LEFT) : '',
					'email' => '',
				];
				$res = $this->FreePBX->Core->processQuickCreate($tech, $extension, $payload);
				if (empty($res['status'])) {
					$results[] = ['ext' => $extension, 'status' => 'error', 'message' => $res['message'] ?? _('Unknown error')];
					$fail++;
					continue;
				}
				$results[] = ['ext' => $extension, 'status' => 'ok', 'message' => $name];
				$ok++;
			} catch (\Throwable $e) {
				$results[] = ['ext' => $extension, 'status' => 'error', 'message' => $e->getMessage()];
				$fail++;
			}
		}
		needreload();
		return [
			'status' => true,
			'created' => $ok,
			'skipped' => $skip,
			'failed' => $fail,
			'results' => $results,
		];
	}

	private function parseRange(array $data): array
	{
		$from = (int) ($data['range_from'] ?? 0);
		$to = (int) ($data['range_to'] ?? 0);
		$errors = [];
		if ($from <= 0 || $to <= 0) {
			$errors[] = _('Range must be numeric and greater than zero');
		}
		if ($to < $from) {
			$errors[] = _('Range end must be greater than or equal to start');
		}
		if (($to - $from + 1) > 500) {
			$errors[] = _('Range cannot exceed 500 extensions');
		}
		return [$from, $to, $errors];
	}

	public function previewBulkEdit(array $data): array
	{
		[$exts, $errors] = $this->parseExtensionList($data);
		if ($errors) {
			return ['status' => false, 'message' => implode(' ', $errors)];
		}
		$fields = $this->selectedBulkEditFields($data);
		if (!$fields) {
			return ['status' => false, 'message' => _('Tick at least one field to change')];
		}
		$fieldError = $this->validateBulkEditValues($data, $fields);
		if ($fieldError !== '') {
			return ['status' => false, 'message' => $fieldError];
		}
		$known = [];
		foreach ($this->FreePBX->Core->listUsers(true) as $u) {
			$known[(string) $u[0]] = $u[1] ?? '';
		}
		$existing = [];
		$missing = [];
		foreach ($exts as $ext) {
			if (isset($known[$ext])) {
				$existing[] = ['ext' => $ext, 'name' => (string) $known[$ext]];
			} else {
				$missing[] = $ext;
			}
		}
		return [
			'status' => true,
			'preview' => true,
			'count' => count($existing),
			'missing' => $missing,
			'fields' => array_keys($fields),
			'extensions' => $existing,
		];
	}

	public function applyBulkEdit(array $data): array
	{
		[$exts, $errors] = $this->parseExtensionList($data);
		if ($errors) {
			return ['status' => false, 'message' => implode(' ', $errors), 'results' => []];
		}
		$fields = $this->selectedBulkEditFields($data);
		if (!$fields) {
			return ['status' => false, 'message' => _('Tick at least one field to change'), 'results' => []];
		}
		$fieldError = $this->validateBulkEditValues($data, $fields);
		if ($fieldError !== '') {
			return ['status' => false, 'message' => $fieldError, 'results' => []];
		}
		$results = [];
		$ok = 0;
		$skip = 0;
		$fail = 0;
		foreach ($exts as $ext) {
			$user = $this->FreePBX->Core->getUser($ext);
			if (!$user) {
				$results[] = ['ext' => $ext, 'status' => 'skipped', 'message' => _('Does not exist')];
				$skip++;
				continue;
			}
			try {
				$changed = $this->applyBulkEditToExtension($ext, $user, $data, $fields);
				$results[] = ['ext' => $ext, 'status' => 'ok', 'message' => implode(', ', $changed)];
				$ok++;
			} catch (\Throwable $e) {
				$results[] = ['ext' => $ext, 'status' => 'error', 'message' => $e->getMessage()];
				$fail++;
			}
		}
		needreload();
		return [
			'status' => true,
			'updated' => $ok,
			'skipped' => $skip,
			'failed' => $fail,
			'results' => $results,
		];
	}

	private function parseExtensionList(array $data): array
	{
		$raw = (string) ($data['extensions'] ?? $data['bulk_extens'] ?? '');
		$exts = [];
		foreach (preg_split('/[\r\n,;]+/', $raw) as $line) {
			$line = trim($line);
			if ($line === '' || !preg_match('/^[0-9]+$/', $line)) {
				continue;
			}
			$exts[$line] = true;
		}
		$list = array_keys($exts);
		sort($list, SORT_NUMERIC);
		$errors = [];
		if (!$list) {
			$errors[] = _('Select at least one extension');
		}
		if (count($list) > 500) {
			$errors[] = _('Cannot edit more than 500 extensions at once');
		}
		return [$list, $errors];
	}

	private function selectedBulkEditFields(array $data): array
	{
		$keys = [
			'secret', 'outboundcid', 'emergency_cid', 'ringtimer', 'callwaiting',
			'recording', 'max_contacts', 'dtmfmode', 'transport', 'qualifyfreq', 'voicemail',
			'webrtc',
		];
		$fields = [];
		foreach ($keys as $key) {
			if (!empty($data['apply_' . $key])) {
				$fields[$key] = true;
			}
		}
		return $fields;
	}

	private function validateBulkEditValues(array $data, array $fields): string
	{
		if (!empty($fields['secret'])) {
			$mode = $data['secret_mode'] ?? 'random';
			if ($mode === 'same' && trim((string) ($data['secret'] ?? '')) === '') {
				return _('Shared SIP secret cannot be empty');
			}
			if ($mode === 'pattern' && trim((string) ($data['secret_pattern'] ?? '')) === '') {
				return _('SIP secret pattern cannot be empty');
			}
		}
		if (!empty($fields['max_contacts'])) {
			$n = (int) ($data['max_contacts'] ?? 0);
			if ($n < 1 || $n > 100) {
				return _('Max contacts must be between 1 and 100');
			}
		}
		if (!empty($fields['dtmfmode'])) {
			$allowed = ['rfc4733', 'inband', 'info', 'auto', 'rfc2833'];
			if (!in_array((string) ($data['dtmfmode'] ?? ''), $allowed, true)) {
				return _('Invalid DTMF mode');
			}
		}
		if (!empty($fields['callwaiting']) && !in_array((string) ($data['callwaiting'] ?? ''), ['enabled', 'disabled'], true)) {
			return _('Invalid call waiting value');
		}
		if (!empty($fields['recording']) && !in_array((string) ($data['recording'] ?? ''), ['dontcare', 'always', 'never'], true)) {
			return _('Invalid recording value');
		}
		if (!empty($fields['voicemail']) && !in_array((string) ($data['voicemail'] ?? ''), ['yes', 'no'], true)) {
			return _('Invalid voicemail value');
		}
		if (!empty($fields['qualifyfreq']) && (int) ($data['qualifyfreq'] ?? -1) < 0) {
			return _('Qualify frequency cannot be negative');
		}
		if (!empty($fields['ringtimer']) && (int) ($data['ringtimer'] ?? -1) < 0) {
			return _('Ring timer cannot be negative');
		}
		if (!empty($fields['webrtc'])) {
			if (!in_array((string) ($data['webrtc'] ?? ''), ['yes', 'no'], true)) {
				return _('Invalid WebRTC value');
			}
			if (($data['webrtc'] ?? '') === 'yes') {
				$transport = trim((string) ($data['webrtc_transport'] ?? ''));
				if ($transport === '') {
					$transport = $this->defaultWssTransport();
				}
				if ($transport === '' || !$this->isWebsocketTransport($transport)) {
					return _('No WSS/WS transport is configured. Enable WSS in Settings → Asterisk SIP Settings, then apply again. FreePBX names it 0.0.0.0-wss, not transport-wss.');
				}
				$known = $this->listPjsipTransports();
				if ($known && !in_array($transport, $known, true)) {
					return sprintf(_('PJSIP transport "%s" does not exist.'), $transport);
				}
			}
		}
		return '';
	}

	private function applyBulkEditToExtension(string $ext, array $user, array $data, array $fields): array
	{
		$changed = [];
		$astman = $this->FreePBX->astman;
		if (!empty($fields['secret'])) {
			$mode = $data['secret_mode'] ?? 'random';
			$secret = match ($mode) {
				'same' => (string) ($data['secret'] ?? ''),
				'pattern' => str_replace('{ext}', $ext, (string) ($data['secret_pattern'] ?? '{ext}')),
				default => $this->FreePBX->Core->generateSecret(),
			};
			$this->upsertSipKeyword($ext, 'secret', $secret);
			$this->invalidatePhoneProvision($ext);
			$changed[] = 'secret';
		}
		if (!empty($fields['outboundcid'])) {
			$cid = trim((string) ($data['outboundcid'] ?? ''));
			if ($cid !== '') {
				$sth = $this->db->prepare('UPDATE users SET outboundcid = ? WHERE extension = ?');
				$sth->execute([$cid, $ext]);
				if ($astman && $astman->connected()) {
					$astman->database_put('AMPUSER', $ext . '/outboundcid', $cid);
				}
				$changed[] = 'outboundcid';
			}
		}
		if (!empty($fields['emergency_cid'])) {
			$cid = trim((string) ($data['emergency_cid'] ?? ''));
			if ($cid !== '') {
				$sth = $this->db->prepare('UPDATE devices SET emergency_cid = ? WHERE id = ?');
				$sth->execute([$cid, $ext]);
				if ($astman && $astman->connected()) {
					$astman->database_put('DEVICE', $ext . '/emergency_cid', $cid);
				}
				$changed[] = 'emergency_cid';
			}
		}
		if (!empty($fields['ringtimer'])) {
			$timer = (string) (int) ($data['ringtimer'] ?? 0);
			$sth = $this->db->prepare('UPDATE users SET ringtimer = ? WHERE extension = ?');
			$sth->execute([$timer, $ext]);
			if ($astman && $astman->connected()) {
				$astman->database_put('AMPUSER', $ext . '/ringtimer', $timer);
			}
			$changed[] = 'ringtimer';
		}
		if (!empty($fields['callwaiting']) && $astman && $astman->connected()) {
			if (($data['callwaiting'] ?? '') === 'enabled') {
				$astman->database_put('CW', $ext, 'ENABLED');
			} else {
				$astman->database_del('CW', $ext);
			}
			$changed[] = 'callwaiting';
		}
		if (!empty($fields['recording']) && $astman && $astman->connected()) {
			$rec = (string) ($data['recording'] ?? 'dontcare');
			foreach (['in/external', 'out/external', 'in/internal', 'out/internal'] as $path) {
				$astman->database_put('AMPUSER', $ext . '/recording/' . $path, $rec);
			}
			$changed[] = 'recording';
		}
		if (!empty($fields['max_contacts'])) {
			$n = (int) ($data['max_contacts'] ?? 1);
			$n = max(1, min(100, $n));
			$this->upsertSipKeyword($ext, 'max_contacts', (string) $n);
			$this->upsertSipKeyword($ext, 'remove_existing', $n === 1 ? 'yes' : 'no');
			$changed[] = 'max_contacts';
		}
		if (!empty($fields['dtmfmode'])) {
			$this->upsertSipKeyword($ext, 'dtmfmode', (string) $data['dtmfmode']);
			$changed[] = 'dtmfmode';
		}
		if (!empty($fields['transport'])) {
			$transport = trim((string) ($data['transport'] ?? ''));
			if ($transport !== '') {
				$this->upsertSipKeyword($ext, 'transport', $transport);
				$changed[] = 'transport';
			}
		}
		if (!empty($fields['qualifyfreq'])) {
			$this->upsertSipKeyword($ext, 'qualifyfreq', (string) (int) ($data['qualifyfreq'] ?? 60));
			$changed[] = 'qualifyfreq';
		}
		if (!empty($fields['voicemail'])) {
			$this->applyBulkVoicemail($ext, $user, ($data['voicemail'] ?? '') === 'yes');
			$changed[] = 'voicemail';
		}
		if (!empty($fields['webrtc'])) {
			$this->applyBulkWebrtc($ext, ($data['webrtc'] ?? '') === 'yes', trim((string) ($data['webrtc_transport'] ?? '')));
			$changed[] = 'webrtc';
		}
		return $changed;
	}

	private function applyBulkVoicemail(string $ext, array $user, bool $enable): void
	{
		$astman = $this->FreePBX->astman;
		$context = 'novm';
		if ($this->FreePBX->Modules->checkStatus('voicemail')) {
			$vm = $this->FreePBX->Voicemail;
			$box = $vm->getMailbox($ext);
			if ($enable) {
				if (!$box) {
					$vm->addMailbox($ext, [
						'vm' => 'enabled',
						'vmcontext' => 'default',
						'vmpwd' => str_pad(substr($ext, -4), 4, '0', STR_PAD_LEFT),
						'name' => $user['name'] ?? $ext,
						'email' => '',
					]);
					$context = 'default';
				} else {
					$context = $box['vmcontext'] ?: 'default';
				}
			} elseif ($box) {
				$vm->delMailbox($ext);
			}
		} elseif ($enable) {
			$context = 'default';
		}
		$sth = $this->db->prepare('UPDATE users SET voicemail = ? WHERE extension = ?');
		$sth->execute([$context, $ext]);
		if ($astman && $astman->connected()) {
			$astman->database_put('AMPUSER', $ext . '/voicemail', $context);
		}
	}

	private function applyBulkWebrtc(string $ext, bool $enable, string $transport): void
	{
		if ($enable) {
			if ($transport === '') {
				$transport = $this->defaultWssTransport();
			}
			foreach ([
				'webrtc' => 'yes',
				'avpf' => 'yes',
				'media_encryption' => 'dtls',
				'icesupport' => 'yes',
				'rtcp_mux' => 'yes',
				'direct_media' => 'no',
				'force_rport' => 'yes',
				'bundle' => 'yes',
				'rtp_symmetric' => 'yes',
				'rewrite_contact' => 'yes',
				'dtmfmode' => 'rfc4733',
			] as $keyword => $value) {
				$this->upsertSipKeyword($ext, $keyword, $value);
			}
			if ($transport !== '') {
				$this->upsertSipKeyword($ext, 'transport', $transport);
			}
		} else {
			foreach ([
				'webrtc' => 'no',
				'avpf' => 'no',
				'media_encryption' => 'no',
				'icesupport' => 'no',
				'rtcp_mux' => 'no',
				'direct_media' => 'yes',
				'bundle' => 'no',
				'transport' => '',
			] as $keyword => $value) {
				$this->upsertSipKeyword($ext, $keyword, $value);
			}
		}
		$this->invalidatePhoneProvision($ext);
	}

	public function listPjsipTransports(): array
	{
		$driver = $this->FreePBX->Core->getDriver('pjsip');
		if (!$driver || !method_exists($driver, 'getTransportConfigs')) {
			return [];
		}
		$configs = $driver->getTransportConfigs();
		if (!is_array($configs)) {
			return [];
		}
		$names = array_keys($configs);
		sort($names);
		return $names;
	}

	public function defaultWssTransport(): string
	{
		$names = $this->listPjsipTransports();
		foreach ($names as $name) {
			if (str_ends_with($name, '-wss') || $name === 'wss' || $name === 'transport-wss') {
				return $name;
			}
		}
		foreach ($names as $name) {
			if (str_ends_with($name, '-ws') || $name === 'ws' || $name === 'transport-ws') {
				return $name;
			}
		}
		return '';
	}

	private function isWebsocketTransport(string $name): bool
	{
		return str_ends_with($name, '-wss')
			|| str_ends_with($name, '-ws')
			|| in_array($name, ['wss', 'ws', 'transport-wss', 'transport-ws'], true);
	}

	private function upsertSipKeyword(string $id, string $keyword, string $data): void
	{
		$check = $this->db->prepare('SELECT COUNT(*) FROM sip WHERE id = ? AND keyword = ?');
		$check->execute([$id, $keyword]);
		if ((int) $check->fetchColumn() > 0) {
			$sth = $this->db->prepare('UPDATE sip SET data = ? WHERE id = ? AND keyword = ?');
			$sth->execute([$data, $id, $keyword]);
			return;
		}
		$ins = $this->db->prepare('INSERT INTO sip (id, keyword, data, flags) VALUES (?, ?, ?, 0)');
		$ins->execute([$id, $keyword, $data]);
	}

	private function invalidatePhoneProvision(string $ext): void
	{
		$sth = $this->db->prepare('UPDATE exunity_phones SET provision_config_hash = NULL WHERE extension = ?');
		$sth->execute([$ext]);
	}

	public function listPhones(): array
	{
		$sql = 'SELECT p.*, v.code AS vendor_code, v.name AS vendor_name, t.name AS template_name
			FROM exunity_phones p
			LEFT JOIN exunity_vendors v ON v.id = p.vendor_id
			LEFT JOIN exunity_templates t ON t.id = p.template_id
			ORDER BY p.last_seen DESC, p.id DESC';
		return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function listPhonesForGrid(): array
	{
		$registered = $this->pjsipRegisteredExtensions();
		$rows = [];
		foreach ($this->listPhones() as $phone) {
			$ext = trim((string) ($phone['extension'] ?? ''));
			if ($ext === '') {
				$sip = 'waiting';
				$sipLabel = _('Waiting');
			} elseif (isset($registered[$ext])) {
				$sip = 'online';
				$sipLabel = _('Online');
			} else {
				$sip = 'offline';
				$sipLabel = _('Offline');
			}
			$rows[] = [
				'id' => $phone['id'],
				'mac_address' => $phone['mac_address'],
				'ip_address' => $phone['ip_address'],
				'model' => $phone['model'],
				'vendor_name' => $phone['vendor_name'],
				'extension' => $ext,
				'sip_status' => $sipLabel,
				'sip_class' => $sip,
				'last_seen' => $phone['last_seen'],
				'provision_status' => $phone['provision_status'],
				'actions' => '<a href="?display=exunity_phones&amp;view=form&amp;id=' . (int) $phone['id'] . '"><i class="fa fa-edit"></i></a> '
					. '<a href="?display=exunity_phones&amp;action=delete&amp;id=' . (int) $phone['id'] . '"><i class="fa fa-trash"></i></a>',
			];
		}
		return $rows;
	}

	public function getPhone($id): ?array
	{
		$sth = $this->db->prepare('SELECT p.*, v.code AS vendor_code, v.name AS vendor_name
			FROM exunity_phones p LEFT JOIN exunity_vendors v ON v.id = p.vendor_id WHERE p.id = ?');
		$sth->execute([(int) $id]);
		$row = $sth->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function getPhoneByMac(string $mac): ?array
	{
		$normalized = $this->normalizeMac($mac);
		if (!$normalized) {
			return null;
		}
		$sth = $this->db->prepare('SELECT p.*, v.code AS vendor_code, v.name AS vendor_name
			FROM exunity_phones p LEFT JOIN exunity_vendors v ON v.id = p.vendor_id
			WHERE REPLACE(UPPER(p.mac_address), ":", "") = ?');
		$sth->execute([str_replace(':', '', $normalized)]);
		$row = $sth->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function updatePhoneExtension($id, $extension, $templateId = null): void
	{
		$extension = $extension === '' ? null : $extension;
		$sth = $this->db->prepare('UPDATE exunity_phones SET extension = ?, template_id = ?, provision_config_hash = NULL WHERE id = ?');
		$sth->execute([$extension, $templateId, $id]);
	}

	public function deletePhone($id): void
	{
		$sth = $this->db->prepare('DELETE FROM exunity_phones WHERE id = ?');
		$sth->execute([(int) $id]);
	}

	public function autoAssignExtensions(): array
	{
		$assigned = [];
		foreach ($this->listPhones() as $p) {
			if (!empty($p['extension'])) {
				$assigned[$p['extension']] = true;
			}
		}
		$free = [];
		foreach ($this->FreePBX->Core->getAllUsers() ?: [] as $user) {
			$ext = (string) ($user['extension'] ?? '');
			if ($ext !== '' && empty($assigned[$ext])) {
				$free[] = $ext;
			}
		}
		$unassigned = array_values(array_filter($this->listPhones(), static fn($p) => empty($p['extension'])));
		$paired = 0;
		foreach ($unassigned as $i => $phone) {
			if (!isset($free[$i])) {
				break;
			}
			$this->updatePhoneExtension($phone['id'], $free[$i], $phone['template_id'] ? (int) $phone['template_id'] : null);
			$paired++;
		}
		return ['status' => true, 'assigned' => $paired];
	}

	public function registerPhoneFromProvision(string $mac, string $ip, array $uaInfo, string $userAgent): array
	{
		$mac = $this->normalizeMac($mac);
		if (!$mac) {
			throw new \InvalidArgumentException('Invalid MAC');
		}
		$vendorId = null;
		if (!empty($uaInfo['vendor_code'])) {
			$vendor = $this->getVendorByCode($uaInfo['vendor_code']);
			$vendorId = $vendor['id'] ?? null;
		}
		$existing = $this->getPhoneByMac($mac);
		$now = date('Y-m-d H:i:s');
		if ($existing) {
			$sth = $this->db->prepare('UPDATE exunity_phones SET ip_address=?, model=COALESCE(?,model), firmware=COALESCE(?,firmware), vendor_id=COALESCE(?,vendor_id), last_seen=?, user_agent=? WHERE id=?');
			$sth->execute([$ip, $uaInfo['model'] ?? null, $uaInfo['firmware'] ?? null, $vendorId, $now, substr($userAgent, 0, 255), $existing['id']]);
			return $this->getPhone((int) $existing['id']);
		}
		$sth = $this->db->prepare('INSERT INTO exunity_phones (mac_address, ip_address, model, firmware, vendor_id, last_seen, provision_status, user_agent)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
		$sth->execute([$mac, $ip, $uaInfo['model'] ?? null, $uaInfo['firmware'] ?? null, $vendorId, $now, _('Auto-registered'), substr($userAgent, 0, 255)]);
		return $this->getPhone((int) $this->db->lastInsertId());
	}

	public function renderPhoneConfig(array $phone): array
	{
		$template = $this->resolveTemplateForPhone($phone);
		if ($template) {
			$vars = $this->buildProvisionVariables($phone);
			return [
				'body' => $this->renderTemplateBody($template['config_body'], $vars),
				'content_type' => $template['content_type'] ?: 'text/plain; charset=utf-8',
				'template' => $template,
			];
		}
		$config = $this->buildDriverConfig($phone);
		$code = $phone['vendor_code'] ?? 'fanvil';
		$manager = new \PhoneManager\PhoneManager();
		$driver = $manager->getDriver($code) ?? $manager->getDriver('fanvil');
		return [
			'body' => $driver->generateCfg($config),
			'content_type' => $driver->cfgContentType(),
			'template' => null,
		];
	}

	public function finalizeProvisionConfig(array $phone, array $rendered): array
	{
		$vendor = strtolower((string) ($phone['vendor_code'] ?? ''));
		if (!$this->isPhoneProvisionReady($phone)) {
			if ($vendor === 'grandstream' || $this->isGrandstreamRequest()) {
				$body = $this->grandstreamPlainToXml('', $this->macRaw($phone));
				$contentType = 'application/xml; charset=utf-8';
			} elseif ($vendor === 'microsip' || $this->isMicrosipRequest()) {
				$body = "{}\n";
				$contentType = 'application/json; charset=utf-8';
			} else {
				$body = "<<VOIP CONFIG FILE>>Version:0.pending.0000000000\r\n\r\n";
				$contentType = 'text/plain; charset=utf-8';
			}
			$hash = hash('sha256', $body);
			return [
				'body' => $body,
				'content_type' => $contentType,
				'template' => $rendered['template'] ?? null,
				'hash' => $hash,
				'etag' => '"' . $hash . '"',
				'changed' => ($phone['provision_config_hash'] ?? '') !== $hash,
				'pending' => true,
			];
		}
		$body = $rendered['body'];
		$contentType = $rendered['content_type'];
		if ($vendor === 'grandstream' || $this->isGrandstreamRequest()) {
			$body = $this->grandstreamPlainToXml($body, $this->macRaw($phone));
			$contentType = 'application/xml; charset=utf-8';
		}
		$hash = hash('sha256', $body);
		return [
			'body' => $body,
			'content_type' => $contentType,
			'template' => $rendered['template'] ?? null,
			'hash' => $hash,
			'etag' => '"' . $hash . '"',
			'changed' => ($phone['provision_config_hash'] ?? '') !== $hash,
			'pending' => false,
		];
	}

	public function isPhoneProvisionReady(array $phone): bool
	{
		if (trim((string) ($phone['extension'] ?? '')) === '') {
			return false;
		}
		$template = $this->resolveTemplateForPhone($phone);
		return $template && !empty($template['config_body']);
	}

	public function markProvisionDelivery($id, $hash, $status): void
	{
		$sth = $this->db->prepare('UPDATE exunity_phones SET provision_config_hash = ?, last_provisioned = NOW(), provision_status = ? WHERE id = ?');
		$sth->execute([$hash, $status, $id]);
	}

	public function setPhoneProvisionStatus($id, $status): void
	{
		$sth = $this->db->prepare('UPDATE exunity_phones SET provision_status = ? WHERE id = ?');
		$sth->execute([$status, $id]);
	}

	public function resolveTemplateForPhone(array $phone): ?array
	{
		if (!empty($phone['template_id'])) {
			$t = $this->getTemplate((int) $phone['template_id']);
			if ($t && !empty($t['config_body'])) {
				return $t;
			}
		}
		$model = trim((string) ($phone['model'] ?? ''));
		$vendorId = (int) ($phone['vendor_id'] ?? 0);
		foreach ($this->listTemplates() as $t) {
			if (empty($t['config_body'])) {
				continue;
			}
			$models = $this->parseModels($t['models'] ?? '');
			$vendorOk = !$t['vendor_id'] || !$vendorId || (int) $t['vendor_id'] === $vendorId;
			if (!$vendorOk || $models === []) {
				continue;
			}
			foreach ($models as $m) {
				if ($model !== '' && (strcasecmp($model, $m) === 0 || stripos($model, $m) !== false)) {
					return $t;
				}
			}
		}
		return $this->getDefaultTemplate($vendorId ?: null);
	}

	public function buildProvisionVariables(array $phone): array
	{
		$settings = $this->getAllSettings();
		$sip = $this->sipSettingsForPhone($phone);
		$mac = $phone['mac_address'] ?? '';
		$macRaw = strtolower(str_replace(':', '', $mac));
		$extension = (string) ($phone['extension'] ?? '');
		$lang = $settings['default_language'] ?: 'ru';
		$tz = $settings['default_timezone'] ?: 'Asia/Tashkent';
		$vars = [];
		foreach ($this->listVariables() as $v) {
			$vars[$v['name']] = $v['default_value'] ?? '';
		}
		$runtime = [
			'sip_server' => $sip['host'],
			'sip_port' => (string) $sip['port'],
			'sip_transport' => $sip['transport'],
			'sip_extension' => $extension,
			'sip_password' => $sip['secret'],
			'display_name' => $sip['name'] ?: $extension,
			'timezone' => $tz,
			'language' => $lang,
			'language_fanvil' => $lang === 'en' ? 'English' : 'Russian',
			'language_grandstream' => $lang,
			'language_yealink' => $lang === 'en' ? 'English' : 'Russian',
			'timezone_grandstream' => $this->mapGrandstreamTz($tz),
			'timezone_yealink' => $this->mapYealinkTz($tz),
			'microsip_server' => ($sip['port'] != 5060 && !str_contains($sip['host'], ':')) ? $sip['host'] . ':' . $sip['port'] : $sip['host'],
			'account_label' => $settings['account_label'] ?: 'Office',
			'admin_password' => $settings['default_admin_password'],
			'mac' => $mac,
			'mac_raw' => $macRaw,
			'mac_upper' => strtoupper($macRaw),
			'ip' => $phone['ip_address'] ?? '',
			'model' => $phone['model'] ?? '',
			'firmware' => $phone['firmware'] ?? '',
			'vendor_name' => $phone['vendor_name'] ?? '',
			'provision_base_url' => $this->provisionBaseUrl(),
			'phonebook_name' => $this->phonebook()->bookName(),
			'phonebook_url' => $this->phonebook()->urlForVendor($phone['vendor_code'] ?? ''),
			'phonebook_yealink_url' => $this->phonebook()->urlFor('yealink'),
			'phonebook_grandstream_url' => $this->phonebook()->urlFor('grandstream'),
			'phonebook_fanvil_url' => $this->phonebook()->urlFor('fanvil'),
			'phonebook_microsip_url' => $this->phonebook()->urlFor('microsip'),
		];
		foreach ($runtime as $k => $v) {
			if ($v !== '' && $v !== null) {
				$vars[$k] = (string) $v;
			}
		}
		return $vars;
	}

	public function sipSettingsForPhone(array $phone): array
	{
		$settings = $this->getAllSettings();
		$host = $settings['provision_sip_host'];
		if ($host === '') {
			try {
				$host = (string) $this->FreePBX->Sipsettings->getConfig('externip');
			} catch (\Throwable $e) {
				$host = '';
			}
		}
		if ($host === '' || $host === '127.0.0.1' || $host === '::1') {
			$host = $_SERVER['SERVER_ADDR'] ?? '';
		}
		if ($host === '' || $host === '127.0.0.1' || $host === '::1') {
			$host = $this->localNonLoopbackIp();
		}
		if ($host === '') {
			$host = '10.18.10.188';
		}
		$port = (int) ($settings['provision_sip_port'] ?: 5060);
		$transport = $settings['provision_sip_transport'] ?: 'udp';
		$secret = '';
		$name = '';
		$ext = (string) ($phone['extension'] ?? '');
		if ($ext !== '') {
			$user = $this->FreePBX->Core->getUser($ext);
			$name = $user['name'] ?? $ext;
			$device = $this->FreePBX->Core->getDevice($ext);
			$secret = $device['secret'] ?? '';
			if ($secret === '') {
				try {
					$sth = $this->db->prepare("SELECT data FROM pjsip WHERE id = ? AND keyword = 'secret' LIMIT 1");
					$sth->execute([$ext]);
					$secret = (string) $sth->fetchColumn();
				} catch (\Throwable $e) {
					$secret = '';
				}
			}
		}
		return ['host' => $host, 'port' => $port, 'transport' => $transport, 'secret' => $secret, 'name' => $name];
	}

	private function buildDriverConfig(array $phone): array
	{
		$vars = $this->buildProvisionVariables($phone);
		return [
			'sip_extension' => $vars['sip_extension'] ?? '',
			'sip_password' => $vars['sip_password'] ?? '',
			'sip_server' => $vars['sip_server'] ?? '',
			'sip_port' => $vars['sip_port'] ?? 5060,
			'sip_transport' => $vars['sip_transport'] ?? 'udp',
			'display_name' => $vars['display_name'] ?? '',
			'timezone' => $vars['timezone'] ?? 'Asia/Tashkent',
			'language' => $vars['language'] ?? 'ru',
			'admin_password' => $vars['admin_password'] ?? '',
			'account_label' => $vars['account_label'] ?? 'Office',
			'provision_base_url' => $this->provisionBaseUrl(),
			'phonebook_name' => $vars['phonebook_name'] ?? 'Company',
			'phonebook_url' => $vars['phonebook_url'] ?? '',
			'phonebook_yealink_url' => $vars['phonebook_yealink_url'] ?? '',
			'phonebook_grandstream_url' => $vars['phonebook_grandstream_url'] ?? '',
			'phonebook_fanvil_url' => $vars['phonebook_fanvil_url'] ?? '',
			'phonebook_microsip_url' => $vars['phonebook_microsip_url'] ?? '',
		];
	}

	public function renderTemplateBody(string $body, array $vars): string
	{
		return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
			return $vars[$m[1]] ?? '';
		}, $body);
	}

	public function listTemplates(): array
	{
		return $this->db->query('SELECT t.*, v.name AS vendor_name, v.code AS vendor_code FROM exunity_templates t
			LEFT JOIN exunity_vendors v ON v.id = t.vendor_id ORDER BY t.name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function getTemplate($id): ?array
	{
		$sth = $this->db->prepare('SELECT * FROM exunity_templates WHERE id = ?');
		$sth->execute([(int) $id]);
		$row = $sth->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function getDefaultTemplate($vendorId = null): ?array
	{
		if ($vendorId) {
			$sth = $this->db->prepare('SELECT * FROM exunity_templates WHERE vendor_id = ? AND is_default = 1 AND config_body IS NOT NULL ORDER BY id LIMIT 1');
			$sth->execute([(int) $vendorId]);
			$row = $sth->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				return $row;
			}
		}
		$row = $this->db->query('SELECT * FROM exunity_templates WHERE is_default = 1 AND config_body IS NOT NULL ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function saveTemplate(array $request): void
	{
		$id = !empty($request['id']) ? (int) $request['id'] : null;
		$fields = [
			trim($request['name'] ?? ''),
			(int) ($request['vendor_id'] ?? 0) ?: null,
			trim($request['models'] ?? ''),
			$request['config_body'] ?? '',
			trim($request['content_type'] ?? 'text/plain; charset=utf-8'),
			!empty($request['is_default']) ? 1 : 0,
		];
		if ($id) {
			$fields[] = $id;
			$sth = $this->db->prepare('UPDATE exunity_templates SET name=?, vendor_id=?, models=?, config_body=?, content_type=?, is_default=? WHERE id=?');
			$sth->execute($fields);
		} else {
			$sth = $this->db->prepare('INSERT INTO exunity_templates (name, vendor_id, models, config_body, content_type, is_default) VALUES (?,?,?,?,?,?)');
			$sth->execute($fields);
		}
	}

	public function deleteTemplate($id): void
	{
		$sth = $this->db->prepare('DELETE FROM exunity_templates WHERE id = ?');
		$sth->execute([(int) $id]);
	}

	public function listVendors(): array
	{
		return $this->db->query('SELECT * FROM exunity_vendors ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function getVendorByCode(string $code): ?array
	{
		$sth = $this->db->prepare('SELECT * FROM exunity_vendors WHERE code = ?');
		$sth->execute([$code]);
		$row = $sth->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function listVariables(): array
	{
		return $this->db->query('SELECT * FROM exunity_variables ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function coreExtensions(): array
	{
		$out = ['' => _('— none —')];
		foreach ($this->FreePBX->Core->getAllUsers() ?: [] as $user) {
			$out[$user['extension']] = $user['extension'] . ' - ' . $user['name'];
		}
		return $out;
	}

	public function sendTelegram($chatid, $text): array
	{
		$token = $this->getAllSettings()['tg_token'];
		if ($token === '' || $chatid === '') {
			return ['status' => false, 'message' => _('Bot token or chat ID is empty')];
		}
		$url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
		$result = $this->telegramPost($url, [
			'chat_id' => $chatid,
			'text' => $text,
			'parse_mode' => 'HTML',
			'disable_web_page_preview' => true,
		]);
		if ($result['ok']) {
			return ['status' => true, 'message' => _('Sent')];
		}
		$fallback = $this->telegramPost($url, [
			'chat_id' => $chatid,
			'text' => html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'),
			'disable_web_page_preview' => true,
		]);
		return [
			'status' => $fallback['ok'],
			'message' => $fallback['ok'] ? _('Sent') : ($fallback['error'] ?: $result['error']),
		];
	}

	private function telegramPost(string $url, array $payload): array
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 8,
		]);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$decoded = json_decode((string) $body, true);
		$ok = $code >= 200 && $code < 300 && !empty($decoded['ok']);
		return [
			'ok' => $ok,
			'error' => $err ?: ($decoded['description'] ?? $body),
		];
	}

	public function sendTelegramVoice(string $chatid, string $audioPath, string $caption): array
	{
		$token = $this->getAllSettings()['tg_token'];
		if ($token === '' || $chatid === '' || !is_file($audioPath)) {
			return ['status' => false, 'message' => _('Bot token, chat ID, or audio file is empty')];
		}
		$voice = $this->voiceFileForTelegram($audioPath);
		$extra = [
			'caption' => $caption,
			'parse_mode' => 'HTML',
		];
		if ($voice['type'] === 'voice') {
			$result = $this->telegramUpload($token, 'sendVoice', $chatid, 'voice', $voice['path'], $voice['name'], $voice['mime'], $extra);
		} elseif ($voice['type'] === 'audio') {
			$result = $this->telegramUpload($token, 'sendAudio', $chatid, 'audio', $voice['path'], $voice['name'], $voice['mime'], $extra);
		} else {
			$result = $this->telegramUpload($token, 'sendDocument', $chatid, 'document', $voice['path'], $voice['name'], $voice['mime'], $extra);
		}
		if (!empty($voice['tmp']) && is_file($voice['tmp'])) {
			@unlink($voice['tmp']);
		}
		if ($result['ok']) {
			return ['status' => true, 'message' => _('Sent')];
		}
		$text = $this->sendTelegram($chatid, $caption);
		return [
			'status' => $text['status'],
			'message' => $result['error'] ?: $text['message'],
		];
	}

	/** @return array{type:string,path:string,name:string,mime:string,tmp:?string} */
	private function voiceFileForTelegram(string $audioPath): array
	{
		$ext = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));
		if ($ext === 'ogg') {
			return ['type' => 'voice', 'path' => $audioPath, 'name' => 'voicemail.ogg', 'mime' => 'audio/ogg', 'tmp' => null];
		}
		$tmp = sys_get_temp_dir() . '/exunity-vm-' . bin2hex(random_bytes(6));
		$ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
		if ($ffmpeg !== '') {
			$ogg = $tmp . '.ogg';
			$cmd = escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($audioPath)
				. ' -c:a libopus -b:a 24k -vbr on -application voip ' . escapeshellarg($ogg) . ' 2>/dev/null';
			exec($cmd, $out, $code);
			if ($code === 0 && is_file($ogg) && filesize($ogg) > 0) {
				return ['type' => 'voice', 'path' => $ogg, 'name' => 'voicemail.ogg', 'mime' => 'audio/ogg', 'tmp' => $ogg];
			}
			@unlink($ogg);
			$mp3 = $tmp . '.mp3';
			$cmd = escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($audioPath)
				. ' -codec:a libmp3lame -b:a 32k ' . escapeshellarg($mp3) . ' 2>/dev/null';
			exec($cmd, $out, $code);
			if ($code === 0 && is_file($mp3) && filesize($mp3) > 0) {
				return ['type' => 'audio', 'path' => $mp3, 'name' => 'voicemail.mp3', 'mime' => 'audio/mpeg', 'tmp' => $mp3];
			}
			@unlink($mp3);
		}
		$mime = match ($ext) {
			'mp3' => 'audio/mpeg',
			'wav', 'wav49' => 'audio/wav',
			'gsm' => 'audio/gsm',
			default => 'application/octet-stream',
		};
		return ['type' => 'document', 'path' => $audioPath, 'name' => 'voicemail.' . ($ext !== '' ? $ext : 'bin'), 'mime' => $mime, 'tmp' => null];
	}

	private function telegramUpload(string $token, string $method, string $chatid, string $field, string $path, string $filename, string $mime, array $extra): array
	{
		$url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method;
		$payload = $extra;
		$payload['chat_id'] = $chatid;
		$payload[$field] = new \CURLFile($path, $mime, $filename);
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 60,
		]);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$decoded = json_decode((string) $body, true);
		$ok = $code >= 200 && $code < 300 && !empty($decoded['ok']);
		return [
			'ok' => $ok,
			'error' => $err ?: ($decoded['description'] ?? (string) $body),
		];
	}

	public function sendTelegramTest(string $chatid): array
	{
		return $this->sendTelegram($chatid, $this->renderTelegramTemplate('ring', [
			'callerid' => '123456789',
			'callername' => 'Test',
			'extension' => '101',
			'did' => '',
			'diversion' => '987654321',
			'diversion_reason' => 'unconditional',
			'time' => date('Y-m-d H:i:s'),
		]));
	}

	public function notifyIncoming(string $event, string $extension, string $callerid, string $callername, string $did = '', string $diversion = '', string $diversionReason = ''): void
	{
		$vars = [
			'callerid' => $callerid,
			'callername' => $callername,
			'extension' => $extension,
			'did' => $did,
			'diversion' => $diversion,
			'diversion_reason' => $diversionReason,
			'rdnis' => $diversion,
			'time' => date('Y-m-d H:i:s'),
		];
		if ($event === 'dest') {
			$dest = $this->getTgDest((int) $extension);
			if ($dest && $dest['chatid'] !== '') {
				$this->sendTelegram($dest['chatid'], $this->renderTelegramTemplate('ring', $vars));
			}
			return;
		}
		$row = $this->getExtenTelegram($extension);
		if (empty($row['enabled']) || $row['chatid'] === '') {
			return;
		}
		$tpl = $event === 'missed' ? 'missed' : 'ring';
		if ($tpl === 'missed' && trim($this->getAllSettings()['tg_template_missed']) === '') {
			return;
		}
		$this->sendTelegram($row['chatid'], $this->renderTelegramTemplate($tpl, $vars));
	}

	public function renderTelegramTemplate(string $which, array $vars): string
	{
		$settings = $this->getAllSettings();
		$tpl = match ($which) {
			'missed' => $settings['tg_template_missed'],
			'vm' => $settings['tg_template_vm'],
			default => $settings['tg_template_ring'],
		};
		if ($tpl === '' && $which !== 'vm') {
			$tpl = $settings['tg_template_ring'];
		}
		if ($tpl === '' && $which === 'vm') {
			$tpl = "Voicemail\nFrom: {{callername}} <{{callerid}}>\nTo: {{extension}}\nDuration: {{duration}}s\nTime: {{time}}";
		}
		$vars = $this->expandTelegramVars($vars);
		$htmlKeys = [
			'callerid_tg' => true, 'callerid_tel' => true,
			'did_tg' => true, 'did_tel' => true,
			'diversion_tg' => true, 'diversion_tel' => true,
			'rdnis_tg' => true, 'rdnis_tel' => true,
		];
		$hasDiversion = trim((string) ($vars['diversion'] ?? '')) !== '';
		$tpl = preg_replace_callback('/\{\{\s*if_diversion\s*\}\}(.*?)\{\{\s*\/if_diversion\s*\}\}/s', static function ($m) use ($hasDiversion) {
			return $hasDiversion ? $m[1] : '';
		}, $tpl);
		$text = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars, $htmlKeys) {
			$key = $m[1];
			$value = (string) ($vars[$key] ?? '');
			if (isset($htmlKeys[$key])) {
				return $value;
			}
			return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}, $tpl);
		$text = $this->convertTelegramMarkdownLinks($text);
		$text = $this->convertTelegramIconLinks($text);
		return $this->finalizeTelegramHtml($text);
	}

	public function expandTelegramVars(array $vars): array
	{
		$diversion = trim((string) ($vars['diversion'] ?? ''));
		$rdnis = trim((string) ($vars['rdnis'] ?? ''));
		if ($diversion === '' && $rdnis !== '') {
			$diversion = $rdnis;
		} elseif ($rdnis === '' && $diversion !== '') {
			$rdnis = $diversion;
		}
		$vars['diversion'] = $diversion;
		$vars['rdnis'] = $rdnis;
		foreach (['callerid', 'did', 'diversion', 'rdnis'] as $base) {
			$e164 = $this->telegramE164((string) ($vars[$base] ?? ''));
			$vars[$base . '_e164'] = $e164;
			$href = $e164 === '' ? '' : 'https://t.me/+' . $e164;
			$vars[$base . '_tg'] = $href === '' ? '' : $this->telegramHtmlLink($href, '📩');
			$vars[$base . '_tel'] = $href === '' ? '' : $this->telegramHtmlLink($href, '☎️');
		}
		return $vars;
	}

	public function telegramE164(string $raw): string
	{
		$cc = preg_replace('/\D+/', '', (string) ($this->getAllSettings()['tg_country_code'] ?? '998')) ?: '998';
		$trimmed = trim($raw);
		$digits = preg_replace('/\D+/', '', $trimmed);
		if ($digits === '') {
			return '';
		}
		if (str_starts_with($trimmed, '00')) {
			$digits = preg_replace('/^00/', '', $digits);
		} elseif (str_starts_with($trimmed, '+') || str_starts_with($digits, $cc)) {
			// already international
		} else {
			$digits = ltrim($digits, '0');
			$digits = $cc . $digits;
		}
		if (strlen($digits) < 11) {
			return '';
		}
		return $digits;
	}

	private function telegramHtmlLink(string $url, string $label): string
	{
		$url = $this->sanitizeTelegramHref($url);
		if ($url === '') {
			return $label;
		}
		return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $label . '</a>';
	}

	private function sanitizeTelegramHref(string $url): string
	{
		$url = trim($url);
		if (!preg_match('#^(https?://|tg://)#i', $url)) {
			return '';
		}
		return $url;
	}

	private function convertTelegramMarkdownLinks(string $text): string
	{
		return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|tg:\/\/[^)\s]+)\)/u', function ($m) {
			return $this->telegramHtmlLink($m[2], $m[1]);
		}, $text) ?? $text;
	}

	private function convertTelegramIconLinks(string $text): string
	{
		return preg_replace_callback('/((?:[\x{2600}-\x{27BF}\x{1F300}-\x{1FAFF}\x{FE0F}]\s*)+)\(\s*(https?:\/\/[^)\s]+|tg:\/\/[^)\s]+)\s*\)/u', function ($m) {
			$icon = trim($m[1]);
			if ($icon === '') {
				return $m[0];
			}
			return $this->telegramHtmlLink($m[2], $icon);
		}, $text) ?? $text;
	}

	private function finalizeTelegramHtml(string $text): string
	{
		$slots = [];
		$protected = preg_replace_callback('/<\/?(?:a(?:\s+href="[^"]*")?|b|strong|i|em|u|s|strike|del|code|pre|tg-spoiler|blockquote)(?:\s[^>]*)?>/i', static function ($m) use (&$slots) {
			$key = "\x00TG" . count($slots) . "\x00";
			$slots[$key] = $m[0];
			return $key;
		}, $text);
		$protected = htmlspecialchars($protected, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false);
		return strtr($protected, $slots);
	}

	public function normalizeMac(?string $mac): ?string
	{
		if ($mac === null || $mac === '') {
			return null;
		}
		$hex = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
		if (strlen($hex) !== 12) {
			return null;
		}
		return implode(':', str_split($hex, 2));
	}

	public function parsePhoneUserAgent(string $userAgent): array
	{
		$ua = trim($userAgent);
		$macPattern = '(?:[a-fA-F0-9]{2}[:-]){5}[a-fA-F0-9]{2}|[a-fA-F0-9]{12}';
		$info = ['vendor_code' => null, 'vendor_name' => null, 'model' => null, 'firmware' => null, 'mac' => null];
		$patterns = [
			['grandstream', 'Grandstream', '/Grandstream\s+Model\s+(?:HW\s+)?(\S+)\s+SW\s+(\S+)\s+DevId\s+(' . $macPattern . ')/i'],
			['grandstream', 'Grandstream', '/Grandstream\s+(\S+)\s+(\S+)\s+(' . $macPattern . ')/i'],
			['fanvil', 'Fanvil', '/Fanvil[\s\/]+(\S+)\s+(\S+)\s+(' . $macPattern . ')/i'],
			['yealink', 'Yealink', '/Yealink[\s\-\/]+(\S+)\s+(\S+)\s+(' . $macPattern . ')/i'],
		];
		foreach ($patterns as [$code, $name, $re]) {
			if (preg_match($re, $ua, $m)) {
				return ['vendor_code' => $code, 'vendor_name' => $name, 'model' => $m[1], 'firmware' => $m[2], 'mac' => $this->normalizeMac($m[3])];
			}
		}
		if (preg_match('/MicroSIP[\/\s-]+([0-9]+(?:\.[0-9]+)*)/i', $ua, $m) || stripos($ua, 'microsip') !== false) {
			$info['vendor_code'] = 'microsip';
			$info['vendor_name'] = 'MicroSIP';
			$info['model'] = 'MicroSIP';
			$info['firmware'] = $m[1] ?? null;
		} elseif (stripos($ua, 'grandstream') !== false) {
			$info['vendor_code'] = 'grandstream';
			$info['vendor_name'] = 'Grandstream';
		} elseif (stripos($ua, 'fanvil') !== false) {
			$info['vendor_code'] = 'fanvil';
			$info['vendor_name'] = 'Fanvil';
		} elseif (stripos($ua, 'yealink') !== false) {
			$info['vendor_code'] = 'yealink';
			$info['vendor_name'] = 'Yealink';
		}
		if (preg_match('/(' . $macPattern . ')/', $ua, $m)) {
			$info['mac'] = $this->normalizeMac($m[1]);
		}
		return $info;
	}

	public function provisionBaseUrl(): string
	{
		$url = trim((string) $this->getConfig('provision_base_url'));
		return $url !== '' ? rtrim($url, '/') : $this->defaultProvisionUrl();
	}

	private function localNonLoopbackIp(): string
	{
		$ips = preg_split('/\s+/', trim((string) shell_exec('hostname -I 2>/dev/null')));
		foreach ($ips as $ip) {
			if (filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '127.0.0.1' && $ip !== '::1') {
				return $ip;
			}
		}
		return '';
	}

	private function defaultProvisionUrl(): string
	{
		$host = $_SERVER['HTTP_HOST'] ?? '10.18.10.188';
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		return $scheme . '://' . $host . '/provision';
	}

	public function pjsipRegisteredExtensions(): array
	{
		$out = [];
		$astman = $this->FreePBX->astman;
		if (!$astman || !$astman->connected()) {
			return $out;
		}
		$response = $astman->send_request('Command', ['Command' => 'pjsip show contacts']);
		$lines = explode("\n", $response['data'] ?? '');
		foreach ($lines as $line) {
			$line = trim($line);
			if (!str_starts_with($line, 'Contact:')) {
				continue;
			}
			if (str_contains($line, ' Unavail')) {
				continue;
			}
			if (preg_match('/Contact:\s+([^\/\s]+)\//', $line, $m)) {
				$out[$m[1]] = true;
			}
		}
		return $out;
	}

	private function parseModels($models): array
	{
		if (is_array($models)) {
			return $models;
		}
		$models = trim((string) $models);
		if ($models === '') {
			return [];
		}
		$decoded = json_decode($models, true);
		if (is_array($decoded)) {
			return $decoded;
		}
		return array_values(array_filter(array_map('trim', explode(',', $models))));
	}

	private function macRaw(array $phone): string
	{
		return strtolower(str_replace(':', '', $phone['mac_address'] ?? ''));
	}

	private function isGrandstreamRequest(): bool
	{
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
		return stripos($ua, 'grandstream') !== false || (bool) preg_match('#/cfg#i', $path);
	}

	private function isMicrosipRequest(): bool
	{
		$model = strtolower(trim($_GET['model'] ?? ''));
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		return $model === 'microsip' || stripos($ua, 'microsip') !== false;
	}

	private function grandstreamPlainToXml(string $body, string $macRaw): string
	{
		if (preg_match('/^\s*<\?xml|<gs_provision/i', ltrim($body))) {
			return $body;
		}
		$pXml = '';
		foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			if (preg_match('/^(P\d+)\s*=\s*(.*)$/', $line, $m)) {
				$pXml .= '    <' . $m[1] . '>' . htmlspecialchars($m[2], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $m[1] . ">\n";
			}
		}
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<gs_provision version="1">' . "\n"
			. '  <mac>' . htmlspecialchars($macRaw, ENT_XML1) . "</mac>\n"
			. "  <config version=\"1\">\n"
			. $pXml
			. "  </config>\n"
			. "</gs_provision>\n";
	}

	private function mapGrandstreamTz(string $tz): string
	{
		$map = ['Asia/Tashkent' => 'UZT-5', 'Europe/Moscow' => 'MSK-3', 'UTC' => 'UTC'];
		return $map[$tz] ?? 'UZT-5';
	}

	private function mapYealinkTz(string $tz): string
	{
		$map = ['Asia/Tashkent' => '+5', 'Europe/Moscow' => '+3', 'UTC' => '0'];
		return $map[$tz] ?? '+5';
	}

	private function showPhonesPage(): string
	{
		if (($_REQUEST['view'] ?? '') === 'form') {
			$item = !empty($_REQUEST['id']) ? $this->getPhone($_REQUEST['id']) : null;
			return load_view(__DIR__ . '/views/phone_form.php', [
				'item' => $item,
				'extensions' => $this->coreExtensions(),
				'templates' => $this->listTemplates(),
			]);
		}
		return load_view(__DIR__ . '/views/phones.php', []);
	}

	private function showTemplatesPage(): string
	{
		if (($_REQUEST['view'] ?? '') === 'form') {
			$item = !empty($_REQUEST['id']) ? $this->getTemplate($_REQUEST['id']) : [];
			return load_view(__DIR__ . '/views/template_form.php', [
				'item' => $item,
				'vendors' => $this->listVendors(),
			]);
		}
		return load_view(__DIR__ . '/views/templates.php', []);
	}

	private function showTgDestPage(): string
	{
		if (($_REQUEST['view'] ?? '') === 'form') {
			$item = !empty($_REQUEST['id']) ? $this->getTgDest($_REQUEST['id']) : [];
			return load_view(__DIR__ . '/views/tgdest_form.php', ['item' => $item]);
		}
		return load_view(__DIR__ . '/views/tgdest.php', []);
	}

	private function seedDefaults(): void
	{
		$seeds = require __DIR__ . '/seed/templates.php';
		foreach ($seeds as $i => $seed) {
			$sth = $this->db->prepare('SELECT id FROM exunity_vendors WHERE code = ?');
			$sth->execute([$seed['code']]);
			$vid = $sth->fetchColumn();
			if (!$vid) {
				$ins = $this->db->prepare('INSERT INTO exunity_vendors (code, name, driver_class) VALUES (?, ?, ?)');
				$ins->execute([$seed['code'], $seed['name'], $seed['name'] . 'Driver']);
				$vid = $this->db->lastInsertId();
			}
			$chk = $this->db->prepare('SELECT id FROM exunity_templates WHERE name = ?');
			$chk->execute([$seed['template_name']]);
			if (!$chk->fetchColumn()) {
				$ins = $this->db->prepare('INSERT INTO exunity_templates (name, vendor_id, models, config_body, content_type, is_default) VALUES (?,?,?,?,?,1)');
				$ins->execute([$seed['template_name'], $vid, json_encode($seed['models']), $seed['body'], $seed['content_type']]);
			}
		}
		$vars = [
			['sip_server', 'SIP server', '', '', 10],
			['sip_port', 'SIP port', '', '5060', 11],
			['sip_transport', 'SIP transport', '', 'udp', 12],
			['sip_extension', 'SIP extension', '', '', 20],
			['sip_password', 'SIP password', '', '', 21],
			['display_name', 'Display name', '', '', 22],
			['timezone', 'Timezone', '', 'Asia/Tashkent', 30],
			['language', 'Language', '', 'ru', 31],
			['admin_password', 'Phone admin password', '', '', 40],
			['mac', 'MAC', '', '', 50],
			['provision_base_url', 'Provision URL', '', $this->defaultProvisionUrl(), 58],
			['phonebook_name', 'Phonebook name', '', 'Company', 59],
			['phonebook_url', 'Phonebook URL', '', '', 60],
			['phonebook_yealink_url', 'Yealink phonebook URL', '', '', 61],
			['phonebook_grandstream_url', 'Grandstream phonebook URL', '', '', 62],
			['phonebook_fanvil_url', 'Fanvil phonebook URL', '', '', 63],
			['phonebook_microsip_url', 'MicroSIP directory URL', '', '', 64],
			['account_label', 'MicroSIP account label', '', 'Office', 38],
			['microsip_server', 'MicroSIP server', '', '', 37],
		];
		$ins = $this->db->prepare('INSERT INTO exunity_variables (name, label, description, default_value, is_system, sort_order)
			VALUES (?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)');
		foreach ($vars as $v) {
			try {
				$ins->execute($v);
			} catch (\Throwable $e) {
				$exists = $this->db->prepare('SELECT id FROM exunity_variables WHERE name = ?');
				$exists->execute([$v[0]]);
				if (!$exists->fetchColumn()) {
					$this->db->prepare('INSERT INTO exunity_variables (name, label, description, default_value, is_system, sort_order) VALUES (?,?,?,?,1,?)')
						->execute($v);
				}
			}
		}
		if (!$this->getConfig('provision_base_url')) {
			$this->setConfig('provision_base_url', $this->defaultProvisionUrl());
		}
	}

	public function ensurePhonebookTemplates(): void
	{
		$snippets = [
			'yealink' => "\nremote_phonebook.data.1.url = {{phonebook_yealink_url}}\nremote_phonebook.data.1.name = {{phonebook_name}}\nsearch_in_dialing.remote_phonebook.enable = 1\n",
			'grandstream' => "\nP330=1\nP331={{phonebook_grandstream_url}}\nP332=60\n",
			'fanvil' => "\n<PHONEBOOK CONFIG MODULE>\n--Phone Book List--  :\nPhone 1 :Name:{{phonebook_name}}\nPhone 1 :Addr:{{phonebook_fanvil_url}}\n</PHONEBOOK CONFIG MODULE>\n",
		];
		$markers = [
			'yealink' => 'remote_phonebook.data.1',
			'grandstream' => 'P331=',
			'fanvil' => 'PHONEBOOK CONFIG MODULE',
		];
		foreach ($this->listTemplates() as $t) {
			$code = strtolower((string) ($t['vendor_code'] ?? ''));
			$body = (string) ($t['config_body'] ?? '');
			if ($body === '') {
				continue;
			}
			if ($code === 'microsip') {
				if (str_contains($body, 'usersDirectory')) {
					continue;
				}
				$trimmed = rtrim($body);
				if (str_ends_with($trimmed, '}')) {
					$without = rtrim(substr($trimmed, 0, -1));
					$new = $without . ",\n  \"usersDirectory\": \"{{phonebook_microsip_url}}\"\n}\n";
					$sth = $this->db->prepare('UPDATE exunity_templates SET config_body = ? WHERE id = ?');
					$sth->execute([$new, (int) $t['id']]);
				}
				continue;
			}
			if (!isset($snippets[$code])) {
				continue;
			}
			if (str_contains($body, $markers[$code])) {
				continue;
			}
			$sth = $this->db->prepare('UPDATE exunity_templates SET config_body = ? WHERE id = ?');
			$sth->execute([rtrim($body) . $snippets[$code], (int) $t['id']]);
		}
	}

	private function installProvisionWebroot(): void
	{
		$webroot = $this->FreePBX->Config->get('AMPWEBROOT') ?: '/var/www/html';
		$dir = rtrim($webroot, '/') . '/provision';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$index = <<<'PHP'
<?php
$bootstrap_settings['freepbx_auth'] = false;
$restrict_mods = ['exunity' => true, 'core' => true, 'sipsettings' => true, 'contactmanager' => true, 'userman' => true];
if (!@include_once(getenv('FREEPBX_CONF') ?: '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}
try {
	(new \FreePBX\modules\Exunity\Provision(\FreePBX::Exunity()))->handleRequest();
} catch (Throwable $e) {
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo "Provisioning error\n";
	error_log('exunity provision: ' . $e->getMessage());
}
PHP;
		$htaccess = <<<'HT'
RewriteEngine On
RewriteRule ^.+\.cfg$ index.php [L,QSA,NC]
RewriteRule ^cfg([a-f0-9]{12})(?:\.xml)?$ index.php [L,QSA,NC]
RewriteRule ^cfg\.xml$ index.php [L,QSA,NC]
RewriteRule ^cfg[a-z0-9]+\.xml$ index.php [L,QSA,NC]
RewriteRule ^phonebook(/.*)?$ index.php [L,QSA,NC]
RewriteRule ^index\.php$ - [L]
RewriteRule ^$ index.php [L,QSA]
FallbackResource /provision/index.php
HT;
		file_put_contents($dir . '/index.php', $index);
		file_put_contents($dir . '/.htaccess', $htaccess);
	}
}
