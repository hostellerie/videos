# Videos plugin for Geeklog

Development version: **0.20.0**

Videos is a Geeklog plugin that builds and maintains a public video catalogue from YouTube Data API v3 while keeping editorial control, local ratings, recommendations, moderation, SEO metadata and persistent JSON data on the Geeklog site.

## Compatibility

- Geeklog **2.1.1 through 2.2.2**
- PHP **5.6 through 8.1**, using PHP 5.6-compatible syntax
- YouTube Data API v3
- No plugin-owned database table
- Persistent plugin data stored outside Geeklog `path_data` since Videos 0.17.1

## Videos 0.20.0

Videos 0.20.0 consolidates the plugin architecture while preserving Geeklog 2.1.1–2.2.2 and PHP 5.6–8.1 compatibility.

Main changes include:

- PHP 5.6-compatible class autoloading;
- provider abstraction for explicit YouTube synchronization;
- local-only public catalogue rendering with no visitor-triggered provider calls;
- native Geeklog templates for the public catalogue;
- schema-driven configuration creation;
- strengthened storage, lifecycle, search, sitemap and syndication validation;
- shared provider-neutral capability discovery for Agent, Hub, Eclipse and future consumers;
- read-only `dashboard.summary`, channel, ranking and provider-status services;
- CI validation of the shared interoperability contract and release archive.

The shared capability declaration currently advertises:

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

Videos implements `content.popular` through the shared Item Info contract. The normalized `hits` field maps to Videos' local qualified view count, and collections support `order=hits-desc`. Dedicated video/channel ranking services remain separate because they use richer scoring signals than simple popularity.

## Videos 0.19.0

Videos 0.19.0 consolidates the public catalogue, administration, Geeklog interoperability and packaging work introduced in 0.18.0, and improves presentation and asset loading.

### Conditional CSS loading

The plugin now separates its styles according to where they are actually needed:

- `videos.css` is loaded only on public `/videos/` pages;
- `autotag.css` is loaded only when a valid `[videos:...]` autotag is rendered;
- `block.css` is loaded only when the Videos dynamic block produces content;
- `admin.css` is loaded only in `/admin/plugins/videos/`.

This keeps normal Geeklog pages lighter and avoids loading the full Videos stylesheet when the plugin is not displayed.

### Video thumbnails and play affordance

Video thumbnails now use a centered play icon inspired by familiar video interfaces. The overlay is generated with CSS and does not require an additional image asset.

The play affordance is used on relevant catalogue, autotag, block, ranking and history thumbnails, with hover/focus feedback to make playable content easier to identify.

### Administration layout

The main Videos administration overview now follows the standard Geeklog administration presentation using `block-center`, `block-title` and `block-content` containers.

Administration remains separated into focused areas:

- **Overview** for the general state of the plugin;
- **Actions** for curation, YouTube API operations, discovery, maintenance and indexing actions;
- **Statistics** for reservoir, rankings, permanent catalogue, quota, cache and SEO diagnostics;
- **Moderation** for video and channel decisions.

### Installable archive

The `0.19.0` branch includes a GitHub Actions workflow that builds the installable archive:

```text
videos_0.19.0_2.1.1.zip
```

The generated archive is stored under `dist/` and is validated before being committed. The workflow:

- runs PHP syntax checks;
- builds the standard `videos/` plugin directory inside the ZIP;
- verifies ZIP integrity;
- verifies the required CSS files are included;
- rejects unsafe archive filenames;
- keeps the package compatible with Geeklog installation requirements.

### Upgrade path

Videos 0.19.0 supports upgrades from 0.17.1 through the explicit migration chain:

```text
0.17.1 -> 0.18.0 -> 0.19.0
```

The 0.18.0 to 0.19.0 step does not require an SQL or configuration migration because 0.19.0 changes PHP/CSS integration and packaging only.

Earlier migration steps remain available in `install_updates.php`, allowing Geeklog to follow the complete supported upgrade chain.

## Editorial curation

Videos can operate as an automated discovery engine while also giving administrators direct editorial control over the catalogue.

Administrators can:

- add a video directly from a YouTube ID or URL;
- keep a video in the permanent catalogue;
- pin or unpin a remarkable video;
- remove a video from the permanent catalogue;
- exclude a video from the permanent pool or allow it again;
- classify a channel as neutral, allowed, priority, blocked or disabled;
- seed and refresh the discovery reservoir;
- rebuild local video and channel rankings;
- manage cache and maintenance operations.

Adding a video manually retrieves its YouTube metadata, verifies that it is public and embeddable, applies the active video policy, stores the result in the local cache and adds the video to the permanent catalogue.

## Public catalogue

Videos maintains a paginated public catalogue backed by a bounded discovery reservoir and local ranking signals.

The catalogue can combine:

- YouTube search relevance;
- local ratings;
- qualified local views;
- watch completion ratio;
- recent local activity;
- publication recency;
- priority channels;
- permanent catalogue entries;
- pinned editorial selections;
- deterministic rotation and channel diversity rules.

Blocked, disabled, unavailable and policy-excluded content is filtered before display.

## Permanent catalogue

The permanent catalogue gives selected videos a durable local presence independent of normal discovery rotation.

A video may be:

- automatically admitted according to rating rules;
- manually added by an administrator;
- pinned as a stronger editorial selection;
- removed;
- excluded from automatic readmission;
- restored later.

Permanent and pinned decisions can generate Geeklog lifecycle signals for compatible consumer plugins.

## Rankings and channel pages

Videos maintains bounded local rankings for videos and channels using local engagement data already collected by the plugin.

The video score can combine Bayesian rating, rating confidence, qualified views, watch ratio, activity recency and publication recency. Channel rankings are derived from the ranked video corpus without requiring an additional YouTube API search.

Eligible remarkable or priority channels can have their own local public page containing notable videos from that channel.

## Recommendations

Next-video recommendations use the originating search context when available and fall back to the local global ranking when necessary.

Recommendations respect:

- the current video exclusion;
- registered-user history;
- anonymous recent-history rules when enabled;
- channel diversity;
- moderation decisions;
- unavailable-video status;
- the configured Shorts policy.

Suggestions do not trigger extra YouTube API calls merely because the page is rendered.

## Local ratings and qualified views

Videos records local engagement without relying on YouTube view statistics as its primary recommendation signal.

The plugin supports:

- pseudonymous qualified views;
- local 1-to-5 ratings;
- aggregate rating statistics;
- playback completion refinement;
- registered-user viewing history;
- deletion of personal ratings and account-linked plugin data where configured.

## Moderation

Moderators can act on individual videos and channels.

Videos can block a video and classify channels as allowed, priority, blocked, disabled or neutral. These decisions are stored in protected JSON data and are applied to catalogue selection, recommendations, rankings, blocks and public playback.

Quick editorial actions are also available from individual video pages for authorized administrators.

## Geeklog block

The optional dynamic Geeklog block can display:

- recommended videos;
- top-rated videos;
- most-watched videos;
- recently active videos;
- random videos;
- best local channels;
- a random choice among the available block modes.

The block reads local rankings and cache data without requiring a YouTube API call during normal rendering.

## SEO

Videos generates server-side SEO metadata for its public pages.

Features include:

- canonical URLs;
- index/noindex robots directives;
- page-specific meta descriptions;
- Open Graph metadata;
- Twitter Card metadata;
- descriptive image alternative text;
- `VideoObject` structured data for video pages;
- page-aware titles and descriptions for paginated catalogues;
- canonical identities for catalogue, ranking, channel and video resources;
- local internal linking between rankings, channels and videos;
- visible FAQ sections with optional matching `FAQPage` structured data.

Individual video pages prefer video-specific descriptions rather than reusing one global fallback description across many URLs.

## Geeklog interoperability

Videos exposes its public content through generic Geeklog-compatible identities so other plugins do not need to understand its JSON storage or YouTube implementation.

Supported identities include:

- `videos / catalogue`
- `videos / rankings:videos`
- `videos / rankings:channels`
- `videos / channel:UC...`
- `videos / <YouTube video ID>`

The plugin provides:

- `plugin_getiteminfo_videos()` for structured content metadata;
- collection access with `id='*'`;
- canonical URL resolution through `plugin_idtourl_videos()`;
- reverse URL resolution through `plugin_urltoid_videos()`;
- Videos autotags;
- Geeklog lifecycle events for meaningful editorial changes.

This allows compatible plugins such as Hello, Hub or IndexNow to consume Videos content without Videos-specific SQL or routing logic.

Videos also exposes `plugin_getcapabilities_videos()` and provider-owned read-only services through Geeklog's service layer. Agent can discover and normalize Videos resources, Eclipse can request `dashboard.summary` without querying Videos storage, and Hub can reuse the same content, lifecycle, channel and ranking contracts without creating a Hub-specific API.

## Autotags

Videos provides its own autotag namespace to avoid collisions with other Geeklog plugins:

```text
[videos:VIDEO_ID]
[videos:VIDEO_ID player]
```

The default form renders a thumbnail card linking to the local canonical Videos page. The `player` variant renders a responsive privacy-enhanced YouTube player while continuing to use local cached metadata.

Autotag styles are loaded only when a valid Videos autotag is actually rendered.

## Persistent JSON storage

Persistent plugin data is stored beside Geeklog `path_data` rather than inside it. For example:

```text
path_data = /private/data/S1/
Videos    = /private/data/S1-videos/
```

A site may define an absolute custom location before Geeklog loads the plugin:

```php
$_CONF['videos_data_path'] = '/private/persistent/S1/videos/';
```

Relative paths, parent traversal and locations inside `path_data` are rejected. Legacy `path_data/videos/` data is migrated conservatively and retained as a recovery copy.

## Main capabilities

- public paginated video catalogue;
- YouTube Data API v3 search and bounded discovery reservoir;
- direct editorial addition of YouTube videos;
- quota and cache management;
- privacy-enhanced YouTube player support;
- qualified local views and ratings;
- registered-user history and privacy controls;
- global video and channel rankings;
- local pages for remarkable channels;
- next-video recommendations;
- video and channel moderation;
- channel priority decisions;
- bounded permanent catalogue pool;
- pinned editorial videos;
- Geeklog dynamic video block;
- SEO metadata and structured data;
- FAQ areas;
- content interoperability and canonical URL resolution;
- Videos autotags;
- Hello-compatible content collections;
- IndexNow-compatible lifecycle signaling;
- conditional front-end and administration CSS loading;
- GitHub-generated installable distribution archive.

## Installation

For Geeklog 2.1.1 and later, use the generated archive:

```text
dist/videos_0.19.0_2.1.1.zip
```

Install or upgrade it using Geeklog's plugin administration interface.

Before any production upgrade, keep a backup of the Geeklog database, plugin files and the external Videos persistent-data directory.
