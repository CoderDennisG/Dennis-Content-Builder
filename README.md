# Dennis Content Builder

Build and edit WordPress content by chatting with AI — designed so non-coders can create real pages without touching code, while still being able to fine-tune everything in the page builder they already know.

> **Status: Planning** — no feature code yet. See [docs/ROADMAP.md](docs/ROADMAP.md) for the build plan.

## What it does

- **Build content via chat** — describe the page you want ("a landing page for my workshop with a hero, three benefits, and a signup button") and the AI creates it as a draft.
- **Edit content via the same chat** — open any page and say "make the headline shorter" or "add a testimonials section after the pricing".
- **Page-builder aware** — content is created in the site's native builder format. Gutenberg (block editor) first; the architecture supports adding adapters for other builders (Elementor, Bricks, …) later.
- **Safe for non-coders** — the AI only creates drafts and revisions. A human always reviews and publishes. Every AI edit is undoable through WordPress revisions.

## Who it's for

Site owners, editors, and clients who know what they want to say but don't want to wrestle with blocks and layouts. Power users get the same chat plus full builder access.

## How it works (high level)

1. A chat panel lives inside the Gutenberg editor (for editing the page you're on) and on a standalone **Content Builder** admin page (for creating new content).
2. The chat talks to a REST API inside this plugin — never directly to the AI from the browser.
3. The plugin calls the **Claude API** (Anthropic) server-side using an API key stored in plugin settings. Claude is given a small set of tools (read page, create draft, update blocks) and works in a loop until the request is done.
4. A **builder adapter** translates between Claude's neutral content model and the builder's storage format (Gutenberg block markup in v1).

Full detail: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

## Requirements (planned)

| Requirement | Minimum |
|---|---|
| WordPress | 6.6+ |
| PHP | 8.1+ |
| Anthropic API key | Required (entered in plugin settings) |

## Project documents

| Document | Purpose |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Layers, data flow, builder-adapter design, AI tool definitions |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Phased build plan with scope per phase |
| [docs/RULES.md](docs/RULES.md) | Coding standards, security rules, AI safety rules |
| [docs/VERSIONING.md](docs/VERSIONING.md) | SemVer policy, changelog, release process |
| [CHANGELOG.md](CHANGELOG.md) | Human-readable history of changes |

## Distribution & license

Built for personal and client sites (not WordPress.org). GPL-2.0+ — see [LICENSE.txt](LICENSE.txt).

## Author

Dennis Gutierrez — https://myfreelance101.com
