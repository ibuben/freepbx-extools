$(function () {
	$('#secret_mode, #be_secret_mode').on('change', function () {
		var mode = $(this).val();
		var $form = $(this).closest('form');
		$form.find('#secret_same, #be_secret_same').toggle(mode === 'same');
		$form.find('#secret_pattern, #be_secret_pattern').toggle(mode === 'pattern');
	}).trigger('change');

	function bulkPayload() {
		var data = $('#exunity-bulk-form').serializeArray();
		var out = { module: 'exunity' };
		data.forEach(function (f) { out[f.name] = f.value; });
		out.skip_existing = $('input[name=skip_existing]').is(':checked') ? 1 : 0;
		out.voicemail = $('input[name=voicemail]').is(':checked') ? 1 : 0;
		return out;
	}

	function renderBulk(res) {
		if (!res || res.status === false) {
			$('#exunity-bulk-result').html('<div class="alert alert-danger">' + (res && res.message ? res.message : 'Error') + '</div>');
			return;
		}
		if (res.create) {
			$('#exunity-bulk-result').html('<div class="alert alert-info">Will create ' + res.count + ', skip ' + (res.existing ? res.existing.length : 0) + '</div>');
			return;
		}
		var html = '<div class="alert alert-success">Created ' + res.created + ', skipped ' + res.skipped + ', failed ' + res.failed + '</div><table class="table"><thead><tr><th>Ext</th><th>Status</th><th></th></tr></thead><tbody>';
		(res.results || []).forEach(function (r) {
			html += '<tr><td>' + r.ext + '</td><td>' + r.status + '</td><td>' + (r.message || '') + '</td></tr>';
		});
		html += '</tbody></table>';
		$('#exunity-bulk-result').html(html);
	}

	$('#exunity-bulk-preview').on('click', function () {
		var payload = bulkPayload();
		payload.command = 'bulktest';
		$.post('ajax.php', payload, renderBulk, 'json');
	});
	$('#exunity-bulk-create').on('click', function () {
		if (!confirm('Create this range of extensions?')) {
			return;
		}
		var payload = bulkPayload();
		payload.command = 'bulksave';
		$.post('ajax.php', payload, renderBulk, 'json');
	});

	$('#exunity-tg-test').on('click', function () {
		$.post('ajax.php', {
			module: 'exunity',
			command: 'tgtest',
			chatid: $('#tg_test_chatid').val()
		}, function (res) {
			$('#exunity-tg-test-result').text(res && res.message ? res.message : 'Error');
		}, 'json');
	});

	$('#exunity-cdr-purge').on('click', function () {
		var days = parseInt($('#cdr_recording_keep_days').val(), 10) || 0;
		if (days <= 0) {
			$('#exunity-cdr-purge-result').text('Set a number of days greater than 0, save, then run cleanup.');
			return;
		}
		if (!confirm('Permanently delete CDR recording audio older than ' + days + ' days? Call history rows will be kept.')) {
			return;
		}
		var $btn = $(this).prop('disabled', true);
		$('#exunity-cdr-purge-result').text('Cleaning...');
		$.post('ajax.php', {
			module: 'exunity',
			command: 'cdrrecpurge'
		}, function (res) {
			$btn.prop('disabled', false);
			$('#exunity-cdr-purge-result').text(res && res.message ? res.message : 'Error');
		}, 'json').fail(function () {
			$btn.prop('disabled', false);
			$('#exunity-cdr-purge-result').text('Error');
		});
	});

	function bulkEditPayload() {
		var data = $('#exunity-bulkedit-form').serializeArray();
		var out = { module: 'exunity' };
		data.forEach(function (f) { out[f.name] = f.value; });
		return out;
	}

	function renderBulkEdit(res) {
		if (!res || res.status === false) {
			$('#exunity-bulkedit-result').html('<div class="alert alert-danger">' + (res && res.message ? res.message : 'Error') + '</div>');
			return;
		}
		if (res.preview) {
			var html = '<div class="alert alert-info">Will update ' + res.count + ' extension(s)';
			if (res.fields && res.fields.length) {
				html += ': ' + res.fields.join(', ');
			}
			html += '</div>';
			if (res.missing && res.missing.length) {
				html += '<div class="alert alert-warning">Not found and will be skipped: ' + res.missing.join(', ') + '</div>';
			}
			html += '<table class="table"><thead><tr><th>Ext</th><th>Name</th></tr></thead><tbody>';
			(res.extensions || []).forEach(function (r) {
				html += '<tr><td>' + r.ext + '</td><td>' + (r.name || '') + '</td></tr>';
			});
			html += '</tbody></table>';
			$('#exunity-bulkedit-result').html(html);
			return;
		}
		var html = '<div class="alert alert-success">Updated ' + res.updated + ', skipped ' + res.skipped + ', failed ' + res.failed + '</div><table class="table"><thead><tr><th>Ext</th><th>Status</th><th></th></tr></thead><tbody>';
		(res.results || []).forEach(function (r) {
			html += '<tr><td>' + r.ext + '</td><td>' + r.status + '</td><td>' + (r.message || '') + '</td></tr>';
		});
		html += '</tbody></table>';
		$('#exunity-bulkedit-result').html(html);
	}

	$('#be-add-range').on('click', function () {
		var from = parseInt($('#be_range_from').val(), 10);
		var to = parseInt($('#be_range_to').val(), 10);
		if (!from || !to || to < from) {
			alert('Enter a valid extension range');
			return;
		}
		if ((to - from + 1) > 500) {
			alert('Range cannot exceed 500 extensions');
			return;
		}
		var lines = ($('#bulk_extens').val() || '').split(/\r?\n/).map(function (s) { return $.trim(s); }).filter(Boolean);
		var have = {};
		lines.forEach(function (l) { have[l] = true; });
		$('#qsagents1 option').each(function () {
			var v = $(this).val();
			if (!v) {
				return;
			}
			var n = parseInt(v, 10);
			if (n >= from && n <= to && !have[v]) {
				lines.push(v);
				have[v] = true;
			}
		});
		$('#bulk_extens').val(lines.join('\n')).trigger('change');
	});

	$('#exunity-bulkedit-preview').on('click', function () {
		var payload = bulkEditPayload();
		payload.command = 'bulkedittest';
		$.post('ajax.php', payload, renderBulkEdit, 'json');
	});
	$('#exunity-bulkedit-apply').on('click', function () {
		if (!confirm('Apply the ticked fields to the selected extensions?')) {
			return;
		}
		var payload = bulkEditPayload();
		payload.command = 'bulkeditsave';
		$.post('ajax.php', payload, renderBulkEdit, 'json');
	});
});
