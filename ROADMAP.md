# Videos roadmap

This roadmap aligns the Videos plugin with the current recommendations documented in [`hostellerie/memorandum`](https://github.com/hostellerie/memorandum), while preserving the current compatibility target:

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through PHP 8.1

The objective remains to stabilize the plugin, simplify its architecture, and strengthen interoperability before extending it further.

## 0.19.0 — release stabilization status

Version 0.19.0 is functionally complete.

### Completed

- Persistent storage migration no longer runs implicitly during normal bootstrap.
- Legacy storage remains readable until an explicit upgrade or repair path performs migration.
- Storage migration is site-scoped, idempotent, restartable and non-destructive.
- Shared-files multisite behavior is isolated by each site's `path_data`.
- Save/delete lifecycle events are implemented for public item transitions.
- Administration language keys use semantic identifiers instead of hashed `text_xxx` keys.
- Overview, Actions, Statistics and Moderation use a consistent administration shell and styling.
- Native Geeklog Content Syndication support is implemented with `plugin_getfeednames_videos()` and `plugin_getfeedcontent_videos()`.
- Install/uninstall metadata has been aligned.
- The installable archive is generated as `videos_0.19.0_2.1.1.zip`.
- `plugin.json` exposes plugin identity and minimum Geeklog/PHP requirements.
- Manual testing has been completed on Geeklog 2.1.1 and 2.2.2.
- CI validates PHP 5.6, 7.4 and 8.1 compatibility, storage regression behavior, Geeklog integration contracts, install/uninstall consistency and administration language coverage.
- The duplicate `plugin_getfeedcontent_videos()` declaration was removed; the canonical implementation is in `interoperability.php`.

## 0.20.0 — architectural consolidation

Version 0.20.0 is the active development branch. It is a simplification release, not a feature race. The objective is to reduce coupling and prepare the plugin for future common Geeklog integration services.

### Implementation status

Completed so far on branch `0.20.0`:

- version switched to `0.20.0` with release status `development`;
- PHP 5.6-compatible class autoloader added in `autoload.php`;
- the autoloader resolves classes relative to the plugin itself and is independent of the active Geeklog filesystem root;
- `functions.inc` no longer eagerly requires the complete Videos class set on every request;
- Plugin API integration files remain explicitly loaded;
- combined integration-load test detects duplicate callback declarations and fatal load conflicts;
- autoloader coverage test validates all `classes/Videos_*.php` files;
- release validation workflow runs on both `0.19.0` and `0.20.0`;
- loading/autoload tests run across PHP 5.6, 7.4 and 8.1;
- Geeklog 2.1.1 and 2.2.2 Plugin API contract checks remain green;
- a provider contract isolates the external video capabilities `search()`, `videos()`, `channels()` and `getLastError()`;
- `Videos_YouTubeProvider` adapts the existing YouTube client to that provider contract;
- `Videos_ProviderFactory` centralizes provider/service construction for explicit external synchronization paths;
- `Videos_ExternalSync` centralizes administration search, discovery seeding and single-video synchronization;
- administration code no longer constructs `Videos_YouTubeClient` or `Videos_YouTubeService` directly;
- `Videos_HttpClient` enforces a bounded response size while preserving TLS verification, timeouts, host restrictions and disabled redirects;
- the public catalogue no longer triggers YouTube requests or discovery refreshes;
- public catalogue rendering reads exact/stale local search cache first, then compatible local cache, then the local discovery reservoir;
- explicit discovery refresh remains an administration/maintenance responsibility;
- `Videos_TemplateRenderer` wraps Geeklog's native template layer;
- `Videos_CatalogueRenderer` now renders the public catalogue through `catalogue.thtml` and `video-card.thtml`;
- `public_html/index.php` no longer contains the video-card presentation markup;
- templates remain overridable through Geeklog's normal plugin template resolution;
- CI enforces both the local-only public runtime and the catalogue presentation boundary;
- `plugin_feedupdatecheck_videos()` avoids unnecessary feed regeneration when the public editorial corpus has not changed;
- `videos_config_schema()` now drives initial Geeklog configuration creation;
- schema/default parity and initial schema-driven installation are checked in CI;
- real ID -> URL -> ID round-trip behavior is tested for videos, channels, catalogue and rankings;
- `plugin_getiteminfo_videos()` is behaviorally tested for single items, field filtering, collection `*`, `since`, `limit` and ordering;
- native Content Syndication is tested against the same local editorial corpus used by Item Info;
- moderation visibility is behaviorally tested for blocked videos and excluded channels in both Item Info and syndication;
- lifecycle interoperability is behaviorally tested for video block/unblock and channel exclusion through `PLG_itemSaved()` / `PLG_itemDeleted()`;
- these interoperability tests pass across PHP 5.6, 7.4 and 8.1;
- the 0.20 distribution workflow derives the archive version/minimum Geeklog version from `version.php`;
- the installable archive is rebuilt automatically as `videos_0.20.0_2.1.1.zip` and is checked for the provider, feed and template architecture before commit.
- `plugin_getcapabilities_videos()` now declares the shared provider-neutral content/service capabilities defined by the memorandum;
- Videos exposes read-only `dashboard_summary`, `channels_read`, `rankings_read` and `provider_status` services for Agent, Eclipse, Hub and future consumers;
- the Eclipse-facing dashboard contract is provider-owned, permission-checked with `videos.admin`, and does not require Eclipse-specific code in Videos;
- Agent and Hub can reuse Item Info, collection, URL, lifecycle, syndication and specialized read services without accessing Videos JSON storage directly;
- `content.popular` is implemented through normalized local qualified-view `hits` and Item Info `order=hits-desc`;
- CI now validates the shared capability declaration and verifies that the release archive contains the interoperability services.

### P1 — shared Agent / Eclipse / Hub capabilities — completed

Videos follows the shared capability contract from the memorandum instead of exposing consumer-specific APIs.

Current declaration:

```text
roles: content, service

content.read
content.collection
content.search
content.popular
content.url.resolve
content.lifecycle
content.syndication
dashboard.summary
videos.channels.read
videos.rankings.read
videos.provider.status
```

Implementation boundaries:

- normalized content stays in `plugin_getiteminfo_videos()`;
- content identity and URLs stay in the native Item Info / URL callbacks;
- save/delete lifecycle remains provider-owned;
- `dashboard.summary` is an internal, read-only, `videos.admin`-protected service;
- channel and ranking reads are bounded and local-only;
- provider status describes availability without exposing secrets or triggering external synchronization;
- no capability requires Agent, Eclipse or Hub to be installed;
- consumers must not query Videos private JSON storage.

Future additions must be declared only when the underlying behavior exists and is testable.

### P1 — move YouTube refresh work out of visitor requests — completed

The public catalogue now follows this model:

```text
admin / maintenance
        -> Videos_ExternalSync
        -> provider factory
        -> external provider
        -> discovery reservoir / cache

visitor request
        -> local search cache
        -> compatible local cache
        -> discovery reservoir
        -> rankings / editorial corpus
```

Visitor requests no longer perform provider discovery or reservoir refresh operations.

Manual seeding and explicit synchronization remain available from administration/maintenance paths.

Benefits enforced by CI:

- predictable frontend response time;
- no accidental YouTube quota use from catalogue visitors;
- public rendering remains available during provider outages when local data exists;
- clear separation between external synchronization and public rendering.

### P1 — autoload plugin classes — completed

A PHP 5.6-compatible autoloader maps `Videos_*` classes to `classes/<ClassName>.php` using `spl_autoload_register()`.

`functions.inc` remains focused on:

- minimal bootstrap;
- configuration;
- Plugin API callbacks;
- compatibility helpers.

CI verifies that all Videos class files are actually loadable through the autoloader.

### P1 — introduce `.thtml` templates progressively — catalogue completed

The first public presentation boundary is now implemented:

```text
classes/
    Videos_TemplateRenderer.php
    Videos_CatalogueRenderer.php

templates/default/
    catalogue.thtml
    video-card.thtml
```

`public_html/index.php` prepares catalogue data but delegates presentation to `Videos_CatalogueRenderer`.

The templates are resolved through Geeklog's native `COM_newTemplate()` / `CTL_plugin_templatePath()` mechanism and therefore remain theme-overridable and compatible with Geeklog 2.1.1 through 2.2.2.

Further template extraction should be progressive and only target meaningful presentation blocks, for example:

```text
templates/
    navigation.thtml
    admin/
        page.thtml
        section.thtml
        stats-card.thtml
```

Business logic must remain in PHP.

### P1 — consolidate configuration definitions — completed

Configuration remains represented by defaults plus one declarative `videos_config_schema()`.

`plugin_initconfig_videos()` now creates the subgroup and then iterates the schema for tabs, fieldsets and values instead of duplicating the complete configuration definition manually.

CI verifies:

- schema/default key parity;
- unique settings;
- valid fieldsets and ordering;
- supported setting types;
- initial configuration creation is actually schema-driven.

This keeps the implementation PHP 5.6-compatible and avoids introducing an additional configuration framework.

### P2 — provider abstraction for external video services — completed for 0.20 scope

The external boundary is now explicit:

```text
Videos_ExternalSync
    -> Videos_ProviderFactory
        -> Videos_ProviderInterface
            search()
            videos()
            channels()
            getLastError()

Videos_YouTubeProvider
    -> Videos_YouTubeClient
```

Administration search, discovery seeding and single-video synchronization now pass through this boundary. CI fails if `admin/actions.php` directly reconstructs the legacy YouTube client/service path.

No additional providers are planned without a demonstrated use case.

### P2 — strengthen HTTP resilience — partially completed

Completed:

- TLS verification;
- bounded connect/request timeouts;
- strict YouTube API host restriction;
- redirects disabled;
- explicit response-size limit;
- structured local error state through `getLastError()`.

Still to evaluate before adding complexity:

- narrowly-scoped retry/backoff for transient 429/5xx responses;
- provider rate-limit metadata where genuinely useful.

Do not retry functional validation errors or exhausted quota conditions.

### P2 — define the boundary between JSON storage and SQL

Keep the current JsonStore for compact site-scoped state where it works well, but avoid growing it into a general database engine.

Guideline:

```text
JSON
    cache, compact state, secrets, small indexes, bounded records

SQL
    use when queries, relations, large datasets, filtering or aggregation
    begin to justify a relational store
```

No migration to SQL is required for 0.20.0 unless a concrete scaling or querying problem appears.

### P2 — focused interoperability tests — substantially completed

Behavioral coverage now includes:

- `plugin_getiteminfo_videos()` single item;
- collection `'*'` with `since`, `limit`, `modified-desc` and `created-desc`;
- field filtering;
- ID -> URL;
- URL -> ID;
- rejection of invalid IDs and external-host URLs;
- native Content Syndication using the same editorial corpus;
- moderation visibility for blocked videos;
- moderation visibility for excluded channels;
- save/delete lifecycle signaling for video block/unblock;
- lifecycle signaling and collection invalidation for channel exclusion;
- combined loading of Plugin API integration files;
- callback presence after combined loading;
- autoload coverage across the supported PHP matrix;
- external provider contract validation;
- local-only public-runtime validation;
- catalogue template/presentation-boundary validation;
- configuration schema/default consistency validation.

Remaining focused candidates:

- XMLSitemap Item Info fallback as exercised by the XMLSitemap plugin;
- search callback behavior on a local fixture;
- statistics callback behavior on a local fixture.

### P3 — feed regeneration optimization — completed

`plugin_feedupdatecheck_videos()` compares Geeklog's feed update state with the modification signature of the same public editorial corpus used by the Videos feed.

Transient discovery/cache changes do not force unnecessary feed regeneration.

### P3 — native sitemap collector only if justified

Do **not** add `plugin_collectSitemapItems_videos()` merely because the callback exists.

The current Item Info collection fallback is sufficient unless real requirements appear for:

- sitemap-specific filtering;
- large-corpus performance;
- custom priorities;
- sitemap-only permission logic.

If those needs appear, the specialized collector can be added later while reusing the same underlying content inventory logic.

### P3 — specialized services only for real actions

Do not create Connector-specific or AtomPub service callbacks simply to advertise compatibility.

Existing capabilities should remain discoverable through normal Geeklog Plugin APIs where possible.

Add services only for genuine specialized actions, for example future authorized operations such as:

- add a video to the permanent catalogue;
- block/unblock a video;
- prioritize a channel.

Such actions must preserve Geeklog ACL checks, security tokens or equivalent authorization, and auditability.

## Architecture principles to preserve

Videos should continue to follow these rules throughout both releases:

1. **Site-scoped state** — derive persistent storage and configuration from the active Geeklog site context.
2. **Shared-files safe upgrades** — deploying new plugin files must not silently migrate every site that shares those files.
3. **Structured interoperability first** — Item Info, lifecycle events and URL resolution remain the primary common contract.
4. **Local rendering first** — public rendering must work from local state without depending on live provider availability.
5. **No unnecessary duplication of Geeklog Core** — reuse search, statistics, syndication, sitemap and Plugin API mechanisms before inventing plugin-specific alternatives.
6. **Provider-specific code stays isolated** — YouTube details should not leak throughout the plugin business model.
7. **Persistent data is not cache** — cache cleanup must never erase editorial, moderation, privacy or user-owned state.
8. **PHP 5.6–8.1 compatibility remains intentional** until the project explicitly changes policy.
9. **Theme independence** — Eclipse may be a reference presentation environment, but Videos must not depend on Eclipse-specific markup or behavior.
10. **Simplify before extending** — future capabilities should reduce coupling or provide demonstrated user value.

## Not planned for 0.20.0 unless requirements change

The following are intentionally not priorities:

- replacing JsonStore with SQL without a measured need;
- adding multiple video providers without a real use case;
- building a plugin-specific REST API before a shared Geeklog resource layer exists;
- duplicating XMLSitemap logic while Item Info fallback remains sufficient;
- adding additional SEO layers that overlap Hub, IndexNow, Analytics or search-engine measurement plugins;
- adding new recommendation systems before the existing architecture is consolidated.

## Release philosophy

### 0.19.0

**Stabilize what already exists.**

### 0.20.0

**Make the same plugin easier to understand and maintain.**

The release should reduce runtime coupling, move presentation toward templates, isolate external providers and make synchronization explicit rather than adding another large feature layer.
