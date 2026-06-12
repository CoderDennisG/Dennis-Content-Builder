# Dennis Content Builder — AI session guide

WordPress plugin: build/edit content via AI chat, builder-agnostic core, Gutenberg adapter first. Currently in **planning/early build** — check [docs/ROADMAP.md](docs/ROADMAP.md) for the current phase before writing code.

## Read before coding

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — layers, neutral content model, adapter contract, AI tool list. Don't bypass these boundaries (e.g. never have the AI layer emit raw block markup; that's the adapter's job).
- [docs/RULES.md](docs/RULES.md) — coding standards + security rules. The security and AI-safety rules are non-negotiable.
- [docs/VERSIONING.md](docs/VERSIONING.md) — bump the version in all listed locations together; changelog entry for every user-visible change.

## Hard constraints (summary)

- API key is server-side only; never in REST responses, localized data, or HTML.
- Every REST route: real `permission_callback` + nonce. Capability re-checks inside each AI tool.
- AI may only create drafts/revisions — no publish/delete/users/options tools, ever, without an explicit decision recorded in RULES.md.
- All AI output validated against the neutral-model schema and sanitized before saving.
- PHP: WPCS, `DCB\` namespace, `dcb_` prefix, strict types, PHP 8.1+, WP 6.6+.
- JS: `@wordpress/scripts` toolchain, WP packages before third-party deps.

## Claude API usage in this plugin

- Official `anthropic-ai/sdk` (Composer). Default model `claude-opus-4-8`; adaptive thinking; no sampling params.
- Manual tool-use loop in `DCB\Ai` (per-tool capability checks + audit logging are required, so don't switch to the SDK toolRunner without preserving those).

## Workflow

- Branch from `main`, one logical change per commit, CHANGELOG `[Unreleased]` entry with each feature/fix.
- Definition of done checklist is at the bottom of docs/RULES.md.
