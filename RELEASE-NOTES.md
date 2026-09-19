# Videos 0.20.0 release notes

Videos 0.20.0 is an architectural consolidation release focused on predictable public rendering, provider-neutral interoperability and safer long-term maintenance while preserving the transition compatibility target of Geeklog 2.1.1–2.2.2 and PHP 5.6–8.1.

## Highlights

- Added PHP 5.6-compatible class autoloading and reduced eager class loading.
- Isolated external video synchronization behind a provider contract and `Videos_ProviderFactory`.
- Kept public catalogue rendering local-only: visitor requests do not trigger YouTube discovery or synchronization.
- Moved the public catalogue to Geeklog-native overridable templates.
- Consolidated initial plugin configuration around `videos_config_schema()`.
- Strengthened HTTP transport limits, storage migration safety and shared-files multisite isolation.
- Expanded automated coverage for Item Info, search, statistics, syndication, XMLSitemap fallback and lifecycle events.
- Added shared provider-neutral capability discovery for Agent, Eclipse, Hub and future integrations.
- Added read-only internal services for dashboard summaries, channels, rankings and provider status.
- Updated the installable package workflow to validate the new interoperability layer.

## Shared interoperability

Videos now declares:

```text
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

The plugin remains the owner of its data and business rules:

- Agent can consume normalized content and specialized reads without parsing presentation HTML.
- Eclipse can consume `dashboard.summary` without reading Videos JSON storage.
- Hub can reuse the same content identity, collection, lifecycle and specialized read contracts.
- No consumer-specific dependency is introduced.
- Public rendering and shared read services do not trigger external YouTube synchronization.
- Dashboard data remains permission-checked with `videos.admin`.

`content.popular` is implemented in 0.20.0. Item Info exposes normalized `hits` from Videos' local qualified-view counter and supports `order=hits-desc`. Dedicated local video/channel rankings remain available through `videos.rankings.read` because ranking score and popularity are intentionally distinct concepts.

## Packaging

The release archive is:

```text
videos_0.20.0_2.1.1.zip
```

The GitHub Actions package workflow rebuilds and validates the archive from the branch source.

## Upgrade

The maintained upgrade chain is:

```text
0.17.1 -> 0.18.0 -> 0.19.0 -> 0.20.0
```

Persistent storage migration remains explicit, site-scoped, restartable and non-destructive. Ordinary runtime bootstrap does not move persistent data. The `0.19.0 -> 0.20.0` step is explicitly registered and intentionally performs no data/configuration migration.

## Release gate

Before tagging 0.20.0 stable:

- both GitHub Actions workflows must be green on the final commit;
- fresh install must pass on Geeklog 2.1.1/PHP 5.6 and Geeklog 2.2.2/PHP 8.1;
- upgrade from 0.19.0 must pass on both reference stacks;
- shared-files multisite isolation must pass on both reference stacks;
- Agent/Eclipse/Hub interoperability services must be smoke-tested on a real Geeklog installation;
- `VIDEOS_RELEASE_STATUS` must then be changed from `development` to `stable` and the final archive rebuilt.
