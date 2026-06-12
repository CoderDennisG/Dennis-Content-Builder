# Versioning & Releases

## Scheme

**Semantic Versioning 2.0** (`MAJOR.MINOR.PATCH`):

| Bump | When |
|---|---|
| **MAJOR** | Breaking change for users or for adapter/filter API consumers (changed hooks, changed neutral-model schema in a non-backward-compatible way, raised WP/PHP minimums) |
| **MINOR** | New features, new AI tools, new adapters, expanded permissions |
| **PATCH** | Bug fixes, security fixes, copy/translation fixes |

### Pre-1.0 rule

We are in `0.x` until the plugin runs on a real client site. During `0.x`:
- MINOR = each completed roadmap phase (0.2.0 Foundation, 0.3.0 Content engine, …)
- Breaking changes are allowed in MINOR bumps but must be called out in the changelog.
- `1.0.0` = Phase 6 complete (see ROADMAP.md).

**First action of Phase 0:** reset the boilerplate's `1.0.0` to `0.1.0`.

## Where the version lives (bump all together)

1. Plugin header `Version:` in `dennis-content-builder.php`
2. Constant `DENNIS_CONTENT_BUILDER_VERSION`
3. `CHANGELOG.md` (release section)

A release isn't done if these disagree. (Add `package.json`/`composer.json` versions to this list once they exist.)

Separate from the plugin version: **`dcb_db_version`** (integer, stored in options) tracks the DB schema and only increments when a migration is added.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/):

- Work in progress accumulates under `[Unreleased]` as you commit.
- Categories: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- Entries describe the change for a *user/integrator*, not the implementation.

## Release process

1. Confirm `main` is green: PHPCS passes, `npm run build` clean, manual smoke test (settings → test connection → create a draft via chat).
2. Move `[Unreleased]` entries into a new `[X.Y.Z] - YYYY-MM-DD` section.
3. Bump the version in all locations listed above (one commit: `release: vX.Y.Z`).
4. Tag: `git tag vX.Y.Z` and push tags.
5. Build a distributable zip (excludes `src/`, `node_modules/`, dev files; includes `build/` and `vendor/`).

## Compatibility policy

- Support the **current and previous major WordPress release**.
- PHP minimum **8.1**; raise only in a MAJOR bump.
- The neutral content model JSON carries its own `version` field; adapters must keep reading older model versions within the same plugin MAJOR.
