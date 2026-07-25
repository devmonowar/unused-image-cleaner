# Getting started

## Install it

Upload the plugin like any other, then activate it. There's nothing to configure — the plugin is
designed to work correctly on its defaults, and the Settings screen exists mainly to explain what
it's doing, not to ask you for decisions up front.

## Run your first scan

Open **Media → Image Cleaner** in your WordPress admin. On a fresh install the Dashboard has one
thing on it: a **Start the first scan** button. Click it.

The scan reads through your posts, pages, Gutenberg blocks, templates, the Customizer, theme
options, widgets, menus, and — if they're active — Elementor, ACF, and WooCommerce. On a small site
this takes seconds; on a large media library it can take longer, and continues in the background
across a few cron ticks rather than tying up one long request.

## What you'll see

When it finishes, the Dashboard shows a **Media Health** score first, then the breakdown:

- **Images** — how many attachments were scanned
- **Unused** — how many the plugin found no evidence for
- **Need Review** — unused images the plugin isn't confident enough to recommend removing
- **Coverage** — how much of your site the scan actually reached

Below that, a **Recommendations** count (Move to Trash / Review / Keep) and a **Storage** figure —
what's recoverable if you accept every Trash recommendation, not what's merely unused.

From here:

- **Review Images** takes you to the filtered list, where you can inspect individual images or
  select several for a bulk action
- **View scan history** shows every past scan as an unchangeable record — useful for "what did the
  plugin tell me about this image last month"

## Rescan later

The Dashboard's **Rescan** button re-reads your site. A scan result is cached until something
changes (a post is edited, a plugin or theme is activated, a page is published) — the cache
invalidates itself, so you don't need to rescan manually just to keep results current. Rescan
manually when you want a fresh check right now, such as right after deleting a lot of content.

Next: [Understanding Your Results](understanding-your-results.md).
