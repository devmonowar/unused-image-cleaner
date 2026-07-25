# Understanding your results

Every image gets three separate readings, and they're kept separate on purpose — blending them into
one score would hide exactly the information you need to make a safe decision.

## Status: Used, Possibly Used, or Unused

This is the simple question: did the scan find any evidence this image is referenced anywhere?

- **Used** — at least one strong reference was found (inside post content, a Gutenberg block, a
  widget, and so on)
- **Possibly Used** — a weak reference was found. Not proof, but enough to hold the image back from
  ever being recommended for deletion
- **Unused** — no reference was found anywhere the scan looked

## Confidence: how sure the plugin is

Confidence answers a different question depending on the status:

- For a **Used** image, confidence is how strong the single best piece of evidence was. An
  attachment ID found directly in a block is stronger evidence than a filename that merely matches.
- For an **Unused** image, confidence is about the *search itself* — what fraction of the applicable
  scanners actually ran and completed, weighted by how reliable each one is. A perfect scan reports
  **97%, never 100%** — proving an image is unused means proving the absence of evidence everywhere,
  and "everywhere" always has an edge the plugin is honest about not having reached.

If a scanner failed partway through your scan, or your site uses something the plugin can't fully
read (a page builder it doesn't recognise by name, for instance), confidence drops and the Dashboard
names the reason. It's never lowered silently.

## Risk: what it would cost to be wrong

Risk is a completely separate question from confidence: **if this image really is safe to delete,
and we're somehow wrong about that — how much does the mistake cost?**

An image that's genuinely just an old, unreferenced upload is low risk even at moderate confidence.
A site logo is high risk even if the scan is fairly confident nothing points at it, because if the
scan is wrong, you lose your logo. The plugin prices that difference before it ever offers Trash.

## Recommendation: what the plugin suggests

The recommendation is the combination of everything above, gated in a fixed order that no single
high score can override:

1. **Coverage below 70%?** → always **Rescan**, regardless of what any individual image shows
2. **Risk at Medium or above?** → always **Review**, no matter how confident the scan is
3. **Confidence below 80%?** → **Review**

Only when coverage, risk, *and* confidence all clear their bar does an image get **Move to Trash**.
A **Keep** recommendation means the image is genuinely in use — trashing it isn't offered at all.

## Why a 97%-confidence image can still say "Review"

This is the case people ask about most. A high confidence score means the plugin is fairly sure
nothing currently references the image — but confidence never overrides risk. If that image is your
site's header or background, the plugin holds it at Review regardless of how confident the scan is,
because the cost of being wrong there is too high to accept at any confidence level.

Next: [Staying Safe](staying-safe.md).
