#!/bin/bash

# ── 1. Virtual framebuffer ────────────────────────────────────────────────────
echo "[1/4] Starting Xvfb..."
Xvfb :1 -screen 0 "${SCREEN_WIDTH}x${SCREEN_HEIGHT}x${SCREEN_DEPTH}" \
    -ac +extension GLX +render -noreset &

until [ -e /tmp/.X11-unix/X1 ]; do sleep 0.2; done
echo "[1/4] Xvfb ready."

# ── 2. VNC server ─────────────────────────────────────────────────────────────
echo "[2/4] Starting x11vnc..."
x11vnc -display :1 -nopw -rfbport 5900 -forever 2>&1 &
sleep 4   # give x11vnc enough time; no port-loop so websockify always starts
echo "[2/4] x11vnc started (or failed — check logs above)."

# ── 3. noVNC WebSocket proxy ──────────────────────────────────────────────────
echo "[3/4] Starting websockify..."
websockify --web /opt/novnc 0.0.0.0:6080 localhost:5900 &
sleep 2
echo "[3/4] websockify ready on :6080."

# ── 4. Chrome with Site Tweaker extension + CDP ───────────────────────────────
# Chrome 157 ignores --remote-debugging-address=0.0.0.0 and always binds the CDP
# port to 127.0.0.1, so other containers (the backend) can't reach it directly.
# We bind Chrome's CDP to loopback on 9223 and expose it to the Docker network on
# 9222 via a socat bridge (connections then arrive at Chrome from 127.0.0.1 and
# pass its loopback/Host checks).
echo "[4/4] Starting Chrome (CDP on 127.0.0.1:9223, bridged to 0.0.0.0:9222)..."

start_chrome() {
    # Clear any stale profile lock left by a previous (crashed) Chrome.
    rm -f /tmp/chrome-profile/SingletonLock /tmp/chrome-profile/SingletonCookie 2>/dev/null
    google-chrome \
        --no-sandbox \
        --disable-dev-shm-usage \
        --disable-gpu \
        --load-extension=/extensions/site-tweaker \
        --remote-debugging-port=9223 \
        --remote-debugging-address=127.0.0.1 \
        --user-data-dir=/tmp/chrome-profile \
        --window-size="${SCREEN_WIDTH},${SCREEN_HEIGHT}" \
        --start-maximized \
        "${START_URL:-about:blank}" 2>&1
}

# Keep Chrome alive: if it ever exits (e.g. the last tab is closed), respawn it
# so the "Browser" session is always running and ready to navigate.
( while true; do
    echo "[chrome] launching..."
    start_chrome
    echo "[chrome] exited — respawning in 2s"
    sleep 2
done ) &

# CDP bridge: expose Chrome's loopback debugger to the Docker network.
socat TCP-LISTEN:9222,fork,reuseaddr TCP:127.0.0.1:9223 &

echo "========================================"
echo "PhishGuard noVNC ready"
echo "  VNC viewer → http://localhost:6080/vnc_lite.html"
echo "  CDP        → http://localhost:9222  (bridged → 127.0.0.1:9223)"
echo "========================================"

wait
