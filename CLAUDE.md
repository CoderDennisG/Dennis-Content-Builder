# Denise — AI session guide

WordPress plugin: build/edit content via AI chat, builder-agnostic core, Gutenberg adapter first. Currently in **planning/early build** — check [docs/ROADMAP.md](docs/ROADMAP.md) for the current phase before writing code.

## Renamed to Denise (2026-11-14, uncommitted)

This plugin was "Dennis/Denise Content Builder" (prefix `dcb`). It is now **Denise**: folder `denise/`, bootstrap `denise.php`, namespace `Denise\`, constants `DENISE_*`, prefix `denise_`, text domain `denise`, REST namespace `denise/v1`, capability `denise_use_chat`, tables `{prefix}denise_conversations|messages|audit_log`, meta `_denise_field_backup`, options `denise_settings|version|db_version|profiles|allowed_blocks|schedule_state|system_user_id|post_type_builder`, cron hooks `denise_run_schedule` / `denise_schedule_resync`, debug constant `DENISE_DEBUG`, log `uploads/denise-debug.log`, JS globals `deniseChat` / `deniseBubble` / `deniseCreateChat`, CSS prefix `denise-`.

`Denise\Support\Migration::maybe_migrate()` (called first in `Plugin::boot()` and `Plugin::activate()`) carries the old `dcb` tables, options, capability, meta key and cron over once; flag `denise_migrated_from_dcb`. `composer dump-autoload -o` must be re-run after the namespace change (vendor/ is gitignored). The GitHub remote is still `CoderDennisG/Dennis-Content-Builder`.

**Direction (2026-11-14):** Denise becomes the site-wide admin assistant with pluggable skills (admin-bar trigger, settings, Claude or ChatGPT by setting). The content builder tools are the first skill family; a "mea-workshops" skill (answer questions from the plugin's docs plus lookup tools) is next. The MEA Companion plugin (visitor bots) stays separate.

## Skills

Ask Denise answers from **skills**: one per plugin or area. Contract: `includes/Skills/Skill.php` — `id()`, `name()`, `description()` (one line for the prompt's skill index), `triggers()` (lowercase keywords; a question matching any loads the skill, no match loads all), `knowledge()` (Markdown notes injected into the system prompt, cached with `cacheControl`), `tools()` (Anthropic tool definitions, **read-only only**), `label()`, `execute()` (must re-check `Capabilities::USE_CHAT`). Register built-ins in `SkillRegistry::all()`; other plugins append an instance via the `denise_skills` filter. Tool names must be unique across skills.

First skill: `includes/Skills/MeaWorkshops/` — reads the plugin's own `docs/card.md` and `docs/shortcodes.md` live from `WP_PLUGIN_DIR` (no bundled copy; the card is the source of truth for the knowledge base too), and three lookups (`find_faculty`, `find_workshop`, `workshops_for_faculty`; faculty live in ACF relationship fields `faculty` / `featured_faculty` / `host` as serialized ID arrays). There is one loop, `Denise\Ai\Orchestrator` (route `POST denise/v1/chat`, SSE), which runs the content tools and every skill's lookup tools; `Denise\Ai\Streamer` does the streamed turn. The `kind` column on conversations is unused since the merge.

Speed facts (measured 2026-11-14): the notes (~33k tokens with the faculty/workshop indexes) are sent as a cached system block, so after the first turn they cost cache reads only; wall time is model generation. One model setting for everything (default Claude Sonnet 5.5); no effort override. Streaming only works through `Denise\Ai\ClientFactory` (Guzzle `stream => true` + `StreamingTransport` 256-byte reads); a bare `new Anthropic\Client` buffers whole turns. The MEA Workshops skill prepends faculty/workshop indexes (transient, 10 min) so name questions answer in one turn.

Where knowledge sits: in the plugin each skill describes (`docs/card.md` overview + `docs/shortcodes.md` reference, read live) + indexes the skill generates from the database. Nothing is bundled inside Denise. Loaded by `Orchestrator::with_skills()`: trigger match loads the matching skills, no match loads all (so plain questions still get answers).

Elementor: Denise talks to Elementor 4.3+'s own abilities (`Adapters\ElementorAbilities`, registered in-process on `wp_abilities_api_init` when Elementor's MCP switch is off). EMCP Tools (elementor-mcp) is no longer used. New pages are built with `build-composition` (XML + plain settings + CSS strings); existing pages are edited by element id with `manage-elements`. All settings are plain maps validated by Elementor; never hand-write `$$type` props. Edits to a published page land in the user's autosave revision (pending draft, published from the editor); Denise reads from that autosave when it is newer than the page. EMCP Tools (`elementor-mcp-main`) was deactivated on 2026-11-14 and can be deleted.

Front end: no floating bubble. `Frontend\Launcher` adds a "Denise" admin-bar item for users with the chat capability; on a singular view of a post type activated in Settings -> Post Types that the user can edit, it toggles the chat panel scoped to that post (assets/bubble.js, panel drops under the toolbar); elsewhere it links to the wp-admin chat page. The gate is `Profiles::is_eligible()`, so activating a post type in Settings is what switches Denise on for that content, plus the builder gate `Profiles::is_supported_build()` (`Profiles::editor_of()` = elementor | blocks | thrive | classic; empty posts on block-editor types count as blocks). The same gate is enforced in `Ai\Tools`: `read_content` / `update_content` refuse Thrive and classic posts, `list_content` reports `editor` + `editable`, and the system prompt tells Denise when the open page is not editable. Thrive Architect and classic-editor pages get the toolbar link instead of the toggle. `update_fields` is deliberately not gated (RULES.md rule 9).

Elementor editor mode (third surface, `Admin\ElementorEditor`): inside the Elementor editor the panel mounts in the editor window (corner button; `assets/elementor-editor.js` is the bridge, loaded before bubble.js as `window.DeniseEditorBridge`). The bridge sends the live document with every message (`editor` request field → `Ai\EditorContext`, validated/capped, never stored; appended as the LAST system block so it never breaks the prompt cache) and applies Denise's edits with `$e.run('document/elements/settings|create|move|duplicate|delete')`, i.e. like manual edits: instant, undoable, saved on Update only. `Tools::set_editor()` switches the Elementor tools: reads answer from the snapshot, writes record `{action:'editor', op, …}` actions relayed by the panel; no database write in editor mode. Plain settings → typed props via Elementor's own `Plain_Values_Resolver` (`Adapters\ElementorProps`; V3 widgets stay plain, e-heading/e-paragraph text is `escaped-html`). Saved templates: `find_elementor_template` (name lookup, `Adapters\ElementorTemplates`, local source only) and `insert_elementor_template` (PARKED behind `Tools::TEMPLATE_INSERT_ENABLED = false`, see docs/ROADMAP.md backlog; the prompt tells Denise to send people to Add Template instead; editor mode only; relayed as an `import` action; the bridge drives Elementor's library: `library/open` + `library/insert-template`, outcome observed on `$e.commands` `run:after`; Elementor fetches the content and may show its styles dialog). Bridge `apply( action, done )` is callback-based; the panel logs `[Denise] editor edit` lines to the console. Not relayed yet: styles, classes, `add_elementor_elements` (refused with a hint). Elementor's in-editor MCP (`window.elementorV2.editorMcp`, WebMCP/Angie) was evaluated and not used: its `configure-element` wants typed values too and its `get-page-structure` proxies to the saved copy.

Workshop intake (2026-11-14): skill `includes/Skills/WorkshopIntake/` (`knowledge.md` = the agreed procedure: ACF mapping, template-section mapping for "WS Guest Faculty Template", photos are the person's, non-conforming content is reported never built, two-step summary→build; `AgendaConverter` + tool `convert_agenda`). Content tools `create_elementor_draft_from_template` / `apply_elementor_template` copy a saved page template onto a DRAFT through `Adapters\ElementorTemplates::apply_to()` (Elementor `Document::save()`, RULES.md rule 6 exception). `Content\Fields` writes repeaters/groups/post references (RULES.md rule 11, `confirm_replace`). Legacy V3 widgets (text-editor, icon-list, shortcode…) are refused by Elementor's ability: `Adapters\ElementorLegacy` reads their text into `read_elementor_element` and writes text keys on DRAFTS via `Document::save()`. Elementor validates dynamic-tag props only where control labels survive (admin/REST, not front end or bare CLI: define `REST_REQUEST` in probes).

Not yet: enabling/disabling skills in Settings, an OpenAI provider, a `load_skill` tool for when the notes outgrow the prompt (today every selected skill's notes are injected whole).

## Read before coding

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — layers, neutral content model, adapter contract, AI tool list. Don't bypass these boundaries (e.g. never have the AI layer emit raw block markup; that's the adapter's job).
- [docs/RULES.md](docs/RULES.md) — coding standards + security rules. The security and AI-safety rules are non-negotiable.
- [docs/VERSIONING.md](docs/VERSIONING.md) — bump the version in all listed locations together; changelog entry for every user-visible change.

## Hard constraints (summary)

- API key is server-side only; never in REST responses, localized data, or HTML.
- Every REST route: real `permission_callback` + nonce. Capability re-checks inside each AI tool.
- AI may only create drafts/revisions — no publish/delete/users/options tools, ever, without an explicit decision recorded in RULES.md.
- All AI output validated against the neutral-model schema and sanitized before saving.
- PHP: WPCS, `Denise\` namespace, `denise_` prefix, strict types, PHP 8.1+, WP 6.6+.
- JS: `@wordpress/scripts` toolchain, WP packages before third-party deps.

## Claude API usage in this plugin

- Official `anthropic-ai/sdk` (Composer). Default model `claude-opus-4-8`; adaptive thinking; no sampling params.
- Manual tool-use loop in `Denise\Ai` (per-tool capability checks + audit logging are required, so don't switch to the SDK toolRunner without preserving those).

## Workflow

- Branch from `main`, one logical change per commit, CHANGELOG `[Unreleased]` entry with each feature/fix.
- Definition of done checklist is at the bottom of docs/RULES.md.
