<div class="container-fluid ex-calls">
	<h1><?php echo _('eX Call History') ?></h1>
	<div class="fpbx-container">
		<div class="display no-border">
			<div class="ex-calls-bar" id="toolbar-calls">
				<div class="ex-calls-presets">
					<button type="button" class="btn btn-default ex-range" data-range="today"><?php echo _('Today') ?></button>
					<button type="button" class="btn btn-default ex-range" data-range="yesterday"><?php echo _('Yesterday') ?></button>
					<button type="button" class="btn btn-default ex-range is-on" data-range="7"><?php echo _('Last 7 days') ?></button>
					<button type="button" class="btn btn-default ex-range" data-range="30"><?php echo _('Last 30 days') ?></button>
				</div>
				<div class="ex-calls-dates">
					<input type="date" class="form-control" id="ex-date-from" value="<?php echo htmlentities(date('Y-m-d', strtotime('-6 days'))) ?>">
					<span class="ex-calls-sep">—</span>
					<input type="date" class="form-control" id="ex-date-to" value="<?php echo htmlentities(date('Y-m-d')) ?>">
				</div>
				<div class="ex-calls-dirs">
					<button type="button" class="btn btn-default ex-dir is-on" data-dir="all"><?php echo _('All') ?></button>
					<button type="button" class="btn btn-default ex-dir" data-dir="incoming"><?php echo _('Incoming') ?></button>
					<button type="button" class="btn btn-default ex-dir" data-dir="outgoing"><?php echo _('Outgoing') ?></button>
					<button type="button" class="btn btn-default ex-dir" data-dir="internal"><?php echo _('Internal') ?></button>
					<button type="button" class="btn btn-default ex-dir" data-dir="missed"><?php echo _('Missed') ?></button>
				</div>
				<div class="ex-calls-search">
					<input type="text" class="form-control" id="ex-call-q" placeholder="<?php echo _('Number or name') ?>">
				</div>
			</div>
			<table id="exunity-calls"
				data-toggle="table"
				data-url="ajax.php?module=exunity&amp;command=getcalls"
				data-toolbar="#toolbar-calls"
				data-pagination="true"
				data-side-pagination="server"
				data-page-size="50"
				data-page-list="[25, 50, 100]"
				data-query-params="exCallsQueryParams"
				data-escape="false"
				data-cache="false"
				data-sort-name="calldate"
				data-sort-order="desc"
				class="table table-hover">
				<thead>
					<tr>
						<th data-field="time" data-width="170"><?php echo _('Time') ?></th>
						<th data-field="direction_html" data-width="120"><?php echo _('Direction') ?></th>
						<th data-field="from_html"><?php echo _('From') ?></th>
						<th data-field="to_html"><?php echo _('To') ?></th>
						<th data-field="duration" data-width="90"><?php echo _('Duration') ?></th>
						<th data-field="status_html" data-width="110"><?php echo _('Status') ?></th>
						<th data-field="recording_html" data-width="110"><?php echo _('Recording') ?></th>
					</tr>
				</thead>
			</table>
			<p class="help-block"><?php echo _('One row per call. Queue ring attempts and duplicate recordings are hidden.') ?></p>
		</div>
	</div>
	<div id="ex-player-bar" class="ex-player-bar" hidden>
		<button type="button" class="btn btn-sm" id="ex-player-toggle" title="<?php echo htmlentities(_('Play')) ?>"><i class="fa fa-play"></i></button>
		<button type="button" class="btn btn-sm btn-default" id="ex-player-back" title="-5s"><i class="fa fa-undo"></i> 5</button>
		<span id="ex-player-cur" class="ex-player-time">0:00</span>
		<input type="range" id="ex-player-seek" min="0" max="1000" value="0" step="1">
		<span id="ex-player-dur" class="ex-player-time">0:00</span>
		<button type="button" class="btn btn-sm btn-default" id="ex-player-fwd" title="+5s">5 <i class="fa fa-repeat"></i></button>
		<select id="ex-player-rate" class="form-control input-sm" title="<?php echo htmlentities(_('Speed')) ?>">
			<option value="0.75">0.75×</option>
			<option value="1" selected>1×</option>
			<option value="1.25">1.25×</option>
			<option value="1.5">1.5×</option>
			<option value="1.75">1.75×</option>
			<option value="2">2×</option>
		</select>
		<button type="button" class="btn btn-sm btn-default" id="ex-player-close" title="<?php echo htmlentities(_('Close')) ?>"><i class="fa fa-times"></i></button>
		<audio id="ex-call-player" preload="metadata"></audio>
	</div>
</div>
<link href="assets/exunity/css/call-history.css?v=5" rel="stylesheet">
<script src="assets/exunity/js/call-history.js?v=5"></script>
