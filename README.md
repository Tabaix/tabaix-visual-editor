# Tabaix Visual Editor

A WordPress plugin that edits any WordPress theme visually without breaking it. Click any text to change it, swap images, style elements visually, insert Gutenberg-like blocks, hide elements, custom CSS — applied as a safe overlay layer with undo history. Launch it from the admin bar, block editor, post lists, or Customizer.

Built for [tabaix.com](https://tabaix.com/). Free to use and share (GPL-2.0-or-later).

## Features

- **Edits the real frontend** — text, images, styles and blocks on the live page of any theme, without rebuilding the site inside a page builder and without touching theme files.
- **Safe overlay layer** — edits are stored separately and applied as an overlay. Deactivating the plugin restores the original site.
- **Undo history** — every change is a snapshot you can step back through, per page.
- **Pre-built blocks** — 27 blocks (headers, FAQ, pricing table, gallery, video, team, social icons, contact info and more), each with an enable/disable toggle in the admin Blocks Library.
- **Design panel** — per-block font size, text alignment, background colour and padding, applied live and kept through undo/redo.
- **Admin dashboard** — a **Visual Editor** menu with Blocks Library, Settings and History (restore or delete snapshots per page).
- **Custom CSS** — add your own CSS on top of the visual edits.
- **Phone-first** — designed to be usable from a phone, not just a desktop.

## Requirements

- WordPress. No extra libraries — the plugin only uses core WordPress functions.

## Installation

1. Download the [latest release](https://github.com/Tabaix/tabaix-visual-editor/releases) (or clone this repo).
2. Upload the `tabaix-visual-editor` folder to `/wp-content/plugins/`, **or** in WP Admin go to **Plugins > Add New > Upload Plugin** and install the ZIP.
3. Activate **Tabaix Visual Editor**.
4. Open any page on the frontend and launch the editor from the admin bar (or from the block editor, post lists, or Customizer).

## Usage

- **Edit text or an image:** click it on the live page and change it in place.
- **Add a block:** use the **+ Block** flow, pick a block from the library, place it where you want it (top, before footer, bottom), then reorder, duplicate or remove it from the block manager.
- **Style a block:** use the ◆ Design panel on the block row (font size, alignment, background, padding).
- **Undo:** step back through snapshots, or restore a page from **Visual Editor > History** in wp-admin.
- **Turn blocks on/off:** **Visual Editor > Blocks Library** in wp-admin. Disabled blocks are hidden from the block panel and rejected on save.

## FAQ

**Will it break my theme?**
No. Edits are an overlay layer — the theme files are never touched, and deactivating the plugin restores the original site.

**Does it work with any theme?**
Yes — that is the point. It edits the rendered frontend of the theme you already have, instead of asking you to rebuild the site in a builder.

**Where are my edits stored?**
In WordPress options (page snapshots and block settings), not in theme files.

## Links

- [tabaix.com](https://tabaix.com/)
- [Tabaix agency portfolio](https://agency.tabaix.com/portfolio/)
- [Roadmap](ROADMAP.md)

## Changelog

### 1.6.1

- Current release. Shipped in 1.6 "Blocks+": 6 new blocks (27 total), per-block Design panel, wrapper style and video URL carried through duplicates. Earlier: 1.3 "Designs" (5 pre-built design blocks), 1.4 "Builder" (block manager, stable UIDs, anchor-swap moves), 1.5 "Dashboard" (admin menu with Blocks Library, Settings, History).

## License

GPL-2.0-or-later — see https://www.gnu.org/licenses/gpl-2.0.html
