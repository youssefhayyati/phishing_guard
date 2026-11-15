const button = document.getElementById("toggle");
const status = document.getElementById("status");

async function activeTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab;
}

// Talk to the page. If the content script isn't there yet (tab was open before
// the extension was installed/reloaded), inject it and try again.
async function send(tabId, msg) {
  try {
    return await chrome.tabs.sendMessage(tabId, msg);
  } catch {
    await chrome.scripting.executeScript({ target: { tabId }, files: ["content.js"] });
    return await chrome.tabs.sendMessage(tabId, msg);
  }
}

function render(enabled) {
  button.textContent = enabled ? "Close visual editor" : "Enable visual editor";
}

(async () => {
  const tab = await activeTab();
  if (!tab?.id || !/^https?:/.test(tab.url || "")) {
    button.disabled = true;
    status.textContent = "Open a regular website (http or https) first.";
    return;
  }
  try {
    const state = await send(tab.id, { type: "ST_GET_STATE" });
    render(state?.enabled);
  } catch {
    button.disabled = true;
    status.textContent = "Chrome doesn't allow extensions on this page (e.g. the Chrome Web Store).";
  }
})();

button.addEventListener("click", async () => {
  try {
    const tab = await activeTab();
    const state = await send(tab.id, { type: "ST_TOGGLE" });
    render(state?.enabled);
    if (state?.enabled) window.close(); // get the popup out of the way
    else status.textContent = "Editor closed.";
  } catch {
    status.textContent = "Couldn't reach this page. Reload it and try again.";
  }
});
