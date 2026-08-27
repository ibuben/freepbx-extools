(function ($) {
	'use strict';

	var i18n = $.extend({
		title: 'eX Add CallerIDs',
		help: 'Paste CallerIDs (one per line). Each row gets the same prepend, prefix and match pattern.',
		prepend: 'prepend',
		prefix: 'prefix',
		match: 'match pattern',
		cids: 'CallerIDs',
		paste: 'Or paste extra CallerIDs',
		placeholder: '201\n202\n203',
		fromLast: 'Copy template from last row',
		skipDup: 'Skip duplicates',
		add: 'Add rows',
		added: 'Added %d pattern(s). Save the route to keep them.',
		none: 'No CallerIDs to add.',
		nodp: 'Open the Dial Patterns tab first.'
	}, window.exunityRoutingCidI18n || {});

	function esc(s) {
		return $('<div/>').text(s == null ? '' : String(s)).html();
	}

	function parseCids(raw) {
		var seen = {};
		var out = [];
		String(raw || '').split(/[\r\n,;]+/).forEach(function (line) {
			var cid = $.trim(line).replace(/^["']+|["']+$/g, '');
			if (!cid || seen[cid]) {
				return;
			}
			seen[cid] = true;
			out.push(cid);
		});
		return out;
	}

	function rowKey($tr) {
		return [
			$.trim($tr.find('[id^="prepend_digit_"]').val() || ''),
			$.trim($tr.find('[id^="pattern_prefix_"]').val() || ''),
			$.trim($tr.find('[id^="pattern_pass_"]').val() || ''),
			$.trim($tr.find('[id^="match_cid_"]').val() || '')
		].join('\0');
	}

	function isEmptyRow($tr) {
		return rowKey($tr) === '\0\0\0';
	}

	function existingKeys() {
		var keys = {};
		$('#dptable tr[id^="dprow"]').each(function () {
			keys[rowKey($(this))] = true;
		});
		return keys;
	}

	function nextId() {
		var max = 0;
		$('tr[id^="dprow"]').each(function () {
			var n = parseInt(String(this.id).replace(/\D+/g, ''), 10);
			if (!isNaN(n) && n > max) {
				max = n;
			}
		});
		return max + 1;
	}

	function buildRow(id, prepend, prefix, match, cid) {
		return (
			'<tr id="dprow' + id + '">' +
			'<td class="prepend"><div class="input-group">' +
			'<span class="input-group-addon">(</span>' +
			'<input placeholder="prepend" type="text" id="prepend_digit_' + id + '" class="form-control" value="' + esc(prepend) + '">' +
			'<span class="input-group-addon">)</span></div></td>' +
			'<td><div class="input-group">' +
			'<input placeholder="prefix" type="text" id="pattern_prefix_' + id + '" class="form-control" value="' + esc(prefix) + '">' +
			'<span class="input-group-addon">|</span></div></td>' +
			'<td><div class="input-group">' +
			'<span class="input-group-addon">[</span>' +
			'<input placeholder="match pattern" type="text" id="pattern_pass_' + id + '" class="form-control dpt-value" value="' + esc(match) + '">' +
			'<span class="input-group-addon">/</span></div></td>' +
			'<td><div class="input-group">' +
			'<input placeholder="CallerID" type="text" id="match_cid_' + id + '" class="form-control" value="' + esc(cid) + '">' +
			'<span class="input-group-addon">]</span></div></td>' +
			'<td><a href="#" id="routerowadd' + id + '"><i class="fa fa-plus"></i></a> ' +
			'<a href="#" id="routerowdel' + id + '"><i class="fa fa-trash"></i></a></td></tr>'
		);
	}

	function fillRow($tr, prepend, prefix, match, cid) {
		$tr.find('[id^="prepend_digit_"]').val(prepend);
		$tr.find('[id^="pattern_prefix_"]').val(prefix);
		$tr.find('[id^="pattern_pass_"]').val(match);
		$tr.find('[id^="match_cid_"]').val(cid);
	}

	function lastFilledRow() {
		var $found = $();
		$('#dptable tr[id^="dprow"]').each(function () {
			if (!isEmptyRow($(this))) {
				$found = $(this);
			}
		});
		return $found;
	}

	function panelHtml() {
		return (
			'<div id="exunity-routing-cid" class="exunity-routing-cid">' +
			'<div class="exunity-routing-cid-head">' + esc(i18n.title) + '</div>' +
			'<p class="help-block">' + esc(i18n.help) + '</p>' +
			'<div class="row">' +
			'<div class="col-md-4"><label>' + esc(i18n.prepend) + '</label>' +
			'<input type="text" class="form-control" id="exunity-cid-prepend" placeholder="prepend"></div>' +
			'<div class="col-md-4"><label>' + esc(i18n.prefix) + '</label>' +
			'<input type="text" class="form-control" id="exunity-cid-prefix" placeholder="prefix"></div>' +
			'<div class="col-md-4"><label>' + esc(i18n.match) + '</label>' +
			'<input type="text" class="form-control" id="exunity-cid-match" placeholder="NXXXXXX"></div>' +
			'</div>' +
			'<div class="exunity-routing-cid-actions">' +
			'<button type="button" class="btn btn-default btn-sm" id="exunity-cid-fromlast">' + esc(i18n.fromLast) + '</button>' +
			'</div>' +
			'<label>' + esc(i18n.cids) + '</label>' +
			'<div class="input-group" id="exunity-cid-picker-wrap">' +
			'<textarea class="form-control" id="exunity-cid-list" rows="3" placeholder="' + esc(i18n.placeholder) + '"></textarea>' +
			'<span class="input-group-addon">' +
			'<select id="exunityCidAgents" class="form-control" data-for="exunity-cid-list"><option value=""></option></select>' +
			'</span></div>' +
			'<label class="exunity-routing-cid-paste" id="exunity-cid-paste-label">' + esc(i18n.paste) + '</label>' +
			'<label class="exunity-routing-cid-check"><input type="checkbox" id="exunity-cid-skip-dup" checked> ' + esc(i18n.skipDup) + '</label>' +
			'<button type="button" class="btn btn-default" id="exunity-cid-add">' + esc(i18n.add) + '</button>' +
			'<span class="help-block" id="exunity-cid-result"></span>' +
			'</div>'
		);
	}

	function copyFromLast() {
		var $row = lastFilledRow();
		if (!$row.length) {
			return;
		}
		$('#exunity-cid-prepend').val($row.find('[id^="prepend_digit_"]').val() || '');
		$('#exunity-cid-prefix').val($row.find('[id^="pattern_prefix_"]').val() || '');
		$('#exunity-cid-match').val($row.find('[id^="pattern_pass_"]').val() || '');
	}

	function addRows() {
		if (!$('#dptable').length) {
			$('#exunity-cid-result').text(i18n.nodp);
			return;
		}
		var prepend = $.trim($('#exunity-cid-prepend').val() || '');
		var prefix = $.trim($('#exunity-cid-prefix').val() || '');
		var match = $.trim($('#exunity-cid-match').val() || '');
		var parts = [];
		$('#exunity-routing-cid .exunity-picker [data-role="selected"] .exunity-picker-item').each(function () {
			parts.push(String($(this).data('key') || ''));
		});
		parts.push($('#exunity-cid-list').val() || '');
		var cids = parseCids(parts.join('\n'));
		if (!cids.length) {
			$('#exunity-cid-result').text(i18n.none);
			return;
		}
		var skipDup = $('#exunity-cid-skip-dup').prop('checked');
		var keys = existingKeys();
		var added = 0;
		var id = nextId();
		cids.forEach(function (cid) {
			var key = [prepend, prefix, match, cid].join('\0');
			if (skipDup && keys[key]) {
				return;
			}
			var $empty = $('#dptable tr[id^="dprow"]').filter(function () {
				return isEmptyRow($(this));
			}).first();
			if ($empty.length) {
				fillRow($empty, prepend, prefix, match, cid);
			} else {
				$('#dptable').append(buildRow(id, prepend, prefix, match, cid));
				id += 1;
			}
			keys[key] = true;
			added += 1;
		});
		$('#exunity-cid-result').text(i18n.added.replace('%d', String(added)));
		if ($('.nav-tabs a[href="#dialpatterns"]').length) {
			$('.nav-tabs a[href="#dialpatterns"]').tab('show');
		}
	}

	$(function () {
		if (!$('#dptable').length || $('#exunity-routing-cid').length) {
			return;
		}
		var html = panelHtml();
		if ($('#wizmenu').length) {
			$('#wizmenu').after(html);
		} else {
			$('#dptable').before(html);
		}
		$('#exunity-cid-fromlast').on('click', copyFromLast);
		$('#exunity-cid-add').on('click', addRows);
		fillExtensionSelect();
		bindPickerWhenReady();
	});

	function fillExtensionSelect() {
		var $sel = $('#exunityCidAgents');
		if (!$sel.length) {
			return;
		}
		(window.exunityRoutingCidExtens || []).forEach(function (item) {
			if (!item || item.value == null || item.value === '') {
				return;
			}
			$sel.append($('<option/>').attr('value', String(item.value)).text(item.label || String(item.value)));
		});
	}

	function layoutAfterPicker() {
		var $ta = $('#exunity-cid-list');
		var $picker = $('#exunity-cid-picker-wrap .exunity-picker');
		var $paste = $('#exunity-cid-paste-label');
		if ($picker.length) {
			$picker.after($paste);
			$paste.after($ta);
		}
	}

	function bindPickerWhenReady(tries) {
		tries = tries || 0;
		var $sel = $('#exunityCidAgents');
		if (!$sel.length) {
			return;
		}
		if (typeof window.exunityBindPicker === 'function') {
			window.exunityBindPicker($sel);
			layoutAfterPicker();
			return;
		}
		if (tries < 40) {
			setTimeout(function () {
				bindPickerWhenReady(tries + 1);
			}, 50);
		}
	}
})(jQuery);
