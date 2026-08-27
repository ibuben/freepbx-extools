(function () {
	function boot() {
		if (!document.getElementById('exunity-calls') || window.exCallsInit) {
			return;
		}
		window.exCallsInit = true;

		var direction = 'all';
		var player = document.getElementById('ex-call-player');
		var bar = document.getElementById('ex-player-bar');
		var seek = document.getElementById('ex-player-seek');
		var curEl = document.getElementById('ex-player-cur');
		var durEl = document.getElementById('ex-player-dur');
		var toggleBtn = document.getElementById('ex-player-toggle');
		var rateEl = document.getElementById('ex-player-rate');
		var currentBtn = null;
		var seeking = false;

		function applyRate() {
			if (!player || !rateEl) {
				return;
			}
			var rate = parseFloat(rateEl.value);
			if (!rate || rate < 0.5 || rate > 3) {
				rate = 1;
			}
			player.playbackRate = rate;
			try {
				localStorage.setItem('exunityPlayerRate', String(rate));
			} catch (e) {}
		}

		function ymd(d) {
			var m = ('0' + (d.getMonth() + 1)).slice(-2);
			var day = ('0' + d.getDate()).slice(-2);
			return d.getFullYear() + '-' + m + '-' + day;
		}

		function setRange(kind) {
			kind = String(kind);
			var to = new Date();
			var from = new Date();
			if (kind === 'yesterday') {
				from.setDate(from.getDate() - 1);
				to = new Date(from.getTime());
			} else if (kind === '7') {
				from.setDate(from.getDate() - 6);
			} else if (kind === '30') {
				from.setDate(from.getDate() - 29);
			} else {
				kind = 'today';
			}
			$('#ex-date-from').val(ymd(from));
			$('#ex-date-to').val(ymd(to));
			$('.ex-range').removeClass('is-on');
			$('.ex-range[data-range="' + kind + '"]').addClass('is-on');
		}

		window.exCallsQueryParams = function (params) {
			params.date_from = $('#ex-date-from').val() || '';
			params.date_to = $('#ex-date-to').val() || '';
			params.direction = direction;
			params.search = $('#ex-call-q').val() || '';
			return params;
		};

		function reload() {
			var $table = $('#exunity-calls');
			if (!$table.length || !$table.data('bootstrap.table')) {
				return;
			}
			$table.bootstrapTable('refresh', { pageNumber: 1 });
		}

		function fmt(sec) {
			if (!isFinite(sec) || sec < 0) {
				return '0:00';
			}
			sec = Math.floor(sec);
			var h = Math.floor(sec / 3600);
			var m = Math.floor((sec % 3600) / 60);
			var s = sec % 60;
			var mm = h ? (m < 10 ? '0' : '') + m : String(m);
			var ss = (s < 10 ? '0' : '') + s;
			return h ? h + ':' + mm + ':' + ss : mm + ':' + ss;
		}

		function duration() {
			var d = player && player.duration;
			return (d && isFinite(d) && d > 0) ? d : 0;
		}

		function setPlayingIcons(on) {
			var icon = on ? 'fa fa-pause' : 'fa fa-play';
			if (currentBtn) {
				currentBtn.toggleClass('is-on', on).find('i').attr('class', icon);
			}
			if (toggleBtn) {
				toggleBtn.classList.toggle('is-on', on);
				var ti = toggleBtn.querySelector('i');
				if (ti) {
					ti.className = icon;
				}
			}
		}

		function syncSeek() {
			if (!seek || seeking) {
				return;
			}
			var d = duration();
			var t = player ? player.currentTime : 0;
			seek.value = d ? String(Math.round((t / d) * 1000)) : '0';
			if (curEl) {
				curEl.textContent = fmt(t);
			}
			if (durEl) {
				durEl.textContent = fmt(d);
			}
		}

		function seekToRatio(ratio) {
			var d = duration();
			if (!player || !d) {
				return;
			}
			ratio = Math.max(0, Math.min(1, ratio));
			player.currentTime = ratio * d;
			syncSeek();
		}

		function skip(delta) {
			if (!player || !duration()) {
				return;
			}
			player.currentTime = Math.max(0, Math.min(duration(), player.currentTime + delta));
			syncSeek();
		}

		function showBar() {
			if (bar) {
				bar.hidden = false;
			}
		}

		function stopPlayer() {
			if (player) {
				player.pause();
				player.removeAttribute('src');
				player.load();
			}
			setPlayingIcons(false);
			currentBtn = null;
			if (bar) {
				bar.hidden = true;
			}
			if (seek) {
				seek.value = '0';
			}
			if (curEl) {
				curEl.textContent = '0:00';
			}
			if (durEl) {
				durEl.textContent = '0:00';
			}
		}

		function playUid(uid, $btn) {
			if (!uid || !player) {
				return;
			}
			showBar();
			if (currentBtn && $btn && currentBtn[0] === $btn[0] && player.src && !player.paused) {
				player.pause();
				return;
			}
			$('.ex-play').removeClass('is-on').find('i').attr('class', 'fa fa-play');
			currentBtn = $btn && $btn.length ? $btn : null;
			player.src = 'ajax.php?module=exunity&command=playcdr&uid=' + encodeURIComponent(uid);
			applyRate();
			player.play().catch(function () {});
			setPlayingIcons(true);
			syncSeek();
		}

		$(document).on('click', '.ex-range', function () {
			setRange($(this).attr('data-range'));
			reload();
		});
		$(document).on('click', '.ex-dir', function () {
			direction = String($(this).attr('data-dir') || 'all');
			$('.ex-dir').removeClass('is-on');
			$(this).addClass('is-on');
			reload();
		});
		$(document).on('change', '#ex-date-from, #ex-date-to', function () {
			$('.ex-range').removeClass('is-on');
			reload();
		});
		var qTimer = null;
		$(document).on('input', '#ex-call-q', function () {
			clearTimeout(qTimer);
			qTimer = setTimeout(reload, 300);
		});

		$(document).on('click', '.ex-play', function (e) {
			e.preventDefault();
			playUid($(this).attr('data-uid'), $(this));
		});

		if (toggleBtn) {
			toggleBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if (!player || !player.src) {
					return;
				}
				if (player.paused) {
					player.play().catch(function () {});
				} else {
					player.pause();
				}
			});
		}
		$('#ex-player-back').on('click', function (e) {
			e.preventDefault();
			skip(-5);
		});
		$('#ex-player-fwd').on('click', function (e) {
			e.preventDefault();
			skip(5);
		});
		$('#ex-player-close').on('click', function (e) {
			e.preventDefault();
			stopPlayer();
		});
		if (rateEl) {
			try {
				var saved = localStorage.getItem('exunityPlayerRate');
				if (saved && rateEl.querySelector('option[value="' + saved + '"]')) {
					rateEl.value = saved;
				}
			} catch (e) {}
			rateEl.addEventListener('change', applyRate);
		}
		if (seek) {
			seek.addEventListener('pointerdown', function () {
				seeking = true;
			});
			seek.addEventListener('mousedown', function () {
				seeking = true;
			});
			seek.addEventListener('input', function () {
				seeking = true;
				var d = duration();
				if (curEl) {
					curEl.textContent = fmt(d ? (parseInt(seek.value, 10) / 1000) * d : 0);
				}
			});
			function commitSeek() {
				seekToRatio(parseInt(seek.value, 10) / 1000);
				seeking = false;
			}
			seek.addEventListener('change', commitSeek);
			seek.addEventListener('pointerup', commitSeek);
			seek.addEventListener('mouseup', commitSeek);
			seek.addEventListener('keyup', function (e) {
				if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') {
					commitSeek();
				}
			});
		}

		if (player) {
			player.addEventListener('timeupdate', syncSeek);
			player.addEventListener('durationchange', syncSeek);
			player.addEventListener('loadedmetadata', syncSeek);
			player.addEventListener('pause', function () {
				setPlayingIcons(false);
			});
			player.addEventListener('ended', function () {
				setPlayingIcons(false);
				if (seek) {
					seek.value = '1000';
				}
			});
			player.addEventListener('play', function () {
				showBar();
				setPlayingIcons(true);
				applyRate();
			});
		}

		if (!$('#ex-date-from').val() || !$('#ex-date-to').val()) {
			setRange('7');
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	if (window.jQuery) {
		jQuery(boot);
	}
})();
