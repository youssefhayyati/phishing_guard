(() => {
  "use strict";

  // ---------------------------------------------------------------------------
  // Single-instance guard. If an older copy is still alive, keep it. If it was
  // orphaned (extension reloaded/updated), tear it down and take over.
  // ---------------------------------------------------------------------------
  const alive = () => { try { return !!chrome.runtime?.id; } catch { return false; } };
  const prev = window.__SITE_TWEAKER_PRO__;
  if (prev && typeof prev.isAlive === "function" && prev.isAlive()) return;
  try { prev?.teardown?.(); } catch {}

  const STORE = "siteTweakerPro_v1";
  const HOST_ID = "__site_tweaker_host";
  const CURSOR_ID = "__site_tweaker_cursor";
  const ADDED_PREFIX = "__site_tweaker_added_";
  const STYLE_FIELDS = ["color","background-color","font-size","font-weight","text-align","line-height","width","height","padding","margin","border","border-radius","display","opacity"];
  const ATTR_FIELDS = ["class","title","src","href"];
  const ADD_TAGS = new Set(["div","p","h2","h3","span","button","a","img","hr"]);
  const SRC_TAGS = new Set(["IMG","SOURCE","VIDEO","AUDIO"]);
  const HREF_TAGS = new Set(["A","AREA"]);
  const VOID_TAGS = /^(area|base|br|col|embed|hr|img|input|link|meta|source|track|wbr|textarea|select|iframe|video|audio|canvas)$/i;
  const BLOCKED_EVENTS = ["pointerdown","pointerup","mousedown","mouseup","click","dblclick","auxclick"];

  let enabled = false, dead = false;
  let selected = null, selectedSelector = "", hovered = null;
  let host = null, shadow = null, cursorStyle = null;
  let cache = null, applyTimer = 0;
  const appliedHtml = new WeakMap();
  const observer = new MutationObserver(() => scheduleApply());

  const pageKey = () => location.origin + location.pathname;
  const isObj = v => !!v && typeof v === "object" && !Array.isArray(v);
  const cssEsc = v => (window.CSS?.escape ? CSS.escape(v) : String(v).replace(/[^a-zA-Z0-9_-]/g, "\\$&"));
  const q = id => shadow.getElementById(id);
  const inHost = node => !!(host && node && (node === host || host.contains(node)));
  const addedDomId = item => ADDED_PREFIX + String(item.id ?? "").replace(/[^\w-]/g, "");

  // ---------------------------------------------------------------------------
  // Selectors
  // ---------------------------------------------------------------------------
  function uniqueId(id) {
    try { return document.querySelectorAll(`#${cssEsc(id)}`).length === 1; } catch { return false; }
  }
  function selectorFor(el) {
    if (el.id && uniqueId(el.id)) return `#${cssEsc(el.id)}`;
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1 && node !== document.body && node !== document.documentElement) {
      if (node !== el && node.id && uniqueId(node.id)) {
        parts.unshift(`#${cssEsc(node.id)}`);
        return parts.join(" > ");
      }
      let part = cssEsc(node.localName);
      const parent = node.parentElement;
      if (parent) {
        const same = [...parent.children].filter(x => x.localName === node.localName);
        if (same.length > 1) part += `:nth-of-type(${same.indexOf(node) + 1})`;
      }
      parts.unshift(part);
      node = parent;
    }
    // Anchor the path so it cannot match a look-alike element elsewhere.
    parts.unshift(node === document.body ? "body" : ":root");
    return parts.join(" > ");
  }
  function findEl(sel) {
    if (typeof sel !== "string" || !sel) return null;
    try {
      const el = document.querySelector(sel);
      return el && !inHost(el) ? el : null;
    } catch { return null; }
  }

  // ---------------------------------------------------------------------------
  // Safety: imported JSON could come from anyone, so never allow script URLs
  // or inline event handlers into the page.
  // ---------------------------------------------------------------------------
  function safeUrl(value, allowDataImage = false) {
    const u = String(value ?? "").trim();
    if (!u) return "";
    if (/^[a-z][a-z0-9+.-]*:/i.test(u)) {
      if (/^(https?|mailto|tel):/i.test(u)) return u;
      if (allowDataImage && /^data:image\/(png|jpe?g|gif|webp|avif|svg\+xml)[;,]/i.test(u)) return u;
      return "";
    }
    return u; // relative URL
  }
  function sanitizeHtml(html) {
    const t = document.createElement("template");
    t.innerHTML = String(html);
    t.content.querySelectorAll("script,iframe,frame,frameset,object,embed,link,meta,base").forEach(n => n.remove());
    t.content.querySelectorAll("*").forEach(n => {
      for (const a of [...n.attributes]) {
        const name = a.name.toLowerCase();
        if (name.startsWith("on") || name === "srcdoc") n.removeAttribute(a.name);
        else if (["href","src","action","formaction","xlink:href","poster","data"].includes(name) && !safeUrl(a.value, true)) n.removeAttribute(a.name);
      }
    });
    const d = document.createElement("div");
    d.appendChild(t.content.cloneNode(true));
    return d.innerHTML;
  }

  // ---------------------------------------------------------------------------
  // Storage (cached in memory, kept in sync across tabs)
  // ---------------------------------------------------------------------------
  const normalize = d => (isObj(d) && isObj(d.pages) ? { version: 1, pages: d.pages } : { version: 1, pages: {} });
  async function getAll() {
    const r = await chrome.storage.local.get(STORE);
    cache = normalize(r[STORE]);
    return cache;
  }
  async function setAll(data) {
    cache = data;
    await chrome.storage.local.set({ [STORE]: data });
  }
  const pageItems = data => (Array.isArray(data.pages[pageKey()]) ? data.pages[pageKey()].filter(isObj) : []);
  function writePage(data, list) {
    if (list.length) data.pages[pageKey()] = list;
    else delete data.pages[pageKey()];
  }

  // ---------------------------------------------------------------------------
  // Applying saved changes. Every step is idempotent so re-applying after the
  // site re-renders does not cause flicker or endless DOM mutations.
  // ---------------------------------------------------------------------------
  function withoutAdded(el) {
    const sel = `[id^="${ADDED_PREFIX}"]`;
    if (!el.querySelector(sel)) return el;
    const c = el.cloneNode(true);
    c.querySelectorAll(sel).forEach(n => n.remove());
    return c;
  }
  function applyAttr(el, name, value) {
    let v = value;
    if (name === "src") {
      if (!SRC_TAGS.has(el.tagName)) return;
      v = safeUrl(v, true);
      if (!v) return;
      if (el.tagName === "IMG") {
        // srcset / <picture> sources would otherwise override the new src.
        if (el.hasAttribute("srcset")) el.removeAttribute("srcset");
        if (el.hasAttribute("sizes")) el.removeAttribute("sizes");
        if (el.parentElement?.tagName === "PICTURE") el.parentElement.querySelectorAll("source").forEach(s => s.remove());
      }
    } else if (name === "href") {
      if (!HREF_TAGS.has(el.tagName)) return;
      v = safeUrl(v);
      if (!v) return;
    } else if (!(name === "class" || name === "title" || name === "alt" || name.startsWith("aria-") || name.startsWith("data-"))) {
      return;
    }
    try { if (el.getAttribute(name) !== v) el.setAttribute(name, v); } catch {}
  }
  function applyItem(item) {
    const el = findEl(item.selector);
    if (!el) return;
    if (typeof item.textContent === "string") {
      if (withoutAdded(el).textContent !== item.textContent) el.textContent = item.textContent;
    } else if (typeof item.html === "string") {
      const clean = sanitizeHtml(item.html);
      const rec = appliedHtml.get(el);
      if (!rec || rec.clean !== clean || withoutAdded(el).innerHTML !== rec.result) {
        el.innerHTML = clean;
        appliedHtml.set(el, { clean, result: el.innerHTML });
      }
    }
    if (isObj(item.attributes)) {
      for (const [name, value] of Object.entries(item.attributes)) applyAttr(el, String(name).toLowerCase(), String(value ?? ""));
    }
    if (isObj(item.styles)) {
      for (const [name, value] of Object.entries(item.styles)) {
        if (typeof value === "string" && value.trim()) el.style.setProperty(name, value.trim(), "important");
      }
    }
    if (item.hidden === true) el.style.setProperty("display", "none", "important");
  }
  function applyAdd(item) {
    const tag = String(item.tag || "").toLowerCase();
    if (!ADD_TAGS.has(tag) || item.id === undefined) return;
    const parent = findEl(item.parentSelector);
    if (!parent) return;
    const id = addedDomId(item);
    if (document.getElementById(id)) return;
    const el = document.createElement(tag);
    el.id = id;
    if (isObj(item.styles)) {
      for (const [k, v] of Object.entries(item.styles)) if (typeof v === "string" && v.trim()) el.style.setProperty(k, v.trim());
    }
    if (tag === "a") {
      const href = safeUrl(item.href);
      if (href) el.href = href;
      el.textContent = item.text || href || "Link";
    } else if (tag === "img") {
      const src = safeUrl(item.src, true);
      if (src) el.src = src;
      el.alt = String(item.alt || "");
      el.style.maxWidth ||= "100%";
    } else if (tag !== "hr" && item.text) {
      el.textContent = String(item.text);
    }
    if (tag === "button") el.type = "button";
    if (item.className) el.className = String(item.className);
    parent.appendChild(el);
  }
  function applySaved() {
    if (dead) return;
    if (!alive()) { teardown(); return; }
    if (!cache) return;
    const items = pageItems(cache);
    if (items.length) {
      const edits = items.filter(x => x.kind !== "add");
      const adds = items.filter(x => x.kind === "add");
      observer.disconnect(); // don't react to our own changes
      try {
        // edits → adds → edits again (so edits on added elements also apply)
        for (const it of edits) { try { applyItem(it); } catch {} }
        for (const it of adds) { try { applyAdd(it); } catch {} }
        for (const it of edits) { try { applyItem(it); } catch {} }
      } finally {
        if (!dead) observer.observe(document.documentElement, { childList: true, subtree: true });
      }
    }
    refreshBoxes();
  }
  function scheduleApply() {
    if (applyTimer || dead) return;
    applyTimer = setTimeout(() => { applyTimer = 0; applySaved(); }, 60);
  }

  // ---------------------------------------------------------------------------
  // Editor UI (everything lives in a shadow root so the page can't style it
  // and so outlines never touch the page DOM)
  // ---------------------------------------------------------------------------
  function makeUI() {
    if (host) return;
    host = document.createElement("div");
    host.id = HOST_ID;
    host.style.cssText = "all:initial;position:fixed;inset:0;z-index:2147483647;pointer-events:none;display:block;";
    shadow = host.attachShadow({ mode: "open" });
    shadow.innerHTML = `
<style>
:host{all:initial}*{box-sizing:border-box}
.box{position:fixed;pointer-events:none;display:none;z-index:1}
.hoverBox{outline:1px dashed #ff9d36;background:#ff9d3610}
.selBox{border:2px solid #655cff;background:#655cff18}
.shell{pointer-events:auto;cursor:default;position:fixed;z-index:2;right:0;top:0;width:360px;height:100vh;overflow:auto;background:#101522;color:#edf1f8;box-shadow:-12px 0 35px #0004;font:13px/1.45 Inter,system-ui,sans-serif;border-left:1px solid #293246}
.shell.left{right:auto;left:0;border-left:0;border-right:1px solid #293246;box-shadow:12px 0 35px #0004}
.top{position:sticky;top:0;z-index:5;background:#151d2c;padding:15px 16px;border-bottom:1px solid #293246;display:flex;justify-content:space-between;align-items:center;gap:8px}
.brand{font-weight:800;font-size:15px;letter-spacing:.2px}.sub{font-size:11px;color:#98a5ba;margin-top:2px}
.topbtns{display:flex;gap:6px}
.close{background:#273247;color:#dce4f2;border:0;border-radius:8px;padding:7px 10px;cursor:pointer;font:inherit}
main{padding:14px 16px 28px}.notice{padding:9px 10px;border-radius:8px;background:#202c41;color:#aebbd0;font-size:11px;margin-bottom:12px}
.tabs{display:flex;gap:6px;margin-bottom:13px}.tab{flex:1;border:1px solid #344158;background:#1a2435;color:#aebbd0;border-radius:8px;padding:8px 4px;cursor:pointer;font:700 12px system-ui}.tab.active{background:#4f46e5;color:white;border-color:#4f46e5}
.section{border:1px solid #2b374c;background:#151e2d;border-radius:10px;padding:11px;margin:10px 0}.section h3{font-size:12px;margin:0 0 9px;color:#dce5f3}
label{display:block;font-size:11px;color:#aab6c9;margin:8px 0 4px}
input,select,textarea{width:100%;background:#0f1725;border:1px solid #344158;border-radius:7px;color:#edf1f8;padding:8px;font:12px system-ui;outline:none;cursor:text}
select,input[type=file],input[type=checkbox]{cursor:pointer}
input:disabled{opacity:.45;cursor:not-allowed}
input[type=checkbox]{width:auto;vertical-align:middle}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.btn{border:0;border-radius:8px;padding:9px 10px;cursor:pointer;font:750 12px system-ui}.primary{background:#5b54f0;color:white}.secondary{background:#29364c;color:#dce5f3}.danger{background:#4a2732;color:#ffdbe2}.wide{width:100%;margin-top:8px}
.small{padding:5px 8px;font-size:11px;margin-top:8px}
.muted{font-size:10px;color:#8795aa;margin-top:6px}.selected{font-size:11px;color:#9eaee0;overflow-wrap:anywhere}
.hidden{display:none!important}.check{display:flex;align-items:center;gap:7px;margin-top:10px}.check label{margin:0}
.status{min-height:16px;color:#8ee0b2;font-size:11px;margin-top:8px}.status.err{color:#ff9cad}
</style>
<div class="box hoverBox" id="hoverBox"></div>
<div class="box selBox" id="selBox"></div>
<div class="shell" id="shell">
 <div class="top"><div><div class="brand">✦ Site Tweaker</div><div class="sub">Visual website editor · local changes only</div></div>
  <div class="topbtns"><button class="close" id="side" title="Move panel to the other side">⇆</button><button class="close" id="close" title="Close editor">✕</button></div></div>
 <main>
  <div class="notice">While the editor is open, clicking the page selects elements instead of following links. Edit properties here, then save. Changes are stored in this Chrome profile.</div>
  <div class="tabs"><button class="tab active" data-tab="edit">Edit element</button><button class="tab" data-tab="add">Add element</button><button class="tab" data-tab="data">Import / Export</button></div>
  <section id="edit">
   <div class="section"><h3>Selected element</h3><div class="selected" id="selected">Click any page element to select it.</div>
    <button class="btn secondary small" id="pickParent">Select parent element</button></div>
   <div class="section"><h3>Content</h3>
    <label>Text content</label><textarea id="text" rows="3" placeholder="Enter replacement text"></textarea>
    <label>HTML content (optional, advanced)</label><textarea id="html" rows="2" placeholder="Use only if you understand HTML"></textarea>
    <div class="muted">If Text is filled, it takes priority over HTML. Replacing content removes the element's existing child elements. Scripts and event handlers are stripped from HTML.</div>
   </div>
   <div class="section"><h3>Colors & typography</h3>
    <div class="grid"><div><label>Text color</label><input id="color" placeholder="#ffffff"></div><div><label>Background</label><input id="background-color" placeholder="#222222"></div>
    <div><label>Font size</label><input id="font-size" placeholder="18px"></div><div><label>Font weight</label><input id="font-weight" placeholder="600"></div>
    <div><label>Text align</label><select id="text-align"><option value="">Default</option><option>left</option><option>center</option><option>right</option><option>justify</option></select></div>
    <div><label>Line height</label><input id="line-height" placeholder="1.5"></div></div>
   </div>
   <div class="section"><h3>Size & spacing</h3>
    <div class="grid"><div><label>Width</label><input id="width" placeholder="300px / 80%"></div><div><label>Height</label><input id="height" placeholder="auto / 200px"></div>
    <div><label>Padding</label><input id="padding" placeholder="12px 16px"></div><div><label>Margin</label><input id="margin" placeholder="10px auto"></div>
    <div><label>Border</label><input id="border" placeholder="1px solid #ccc"></div><div><label>Radius</label><input id="border-radius" placeholder="12px"></div>
    <div><label>Display</label><select id="display"><option value="">Default</option><option>block</option><option>inline</option><option>inline-block</option><option>flex</option><option>grid</option><option>none</option></select></div>
    <div><label>Opacity</label><input id="opacity" placeholder="0.5 / 1"></div></div>
    <div class="check"><input type="checkbox" id="hidden"><label for="hidden">Hide element</label></div>
   </div>
   <div class="section"><h3>Other properties</h3>
    <label>CSS class (replace existing class)</label><input id="class" placeholder="my-custom-class">
    <label>Title attribute</label><input id="title" placeholder="Tooltip text">
    <label>Image source (img elements only)</label><input id="src" placeholder="https://...">
    <label>Link URL (a elements only)</label><input id="href" placeholder="https://...">
   </div>
   <button class="btn primary wide" id="save">Save selected element</button>
   <button class="btn secondary wide" id="reset">Reset selected element</button>
   <div class="status" id="editStatus"></div>
  </section>
  <section id="add" class="hidden">
   <div class="section"><h3>Add a new element to the page</h3>
    <label>Insert inside selected element</label><div class="selected" id="parent">No parent selected — click a page element first.</div>
    <label>Element type</label><select id="newTag"><option value="div">Container (div)</option><option value="p">Paragraph</option><option value="h2">Heading</option><option value="h3">Small heading</option><option value="span">Inline text</option><option value="button">Button</option><option value="a">Link</option><option value="img">Image</option><option value="hr">Divider</option></select>
    <label>Text / label</label><input id="newText" placeholder="Your new content">
    <label>URL (for link or image)</label><input id="newUrl" placeholder="https://...">
    <label>Alt text (for image)</label><input id="newAlt" placeholder="Image description">
    <div class="grid"><div><label>Text color</label><input id="newColor" placeholder="#222"></div><div><label>Background</label><input id="newBg" placeholder="#eee"></div><div><label>Width</label><input id="newWidth" placeholder="100%"></div><div><label>Padding</label><input id="newPadding" placeholder="10px"></div></div>
    <button class="btn primary wide" id="addElement">Add element & save</button>
    <div class="muted">The new element is appended inside the selected parent. To remove it later, select it and click "Reset selected element".</div>
    <div class="status" id="addStatus"></div>
   </div>
  </section>
  <section id="data" class="hidden">
   <div class="section"><h3>Export this website's changes</h3><p class="muted">Download a JSON file containing saved edits for this page.</p><button class="btn primary wide" id="exportPage">Export current page JSON</button></div>
   <div class="section"><h3>Export all websites</h3><p class="muted">Back up every Site Tweaker change in one JSON file.</p><button class="btn secondary wide" id="exportAll">Export all changes</button></div>
   <div class="section"><h3>Import JSON</h3><label>Choose a Site Tweaker JSON file</label><input type="file" id="importFile" accept=".json,application/json">
    <div class="check"><input type="checkbox" id="replaceImport"><label for="replaceImport">Replace existing changes for matching pages</label></div>
    <button class="btn primary wide" id="import">Import JSON</button><div class="muted">Imported page changes apply when you visit or reload the matching URL.</div>
    <div class="status" id="dataStatus"></div>
   </div>
   <div class="section"><h3>Remove saved changes</h3><button class="btn danger wide" id="clearPage">Delete changes for this page</button><div class="status" id="clearStatus"></div></div>
  </section>
 </main>
</div>`;
    document.documentElement.appendChild(host);

    const shell = q("shell");
    // Stop typing in the panel from triggering the site's keyboard shortcuts.
    for (const t of ["keydown","keyup","keypress"]) shell.addEventListener(t, e => e.stopPropagation());

    q("close").onclick = disable;
    q("side").onclick = () => shell.classList.toggle("left");
    shadow.querySelectorAll(".tab").forEach(btn => btn.onclick = () => showTab(btn.dataset.tab));
    q("pickParent").onclick = () => {
      const p = selected?.parentElement;
      if (p && p !== document.body && p !== document.documentElement) selectElement(p);
      else setStatus("editStatus", "This element has no selectable parent.", true);
    };
    q("save").onclick = () => run(saveSelected, "editStatus");
    q("reset").onclick = () => run(resetSelected, "editStatus");
    q("addElement").onclick = () => run(addElement, "addStatus");
    q("exportPage").onclick = () => run(() => exportData(false), "dataStatus");
    q("exportAll").onclick = () => run(() => exportData(true), "dataStatus");
    q("import").onclick = () => run(importData, "dataStatus");
    q("clearPage").onclick = () => run(clearPage, "clearStatus");
  }

  async function run(fn, statusId) {
    try { await fn(); }
    catch (e) {
      const msg = /context invalidated/i.test(e?.message || "")
        ? "The extension was updated. Reload this page."
        : (e?.message || "Something went wrong.");
      setStatus(statusId, msg, true);
    }
  }
  function setStatus(id, msg, isError = false) {
    if (!shadow) return;
    const el = q(id);
    el.textContent = msg;
    el.classList.toggle("err", isError);
  }
  function showTab(id) {
    shadow.querySelectorAll(".tab").forEach(x => x.classList.toggle("active", x.dataset.tab === id));
    ["edit","add","data"].forEach(x => q(x).classList.toggle("hidden", x !== id));
  }
  function setVal(id, value) { q(id).value = value ?? ""; }
  function currentTab() { return shadow.querySelector(".tab.active")?.dataset.tab; }

  function positionBox(box, el) {
    if (!box) return;
    if (!el || !el.isConnected) { box.style.display = "none"; return; }
    const r = el.getBoundingClientRect();
    if (!r.width && !r.height) { box.style.display = "none"; return; }
    Object.assign(box.style, { display: "block", top: r.top + "px", left: r.left + "px", width: r.width + "px", height: r.height + "px" });
  }
  function refreshBoxes() {
    if (!shadow) return;
    if (selected && !selected.isConnected && selectedSelector) {
      const again = findEl(selectedSelector);
      if (again) selected = again; // site re-rendered the node
    }
    positionBox(q("selBox"), selected);
    positionBox(q("hoverBox"), hovered);
  }

  async function selectElement(el) {
    if (!el || el === document.body || el === document.documentElement || inHost(el)) return;
    makeUI();
    selected = el;
    selectedSelector = selectorFor(el);
    const label = `${el.localName}  ·  ${selectedSelector}`;
    q("selected").textContent = label;
    q("parent").textContent = label;
    ["editStatus","addStatus"].forEach(id => setStatus(id, ""));
    if (currentTab() === "data") showTab("edit");
    q("src").disabled = !SRC_TAGS.has(el.tagName);
    q("href").disabled = !HREF_TAGS.has(el.tagName);
    q("reset").textContent = el.id.startsWith(ADDED_PREFIX) ? "Delete this added element" : "Reset selected element";
    refreshBoxes();

    let items = [];
    try { items = pageItems(await getAll()); } catch {}
    if (selected !== el || !shadow) return; // selection changed meanwhile
    const item = items.find(x => x.kind !== "add" && x.selector === selectedSelector) || {};
    const styles = isObj(item.styles) ? item.styles : {};
    const attrs = isObj(item.attributes) ? item.attributes : {};
    setVal("text", item.textContent);
    setVal("html", item.html);
    for (const id of STYLE_FIELDS) setVal(id, styles[id]);
    q("hidden").checked = item.hidden === true;
    for (const n of ATTR_FIELDS) setVal(n, attrs[n] ?? el.getAttribute(n));
  }

  // ---------------------------------------------------------------------------
  // Page interaction while editing
  // ---------------------------------------------------------------------------
  function fromHost(e) { return !!host && e.composedPath().includes(host); }
  function onBlocked(e) {
    if (!enabled || fromHost(e)) return;
    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
    if (e.type === "click") {
      const t = e.target instanceof Element ? e.target : e.target?.parentElement;
      selectElement(t);
    }
  }
  function onHover(e) {
    if (!enabled || !shadow) return;
    const t = e.target;
    hovered = (t instanceof Element && !fromHost(e) && t !== document.body && t !== document.documentElement) ? t : null;
    positionBox(q("hoverBox"), hovered);
  }
  const scrollOpts = { capture: true, passive: true };

  function enable() {
    if (enabled) return;
    enabled = true;
    makeUI();
    for (const t of BLOCKED_EVENTS) window.addEventListener(t, onBlocked, true);
    window.addEventListener("pointerover", onHover, true);
    window.addEventListener("scroll", refreshBoxes, scrollOpts);
    window.addEventListener("resize", refreshBoxes);
    cursorStyle = document.createElement("style");
    cursorStyle.id = CURSOR_ID;
    cursorStyle.textContent = "html, html *{cursor:crosshair!important}";
    (document.head || document.documentElement).appendChild(cursorStyle);
  }
  function disable() {
    enabled = false;
    for (const t of BLOCKED_EVENTS) window.removeEventListener(t, onBlocked, true);
    window.removeEventListener("pointerover", onHover, true);
    window.removeEventListener("scroll", refreshBoxes, scrollOpts);
    window.removeEventListener("resize", refreshBoxes);
    cursorStyle?.remove(); cursorStyle = null;
    host?.remove(); host = null; shadow = null;
    selected = null; selectedSelector = ""; hovered = null;
  }
  function teardown() {
    dead = true;
    try { disable(); } catch {}
    observer.disconnect();
    clearTimeout(applyTimer);
  }

  // ---------------------------------------------------------------------------
  // Actions
  // ---------------------------------------------------------------------------
  function currentSelected() {
    if (selected?.isConnected) return selected;
    const again = findEl(selectedSelector);
    if (again) selected = again;
    return again;
  }

  async function saveSelected() {
    const el = currentSelected();
    if (!el) { setStatus("editStatus", "Select an element on the page first.", true); return; }
    const selector = selectedSelector;

    const styles = {};
    for (const id of STYLE_FIELDS) { const v = q(id).value.trim(); if (v) styles[id] = v; }
    const hidden = q("hidden").checked;
    const text = q("text").value, html = q("html").value;

    for (const n of ["src","href"]) {
      const v = q(n).value.trim();
      if (v && !q(n).disabled && !safeUrl(v, n === "src")) {
        setStatus("editStatus", `That ${n === "src" ? "image" : "link"} URL isn't allowed. Use http(s), mailto, tel or a relative URL.`, true);
        return;
      }
    }

    const data = await getAll();
    const list = pageItems(data);
    const old = list.find(x => x.kind !== "add" && x.selector === selector) || {};
    const oldAttrs = isObj(old.attributes) ? old.attributes : {};
    const oldStyles = isObj(old.styles) ? old.styles : {};

    // Only store attributes the user actually changed, so the site's own
    // dynamic classes aren't frozen.
    const attributes = {};
    for (const n of ATTR_FIELDS) {
      if (q(n).disabled) continue;
      const v = q(n).value.trim();
      if (!v) continue;
      if (n in oldAttrs || v !== (el.getAttribute(n) ?? "")) attributes[n] = v;
    }

    const item = { kind: "edit", selector, styles, attributes, hidden };
    if (text !== "") item.textContent = text;
    else if (html.trim() !== "") item.html = html;

    // Undo cleared properties right away where we can.
    for (const n of Object.keys(oldStyles)) if (!(n in styles)) el.style.removeProperty(n);
    if (old.hidden === true && !hidden && !("display" in styles)) el.style.removeProperty("display");
    const contentRemoved = (typeof old.textContent === "string" || typeof old.html === "string") && !("textContent" in item) && !("html" in item);
    const attrRemoved = Object.keys(oldAttrs).some(n => !(n in attributes));

    const hasChanges = Object.keys(styles).length || Object.keys(attributes).length || hidden || "textContent" in item || "html" in item;
    const next = list.filter(x => !(x.kind !== "add" && x.selector === selector));
    if (hasChanges) next.push(item);
    writePage(data, next);
    await setAll(data);
    applySaved();

    if (contentRemoved || attrRemoved) setStatus("editStatus", "Saved. Reload the page to restore the original content.");
    else setStatus("editStatus", hasChanges ? "Saved. Your changes will be reapplied on reload." : "Nothing to save — no changes for this element.");
  }
  async function resetSelected() {
    const el = currentSelected();
    if (!el) { setStatus("editStatus", "Select an element first.", true); return; }
    const selector = selectedSelector;
    const data = await getAll();
    const isAdded = el.id.startsWith(ADDED_PREFIX);
    const next = pageItems(data).filter(x => {
      if (x.kind !== "add") return x.selector !== selector;
      return !(isAdded && addedDomId(x) === el.id);
    });
    writePage(data, next);
    await setAll(data);
    if (isAdded) {
      el.remove();
      selected = null; selectedSelector = "";
      q("selected").textContent = "Click any page element to select it.";
      q("parent").textContent = "No parent selected — click a page element first.";
      refreshBoxes();
      setStatus("editStatus", "Added element deleted.");
    } else {
      location.reload();
    }
  }

  async function addElement() {
    const parent = currentSelected();
    if (!parent) { setStatus("addStatus", "Select a parent element on the page first.", true); return; }
    if (VOID_TAGS.test(parent.localName)) {
      setStatus("addStatus", `A <${parent.localName}> can't contain other elements. Select a container instead (try "Select parent element").`, true);
      return;
    }
    const tag = q("newTag").value;
    if (!ADD_TAGS.has(tag)) return;
    const text = q("newText").value;
    const url = q("newUrl").value.trim();
    if (tag === "img" && !safeUrl(url, true)) { setStatus("addStatus", "Enter a valid image URL (https://...).", true); return; }
    if (tag === "a" && !safeUrl(url)) { setStatus("addStatus", "Enter a valid link URL (https://...).", true); return; }

    const styles = {};
    for (const [field, prop] of [["newColor","color"],["newBg","background-color"],["newWidth","width"],["newPadding","padding"]]) {
      const v = q(field).value.trim(); if (v) styles[prop] = v;
    }
    const id = crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
    const item = { kind: "add", id, tag, parentSelector: selectedSelector, text, styles };
    if (tag === "a") item.href = url;
    if (tag === "img") { item.src = url; item.alt = q("newAlt").value.trim(); }

    const data = await getAll();
    writePage(data, [...pageItems(data), item]);
    await setAll(data);
    applySaved();
    const created = document.getElementById(addedDomId(item));
    if (created) {
      ["newText","newUrl","newAlt"].forEach(f => setVal(f, ""));
      setStatus("addStatus", "Element added and saved.");
      created.scrollIntoView({ block: "nearest" });
    } else {
      setStatus("addStatus", "Saved, but the element couldn't be inserted on this page.", true);
    }
  }

  function downloadJson(obj, name) {
    const blob = new Blob([JSON.stringify(obj, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob), a = document.createElement("a");
    a.href = url; a.download = name; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  }
  async function exportData(allPages) {
    const data = await getAll();
    const items = pageItems(data);
    if (!allPages && !items.length) { setStatus("dataStatus", "There are no saved changes for this page yet.", true); return; }
    const output = allPages ? data : { version: 1, pages: { [pageKey()]: items } };
    const site = location.hostname.replace(/[^\w.-]/g, "_") || "page";
    downloadJson(output, allPages ? "site-tweaker-all-sites.json" : `site-tweaker-${site}.json`);
    setStatus("dataStatus", "JSON export created.");
  }

  function mergeItems(existing, incoming) {
    const keyOf = x => (x.kind === "add" ? "a:" + String(x.id) : "e:" + String(x.selector));
    const map = new Map();
    for (const x of existing.filter(isObj)) map.set(keyOf(x), x);
    for (const x of incoming) map.set(keyOf(x), x); // imported wins
    return [...map.values()];
  }
  async function importData() {
    const file = q("importFile").files[0];
    if (!file) { setStatus("dataStatus", "Choose a JSON file first.", true); return; }
    let parsed;
    try { parsed = JSON.parse(await file.text()); } catch { throw new Error("That file isn't valid JSON."); }
    if (!isObj(parsed) || parsed.version !== 1 || !isObj(parsed.pages)) throw new Error("This isn't a Site Tweaker export (expected version 1 with a \"pages\" object).");

    const data = await getAll(), replace = q("replaceImport").checked;
    let count = 0;
    for (const [page, items] of Object.entries(parsed.pages)) {
      if (!Array.isArray(items)) continue;
      try { if (!/^https?:$/.test(new URL(page).protocol)) continue; } catch { continue; }
      const clean = items.filter(isObj);
      data.pages[page] = replace ? clean : mergeItems(Array.isArray(data.pages[page]) ? data.pages[page] : [], clean);
      if (!data.pages[page].length) delete data.pages[page];
      count++;
    }
    if (!count) throw new Error("No valid page entries were found in this file.");
    await setAll(data);
    const here = Object.prototype.hasOwnProperty.call(parsed.pages, pageKey());
    setStatus("dataStatus", `Imported ${count} page${count === 1 ? "" : "s"}.${here ? " Reloading to apply…" : " Changes apply when you visit those pages."}`);
    if (here) setTimeout(() => location.reload(), 900);
  }

  async function clearPage() {
    if (!confirm("Delete all saved Site Tweaker changes for this page?")) return;
    const data = await getAll();
    delete data.pages[pageKey()];
    await setAll(data);
    location.reload();
  }

  // ---------------------------------------------------------------------------
  // Wiring
  // ---------------------------------------------------------------------------
  chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
    if (dead) return;
    if (msg?.type === "ST_TOGGLE") { enabled ? disable() : enable(); reply({ enabled }); }
    else if (msg?.type === "ST_GET_STATE") reply({ enabled });
  });
  chrome.storage.onChanged.addListener((changes, area) => {
    if (dead || area !== "local" || !changes[STORE]) return;
    cache = normalize(changes[STORE].newValue);
    scheduleApply();
  });
  // Single-page apps change the URL without reloading.
  window.addEventListener("popstate", scheduleApply);

  window.__SITE_TWEAKER_PRO__ = { isAlive: alive, teardown };

  observer.observe(document.documentElement, { childList: true, subtree: true });
  getAll().then(applySaved).catch(() => {});
})();
