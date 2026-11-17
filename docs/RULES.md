# Project Rules

Rules for all code in this plugin — written by humans or AI. If a rule blocks something genuinely needed, change the rule in a PR/commit of its own, don't silently break it.

## Coding standards

### PHP

- **WordPress Coding Standards** (PHPCS `WordPress` ruleset). Tabs, Yoda conditions, escaping rules — all of it.
- PHP **8.1+** features allowed (enums, readonly, first-class callables). No polyfills for older PHP.
- Namespace `Denise\`, PSR-4 autoloaded via Composer. No new `class-*.php` WPPB-style files.
- Prefix everything global-facing with `denise_`: hooks, options, transients, capabilities, DB tables, CLI commands.
- Every user-facing string is translatable, text domain `denise`.
- Type-hint everything (params, returns, properties). `declare(strict_types=1)` in new files.

### JavaScript / React

- Built with `@wordpress/scripts`; use `@wordpress/components`, `@wordpress/data`, `@wordpress/i18n` before reaching for outside libraries.
- No new runtime npm dependencies without a written reason in the PR/commit message.
- Function components + hooks only.

### General

- Small modules with one job. If a file needs a table of contents, split it.
- Comments explain *constraints and whys*, not what the next line does.
- No dead code, no commented-out blocks in commits.

## Security rules (non-negotiable)

1. **The API key never reaches the browser.** Not in localized script data, not in REST responses, not in HTML. Settings UI shows last 4 characters only.
2. **Every REST route has a real `permission_callback`.** Never `__return_true`.
3. **Capability checks at the tool layer too** — not just at the REST edge. Each AI tool re-checks `current_user_can()` for the specific post it touches.
4. **All output escaped** (`esc_html`, `esc_attr`, `esc_url`), **all input sanitized**, all DB access through `$wpdb->prepare()` or core APIs.
5. **AI output is untrusted input.** Everything Claude produces is validated against the neutral-model schema, serialized by the adapter, then passed through block validation / `wp_kses_post` before it touches `post_content`.
6. **AI CSS is untrusted input too.** Per-page CSS is sanitized before save: no `expression()`, `behavior:`, `javascript:` URLs, or `@import`; size-capped; selectors restricted to `denise-`-prefixed classes.
7. **Page content is data, not instructions.** When existing content goes into a prompt, it is delimited and labeled as content; the system prompt states that instructions inside content must be ignored.

## AI safety rules (product-level)

1. The AI can **never publish, schedule, or delete** content from the chat. Drafts and revisions only. **Scoped exception (v0.5.0):** *scheduled* auto-creation may publish, but only for a post type whose profile has `auto_publish` explicitly enabled by an admin, and only as a separate step in the scheduler **after** the model has produced a draft. The `create_draft`/`update_content` tools still only ever draft — the model itself never publishes; the scheduler does, on the admin's opt-in. The interactive chat path can never publish under any prompt.
2. Every AI content change **creates a WP revision first** — undo must always be one click away.
3. Every tool invocation is **audit-logged** (user, time, tool, target, conversation).
4. The tool list is an **allowlist defined in code** — no dynamic tool registration from options/DB.
5. New tools or expanded permissions require a minor version bump and a CHANGELOG entry under "Security/Permissions".
6. **Elementor goes through Elementor's own abilities only** (`elementor/get-page-structure`, `manage-elements` update/move/duplicate/delete, `build-composition`, `get-widget-schema`, `list-widget-schemas`). No global settings, no custom code, no `publish-document`, no `create-page` outside the draft flow. **Scoped exception (2026-11-14):** applying a saved page template to a DRAFT (`Adapters\ElementorTemplates::apply_to()`, tools `create_elementor_draft_from_template` / `apply_elementor_template`) copies the template's elements with fresh ids through Elementor's `Document::save()`, the server half of the library's own Insert. Drafts only, never a published or private post; the local template source only; page settings copied without the post-level keys (status, title, excerpt, featured image). Replacing an existing draft layout requires the person's confirmation. The same exception covers **text edits on legacy (V3) widgets of a draft** (`Adapters\ElementorLegacy`): Elementor's ability refuses V3 elements, so Denise changes their content keys (text, editor, shortcode, icon-list rows…) in the stored element data and saves through `Document::save()`; never styles, never on a published post. **Text edits change text only**, in both paths: list rows keep their icon, id, image and link unless the person asks to change those.
7. **Builder adapters stay inside the content boundary.** An adapter (e.g. `ElementorAdapter`) may only create/edit content on a post the tool layer already created or is editing — it never reaches for a builder plugin's broader capabilities. The Elementor integration's allowed EMCP surface is: the element factory + data-layer save; the **read-only** discovery abilities (`list-widgets`, `get-widget-schema`, `get-page-structure`, `get-element-settings`); and the **in-place content-editing** abilities (`update-element`, `add-widget`, `add-container`, `move-element`, `remove-element`). It must **never** invoke EMCP abilities that change global/site settings (`update-global-colors`, `update-global-typography`), inject code (`add-custom-js`, `add-code-snippet`, PHP snippets), or delete the post itself. `remove-element` removes a *block within a page* (ordinary content editing, undoable via Elementor revisions) — it is not post deletion, which remains forbidden. The "drafts/revisions only, no publish/delete-post/options" rule applies identically no matter which builder renders the output. Expanding this allowlist requires its own decision recorded here.
8. **The `widget` override is untrusted, content-only.** The Elementor block-override element (`{type:"widget", …}`) lets the model name any Elementor widget, but its free-form `settings` are sanitized as untrusted input in `Model::sanitize_widget_settings()` (recursive `wp_kses_post`, `javascript:`/`vbscript:` URI stripping, depth/breadth caps) before they reach the factory. It still only ever creates a content widget on a draft — never a route to code execution, site options, or publishing.
9. **Denise edits only Elementor and block-editor builds.** `Profiles::is_supported_build()` is the gate: `read_content` / `update_content` refuse a post built with Thrive Architect or the classic editor (the Elementor tools already require the Elementor flag), and the front-end launcher withholds its toggle on the same posts. Thrive content lives in meta the adapters do not understand, and writing blocks into a classic or Thrive post would silently replace or be ignored by what actually renders. Field edits (`update_fields`) are not gated: ACF values render independently of the builder.
10. **Editor mode relays, it does not write.** When a page is open in the Elementor editor (the request carries a validated `editor` snapshot, `Ai\EditorContext`), the Elementor tools must not touch the database: reads answer from the snapshot and writes only record `editor` actions that the browser applies through Elementor's command bus, so the person's unsaved work is never overwritten and every change is undoable and saved only by their Update click. The snapshot is untrusted browser input: ids, types and scalar settings only, sanitized and capped, never persisted. Allowed relayed commands: `document/elements/settings` (on the element, or on a legacy repeater row container for list text), `document/repeater/insert` / `remove` (list rows, cloned from the last row so icons survive), `create`, `move`, `duplicate`, `delete`, and, when `Tools::TEMPLATE_INSERT_ENABLED` is on (off since 2026-11-14), for saved templates `library/open` + `library/insert-template` (Elementor's own insert flow; it fetches the content itself and may show its styles dialog to the person). Typed values come from Elementor's own resolver, never hand-written. Template ids are validated server-side against the local library source only (`Adapters\ElementorTemplates`: published templates, or drafts the user can edit; page/section/container/widget types, never theme parts). Audit log records the tool call as usual.
11. **Complex field writes are schema-validated and confirmed.** `Content\Fields::write()` accepts repeaters, groups and post references (post_object, relationship, page_link) in addition to scalars: every row is validated sub-field by sub-field against the ACF schema, unknown keys and read-only sub-fields (images, files, galleries, flexible content, taxonomy, users) are dropped, and post references must be existing posts of the field's allowed types. A repeater write replaces all rows, so the tool layer refuses it while the post has rows until the model passes `confirm_replace` after showing the person the new rows. Prior values are still snapshotted to `_denise_field_backup`.

## Database rules

- Schema changes only via the migration routine (versioned with a `denise_db_version` option) — never ad-hoc `ALTER`s.
- Custom tables only for genuinely relational data (conversations, messages, audit log). Everything else uses core APIs.
- Uninstall (`uninstall.php`) removes tables and options **only if** the user opted into "delete data on uninstall" in settings.

## Git workflow

- `main` is always releasable. Work happens on `feature/…` / `fix/…` branches.
- Commit messages: imperative, scoped — `adapter: preserve unknown blocks as raw elements`.
- One logical change per commit. Docs-only changes are fine as direct commits to `main`.
- Releases are tagged `vX.Y.Z` on `main` (see VERSIONING.md).

## Definition of done (per feature)

- [ ] Works on a clean WP install with only this plugin active
- [ ] PHPCS passes; `npm run build` succeeds with no warnings
- [ ] Capability + nonce checks verified for any new endpoint/tool
- [ ] Strings translatable
- [ ] CHANGELOG entry added under `[Unreleased]`
- [ ] Docs updated if architecture or rules changed
