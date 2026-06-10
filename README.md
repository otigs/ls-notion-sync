# ls-notion-sync

WordPress mu-plugin connecting the Lightly Salted site to Notion. Two
responsibilities:

1. **Status push (v1.0)** — post status transitions are pushed to the Notion
   page's Status property in the website content database, via Action
   Scheduler (`src/StatusListener.php`, `src/NotionClient.php`). Configured
   in Theme Settings → Integrations.
2. **Content export API (v1.1)** — authenticated REST endpoints consumed by
   the "WP Content Sync" Notion Worker, which mirrors all CPTs (ACF fields +
   Yoast meta) into Notion databases.

## Content export API

Namespace `ls-notion/v1`. Auth: WordPress Application Passwords over HTTPS
basic auth; the user needs `edit_others_posts` (Editor) because the feed
includes drafts/private/pending content.

### `GET /content/{post_type}`

`post_type` ∈ `post|service|case_study|area|testimonial|faq`.

Query params:

| Param | Meaning |
|---|---|
| `modified_after` | GMT ISO 8601; inclusive lower bound on `post_modified_gmt` |
| `page` | 1-based page (modified ASC, ID ASC) |
| `per_page` | default 20, max 50 |

Returns `{items, page, per_page, total, total_pages, has_more, server_time_gmt}`.
Each item: core fields, `permalink` (null for internal CPTs), `featured_image`,
`taxonomies` (posts only), `yoast` (`_yoast_wpseo_title|metadesc|focuskw`),
and `acf` — every ACF field with relationship/post_object values normalised
to integer post IDs and image arrays reduced to `{id, url, alt}`
(`src/Rest/PostSerializer.php`). `page_builder` (posts) and `geojson_data`
(areas) are excluded; filterable via `ls_notion_content_excluded_acf_fields`.

Statuses include `trash` deliberately — trashing bumps `post_modified`, so
the worker's delta cursor picks trashed posts up as status changes.

### `GET /deletions`

Permanent deletions recorded by `src/DeletionLog.php` (hooked to
`before_delete_post`, option `ls_notion_deletions`, capped at 1000 entries /
90 days). Params: `since` (inclusive GMT ISO), `post_type`. Returns
`{items: [{post_id, post_type, deleted_at_gmt}], server_time_gmt}`.

### Setup

1. Create a dedicated user (e.g. `notion-worker`, Editor role) and generate
   an Application Password on their profile page.
2. Smoke test:
   ```shell
   curl -u 'notion-worker:xxxx xxxx xxxx xxxx xxxx xxxx' \
     'https://lightlysalted.agency/wp-json/ls-notion/v1/content/testimonial?per_page=2'
   ```

## Install

```shell
composer install   # in mu-plugins/notion-sync/
```

`ls-notion-sync.php` is the mu-plugin loader; it requires
`notion-sync/vendor/autoload.php` and `notion-sync/bootstrap.php`.
