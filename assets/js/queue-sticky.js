(function ($) {
	'use strict';

	var cfg = window.exunityStickyQueue || null;
	if (!cfg || !window.jQuery) {
		return;
	}

	function fieldHtml() {
		var enabled = !!cfg.enabled;
		return (
			'<input type="hidden" name="exunity_sticky_present" value="1">' +
			'<div class="element-container" id="exunity-sticky-fallback">' +
				'<div class="row"><div class="col-md-12"><div class="row"><div class="form-group">' +
					'<div class="col-md-3"><label class="control-label" for="exunity_sticky_agent">' + $('<div/>').text(cfg.label || 'Sticky last agent').html() + '</label></div>' +
					'<div class="col-md-9"><select class="form-control" id="exunity_sticky_agent" name="exunity_sticky_agent">' +
						'<option value="no"' + (enabled ? '' : ' selected') + '>' + $('<div/>').text(cfg.no || 'Disabled').html() + '</option>' +
						'<option value="yes"' + (enabled ? ' selected' : '') + '>' + $('<div/>').text(cfg.yes || 'Enabled').html() + '</option>' +
					'</select>' +
					'<span class="help-block">' + $('<div/>').text(cfg.help || '').html() + '</span>' +
					'</div></div></div></div></div></div>'
		);
	}

	$(function () {
		if ($('#exunity_sticky_agent').length) {
			return;
		}
		var html = fieldHtml();
		var $account = $('#account').closest('.element-container');
		if ($account.length) {
			$account.after(html);
			return;
		}
		if ($('#qgeneral').length) {
			$('#qgeneral').prepend(html);
		}
	});
})(jQuery);
