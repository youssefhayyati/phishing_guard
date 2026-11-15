# Site Tweaker — Visual Editor (Chrome Extension)

A Manifest V3 Chrome extension for visually customizing websites in your own browser.

## Features

- Click an element to select it and see its CSS selector.
- Edit text or HTML content.
- Change text/background colors, font size/weight, alignment, line height.
- Change width, height, padding, margin, border, border radius, display and opacity.
- Hide elements.
- Delete elements you added (select one, then **Delete this added element**).
- Set common attributes/classes, image source and link URL.
- Add new containers, paragraphs, headings, spans, buttons, links, images and dividers inside a selected element.
- Save changes locally and reapply them on reload.
- Export current-page JSON or all-sites JSON.
- Import a JSON backup, either merging or replacing matching page entries.
- Clear changes for the current page.

## Install

1. Download and unzip `site-tweaker.zip`.
2. In Chrome, open `chrome://extensions`.
3. Enable **Developer mode**.
4. Click **Load unpacked** and select the unzipped `site-tweaker` folder.
   (Updating from an older version? Click the reload ↻ icon on the extension card instead.)
5. Open an ordinary `https://` or `http://` website. (Tabs that were already open work too — no refresh needed.)
6. Click the extension icon, then **Enable visual editor**.
7. Click an element on the page to select it. While the editor is open, clicks select elements instead of following links. Use **Select parent element** to climb to a container, and **⇆** to move the panel to the other side.
8. Save changes. Reload the page to see them restored.

## Import / export

Open the editor's **Import / Export** tab:
- Export current page: exports the current origin + pathname.
- Export all websites: exports all stored page customizations.
- Import JSON: choose a previously exported file. Choose whether to replace existing changes for matching pages or merge them.

## Important limitations

- These are local browser-side changes. They do not modify the website's server, source code, or what other visitors see.
- The extension keys edits by origin and pathname. URL query parameters and hash fragments are ignored.
- Websites with frequently changing markup may cause saved selectors to stop matching.
- The current starter release uses CSS selectors for element matching; it is not a full drag-and-drop page builder.
- HTML editing can replace an element's children. Use it carefully.
- Chrome blocks extensions from modifying protected pages such as `chrome://` pages and the Chrome Web Store.
- A site's own scripts may overwrite some changes after the page loads.
- This is a starter tool: no undo/redo history, responsive breakpoint editor, or automatic selector repair yet.

## Changelog

### 1.2.0
- Fixed: selecting, saving, adding and importing crashed (swapped arguments in an internal helper).
- Fixed: re-applying saved edits triggered an endless loop of DOM updates and storage reads.
- Fixed: saved image source and link URL changes were never applied.
- Fixed: selectors weren't anchored to `<body>`, so edits could land on the wrong element.
- Fixed: removing a style or un-hiding an element could also wipe a display value you set.
- Fixed: adding an element inside an element whose text you edited made it disappear.
- Fixed: tabs that were open before installing needed a manual refresh.
- Security: imported HTML and URLs are sanitized (no `<script>`, inline event handlers or `javascript:` links).
- Improved: page clicks and mouse presses are fully blocked while editing; outlines follow scrolling; typing in the panel no longer triggers the site's keyboard shortcuts; works on single-page apps that change URL without reloading.
