# Staying safe

## Nothing is ever deleted in one step

The plugin only ever moves an image to **Trash** first. Permanent deletion is a separate, later
action you take from the Trash — never a single click from anywhere else. Every removal, including
a bulk selection, is checked one image at a time before it happens.

## Some images can never be offered for Trash

A few rules can't be turned off, because they protect the situations where a mistake is expensive:

- **Your site's logo, icon, header, or background image** — checked live against your current
  settings, not against what the last scan happened to find. These are refused outright.
- **An image uploaded in the last 24 hours** — it may not have been placed on a page yet, so it's
  held back regardless of how the scan reads it.
- **Anything at Medium risk or above** — the strongest thing ever offered for an image like this is
  Review, never Trash.

On the Images screen, a row you can't select for Trash is labelled **Protected** rather than left
blank — that's this behaviour working as intended, not an error.

## Trash is reversible

An image in the Trash can be restored with one click, and its restore record is written *before*
anything is touched — recovery doesn't depend on WordPress's own Trash mechanics working perfectly.

## Deleting permanently

Permanent deletion is only ever offered from the Trash, after the image has already survived being
recommended for it. The confirmation dialog is enforced on the server, not only in your browser — if
the confirmation doesn't genuinely happen, the deletion is refused rather than silently allowed.

**Back up your site before deleting anything permanently.** Trash is reversible; a permanent delete
is not, and no plugin's safety model is a substitute for your own backup.

## If you disagree with a recommendation

Every image has three decisions that outrank whatever the scan concludes:

- **Ignore** — leave it out of future reviews
- **Mark Safe** — never treat it as unused, no matter what a later scan finds
- **Exclude Forever** — never recommend anything for it again

Any of these can be cleared later from the same screen if you change your mind.

## Uninstalling

Every table and option the plugin created is removed on uninstall. Your media is never touched.

Next: [FAQ](faq.md).
