# Videos plugin for Geeklog

Development version: **0.18.0**

Videos is a Geeklog plugin that builds and maintains a public video catalogue from YouTube Data API v3 while keeping editorial control, local ratings, recommendations, moderation, SEO metadata and persistent JSON data on the Geeklog site.

## Compatibility

- Geeklog **2.1.1 through 2.2.2**
- PHP **5.6 through 8.1**, using PHP 5.6-compatible syntax
- YouTube Data API v3
- No plugin-owned database table
- Persistent plugin data stored outside Geeklog `path_data` since Videos 0.17.1

## Videos 0.18.0

The 0.18.0 branch extends the plugin around three major areas: editorial curation, SEO and Geeklog interoperability.

### Editorial curation

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

### Administration

Administration is separated into focused areas:

- **Overview** for the general state of the plugin;
- **Actions** for curation, YouTube API operations, discovery, maintenance and indexing actions;
- **Statistics** for reservoir, rankings, permanent catalogue, quota, cache and SEO diagnostics;
- **Moderation** for video and channel decisions.

This keeps editorial actions separate from technical and statistical information.

### Public catalogue

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

### Permanent catalogue

The permanent catalogue gives selected videos a durable local presence independent of normal discovery rotation.

A video may be:

- automatically admitted according to rating rules;
- manually added by an administrator;
- pinned as a stronger editorial selection;
- removed;
- excluded from automatic readmission;
- restored later.

Permanent and pinned decisions can generate Geeklog lifecycle signals for compatible consumer plugins.

### Global video ranking

Videos maintains a bounded local ranking of remarkable videos using local engagement data already collected by the plugin.

The score combines Bayesian rating, rating confidence, qualified views, watch ratio, activity recency and publication recency. Rebuilding a ranking does not require an additional YouTube API search.

Changes to the visible ranking can signal the corresponding public ranking page without generating events when the public order has not changed.

### Channel ranking and local channel pages

Videos derives a local channel ranking from the ranked video corpus.

Eligible remarkable or priority channels can have their own local public page containing notable videos from that channel. The public channel ranking links to these local pages, creating stronger internal navigation between:

- the video catalogue;
- video rankings;
- channel rankings;
- channel pages;
- individual video pages.

Channel priority and moderation decisions can also update the affected public identities.

### Recommendations

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

### Local ratings and qualified views

Videos records local engagement without relying on YouTube view statistics as its primary recommendation signal.

The plugin supports:

- pseudonymous qualified views;
- local 1-to-5 ratings;
- aggregate rating statistics;
- playback completion refinement;
- registered-user viewing history;
- deletion of personal ratings and account-linked plugin data where configured.

### Moderation

Moderators can act on individual videos and channels.

Videos can block a video and classify channels as allowed, priority, blocked, disabled or neutral. These decisions are stored in protected JSON data and are applied to catalogue selection, recommendations, rankings, blocks and public playback.

Quick editorial actions are also available from individual video pages for authorized administrators.

### Geeklog block

The optional dynamic Geeklog block can display:

- recommended videos;
- top-rated videos;
- most-watched videos;
- recently active videos;
- random videos;
- best local channels;
- a random choice among the available block modes.

Video thumbnails use descriptive `alt` text and the block reads local rankings and cache data without requiring a YouTube API call during normal rendering.

### SEO

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

### Geeklog interoperability

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

### IndexNow compatibility

Meaningful editorial changes can be announced through normal Geeklog lifecycle events. Videos exposes deterministic canonical URLs so IndexNow can resolve the affected resource generically.

Videos can also enumerate the public URLs already represented by its catalogue, global ranking, permanent catalogue, discovery reservoir and eligible channel pages for an initial indexing catch-up.

Technical cache refreshes and unchanged ranking recalculations are deliberately not treated as new content publications.

### Autotags

Videos provides its own autotag namespace to avoid collisions with other Geeklog plugins:

```text
[videos:VIDEO_ID]
[videos:VIDEO_ID player]
```

The default form links to the local canonical Videos page. The `player` variant can render the embedded player while continuing to use the local cached metadata.

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
- IndexNow-compatible lifecycle signaling.

## Historical release notes

The detailed release history from earlier Videos versions through 0.17.1 is retained in the repository file [`README`](README).
