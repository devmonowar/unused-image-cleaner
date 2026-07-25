# Unused Image Cleaner

> A WordPress plugin that finds unused images and removes them **safely** — by proving they are unused first.
>
> **Status:** Planning · pre-development
> **Target:** v1.0.0

| | |
|---|---|
| **Plugin name** | Unused Image Cleaner |
| **WordPress.org slug** | `unused-image-cleaner` *(verified available)* |
| **Namespace** | `UnusedImageCleaner\` |
| **Prefix** | `UIC_` |
| **License** | [GPL-2.0-or-later](LICENSE) — required by WordPress.org, and not optional: WordPress itself is GPL |

Suggested WordPress.org listing title:

```
Unused Image Cleaner – Find & Delete Unused Images Safely
```

---

## What this repository is

Specification and architecture documents. No code yet — **but nothing is blocking the first line of it.**

The architecture is settled: the engines, the scanner contract, the shared data model, the five screens, and the [build order](docs/01-overview/roadmap.md#build-order) are all decided. Every remaining spec is pinned to the milestone that needs it and gets written immediately before that milestone, not sooner.

An earlier plan called for 25–30 specs before the first line of PHP. That plan is withdrawn — it produced 6,580 lines of documentation and zero lines of code, while the plugin's most important numbers (the scanner weights) can only be validated by running real code against real sites. **Seven planned documents were deleted or dropped.** See [BACKLOG → The Rule That Changed](docs/BACKLOG.md#the-rule-that-changed).

---

## Start here

| If you want to… | Read |
|-----------------|------|
| **Start writing code** | [Roadmap → Build Order](docs/01-overview/roadmap.md#build-order) — **what to build first, what depends on what, and how that is decided for anything new** |
| Understand what we're building and why | [Vision](docs/01-overview/vision.md) |
| Understand how it's structured | [Architecture](docs/01-overview/architecture.md) — where any doc disagrees, this one wins |
| Know what ships when | [Roadmap](docs/01-overview/roadmap.md) |
| Know what's left to write, and what deliberately isn't | [Backlog](docs/BACKLOG.md) |

---

## The idea in one screen

Other cleanup plugins say `Unused. Delete?`

This one says:

```
Unused.

Confidence   97%
Risk         Very Low

Why?
  ✓ Not used in Posts          (2,431 checked)
  ✓ Not used in Pages          (184 checked)
  ✓ Not used in Elementor      (56 documents parsed)
  ✓ Not used in ACF            (312 fields resolved)
  ✓ Not used in Theme Options
  ✓ No broken references
  ✓ 100% scan coverage · 42 checks

Recommendation:  Move to Trash
```

Every score has a reason. Every recommendation has evidence. Nothing is deleted in one step.

---

## Documentation

```
docs/
├── BACKLOG.md                    ← 3 specs block coding · 7 dropped, and why
│
├── 01-overview/
│   ├── vision.md                 ← what and why
│   ├── architecture.md           ← single source of truth for layering
│   └── roadmap.md                ← single source of truth for versions
│
├── 02-engines/
│   ├── core-engine.md            ← shared data model · scanner result contract
│   ├── confidence-engine.md      ⭐ "how sure are we?"        — the USP
│   ├── risk-engine.md            ⭐ "what if we're wrong?"    — the other half
│   ├── recommendation-engine.md  ← where the two meet
│   └── evidence-engine.md        ← how a conclusion is explained
│
├── 03-scanners/
│   ├── README.md                 ← the contract every scanner obeys
│   ├── content-scanner.md        Posts, Pages, CPTs
│   ├── elementor-scanner.md
│   └── acf-scanner.md
│
├── 04-ui/
│   └── ui-principles.md          ← all five screens, one document
│
└── 05-developer/
    └── plugin-structure.md       ← directories · coding standards
```

**Where any document disagrees with [Architecture](docs/01-overview/architecture.md), Architecture wins.** Thresholds, level tables, and scanner weights live in exactly one engine document each and are never restated elsewhere — a duplicated number is a number that will drift.

---

## Core principles

**Engine first, features second.** Build the architecture, then the UI, then the features. A feature-first plugin needs its core rewritten every time something is added.

**Scanners find. Engines decide.** A scanner's only job is to answer *"which images does this content reference?"* and report evidence. It never calculates confidence, never assesses risk, never deletes anything. That single rule is what lets a Bricks or Divi scanner be added years from now without touching the Core Engine.

Note which way round the question is asked. *"Where is this image used?"* — once per attachment — is O(images × content) and dies on a real site. See [The Question Is Backwards](docs/03-scanners/README.md#the-question-is-backwards).

**Trash first, delete later.** The strongest recommendation the plugin will ever make for an unused image is *Move to Trash*. Permanent deletion is only offered afterwards, from the Trash. This bounds the damage if the Confidence Engine is ever wrong — and occasionally it will be.

**Never show a score without a reason.** Users are never asked to trust a number they cannot inspect.

---

## Next step

**[M0 — the Walking Skeleton](docs/01-overview/roadmap.md#m0--walking-skeleton).**

Plugin bootstrap, the shared data model, the scanner contract, two scanners (Content and Media Relationship), Confidence Formula B, and a WP-CLI command that prints a list. No UI, no database, **nothing deleted.**

The scanner weights in the Confidence Engine are educated guesses, and no further document can validate them — only code run against real sites can. Deletion is built **last**, at M6, and only after [calibration](docs/01-overview/roadmap.md#m5--calibration--the-gate) proves zero false positives across five real sites.

> A delete button before that point is not a feature. It is an untested destructive operation pointed at somebody's homepage.

---

> **"Scan with evidence, analyze with confidence, clean with safety."**
