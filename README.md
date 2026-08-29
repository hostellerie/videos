# Videos plugin for Geeklog

Development version: **0.18.0**

Videos is a Geeklog plugin that builds and maintains a public video catalogue from YouTube Data API v3 while keeping editorial control, local ratings, recommendations, moderation, SEO metadata and persistent JSON data on the Geeklog site.

## Compatibility

- Geeklog **2.1.1 through 2.2.2**
- PHP **5.6 through 8.1**, using PHP 5.6-compatible syntax
- YouTube Data API v3
- No plugin-owned database table
- Persistent plugin data stored outside Geeklog `path_data` since Videos 0.17.1

## Installable package

The branch includes a GitHub Actions workflow at `.github/workflows/build-dist.yml` that builds an installable Geeklog archive:

`dist/videos_0.1.8_2.2.2.zip`

The ZIP contains a single top-level `videos/` directory with the plugin files and excludes repository metadata, GitHub workflow files, `dist/`, temporary build files, editor files, environment files and local runtime files.

The workflow runs automatically on changes pushed to the `0.18.0` branch, except commits that only change `dist/`. It can also be started manually from **GitHub → Actions → Build installable Videos archive → Run workflow**.

After building the ZIP, the workflow validates it with `unzip -t` and commits the resulting archive back into `dist/` when its contents have changed.

> The plugin code currently reports version **0.18.0**. The distribution filename above is intentionally the filename currently used for this development package.

## Installation

1. Download `dist/videos_0.1.8_2.2.2.zip` from the `0.18.0` branch once the GitHub Action has completed.
2. Install or upload the archive using the normal Geeklog plugin installation procedure.
3. Open the Videos administration page.
4. Configure the YouTube Data API key.
5. Review the catalogue, moderation, SEO and discovery settings before enabling the public catalogue on a production site.

Existing installations can use the normal Geeklog plugin upgrade mechanism. The historical update chain is preserved, including the `0.17.1 → 0.18.0` transition.

## Videos 0.18.0 development work

The 0.18.0 branch extends the plugin around three areas: editorial curation, SEO and Geeklog interoperability.

### Editorial administration

Administration is separated into focused screens:

- **Overview** for the general state of the plugin;
- **Actions** for curation, YouTube API operations, discovery, maintenance and IndexNow synchronization;
- **Statistics** for reservoir, rankings, permanent catalogue, quota, cache and SEO diagnostics;
- **Moderation** for video and channel decisions.

Administrators can add a video directly from a YouTube ID or URL, keep it in the permanent catalogue, pin or unpin it, remove it, exclude it from the permanent pool or allow it again. Channel decisions support neutral, allowed, priority, blocked and disabled states.

### SEO

Public pages keep server-side canonical URLs, robots directives, Open Graph, Twitter metadata and `VideoObject` structured data where appropriate.

The 0.18.0 work also adds or improves:

- descriptive `alt` text on video thumbnails in catalogue, rankings, recommendations and Geeklog blocks;
- unique fallback descriptions for individual video pages;
- page-aware catalogue titles and descriptions;
- local channel pages for eligible channels;
- local internal links from channel rankings to channel pages;
- canonical identities for catalogue, rankings, videos and channels.

### Geeklog interoperability

Videos now exposes generic content identities so other Geeklog plugins do not need to know its JSON storage or YouTube implementation.

Supported identities include:

- `videos / catalogue`
- `videos / rankings:videos`
- `videos / rankings:channels`
- `videos / channel:UC...`
- `videos / <YouTube video ID>`

The plugin provides content metadata through `plugin_getiteminfo_videos()`, canonical URL resolution through `plugin_idtourl_videos()`, reverse URL resolution through `plugin_urltoid_videos()`, and Videos autotags.

Editorial lifecycle changes can emit Geeklog item events. This allows generic consumers such as Hello, Hub or IndexNow to react without Videos-specific SQL or routing code.

For an initial indexing catch-up, the administration builds the complete list of public Videos URLs and uses IndexNow batch submission when available. It deliberately does not generate hundreds of fake item-created events, so Hello and other content consumers do not mistake an indexing catch-up for newly published content.

## Permanent JSON storage

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
- quota and cache management;
- privacy-enhanced YouTube player support;
- qualified local views and ratings;
- registered-user history and privacy controls;
- global video and channel rankings;
- next-video recommendations;
- moderation and channel priority decisions;
- bounded permanent catalogue pool;
- Geeklog dynamic video block;
- SEO metadata and structured data;
- FAQ areas;
- content interoperability, canonical URL resolution and autotags;
- IndexNow-compatible lifecycle signaling.

## Historical release notes

The original detailed release history from Videos 0.1.x through 0.17.1 is retained in the repository file [`README`](README).
