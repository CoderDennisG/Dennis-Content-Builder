# Changelog

All notable changes to Dennis Content Builder are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) · Versioning: [SemVer](https://semver.org/) (see [docs/VERSIONING.md](docs/VERSIONING.md)).

## [Unreleased]

## [0.2.0] - 2026-06-13

### Added
- Live progress streaming: `/chat` now answers as a Server-Sent Events stream — assistant text arrives token by token and tool steps show as status updates ("Writing your draft… 2,400 characters").
- Conversation persistence: conversations and messages are stored in custom tables; previous conversations can be resumed from a picker on the chat page.
- Audit log: every AI tool invocation is recorded (user, conversation, tool, post, detail).
- `dcb_use_chat` capability gates the chat UI and REST routes; role checkboxes in Settings control who gets it (administrators always do).
- PHPCS with the WordPress Coding Standards ruleset (`phpcs.xml.dist`); codebase passes clean.

### Changed
- The browser no longer round-trips Claude message history; the client sends only `{conversation_id, message}` and the server owns state.

### Added
- Project planning documents: README, architecture, roadmap, rules, versioning policy (2026-06-13).
- Working prototype (vertical slice, 2026-06-13):
  - Settings page with write-only Anthropic API key, model picker, and connection test.
  - Neutral content model with strict server-side sanitization of AI output.
  - `GutenbergAdapter` — neutral model ⇄ core block markup, round-trip verified against the WP block parser; unknown blocks preserved as opaque raw elements.
  - AI orchestrator running the Claude tool-use loop server-side (official `anthropic-ai/sdk` ^0.7) with a code-defined tool allowlist: `list_content`, `read_content`, `create_draft`, `update_content`, `search_media`.
  - REST API `dcb/v1` (`/chat`, `/test`) with capability-checked permission callbacks.
  - "Content Builder" admin chat page (drafts only — the AI cannot publish or delete).

### Changed
- Replaced the WPPB boilerplate with a PSR-4 `DCB\` structure; plugin version reset to 0.1.0 per docs/VERSIONING.md.
