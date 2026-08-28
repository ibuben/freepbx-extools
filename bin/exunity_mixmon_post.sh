#!/bin/bash
# MixMonitor post: stereo merge (sox) + optional MP3 (ffmpeg/lame).
# Left  = receive  (A-leg / the recorded channel)
# Right = transmit (B-leg / the other party)
#
# MixMonitor's ^{MIXMONITOR_FILENAME} is often relative (MIXMON_DIR empty).
# Asterisk runs this with cwd /tmp, so relative paths must be resolved
# against the monitor spool — otherwise we exit and leave -L/-R WAV files.
set -u

LOG="${EXUNITY_MIXMON_LOG:-/var/log/asterisk/exunity_mixmon.log}"
MONITOR_DIR="/var/spool/asterisk/monitor"
SOX="/usr/bin/sox"
FFMPEG="/usr/bin/ffmpeg"
LAME="/usr/bin/lame"

log() {
	echo "$(date '+%F %T') $*" >>"$LOG" 2>/dev/null || true
}

resolve() {
	local f="$1"
	[ -n "$f" ] || return 1
	f="${f#file://}"
	if [ -f "$f" ]; then
		printf '%s\n' "$f"
		return 0
	fi
	# relative to monitor spool (normal FreePBX MIXMON_DIR='')
	if [ "${f#/}" = "$f" ] && [ -f "$MONITOR_DIR/$f" ]; then
		printf '%s\n' "$MONITOR_DIR/$f"
		return 0
	fi
	return 1
}

FILE=""
for cand in "${1:-}" "${2:-}"; do
	if resolved="$(resolve "$cand")"; then
		FILE="$resolved"
		break
	fi
done

if [ -z "$FILE" ]; then
	i=0
	while [ "$i" -lt 8 ]; do
		sleep 0.25
		for cand in "${1:-}" "${2:-}"; do
			if resolved="$(resolve "$cand")"; then
				FILE="$resolved"
				break 2
			fi
		done
		i=$((i + 1))
	done
fi

if [ -z "$FILE" ]; then
	log "skip: no file arg1=${1:-} arg2=${2:-} cwd=$(pwd)"
	exit 0
fi

CONF="$(cd "$(dirname "$0")" && pwd)/exunity_mixmon.conf"
STEREO=no
MP3=no
MP3_BITRATE=64
if [ -f "$CONF" ]; then
	# shellcheck disable=SC1090
	. "$CONF"
fi

base="${FILE%.*}"
left="${base}-L.wav"
right="${base}-R.wav"

if [ ! -f "$left" ] && [ -n "${1:-}" ]; then
	# L/R may already be under the monitor spool even if FILE was elsewhere
	alt="$(resolve "${1%.*}-L.wav" 2>/dev/null || true)"
	[ -n "$alt" ] && [ -f "$alt" ] && left="$alt" && right="${alt%-L.wav}-R.wav"
fi

if [ "${STEREO}" = "yes" ] && [ -f "$left" ] && [ -f "$right" ]; then
	if [ -x "$SOX" ] || SOX="$(command -v sox 2>/dev/null)"; then
		tmp="${base}-st.$$.wav"
		if "$SOX" -M "$left" "$right" "$tmp" 2>>"$LOG"; then
			mv -f "$tmp" "$FILE"
			chown asterisk:asterisk "$FILE" 2>/dev/null || true
			chmod 644 "$FILE" 2>/dev/null || true
		else
			log "sox failed file=$FILE"
			rm -f "$tmp"
		fi
	else
		log "sox missing file=$FILE"
	fi
	rm -f "$left" "$right"
elif [ -f "$left" ] || [ -f "$right" ]; then
	rm -f "$left" "$right"
fi

if [ "${MP3}" != "yes" ]; then
	log "ok stereo=$STEREO mp3=no file=$FILE"
	exit 0
fi

ext="${FILE##*.}"
if [ "$(echo "$ext" | tr '[:upper:]' '[:lower:]')" = "mp3" ]; then
	exit 0
fi

bitrate="${MP3_BITRATE:-64}"
case "$bitrate" in
	32|48|64|96|128) ;;
	*) bitrate=64 ;;
esac

mp3="${base}.mp3"
ok=0
if [ -x "$FFMPEG" ] || FFMPEG="$(command -v ffmpeg 2>/dev/null)"; then
	if "$FFMPEG" -y -hide_banner -loglevel error -i "$FILE" -ar 16000 -codec:a libmp3lame -b:a "${bitrate}k" "$mp3" 2>>"$LOG"; then
		ok=1
	fi
elif [ -x "$LAME" ] || LAME="$(command -v lame 2>/dev/null)"; then
	if "$LAME" --quiet --resample 16 -b "$bitrate" "$FILE" "$mp3" 2>>"$LOG"; then
		ok=1
	fi
else
	log "no ffmpeg/lame file=$FILE"
fi

if [ "$ok" = "1" ] && [ -s "$mp3" ]; then
	chown asterisk:asterisk "$mp3" 2>/dev/null || true
	chmod 644 "$mp3" 2>/dev/null || true
	rm -f "$FILE"
	log "ok stereo=$STEREO mp3=yes file=$mp3"
else
	log "mp3 failed file=$FILE"
fi
exit 0
