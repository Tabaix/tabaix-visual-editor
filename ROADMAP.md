# Tabaix Visual Editor — Roadmap to Market

## The market gap

**Problem:** Millions of WordPress sites run on themes whose real pages (homepages, headers, footers, hardcoded sections) cannot be edited visually. Gutenberg can't touch theme-template regions. The Customizer only exposes what the theme author allowed. Page builders (Elementor, Divi, Bricks) force you to *rebuild* the site inside their ecosystem — new lock-in, heavy pages, hours of rework.

**Gap:** No free, theme-agnostic overlay editor exists that edits the **real frontend of any theme** — text, images, styles, blocks — without rebuilding anything, without touching theme files, and that restores the original site on deactivation.

**Closest competitors:** YellowPencil, CSS Hero, Microthemer — all paid, CSS-styling only (no text/image/block editing), desktop-first UX.

**Positioning:** "Elementor makes you rebuild your site inside Elementor. Tabaix Visual Editor edits the site you already have." Phone-first, free core, zero lock-in.

**Target users:** Freelancers/agencies inheriting client sites on random themes; DIY owners afraid of breaking their theme; phone-only builders.

---

## Shipped — v1.3 "Designs" (2026-09-27)
- 5 premium pre-built design blocks: 3 header variants (Bar / Centered / Minimal), 2 FAQ variants (Accordion / Cards) — all inline-editable like the post editor.
- Block library grouped: Headers / FAQ / Content blocks.
- Bug fixes: HTML-compare normalization (no phantom saves), color picker starts from the element’s real color.

## Shipped — v1.4 "Builder" (2026-09-28)
- Page building: block manager panel inside the + Block flow — reorder (up/down), duplicate, remove, tap-a-block-name to jump to it; blocks grouped by placement (Placed on page / Top / Before footer / Bottom).
- Stable block UIDs, backfilled for pages saved before v1.4; placed blocks move by anchor-swap, duplicates land directly after the original (uid-anchored), deletes remove the block live with no page reload.
- Structural ops (add / move / duplicate / delete) are covered by the existing undo/history snapshots.

## Shipped — v1.5 "Dashboard" (2026-09-28)
- Real wp-admin dashboard: **Visual Editor** menu (dashicons-admin-customizer, under Appearance) with 3 tabs — Blocks Library, Settings, History.
- Blocks Library: all 21 prebuilt blocks grouped Headers / FAQ / Content blocks, each with an enable/disable toggle (single `tve_disabled_blocks` option). Disabled blocks are filtered out of the frontend block panel and rejected by the `section_add` AJAX op; blocks already placed on pages keep rendering.
- Settings (`tve_settings` option): frontend overlay on/off (gates edit mode, all launch entry points, and saved-edit rendering), default device preview (Desktop/Tablet/Phone — wired through `TVE.defaultDevice`, per-browser localStorage still wins), history snapshot limit 1–100 (default 15; existing stacks trimmed on save).
- History: aggregates per-page snapshots across the site — page title (resolved from recorded URL labels), last snapshot date, snapshot count — with per-row Restore (latest snapshot, current state kept undoable) and Delete (clears snapshots). Nonce + `manage_options` on every action.
- Storage: `tve_page_paths` option records human-readable URL per page key so History can show real page titles.

## Shipped — v1.6 "Blocks+" (2026-09-28)
- 6 new blocks (27 total): Pricing Table (3 tiers, middle highlighted), Gallery Grid (4 images + captions), Video (YouTube/Vimeo/MP4, responsive 16:9; URL sanitized to allow only those hosts), Team (3 members), Social Icons (5 links), Contact Info (address/phone/email/hours). All respect `tve_disabled_blocks` and appear in the Blocks Library admin tab automatically.
- Per-block Design panel (◆ button on each placed block row): font-size select, text-align, background color picker, padding S/M/L — applied as inline style on the block wrapper, saved in the section's `style` array, live preview while editing, survives undo/redo via the `section_style` AJAX op (in the mutating/revision list). Video URL field appears for Video blocks only; embed updates in place.
- `tve_section_html()` now takes the section array so wrapper style + stored video URL render on every page load; duplicate copies carry style/video over.

## Phase 1 — v1.7 "Trust" (reliability before clients)
Goal: safe to run on paying client sites.
- Stable selector fingerprints (tag + classes + id + text hash) with mismatch detection and "re-link by clicking" repair.
- Cache purge on save: LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache.
- CSS background-image swap via media library.
- Compatibility test matrix: classic themes, block themes (FSE), Elementor/Divi output, top cache plugins.

## Phase 2 — v1.7 "Portable" (workflows)
Goal: professional workflow for staging → live and repeat clients.
- Export/import per-page designs (JSON file).
- Global scope: header/footer edits applied site-wide, not per URL.
- Save any block as a reusable preset; preset library per site.
- Named revisions (labels instead of timestamps only).

## Phase 3 — v1.8 "Pro polish"
Goal: agency-grade.
- Responsive controls: per-device styles (e.g. smaller heading on phone), hide-on-device toggle.
- Role permissions: who gets the Visual Edit button (Editor role, etc.).
- Undo for block add/remove/reorder.
- Performance: editor assets load only for users who can edit; frontend overlay stays light.
- Multisite sanity + PHP 8.x / WP 6.x matrix pass.

## Phase 4 — Market launch
- **WordPress.org submission (free core).** Review-safe: no tracking without consent, prefix everything, uninstall cleanup. This is the distribution engine.
- **Freemium Pro:** free = everything local, unlimited. Pro = cloud block-template library, site design system (global fonts/colors), white-label for agencies, priority support. Suggested: $29/yr single site, $79/yr agency.
- **Proof assets:** 60-second demo video (theme before → visual edit → after), TikTok + YouTube Shorts, tabaix.com landing page with live demo GIFs.
- **Upwork flywheel:** use it on client jobs as the secret weapon (faster delivery than Elementor rebuilds) → reviews → case studies feed the plugin page.
- **Bundle option:** ship as the editing layer for WP Theme Studio–generated themes ("every Studio theme is visually editable out of the box").

## Honest risks / non-goals
- Requires PHP DOMDocument; some cheap hosts lack it — detect and show a clear admin notice.
- Cannot restructure theme-native header/footer markup or drag arbitrary theme sections (overlay edits, not a builder).
- Newsletter/social blocks are visual only until wired to providers — label honestly on the .org page.
- Never promise "works with every theme" on the listing; say "works with most conventional PHP themes, deactivates cleanly."
