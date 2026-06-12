# Roadmap

Each phase ends with something usable and a tagged release. Scope creep goes to the backlog, not into the current phase.

## Phase 0 — Project setup *(target: v0.1.0)*

- [ ] `git init` in the plugin folder; first commit of boilerplate + docs
- [ ] Reset plugin version to `0.1.0` (header + constant) — we are pre-release
- [ ] Composer setup (`DCB\` PSR-4 autoload, `anthropic-ai/sdk`)
- [ ] `@wordpress/scripts` build tooling (`npm run build` / `start`)
- [ ] Reshape WPPB boilerplate into the target layout (see ARCHITECTURE.md)
- [ ] PHPCS with WordPress Coding Standards ruleset

## Phase 1 — Foundation *(v0.2.0)*

- [ ] Settings page: API key (write-only field), model picker, default post type
- [ ] `dcb_use_chat` capability + role assignment on activation
- [ ] Custom tables for conversations/messages + audit log (dbDelta, schema version option)
- [ ] REST scaffold `dcb/v1` with auth/permission callbacks and a `/ping` route
- [ ] Smoke-test Claude connection from settings page ("Test connection" button)

## Phase 2 — Content engine + Gutenberg adapter *(v0.3.0)*

- [ ] Neutral content model classes + JSON schema + validation
- [ ] `Builder_Adapter` interface + adapter registry (filter-based)
- [ ] `GutenbergAdapter`: serialize (model → block markup) for all v1 element types
- [ ] `GutenbergAdapter`: parse (block markup → model) with `raw` passthrough for unknown blocks
- [ ] Block vocabulary registry: capability tiers, registry/content discovery, enabled flags (see ARCHITECTURE.md → Block vocabulary)
- [ ] Styling presets: read theme design system (`wp_get_global_settings()`), `style` object in the neutral model, attribute+class serialization (see ARCHITECTURE.md → Styling)
- [ ] Per-page custom CSS: `_dcb_custom_css` meta, editor panel, front-end enqueue, CSS sanitizer (see ARCHITECTURE.md → Styling level 3)
- [ ] "AI Blocks" settings screen: toggle blocks, third-party badge, tier display
- [ ] Round-trip tests: parse → serialize is non-destructive for supported blocks

## Phase 3 — AI orchestrator *(v0.4.0)*

- [ ] Tool registry: `list_content`, `read_content`, `create_draft`, `update_content`, `search_media`, `update_page_css`
- [ ] Per-tool capability enforcement + audit logging
- [ ] System prompt (versioned in repo) + tool-use loop against Claude Messages API
- [ ] `/chat` REST endpoint wired end-to-end (text in → draft created/updated)
- [ ] Error handling: rate limits, overload, refusal stop reason, invalid model output

## Phase 4 — Chat UI: admin page *(v0.5.0)*

- [ ] `<ChatPanel>` React component (history, streaming-friendly message list)
- [ ] "Content Builder" admin page: create content from scratch via chat
- [ ] Result cards: link to draft, "Open in editor", "Restore previous version"
- [ ] Conversation persistence + resume

## Phase 5 — Chat UI: editor sidebar *(v0.6.0)*

- [ ] Gutenberg sidebar plugin scoped to the open post
- [ ] Live refresh: editor canvas updates after an AI edit without reload
- [ ] Pre-save preview: show a diff/summary of what the AI will change, Apply/Discard

## Phase 6 — Non-coder polish *(v0.7.0 → v1.0.0)*

- [ ] Plain-language onboarding (empty-state prompts, example requests)
- [ ] Guardrail review: confirm AI cannot publish/delete under any prompt
- [ ] Per-role settings: which roles see the chat, which post types are allowed
- [ ] i18n pass; docs for site owners (non-technical)
- [ ] **v1.0.0** — first version installed on a real client site

## Phase 7 — Style reference learning *(v1.1.0)*

- [ ] Training tab: upload reference page images, tag by page type
- [ ] One-time vision analysis → editable text style profiles (see ARCHITECTURE.md → Style reference learning)
- [ ] Profile selection per conversation; active profile injected into system prompt
- [ ] Per-request image attachment in chat ("build it like this")

## Later (backlog — not scheduled)

- Second builder adapter (Elementor or Bricks) to prove the abstraction
- Block patterns / synced patterns support
- Image generation or AI-assisted media selection
- SSE streaming to the browser
- MCP endpoint so power users can drive the same tools from Claude Code
- Full-site-editing (templates, template parts)
