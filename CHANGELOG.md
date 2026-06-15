# Changelog

All notable changes to Dennis Content Builder are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) · Versioning: [SemVer](https://semver.org/) (see [docs/VERSIONING.md](docs/VERSIONING.md)).

## [Unreleased]

## [0.6.0] - 2026-06-13

### Added
- Custom fields (ACF + native registered meta). The assistant can read and fill a post type's custom fields, scoped to an admin allowlist in the post-type popup.
  - **Discovery** is recursive: ACF field groups (incl. nested repeater/group/flexible-content sub-fields and choices) plus `register_post_meta` keys, surfaced as a checklist with type badges.
  - **Reading** covers the full field tree, including complex types, today.
  - **Writing** is enabled for the reliable types this release — text, textarea, wysiwyg, number, email, url, select/radio/checkbox/button-group, true/false, date/time, color — validated per type (choices checked, values sanitized). Complex fields (repeater, group, flexible content, image, file, relationship, post object) are read-only for now and reported as such to the model.
  - Two new AI tools: `read_fields` and `update_fields`. ACF values are written via `update_field`; native meta via `update_post_meta`.
  - **Undo for meta:** prior field values are snapshotted to `_dcb_field_backup` before any write, since WordPress revisions don't cover meta.
- New `dcb/v1/fields` endpoint backs the settings checklist.

### Notes
- Writing complex/nested field types (repeaters, flexible content) and setting media/relationship fields by searching existing items is the next phase — the schema engine already handles the full tree, so it's enablement, not a rebuild.

### Added
- Scheduled auto-creation, per post type: pick specific weekdays and a time (e.g. Mon/Wed/Fri at 6:00 PM) in the type's popup, give it a standing brief, and the assistant creates a new item on that schedule. Uses precise self-rearming timed events in the site timezone (exact under a real server cron such as WP Engine).
- "Run now" button in the popup to fire a scheduled type immediately (testing, and for environments where cron doesn't fire locally).
- Scheduled runs execute headless as a dedicated, plugin-provisioned "Content Builder" system user, attributing automated drafts to it; every run is audit-logged.
- Per-type **auto-publish** option: a scheduled run may publish its result automatically. Off by default.

### Security
- Documented the auto-publish exception in docs/RULES.md: the `create_draft`/`update_content` tools still only ever draft and the interactive chat can never publish; publishing happens only in the scheduled path, only for types an admin explicitly opted in, as a step after the model produces the draft.

### Notes
- Custom-field (ACF/meta) editing moves to v0.6.0.

## [0.4.3] - 2026-06-13

### Changed
- Post Types tab is now a compact list — one row per type with its eligibility checkbox and a pencil icon. Writing guidance opens in a modal on demand instead of an always-visible textarea, so the tab stays readable with many post types. The pencil highlights when guidance is set.

## [0.4.2] - 2026-06-13

### Changed
- Spacing on the Allowed Blocks checklist (row gap + breathing room) so the options aren't cramped.

## [0.4.1] - 2026-06-13

### Changed
- Allowed blocks are now a **single global setting** on their own "Allowed Blocks" tab, applied to every post type — instead of being chosen per post type. The "Post Types" tab now carries just eligibility and per-type writing guidance. Settings has three tabs: General, Allowed Blocks, Post Types.
- Block restriction is still enforced structurally at save and surfaced to the model once, globally, in the system prompt.

## [0.4.0] - 2026-06-13

### Added
- Post type profiles: each post type gets its own behavior from one shared model — there is no separate "AI" per type, just a per-type configuration.
  - **Eligibility allowlist** — choose which post types the assistant manages; the editor sidebar is hidden and creation refused on the rest (defaults: pages and posts).
  - **Writing guidance** — per-type instructions/persona injected into the system prompt (a Product writes differently from a Blog post).
  - **Allowed blocks** — restrict each type to a chosen subset of blocks; enforced structurally at save (`Model::sanitize_elements`), not just suggested.
- New "Post Types" tab on the settings page (wp-components TabPanel) to edit all of the above.
- Profile rules are enforced across every entry point: `list_content`/`read_content`/`create_draft`/`update_content` all respect eligibility and per-type block limits, using each post type's own capability.

### Notes
- Custom-field (ACF/meta) editing is intentionally deferred to v0.5.0; field-based types show a note and still get block + guidance support.

### Changed
- Settings page rebuilt on WordPress's bundled `@wordpress/components` (Card, TextControl, SelectControl, CheckboxControl, Notice) for a native, modern look — no build step and no new dependency. It now reads/writes through a new `dcb/v1/settings` REST endpoint (manage_options) instead of an `options.php` form. Scope is the settings page only; the chat and editor sidebar are unchanged.

## [0.3.0] - 2026-06-13

### Added
- Gutenberg editor sidebar: the chat opens next to the post/page you're editing (pin icon in the editor toolbar), scoped to that post — "make the intro shorter" needs no explanation of which page.
- Live canvas refresh: when the AI updates the open post, the editor content reloads in place with a snackbar notice — review and save.
- Conversations are scoped per post in the sidebar (each page has its own history); the admin chat page still shows everything.
- Unsaved-changes warning before sending from the sidebar (the AI reads the last saved version).

### Changed
- Chat UI refactored into a reusable factory (`dcbCreateChat`) shared by the admin page and the sidebar; styles are class-based with a compact sidebar variant.

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
