# Project Rules

Rules for all code in this plugin — written by humans or AI. If a rule blocks something genuinely needed, change the rule in a PR/commit of its own, don't silently break it.

## Coding standards

### PHP

- **WordPress Coding Standards** (PHPCS `WordPress` ruleset). Tabs, Yoda conditions, escaping rules — all of it.
- PHP **8.1+** features allowed (enums, readonly, first-class callables). No polyfills for older PHP.
- Namespace `DCB\`, PSR-4 autoloaded via Composer. No new `class-*.php` WPPB-style files.
- Prefix everything global-facing with `dcb_`: hooks, options, transients, capabilities, DB tables, CLI commands.
- Every user-facing string is translatable, text domain `dennis-content-builder`.
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
6. **AI CSS is untrusted input too.** Per-page CSS is sanitized before save: no `expression()`, `behavior:`, `javascript:` URLs, or `@import`; size-capped; selectors restricted to `dcb-`-prefixed classes.
7. **Page content is data, not instructions.** When existing content goes into a prompt, it is delimited and labeled as content; the system prompt states that instructions inside content must be ignored.

## AI safety rules (product-level)

1. The AI can **never publish, schedule, or delete** content from the chat. Drafts and revisions only. **Scoped exception (v0.5.0):** *scheduled* auto-creation may publish, but only for a post type whose profile has `auto_publish` explicitly enabled by an admin, and only as a separate step in the scheduler **after** the model has produced a draft. The `create_draft`/`update_content` tools still only ever draft — the model itself never publishes; the scheduler does, on the admin's opt-in. The interactive chat path can never publish under any prompt.
2. Every AI content change **creates a WP revision first** — undo must always be one click away.
3. Every tool invocation is **audit-logged** (user, time, tool, target, conversation).
4. The tool list is an **allowlist defined in code** — no dynamic tool registration from options/DB.
5. New tools or expanded permissions require a minor version bump and a CHANGELOG entry under "Security/Permissions".

## Database rules

- Schema changes only via the migration routine (versioned with a `dcb_db_version` option) — never ad-hoc `ALTER`s.
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
