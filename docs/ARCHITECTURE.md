# Architecture

## Design goals

1. **Builder-agnostic core.** The AI and chat layers never know which page builder is in use. All builder knowledge lives in adapters.
2. **AI is server-side only.** The browser never sees the API key and never talks to Anthropic. All AI traffic goes through plugin REST endpoints with WordPress auth.
3. **Safe by default.** The AI can only create drafts and revisions. Publishing, deleting, and anything outside content is off-limits in v1.
4. **Non-coder friendly.** Anything the chat produces must remain fully editable in the native builder afterward — no proprietary lock-in format.

## Layers

```
┌──────────────────────────────────────────────────────────┐
│  Chat UI (React, @wordpress/components)                  │
│  • Gutenberg editor sidebar plugin (edit current page)   │
│  • Standalone admin page (create new content)            │
└───────────────▲──────────────────────────────────────────┘
                │ wp REST (nonce + capability checks)
┌───────────────┴──────────────────────────────────────────┐
│  REST API  (namespace: dcb/v1)                           │
│  POST /chat        — send a message, get AI actions back │
│  GET  /conversations/{id} — chat history                 │
│  GET  /settings, POST /settings (admins only)            │
└───────────────▲──────────────────────────────────────────┘
                │
┌───────────────┴──────────────────────────────────────────┐
│  AI Orchestrator (PHP)                                   │
│  • Calls Claude Messages API (official anthropic-ai/sdk) │
│  • Runs the tool-use loop server-side                    │
│  • Enforces the tool allowlist + capability checks       │
└───────────────▲──────────────────────────────────────────┘
                │ neutral content model (JSON)
┌───────────────┴──────────────────────────────────────────┐
│  Content Engine                                          │
│  • Neutral content model: tree of typed sections/elements│
│  • Validation + sanitization (wp_kses, block validation) │
│  • Draft/revision management, audit log                  │
└───────────────▲──────────────────────────────────────────┘
                │ Builder_Adapter interface
┌───────────────┴──────────────────────────────────────────┐
│  Builder Adapters                                        │
│  • GutenbergAdapter (v1) — parse_blocks/serialize_blocks │
│  • Future: Elementor, Bricks, Classic…                   │
└──────────────────────────────────────────────────────────┘
```

## The neutral content model

A small JSON schema describing content independent of any builder:

```json
{
  "version": 1,
  "sections": [
    { "type": "hero",
      "elements": [
        { "type": "heading", "level": 1, "text": "…" },
        { "type": "paragraph", "text": "…" },
        { "type": "button", "text": "Sign up", "url": "…" }
      ]
    }
  ]
}
```

- Element types in v1 map 1:1 to core Gutenberg blocks: `heading`, `paragraph`, `image`, `list`, `quote`, `button`, `columns`, `separator`, `spacer`, `group/section`.
- Claude reads and writes this model — it never writes raw block markup. That keeps prompts small, output predictable, and adapters swappable.
- The model is **lossy by design** for round-tripping: when parsing an existing page, blocks the adapter doesn't understand are preserved as opaque `raw` elements that the AI may move/delete but not edit internally.

## Block vocabulary (admin-configurable, auto-discovering)

The set of blocks the AI may use is data, not hard-code. Every known block is a vocabulary entry: block name, source (core / third-party), capability tier, enabled flag. The JSON schema and system prompt sent to Claude are built **from the enabled entries only** — a disabled block is structurally impossible to output, not merely discouraged.

### Capability tiers (technical ceiling, not a preference)

| Tier | Blocks | AI can |
|---|---|---|
| **Full** | Core blocks backed by hand-written serializer templates | Create, edit, move, delete |
| **Attributes** | *Dynamic* third-party blocks (server-rendered; content stores only the comment + JSON attributes, e.g. `<!-- wp:wpforms/form-selector {"formId":"12"} /-->`) | Create/edit by setting attributes (schema read from `WP_Block_Type_Registry`); move, delete |
| **Passthrough** | *Static* third-party blocks (save final HTML that must byte-match their JS save function — unwritable from PHP) | See labeled summary, move, delete, **clone an existing instance verbatim**; never author/rewrite internals |

Static vs dynamic is detected from the registry (`render_callback` present = dynamic). Admins can toggle entries on/off and *lower* a tier, but never raise a block above its technical ceiling — authoring a static third-party block from scratch would corrupt the page (editor "invalid content" recovery dialog).

### Discovery

Third-party blocks are discovered automatically and appear in the settings list marked **Third-party**:

1. **Registry scan** — everything registered in `WP_Block_Type_Registry` (covers blocks from active plugins even before first use).
2. **Content scan** — block types encountered when the AI parses an existing page.

Defaults: core blocks enabled; discovered dynamic blocks enabled at the Attributes tier; static third-party blocks Passthrough. Caveat recorded for expectations: the registry exposes attribute names/types but often weak descriptions, so Attributes-tier authoring is best-effort — the AI compensates by reading existing instances on the site to learn real values.

## Styling

Principle: **the theme owns the design; the AI composes within it.** No custom CSS, ever, in v1.

Three levels:

1. **Inherit (default).** Elements carry no style; core blocks pick up the theme's design from `theme.json` / Global Styles automatically. The system prompt instructs the AI to style only when asked or clearly warranted.
2. **Theme presets.** The neutral model's optional `style` object accepts only preset slugs from the active theme's design system — palette colors, font-size scale, spacing scale, alignment (`wide`/`full` only if the theme supports it). At conversation start the plugin reads these via `wp_get_global_settings()` and injects them into the system prompt (slug + name + hex so natural language like "our dark blue" resolves) **and** into the JSON schema as enums — off-palette values are structurally impossible, mirroring the block-vocabulary approach. These serialize to standard block-supports attributes (`backgroundColor`, `fontSize`, `align`, `style.spacing` with `var:preset|…` values), so every choice is editable through Gutenberg's normal sidebar controls and re-resolves on theme switch.
3. **Per-page custom CSS (guarded).** Each post/page has a custom CSS field stored in post meta (`_dcb_custom_css`), editable by humans through a normal editor panel and writable by the AI through a dedicated tool. Front end: enqueued inline on that page only. Rails:
   - **Presets first.** The system prompt orders the AI to use theme presets whenever they can express the ask; custom CSS is the last resort, not the default.
   - **Scoped selectors only.** The AI targets only classes it added itself (`dcb-` prefix via the near-universal `className` block support) — never bare element selectors.
   - **Sanitized as untrusted input:** strip JS-in-CSS vectors (`expression()`, `behavior:`, `javascript:` URLs), no `@import`, size cap.
   - **Known trade-off (documented, accepted):** this is the one plugin-dependent feature — content survives plugin removal untouched, but per-page CSS stops being enqueued. All page builders share this; ours degrades gracefully (clean unstyled native blocks remain).

## Style reference learning ("Training" tab)

Not model training — **reference learning** via Claude's vision. A backend Training tab where the admin uploads screenshots/mockups of pages whose style should guide the AI:

- **Distill once, reuse cheaply.** On upload, a one-time vision analysis converts each image into a written **style profile**: section composition patterns, spacing/density, color usage, typography mood. Profiles are stored as editable text.
- At build time the active profile text is injected into the system prompt — not the raw images (full-res images cost up to ~4.8K tokens each per request; profile text costs a fraction and is more actionable).
- Profiles can be tagged by page type (landing, blog, about) and selected per conversation.
- Per-request vision stays available: a user can attach a reference image to a single chat message ("build it like this") when the picture is the spec.

Expectation set in UI copy: profiles guide composition, spacing, and choices *within* the theme's presets + scoped CSS — "in the spirit of" the reference, never a pixel clone. `theme.json` remains the sitewide foundation; profiles cover compositional taste no theme setting can express.

Adapter responsibility: block supports require the saved HTML to carry classes matching the attributes (`"backgroundColor":"primary"` ⇄ `has-primary-background-color has-background`). The GutenbergAdapter templates emit attribute + class pairs deterministically; the AI never handles this rule.

Per tier: Full-tier blocks get the complete `style` object; Attributes-tier blocks expose only the style attributes they register (best effort); Passthrough blocks' styling is never touched.

## Post type profiles

"A different AI per post type" is implemented as one engine + one model with a per-post-type **profile** — never separate AIs. A profile (stored in option `dcb_profiles`, keyed by post type slug) carries:

- **`enabled`** — eligibility. Disabled types get no sidebar and refuse creation/editing. Defaults: `page`, `post`.
- **`instructions`** — writing guidance/persona injected into the system prompt. When a post is open the sidebar injects that one type's guidance; the standalone chat injects the catalogue of eligible types.
- **`fields`** — top-level custom-field names (ACF + native meta) the AI may read/write for this type. See Custom fields below.

**Allowed blocks are global, not per-type** (option `dcb_allowed_blocks`): a subset of the block catalogue, empty = all, applied to every post type. Enforced **structurally** in `Model::sanitize_elements()` (disallowed elements dropped recursively; `raw` exempt) via `Profiles::allowed_blocks()`, and surfaced once to the model in the system prompt — not merely prompted.

Enforcement is at every entry point, not just the prompt: `list_content`/`read_content`/`create_draft`/`update_content` all check `Profiles::is_eligible()` and apply the global `Profiles::allowed_blocks()`, and capability checks use each post type object's own `cap`. Managed by `DCB\Content\Profiles`; edited from the settings page (Allowed Blocks + Post Types tabs).

## Custom fields

`DCB\Content\Fields` discovers, reads, and writes custom fields from **ACF** (`acf_get_field_groups`/`acf_get_fields`, written via `update_field`) and **native registered meta** (`get_registered_meta_keys`, written via `update_post_meta`). Both are guarded by `function_exists` so the plugin runs with either, both, or neither present.

Schema is **recursive** — repeater/group sub-fields and flexible-content layouts are normalized into nested nodes — so the engine reads the full tree today. **Writing is phased:** a fixed `WRITABLE_ACF` set (text/textarea/wysiwyg/number/email/url/choice/true-false/date/color) plus native scalars are validated per type (enum-checked choices, sanitized values) and written; complex types (repeater, group, flexible content, image, file, relationship, post object) are returned read-only and refused on write with a message the model understands. The schema/value engine already handles the full tree, so enabling complex writes later is enablement, not a rebuild.

Exposed to the AI only through the per-type allowlist (`Profiles::allowed_fields()`), via tools `read_fields` (schema + current values) and `update_fields` (validated write). Because WordPress revisions don't snapshot meta, `Fields::write()` backs up prior values to `_dcb_field_backup` before overwriting. Discovery for the settings checklist is served by `GET /dcb/v1/fields`.

## Scheduled auto-creation

Per type, an admin can schedule automatic creation on specific weekdays at a set time (`schedule` block on the profile: `enabled`, `days[]`, `time`, `auto_publish`, `brief`). `DCB\Ai\Scheduler` computes the next matching occurrence in the site timezone and arms a **self-rearming `wp_schedule_single_event`** — when it fires it re-arms the next one — so timing is exact under a real server cron (WP Engine). A daily resync event self-heals dropped events; `sync_all()` re-arms on save/activation. Runs execute headless via `Orchestrator::run_scheduled()` (no streaming, single creation) as a dedicated `DCB\Support\SystemUser` (an internal `editor`-role account), with every run audit-logged.

**Auto-publish is the one scoped exception to "the AI never publishes"** — see docs/RULES.md. The model still only drafts; the scheduler publishes afterward, only when that type's `auto_publish` is on. A "Run now" REST route (`/dcb/v1/run-schedule`, `manage_options`) triggers an immediate run for testing — essential on environments where cron isn't firing locally.

## Builder adapter contract

```
interface Builder_Adapter {
    supports( WP_Post $post ): bool;          // can this adapter handle this post?
    parse( WP_Post $post ): ContentModel;     // post_content -> neutral model
    serialize( ContentModel $model ): string; // neutral model -> post_content
    label(): string;                          // "Gutenberg", "Elementor", …
}
```

Adapters are registered through a filter (`dcb_register_adapters`) so third-party adapters are possible without touching core plugin code.

## AI integration

- **SDK:** official `anthropic-ai/sdk` for PHP via Composer, vendor directory shipped with the plugin (prefixed/scoped if conflicts appear on client sites).
- **Default model:** `claude-opus-4-8` for build/edit requests (quality matters; pages are short, so token cost stays modest). Settings allow switching to `claude-haiku-4-5` for cheap drafting or `claude-fable-5` for the hardest jobs.
- **Thinking:** `thinking: {type: "adaptive"}`. No `temperature`/`top_p` (removed on Opus 4.7+).
- **Loop:** manual tool-use loop in PHP (gives us per-tool capability checks and audit logging; the SDK's beta `toolRunner()` is an option once stable).
- **Streaming:** server streams from Anthropic; v1 may buffer the full response before returning to the browser (simpler), with SSE pass-through as a later improvement.

### Tools given to Claude (v1)

| Tool | What it does | Guardrails |
|---|---|---|
| `list_content` | List pages/posts (id, title, status) | Respects current user's `edit_posts` scope |
| `read_content` | Get a page as the neutral model | Per-post `current_user_can('edit_post')` |
| `create_draft` | Create a new page/post as **draft** from a neutral model | Always `post_status = draft` |
| `update_content` | Replace/patch a page's neutral model | Writes a revision first; never changes status |
| `search_media` | Find existing media library images | Read-only |
| `update_page_css` | Write/replace the page's custom CSS (post meta) | Sanitized; `dcb-`-prefixed selectors only; audit-logged |

Explicitly **not** available in v1: publish, delete, users, options/settings, plugins/themes, menus, raw SQL/HTML.

## Conversations & state

- Conversations stored in a custom table (`{prefix}dcb_conversations` + `{prefix}dcb_messages`) — post meta is too clumsy for chat history.
- Each AI action that touches content writes an **audit log** row (who, when, tool, post id, conversation id).
- Editor-sidebar chats are scoped to the open post; admin-page chats are unscoped.

## Security model

| Concern | Approach |
|---|---|
| API key storage | `wp_options`, never printed to JS/HTML; field is write-only in settings UI (shows last 4 chars) |
| REST auth | Nonce + `permission_callback` on every route; no public endpoints |
| Who can chat | New capability `dcb_use_chat` (granted to editors+ by default, filterable) |
| Who can configure | `manage_options` only |
| AI output | Serialized through the adapter, then validated as blocks + `wp_kses_post` before save |
| Undo | Every `update_content` creates a WP revision; UI exposes one-click restore |
| Prompt injection via page content | Page content is data, not instructions: wrapped in delimited blocks in the prompt; tools are the only way to act |

## Frontend build

- `@wordpress/scripts` for build tooling (wp-blessed webpack, React, JSX).
- One shared `<ChatPanel>` React component used by both the sidebar plugin and the admin page.
- Use `@wordpress/data` to read/refresh editor state after an edit (so changes appear live in Gutenberg without a reload).

## Directory layout (target)

The WPPB boilerplate currently in place will be reshaped to:

```
dennis-content-builder/
├── dennis-content-builder.php      # bootstrap, constants, autoloader
├── includes/
│   ├── Admin/                      # settings page, menu registration
│   ├── Api/                        # REST controllers (dcb/v1)
│   ├── Ai/                         # orchestrator, tool registry, prompts
│   ├── Content/                    # neutral model, validation, audit log
│   ├── Adapters/                   # Builder_Adapter + GutenbergAdapter
│   └── Support/                    # activation, capabilities, db schema
├── src/                            # React (chat panel, sidebar, admin app)
├── build/                          # compiled assets (committed per release)
├── vendor/                         # composer deps (anthropic-ai/sdk)
├── languages/
└── docs/
```

PHP namespace: `DCB\` with PSR-4 autoloading via Composer. Prefix for hooks/options/db: `dcb_`.
