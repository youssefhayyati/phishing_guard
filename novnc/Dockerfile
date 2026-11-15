FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive \
    DISPLAY=:1 \
    SCREEN_WIDTH=1920 \
    SCREEN_HEIGHT=1080 \
    SCREEN_DEPTH=24

# ── System packages ───────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget curl ca-certificates gnupg unzip socat \
    xvfb x11vnc xdotool xclip \
    python3 python3-pip \
    fonts-liberation libappindicator3-1 libasound2 libatk-bridge2.0-0 \
    libatk1.0-0 libcups2 libdbus-1-3 libgdk-pixbuf2.0-0 libgtk-3-0 \
    libnspr4 libnss3 libx11-xcb1 libxcomposite1 libxcursor1 libxdamage1 \
    libxext6 libxfixes3 libxi6 libxrandr2 libxrender1 libxss1 libxtst6 \
    libgbm1 libdrm2 libxkbcommon0 libatspi2.0-0 libpango-1.0-0 libcairo2 \
    && rm -rf /var/lib/apt/lists/*

# ── websockify + websocket-client (CDP monitor) via pip ───────────────────────
RUN pip3 install --no-cache-dir websockify websocket-client

# ── noVNC from GitHub (known-good release with all web assets) ────────────────
RUN wget -qO /tmp/novnc.tar.gz \
    "https://github.com/novnc/noVNC/archive/refs/tags/v1.5.0.tar.gz" \
    && mkdir -p /opt/novnc \
    && tar -xzf /tmp/novnc.tar.gz --strip-components=1 -C /opt/novnc \
    && rm /tmp/novnc.tar.gz

# ── Chrome for Testing ────────────────────────────────────────────────────────
# Branded Google Chrome 137+ ignores the --load-extension switch, so the Site
# Tweaker extension never loads. Chrome for Testing is Google's automation build
# where --load-extension still works (and it matches stable Chrome behaviour).
# https://googlechromelabs.github.io/chrome-for-testing/
RUN set -eux; \
    CFT_JSON="https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json"; \
    CFT_URL="$(curl -fsSL "$CFT_JSON" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(next(b["url"] for b in d["channels"]["Stable"]["downloads"]["chrome"] if b["platform"]=="linux64"))')"; \
    echo "Downloading Chrome for Testing: $CFT_URL"; \
    wget -qO /tmp/chrome.zip "$CFT_URL"; \
    mkdir -p /opt/chrome; \
    unzip -q /tmp/chrome.zip -d /opt/chrome; \
    rm /tmp/chrome.zip; \
    ln -sf /opt/chrome/chrome-linux64/chrome /usr/local/bin/google-chrome; \
    /usr/local/bin/google-chrome --version

# ── Clipboard-enabled client page (served alongside noVNC's own pages) ────────
COPY novnc/pgvnc.html /opt/novnc/pgvnc.html

# ── CDP WebSocket pairing monitor (early scan detection) ──────────────────────
COPY novnc/ws-monitor.py /ws-monitor.py

# ── Startup script ────────────────────────────────────────────────────────────
COPY novnc/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 6080 9222

CMD ["/start.sh"]
