# MEMORY — Dennis Content Builder (project handoff)

> **Read this first if you're a Claude instance picking up this project on another machine.**
> It's a state + context summary so you understand what we're building and why. The
> canonical, detailed docs are in [`docs/`](docs/) and [`CLAUDE.md`](CLAUDE.md) — this file
> points you at them and captures the decisions and mental model that aren't obvious from code.
> Author/owner: **Dennis Gutierrez** (GitHub: CoderDennisG). Repo: https://github.com/CoderDennisG/Dennis-Content-Builder

## What we're building

A WordPress plugin that lets people **build and edit content by chatting with AI**, designed so
non-coders can create real pages/posts without touching blocks — while everything stays natively
editable in Gutenberg afterward. It also auto-creates content on a schedule (e.g. a podcast
episode post every Mon/Wed/Fri).

Who it's for: site owners, editors, and clients who know what they want to say but don't want to
wrestle with the block editor. Built for **personal + client sites** (not WordPress.org).

## The mental model (important — this shapes every design)

- **The AI supplies judgment; the code supplies reach.** An LLM is a text-in/text-out engine. By
  itself it has no internet, no DB, no file access — it can only produce words. It *does* things
  only through **tools**: small PHP functions we expose and let it call. The model decides "call
  `create_draft` with this data"; our code runs the real WordPress function.
- **Consequence — the capability boundary is the code, not the model's goodwill.** The AI can only
  ever use the hands we give it. That's why it cannot publish, delete, or touch anything outside
  its allowlist: we never built those tools (except the one deliberate, scoped exception:
  scheduled auto-publish — see RULES.md).
- **The AI never writes block markup or raw field storage directly.** It reads/writes a neutral
  JSON model; deterministic PHP adapters translate to/from Gutenberg block markup and ACF/meta.
  This keeps output valid, keeps the AI builder-agnostic, and is what makes "support other page
  builders later" possible.
- **Convincing ≠ real.** When the AI lacks a tool for a task (e.g. fetching a web page), it will
  produce believable *fabricated* output unless told to stop. Always give it a real tool or an
  explicit "don't invent — stop if you can't" instruction. (We hit this with the YouTube sync.)

## Architecture (one engine, configured per post type)

```
Chat UI (admin page + Gutenberg sidebar; wp.element, no build step)
   │  REST (dcb/v1, nonce + capability checks, SSE streaming for /chat)
AI Orchestrator (server-side Claude tool-use loop; anthropic-ai/sdk)
   │  neutral model + tool calls
Content engine: neutral block model + GutenbergAdapter (parse/serialize),
                Profiles (per-type config), Fields (ACF + native meta),
                Conversations (history + audit log), Scheduler
```

- **Neutral content model** (`includes/Content/Model.php`) — a flat array of typed elements
  (heading, paragraph, list, quote, image, button, columns, group, separator, spacer, + `raw`
  passthrough for unknown blocks). Strictly sanitized; the AI only ever emits/consumes this.
- **GutenbergAdapter** (`includes/Adapters/GutenbergAdapter.php`) — neutral model ⇄ core block
  markup, byte-exact so blocks never show "invalid content". Unknown/third-party blocks survive
  as opaque `raw` elements. Round-trip verified against WP's real `parse_blocks`.
- **Post type profiles** (`includes/Content/Profiles.php`) — "different AI per post type" is really
  one model + a per-type profile: `enabled` (eligibility allowlist), `instructions` (writing
  persona injected into the prompt), `fields` (which custom fields the AI may touch), and a
  `schedule` block. Allowed **blocks are global** (one site-wide list), not per type.
- **Custom fields** (`includes/Content/Fields.php`) — discovers ACF field groups (recursively:
  repeater/group/flexible-content sub-fields + choices) and native `register_post_meta` keys.
  **Reads** the full tree today; **writes** only the reliable scalar/choice/date types this
  release (complex types — repeater, group, flexible content, image, file, relationship — are
  read-only and refused on write with a clear message). ACF written via `update_field`, native via
  `update_post_meta`. Prior values snapshotted to `_dcb_field_backup` before write (revisions
  don't cover meta).
- **Scheduler** (`includes/Ai/Scheduler.php`) — per-type day+time auto-creation (e.g. MWF 18:00).
  Computes the next occurrence in the site timezone and arms a self-rearming
  `wp_schedule_single_event`; exact under a real server cron (WP Engine). Runs headless via
  `Orchestrator::run_scheduled()` as a dedicated `SystemUser` (`includes/Support/SystemUser.php`,
  an internal editor-role account). Optional per-type `auto_publish`.
- **AI tools** (`includes/Ai/Tools.php`, code-defined allowlist): `list_content`, `read_content`,
  `create_draft` (drafts only), `update_content` (saves a revision), `update_page_css`,
  `search_media`, `read_fields`, `update_fields`. Per-call `current_user_can()` checks.

## Current state

- **Version: 0.6.0.** Pre-1.0 (we're at 1.0 when it runs on a real client site — see ROADMAP.md).
- Shipped so far: prototype pipeline (0.1) → streaming + persistent conversations + audit log +
  `dcb_use_chat` capability (0.2) → Gutenberg editor sidebar with live canvas refresh (0.3) →
  settings rebuilt on `@wordpress/components` (0.3.1) → post-type profiles (0.4) → global Allowed
  Blocks tab + compact Post Types list with guidance-in-a-modal (0.4.x) → scheduled auto-creation
  with day/time picker + auto-publish opt-in (0.5) → custom fields read/write (0.6).
- Verified working on Dennis's Local site, including a real ACF-based **Podcast** post type
  (fields: Episode, Hosts [relationship], Title, Youtube Link [url], Audio URL, Duration [seconds],
  Apple/Spotify/Amazon/iHeart Episode URL, Archive Art [image, 485×275], Fold Image [image,
  1920×1080], Description [textarea/wysiwyg]).

## Stack & conventions

- **PHP 8.1+**, namespace `DCB\` (PSR-4 → `includes/`), prefix `dcb_` for hooks/options/tables/caps.
  `declare(strict_types=1)`. WordPress Coding Standards enforced (`vendor/bin/phpcs`, config in
  `phpcs.xml.dist`) — keep it clean.
- **Composer deps:** `anthropic-ai/sdk` (^0.7) + `guzzlehttp/guzzle` (the SDK is transport-agnostic
  and needs a PSR-18/17 client — without Guzzle every API call throws "No PSR-17 url factory
  found"). `vendor/` is gitignored → run **`composer install`** in the plugin dir on a fresh clone.
- **JS:** no build step. Settings UI and editor sidebar use the bundled `wp.components` / `wp.element`
  globals via `wp.element.createElement` (no JSX). Chat UI is a vanilla-JS factory
  (`assets/chat.js` → `dcbCreateChat`). Assets in `assets/`.
- **Default model:** `claude-opus-4-8` (selectable: sonnet-4-6, haiku-4-5). Adaptive thinking, no
  sampling params. `/chat` streams via SSE.
- **Security/AI-safety invariants (docs/RULES.md):** API key server-side only (write-only field);
  real `permission_callback` on every route; AI output + AI CSS treated as untrusted (validated +
  sanitized); AI can't publish/delete from chat; every edit makes a revision; tool list is
  code-defined; the *only* publish path is scheduled auto-publish on an admin-opted-in type.
- **Versioning (docs/VERSIONING.md):** SemVer; bump the header `Version:` + `DCB_VERSION` constant +
  CHANGELOG together; tag `vX.Y.Z`; one logical change per commit. Commits co-authored by Claude.
  DB schema changes go through `includes/Support/Schema.php` gated by `dcb_db_version`.

## How to run / test

1. `composer install` in the plugin directory (pulls SDK + Guzzle into `vendor/`).
2. Activate the plugin. Admin menu **Content Builder** (chat) + **Settings** submenu.
   - Real admin URLs are `wp-admin/admin.php?page=dennis-content-builder` and `…?page=dcb-settings`
     (admin pages aren't permalinks).
3. Settings → General: paste an Anthropic API key, pick a model, **Test connection**.
4. Settings → Allowed Blocks / Post Types: choose blocks, enable types, set per-type guidance,
   custom-field allowlist, and schedules.
5. Chat from the Content Builder page, or open any eligible post and use the **Content Builder**
   sidebar (pin icon in the editor). Scheduled types have a **Run now** button for testing
   (essential where cron doesn't fire locally, e.g. Local).

## Open threads / what's next

1. **YouTube sync (active topic).** Goal: a scheduled **Podcast** job that, MWF ~6:10 PM, fetches
   the latest video from `https://www.youtube.com/@TheMidlifeChrysalisPodcast` and creates a real
   Podcast draft (title = video title, content = description) with meta fields filled.
   - **Why it's needed:** the AI cannot fetch the web itself; with no tool it fabricated
     believable placeholder episode notes (title prefixed `[Pending YouTube sync]`). The fix is a
     server-side fetch tool, not a better brief.
   - **Decided:** fetch via **YouTube Data API with RSS-feed fallback** — Data API (needs a free
     Google API key in settings) gives title/description/**duration**/thumbnail and resolves the
     `@handle`; RSS feed (`/feeds/videos.xml?channel_id=…`, no key) gives title/link/description/
     thumbnail but **no duration** and needs the channel_id resolved once. Public video = no auth
     needed either way.
   - **Will fill now (writable field types):** Title, Youtube Link, Duration (seconds), Audio/Apple/
     Spotify/Amazon/iHeart URLs, Description.
   - **UNDECIDED:** auto-filling the **image** fields (Fold Image / Archive Art). Requires
     sideloading the thumbnail (`media_sideload_image`) + writing an attachment ID to an ACF image
     field — i.e. the deferred complex-field/media-write work. Note: YouTube `maxresdefault` is
     1280×720 (fits Fold Image's 16:9; OK), but Archive Art's 485×275 needs awkward cropping → best
     left manual. Recommendation given: ship text/data sync first, treat Fold Image as a fast-follow.
   - **Not started — do not code until Dennis says go.** Brief must include "if fetch fails, don't
     invent — stop and say so."
   - Scheduling caveats: fire a few min after 6 PM (feed/API lag); "latest" may catch a Short/live —
     consider filtering (e.g. skip very short videos).
2. **Complex field writes** — enabling writes for repeater/group/flexible-content and media/
   relationship (by searching existing items, never inventing IDs). The Fields schema engine
   already walks the full tree, so this is enablement, not a rebuild.
3. **Scheduled-content repetition** — a fixed brief will drift toward repeating itself; feeding
   recent titles of that type into the run fixes it. Worth adding before relying on a frequent
   schedule.
4. **Backlog (ROADMAP.md):** second builder adapter (Elementor/Bricks) to prove the abstraction;
   block patterns; the style-reference "Training" tab (learn composition from page screenshots);
   audit-log viewer UI; an automation/overview tab listing all scheduled types.

## Gotchas already learned (don't re-discover these)

- Bundling Guzzle can collide with another plugin's Guzzle on a shared site — fine here, but
  before a real client site, scope/prefix the vendor dir (PHP-Scoper) or swap to a PSR-18 adapter
  over WP's HTTP API.
- Submenus must register **after** their parent menu or the sidebar link 404s (boot order +
  priority 11 on the Settings menu hook).
- WP-Cron is traffic-triggered by default; on WP Engine a real server cron fires `wp-cron.php` on
  time, which is why the precise-timestamp scheduler is reliable there (and why **Run now** exists
  for Local).
- ACF must be written with `update_field` (it keeps a hidden `_fieldkey` reference); raw meta
  writes corrupt it. Native meta uses `update_post_meta`.
- Post meta is **not** covered by revisions — that's why field writes snapshot to
  `_dcb_field_backup`.

## What to confirm on this (other) WP install

- Run `composer install`; confirm ACF is active (the Fields feature degrades gracefully without it
  but ACF is where the rich schema is).
- The real **Podcast** ACF field *names* (not labels) for the fields the AI should fill — check the
  field group, or open a Podcast and use Settings → Post Types → Podcast → Custom fields to see the
  discovered list.
- Whether a **YouTube Data API key** will be available here (decides whether duration syncs or we
  run on the RSS fallback).
- The channel handle/URL and posting cadence (assumed `@TheMidlifeChrysalisPodcast`, MWF 6 PM).
