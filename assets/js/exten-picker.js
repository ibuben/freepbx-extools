(function ($) {
	'use strict';

	var i18n = $.extend({
		available: 'Available',
		selected: 'Selected',
		search: 'Search',
		top: 'Move to top',
		up: 'Move up',
		down: 'Move down',
		bottom: 'Move to bottom'
	}, window.exunityPickerI18n || {});

	function parseLine(line, mode) {
		line = $.trim(line || '');
		if (!line) {
			return null;
		}
		if (mode === 'queue') {
			var comma = line.lastIndexOf(',');
			if (comma > 0 && /^\d+$/.test(line.slice(comma + 1))) {
				return { key: String(line.slice(0, comma)), extra: line.slice(comma + 1), raw: line };
			}
			return { key: String(line), extra: '0', raw: line };
		}
		return { key: String(line), extra: '', raw: line };
	}

	function lineFor(item, mode) {
		if (mode === 'queue') {
			return item.key + ',' + (item.extra || '0');
		}
		return item.raw || item.key;
	}

	function catalogFromSelect($select) {
		var catalog = [];
		var seen = {};
		$select.find('option').each(function () {
			var value = $(this).attr('value');
			if (value === undefined || value === '') {
				return;
			}
			if (seen[value]) {
				return;
			}
			seen[value] = true;
			catalog.push({ key: String(value), label: $.trim($(this).text()) || String(value) });
		});
		return catalog;
	}

	function labelFor(key, catalogMap) {
		return catalogMap[key] || key;
	}

	function visibleItems($list) {
		return $list.children('.exunity-picker-item').filter(function () {
			return $(this).css('display') !== 'none';
		});
	}

	function bindPicker($select) {
		var targetId = $select.data('for');
		var $ta = $('#' + targetId);
		if (!targetId || !$ta.length || $select.data('exunityPicker')) {
			return;
		}
		$select.data('exunityPicker', true).off('change');

		var mode = (targetId === 'members' || targetId === 'dynmembers') ? 'queue' : 'ringgroup';
		var catalog = catalogFromSelect($select);
		var catalogMap = {};
		catalog.forEach(function (item) {
			catalogMap[item.key] = item.label;
		});

		var $group = $select.closest('.input-group');
		$select.closest('.input-group-addon').hide();
		$group.removeClass('input-group');

		var $wrap = $(
			'<div class="exunity-picker">' +
				'<div class="exunity-picker-col" data-side="avail">' +
					'<div class="exunity-picker-head"><input type="checkbox" class="exunity-pick-all"> <span class="exunity-picker-count">0</span> ' + i18n.available + '</div>' +
					'<div class="exunity-picker-search"><input type="text" placeholder="' + i18n.search + '"></div>' +
					'<div class="exunity-picker-list" data-role="avail"></div>' +
				'</div>' +
				'<div class="exunity-picker-btns">' +
					'<button type="button" class="btn btn-default" data-move="to-selected" title="' + i18n.selected + '">&gt;</button>' +
					'<button type="button" class="btn btn-default" data-move="to-avail" title="' + i18n.available + '">&lt;</button>' +
				'</div>' +
				'<div class="exunity-picker-col" data-side="selected">' +
					'<div class="exunity-picker-head"><input type="checkbox" class="exunity-pick-all"> <span class="exunity-picker-count">0</span> ' + i18n.selected + '</div>' +
					'<div class="exunity-picker-search"><input type="text" placeholder="' + i18n.search + '"></div>' +
					'<div class="exunity-picker-list" data-role="selected"></div>' +
				'</div>' +
				'<div class="exunity-picker-order">' +
					'<button type="button" class="btn btn-default" data-order="top" title="' + i18n.top + '"><i class="fa fa-angle-double-up"></i></button>' +
					'<button type="button" class="btn btn-default" data-order="up" title="' + i18n.up + '"><i class="fa fa-angle-up"></i></button>' +
					'<button type="button" class="btn btn-default" data-order="down" title="' + i18n.down + '"><i class="fa fa-angle-down"></i></button>' +
					'<button type="button" class="btn btn-default" data-order="bottom" title="' + i18n.bottom + '"><i class="fa fa-angle-double-down"></i></button>' +
				'</div>' +
			'</div>'
		);
		$ta.after($wrap);

		var $avail = $wrap.find('[data-role="avail"]');
		var $selected = $wrap.find('[data-role="selected"]');
		var lastClick = { avail: null, selected: null };
		var syncing = false;

		function writeTextarea() {
			syncing = true;
			var lines = [];
			$selected.children('.exunity-picker-item').each(function () {
				lines.push(lineFor({
					key: $(this).data('key'),
					extra: $(this).data('extra'),
					raw: $(this).data('raw')
				}, mode));
			});
			$ta.val(lines.join('\n'));
			syncing = false;
		}

		function makeItem(entry) {
			var $item = $('<div class="exunity-picker-item"></div>');
			$item.append($('<input type="checkbox">'));
			$item.append($('<span></span>').text(entry.label));
			$item.attr('data-key', entry.key);
			$item.data({
				key: entry.key,
				extra: entry.extra || '',
				raw: entry.raw || entry.key,
				label: entry.label
			});
			return $item;
		}

		function applyFilter($list, query) {
			query = $.trim(query || '').toLowerCase();
			$list.children('.exunity-picker-item').each(function () {
				var text = ($(this).data('label') + ' ' + $(this).data('key')).toLowerCase();
				$(this).toggle(!query || text.indexOf(query) !== -1);
			});
			updateHead($list);
		}

		function updateHead($list) {
			var $col = $list.closest('.exunity-picker-col');
			var $visible = visibleItems($list);
			var checked = $visible.has('input:checked').length;
			$col.find('.exunity-picker-count').text($list.children().length);
			var $all = $col.find('.exunity-pick-all');
			$all.prop('checked', $visible.length > 0 && checked === $visible.length);
			$all.prop('indeterminate', checked > 0 && checked < $visible.length);
		}

		function renderFromTextarea() {
			var chosen = [];
			var chosenMap = {};
			$.trim($ta.val() || '').split(/\r?\n/).forEach(function (line) {
				var parsed = parseLine(line, mode);
				if (!parsed || chosenMap[parsed.key]) {
					return;
				}
				chosenMap[parsed.key] = true;
				chosen.push({
					key: parsed.key,
					extra: parsed.extra,
					raw: parsed.raw,
					label: labelFor(parsed.key, catalogMap)
				});
			});

			$avail.empty();
			$selected.empty();
			catalog.forEach(function (item) {
				if (!chosenMap[item.key]) {
					$avail.append(makeItem({ key: item.key, extra: mode === 'queue' ? '0' : '', raw: item.key, label: item.label }));
				}
			});
			chosen.forEach(function (item) {
				$selected.append(makeItem(item));
			});
			applyFilter($avail, $wrap.find('[data-side="avail"] .exunity-picker-search input').val());
			applyFilter($selected, $wrap.find('[data-side="selected"] .exunity-picker-search input').val());
		}

		function moveChecked(fromSide) {
			var $from = fromSide === 'avail' ? $avail : $selected;
			var $to = fromSide === 'avail' ? $selected : $avail;
			var $moving = $from.children('.exunity-picker-item').filter(function () {
				return $(this).find('input').prop('checked') && $(this).css('display') !== 'none';
			});
			$moving.each(function () {
				var $item = $(this);
				$item.find('input').prop('checked', false);
				$item.removeClass('is-checked');
				if (fromSide === 'selected' && !catalogMap[$item.data('key')]) {
					$item.remove();
					return;
				}
				$to.append($item);
			});
			writeTextarea();
			applyFilter($avail, $wrap.find('[data-side="avail"] .exunity-picker-search input').val());
			applyFilter($selected, $wrap.find('[data-side="selected"] .exunity-picker-search input').val());
			lastClick.avail = null;
			lastClick.selected = null;
		}

		function handleItemClick(e, $list, side) {
			var $item = $(e.currentTarget);
			var $items = visibleItems($list);
			var idx = $items.index($item);
			if (e.shiftKey && lastClick[side] !== null) {
				var start = Math.min(lastClick[side], idx);
				var end = Math.max(lastClick[side], idx);
				var state = !$item.find('input').prop('checked');
				$items.slice(start, end + 1).each(function () {
					$(this).find('input').prop('checked', state);
					$(this).toggleClass('is-checked', state);
				});
			} else if (!$(e.target).is('input')) {
				var next = !$item.find('input').prop('checked');
				$item.find('input').prop('checked', next);
				$item.toggleClass('is-checked', next);
			} else {
				$item.toggleClass('is-checked', $item.find('input').prop('checked'));
			}
			lastClick[side] = idx;
			updateHead($list);
		}

		function reorderSelected(dir) {
			var $items = $selected.children('.exunity-picker-item');
			var $checked = $items.filter(function () {
				return $(this).find('input').prop('checked');
			});
			if (!$checked.length) {
				return;
			}
			if (dir === 'top') {
				$selected.prepend($checked);
			} else if (dir === 'bottom') {
				$selected.append($checked);
			} else if (dir === 'up') {
				$checked.each(function () {
					var $item = $(this);
					var $prev = $item.prev('.exunity-picker-item');
					if ($prev.length && !$prev.find('input').prop('checked')) {
						$item.insertBefore($prev);
					}
				});
			} else if (dir === 'down') {
				$($checked.get().reverse()).each(function () {
					var $item = $(this);
					var $next = $item.next('.exunity-picker-item');
					if ($next.length && !$next.find('input').prop('checked')) {
						$item.insertAfter($next);
					}
				});
			}
			writeTextarea();
			updateHead($selected);
		}

		$wrap.on('click', '.exunity-picker-item', function (e) {
			var $list = $(this).parent();
			handleItemClick(e, $list, $list.data('role'));
		});
		$wrap.on('dblclick', '.exunity-picker-item', function () {
			$(this).find('input').prop('checked', true);
			moveChecked($(this).parent().data('role') === 'avail' ? 'avail' : 'selected');
		});
		$wrap.on('change', '.exunity-pick-all', function () {
			var on = $(this).prop('checked');
			var $list = $(this).closest('.exunity-picker-col').find('.exunity-picker-list');
			visibleItems($list).each(function () {
				$(this).find('input').prop('checked', on);
				$(this).toggleClass('is-checked', on);
			});
			updateHead($list);
		});
		$wrap.on('input', '.exunity-picker-search input', function () {
			var $col = $(this).closest('.exunity-picker-col');
			applyFilter($col.find('.exunity-picker-list'), $(this).val());
		});
		$wrap.on('click', '[data-move]', function () {
			moveChecked($(this).data('move') === 'to-selected' ? 'avail' : 'selected');
		});
		$wrap.on('click', '[data-order]', function () {
			reorderSelected($(this).data('order'));
		});
		$ta.on('change blur', function () {
			if (!syncing) {
				renderFromTextarea();
			}
		});
		$('form').on('submit', function () {
			writeTextarea();
		});

		renderFromTextarea();
	}

	window.exunityBindPicker = bindPicker;

	$(function () {
		$('select[id^="qsagents"]').each(function () {
			bindPicker($(this));
		});
	});
})(jQuery);
