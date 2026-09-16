# Videos 0.20.0 release validation

This document separates checks enforced automatically by CI from checks that must still be performed on real Geeklog installations before 0.20.0 is marked stable.

Supported release target:

- Geeklog 2.1.1 through 2.2.2
- PHP 5.6 through 8.1

## Automated CI checks

Both workflows must be green before release:

- `Validate Videos release matrix`
- `Build installable Videos archive`

### PHP compatibility

The complete PHP/INC runtime tree is linted on:

- PHP 5.6
- PHP 7.4
- PHP 8.1

The same matrix also executes the main behavioral contracts.

### Storage migration

`tools/test-storage-migration.php` verifies:

- legacy storage remains active during ordinary runtime bootstrap;
- migration only happens after `migrateLegacyStorage()` is explicitly called;
- secrets and persistent data survive migration;
- the legacy source is preserved;
- a migration marker is written;
- retrying migration is safe;
- subsequent requests select the migrated root;
- two sites sharing plugin files derive isolated storage roots;
- custom storage inside `path_data` is rejected;
- an unusable preferred destination falls back to readable legacy storage;
- a valid absolute custom persistent root is accepted.

### Autoload and configuration

CI verifies:

- every `classes/Videos_*.php` class/interface resolves through the PHP 5.6-compatible autoloader;
- configuration defaults and `videos_config_schema()` contain the same keys;
- configuration keys and order slots are unique;
- schema fieldsets and types are valid;
- initial `plugin_initconfig_videos()` creation is driven by the declarative schema rather than a duplicated manual definition list.

### Public runtime / provider isolation

CI verifies:

- public catalogue requests do not construct `Videos_YouTubeClient`, `Videos_YouTubeService` or external providers;
- public rendering does not call discovery `refresh()`;
- catalogue rendering can fall back through local exact/stale cache, compatible cache and discovery reservoir;
- administration external synchronization passes through `Videos_ExternalSync` / `Videos_ProviderFactory`;
- the provider contract exposes `search()`, `videos()`, `channels()` and `getLastError()`.

### Presentation boundary

CI verifies:

- `Videos_TemplateRenderer` and `Videos_CatalogueRenderer` are autoloadable;
- `catalogue.thtml` and `video-card.thtml` are present;
- `public_html/index.php` delegates catalogue presentation instead of rebuilding video-card markup inline;
- the installable archive contains the renderer and templates.

### Geeklog integration contracts

CI loads the integration files together and verifies the native callbacks, including:

- Search API;
- statistics summary;
- Item Info;
- ID -> URL and URL -> ID;
- Content Syndication declaration/content callback;
- `plugin_feedupdatecheck_videos()`;
- autotag support;
- save/delete lifecycle integration.

Behavioral coverage includes:

- ID -> URL -> ID round trips for videos, channels, catalogue and rankings;
- invalid ID rejection and foreign-host URL rejection;
- Item Info single-item retrieval;
- Item Info field filtering;
- collection `'*'` with `since`, `limit`, `modified-desc` and `created-desc`;
- Content Syndication generated from the same local editorial corpus;
- blocked videos omitted from Item Info and feeds;
- excluded-channel videos omitted from Item Info and feeds;
- `PLG_itemSaved()` / `PLG_itemDeleted()` lifecycle signals on video block/unblock and channel exclusion;
- Geeklog Search API behavior on local data, including phrase/all matching, title-only mode and date filtering;
- statistics summary count from the moderated public local inventory;
- XMLSitemap's native Item Info fallback contract using `url,date-modified`.

### HTTP safety

The external YouTube transport keeps:

- HTTPS-only Google YouTube API host restriction;
- TLS peer/host verification;
- redirects disabled;
- bounded connect/request timeouts;
- bounded response size;
- structured local error state.

0.20.0 intentionally does not add blind automatic retries for quota/429 errors. Retry/backoff may be added later only with a transport-level testable policy that distinguishes transient 5xx/network failures from quota and validation errors.

### Install/uninstall consistency

The features declared at installation must exactly match the features removed by auto-uninstall.

### Administration languages

All semantic `admin_*` language keys referenced by the administration pages must exist in both English and French.

## Manual Geeklog integration matrix

These rows must be tested with the generated `dist/videos_0.20.0_2.1.1.zip` archive.

| Scenario | Geeklog | PHP | Expected result | Status |
| --- | --- | --- | --- | --- |
| Fresh install | 2.1.1 | 5.6 | Plugin installs and admin/public pages load | Pending |
| Fresh install | 2.2.2 | 8.1 | Plugin installs and admin/public pages load | Pending |
| Upgrade from Videos 0.19.0 | 2.1.1 | 5.6 | Upgrade reaches 0.20.0 and preserves data/configuration | Pending |
| Upgrade from Videos 0.19.0 | 2.2.2 | 8.1 | Upgrade reaches 0.20.0 and preserves data/configuration | Pending |
| Shared-files multisite | 2.1.1 | 5.6 | Sites remain isolated and public runtime remains local-only | Pending |
| Shared-files multisite | 2.2.2 | 8.1 | Separate `path_data` sites remain isolated | Pending |

## Functional checks on each reference installation

### Administration

- Overview, Actions, Statistics and Moderation use the same shell and navigation.
- English installation displays English strings only.
- French installation displays French strings only.
- Configuration tabs and values are present after fresh install and upgrade.
- Configuration tooltips wrap correctly.
- No raw `text_xxx` key appears in the UI.
- Search test, discovery seed and single-video synchronization actions work through the external synchronization boundary.

### Public catalogue and templates

- Catalogue loads without a live YouTube request.
- Existing local catalogue/cache content remains available during provider failure or missing API key.
- `catalogue.thtml` renders the catalogue envelope.
- `video-card.thtml` renders individual cards.
- theme override of the plugin templates works through Geeklog's normal template resolution.
- video page, channels and rankings pages load when enabled.
- responsive CSS remains usable on a narrow viewport.

### Autotag

- `[videos:VIDEO_ID]` renders only for a valid public retained video.
- player mode renders the configured/privacy-enhanced YouTube embed.
- autotag CSS is loaded only when an autotag is actually rendered.

### Dynamic block

- Videos block renders on the configured side.
- block CSS is loaded only when content is produced.
- play button is centered over the thumbnail.
- unavailable, blocked and excluded items are not shown.

### Search and statistics

- Videos appears in Geeklog advanced search.
- search uses only local plugin data.
- search results link to valid local Videos URLs.
- blocked/excluded items do not appear.
- site statistics calls the Videos summary without warnings.
- detailed Videos statistics page renders when ranking data exists.

### Content Syndication

- Videos is available as a native feed source.
- generated RSS/Atom entries come from the permanent editorial catalogue.
- feed generation does not consume YouTube quota.
- feed limit and content length settings are respected.
- blocking a public video removes it from subsequent feed output.

### XMLSitemap

Geeklog XMLSitemap 2.1.1+ first tries the optional sitemap collector and, when Videos has no dedicated collector, falls back to Item Info collection with `url,date-modified`.

Validate that:

- Videos can be enabled as an XMLSitemap content type;
- permanent public videos generate valid local URLs;
- `date-modified` is accepted by XMLSitemap;
- blocked/excluded/unavailable videos are omitted;
- no dedicated `plugin_collectSitemapItems_videos()` is required for 0.20.0.

### Lifecycle / downstream interoperability

- adding/re-admitting a retained public video emits save lifecycle events;
- removing/excluding a public video emits delete lifecycle events;
- blocking/unblocking a published video updates downstream consumers;
- excluding a channel removes affected video items and invalidates catalogue/channel/ranking collections;
- re-enabling content makes it discoverable again without plugin-specific polling.

## Release gate

0.20.0 can move from `development` to `stable` when:

1. `Build installable Videos archive` is green on the final candidate commit;
2. `Validate Videos release matrix` is green on the final candidate commit;
3. all six real-installation rows above are recorded as Passed;
4. catalogue template rendering has been visually checked on both Geeklog 2.1.1 and 2.2.2;
5. a 0.19.0 -> 0.20.0 upgrade has preserved configuration and persistent data on both reference versions;
6. no release-blocking regression remains in storage, administration, public rendering, search, syndication, sitemap fallback or lifecycle interoperability.

Until those manual checks are completed, `VIDEOS_RELEASE_STATUS` should remain `development`.
