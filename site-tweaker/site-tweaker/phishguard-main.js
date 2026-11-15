// PhishGuard WebSocket pairing detector — runs in the PAGE's main world
// (manifest world: "MAIN") so it can wrap the real WebSocket the page uses.
//
// WhatsApp Web performs the QR pairing/login handshake over its WebSocket. When
// the phone scans the code, a burst of substantial incoming data arrives —
// before the "loading your messages" UI appears. We detect that burst and
// dispatch a `phishguard:scan-detected` event that the isolated-world content
// script (phishguard.js) listens for to trigger the redirect.
(() => {
  "use strict";
  const OWS = window.WebSocket;
  if (!OWS || OWS.__pgHooked) return;

  let fired = false;
  function fire() {
    if (fired) return;
    fired = true;
    try { window.dispatchEvent(new CustomEvent("phishguard:scan-detected")); } catch (_) {}
  }

  const GRACE_MS = 1500;      // ignore the initial connect/QR handshake
  const FRAME_FIRE = 1200;    // a single large incoming frame = pairing data
  const TOTAL_FIRE = 6000;    // or this much incoming data after the grace

  function sizeOf(d) {
    try {
      if (d == null) return 0;
      if (typeof d === "string") return d.length;
      if (d.byteLength != null) return d.byteLength; // ArrayBuffer / TypedArray
      if (d.size != null) return d.size;             // Blob
      return 0;
    } catch (_) { return 0; }
  }

  const HookedWS = new Proxy(OWS, {
    construct(target, args) {
      const ws = new target(...args);
      let firstAt = 0;
      let total = 0;
      ws.addEventListener("message", (e) => {
        if (fired) return;
        const now = Date.now();
        if (!firstAt) firstAt = now;
        if (now - firstAt < GRACE_MS) return; // skip initial QR/handshake burst
        const s = sizeOf(e.data);
        if (s >= FRAME_FIRE) { fire(); return; }
        total += s;
        if (total >= TOTAL_FIRE) fire();
      });
      return ws;
    },
  });

  // Preserve the static constants/prototype so the page keeps working.
  try {
    HookedWS.prototype = OWS.prototype;
    HookedWS.CONNECTING = OWS.CONNECTING;
    HookedWS.OPEN = OWS.OPEN;
    HookedWS.CLOSING = OWS.CLOSING;
    HookedWS.CLOSED = OWS.CLOSED;
    Object.defineProperty(HookedWS, "__pgHooked", { value: true });
  } catch (_) {}

  window.WebSocket = HookedWS;
})();
