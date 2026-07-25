# FAQ

For "can it delete something I'm using," "does it support my page builder," and a few other common
questions, see the **Frequently Asked Questions** section of the plugin's `readme.txt` — the same
text also appears on the plugin's listing page. This page covers the ones that don't fit there.

## What counts as "evidence" that an image is used?

Anything the scan can point to directly: an attachment ID inside a Gutenberg block, an `<img>` tag
in post content, a featured image, a widget, a menu item image, a WooCommerce product gallery entry,
an ACF field genuinely configured to hold an image, and more. A weaker signal — like a bare number in
a place the plugin can't fully verify — still counts, but only enough to hold the image back from
deletion, never enough to mark it confidently Used.

## Will scanning a large media library slow down my site?

A scan runs in the background across cron ticks rather than one long request, and results are
cached until something on your site actually changes. Day to day, visiting the plugin's screens
reads the last cached scan rather than rescanning live.

## Does it work with WooCommerce?

Yes. Product gallery images, category and brand images, and your store's own placeholder image are
all recognised directly, at the correct risk level for what they are — a broken product image is
priced as lost revenue, not treated like an ordinary inline photo.

## Does it work with ACF?

Yes. Image, Gallery, and File fields are trusted directly. A Relationship field is trusted too, but
only when it's configured to browse nothing but the Media Library — a Relationship field that can
also pick an ordinary post isn't treated as image evidence, since the same stored number could just
as easily be a post ID.

## What does "Storage Saved" mean in Scan History?

It's the space you'd actually recover if every image that scan recommended for Trash were trashed —
not the total size of everything merely unused. An unused image the plugin is holding back for
Review isn't space you can have yet, so it isn't counted.

## Can I select several images and act on all of them at once?

You can select several and move them to Trash together, or apply a decision (Ignore, Mark Safe,
Exclude Forever) to all of them together. Each image in the selection is still checked individually
— a protected image inside a bulk selection is held back and reported, not swept along with the
rest.

## Why can't I select a particular image for bulk Trash?

If a row shows **Protected** instead of a checkbox, see [Staying Safe](staying-safe.md) — there's
always a specific, statable reason, and opening the image tells you which one.
