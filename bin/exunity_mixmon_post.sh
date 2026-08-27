#!/bin/bash
# Post-process MixMonitor files: optional stereo merge, optional MP3 compress.
# Left  = receive  (A-leg / caller on the recorded channel)
# Right = transmit (B-leg / the other party)
set -u

FILE="${1:-}"
if [ -z "$FILE" ] || [ ! -f "$FILE" ]; then
	exit 0
fi

CONF="$(dirname "$0")/exunity_mixmon.conf"
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

if [ "${STEREO}" = "yes" ] && [ -f "$left" ] && [ -f "$right" ]; then
	sox="$(command -v sox || true)"
	if [ -n "$sox" ]; then
		tmp="${base}-st.$$.wav"
		if "$sox" -M "$left" "$right" "$tmp" 2>/dev/null; then
			mv -f "$tmp" "$FILE"
			chown asterisk:asterisk "$FILE" 2>/dev/null || true
			chmod 644 "$FILE" 2>/dev/null || true
		else
			rm -f "$tmp"
		fi
	fi
	rm -f "$left" "$right"
elif [ -f "$left" ] || [ -f "$right" ]; then
	rm -f "$left" "$right"
fi

if [ "${MP3}" != "yes" ]; then
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
ffmpeg="$(command -v ffmpeg || true)"
lame="$(command -v lame || true)"
if [ -n "$ffmpeg" ]; then
	if "$ffmpeg" -y -hide_banner -loglevel error -i "$FILE" -ar 16000 -codec:a libmp3lame -b:a "${bitrate}k" "$mp3"; then
		ok=1
	fi
elif [ -n "$lame" ]; then
	if "$lame" --quiet --resample 16 -b "$bitrate" "$FILE" "$mp3"; then
		ok=1
	fi
fi

if [ "$ok" = "1" ] && [ -s "$mp3" ]; then
	chown asterisk:asterisk "$mp3" 2>/dev/null || true
	chmod 644 "$mp3" 2>/dev/null || true
	rm -f "$FILE"
fi
exit 0
