#!/bin/sh
# Regression: -autorotate is a boolean flag. "-autorotate 1" makes ffmpeg 8
# treat "1" as an output url (exit 234), so every HLS encode failed.
cd "$(dirname "$0")/.."
if grep -rnE "autorotate'?, *'?[01]'|autorotate [01]" lib; then
	echo "autorotate must be passed without a value" >&2; exit 1
fi
if command -v ffmpeg >/dev/null; then
	ffmpeg -nostdin -hide_banner -loglevel error -f lavfi -i testsrc=d=1:s=64x64 -autorotate -t 1 -f null - 2>/dev/null || true
fi
echo "ffmpeg args ok"
