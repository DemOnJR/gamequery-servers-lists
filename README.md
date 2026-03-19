# GameQuery Server Lists

WPGS is a WordPress plugin for embedding GameQuery API server lists with shortcodes.

## Features (MVP)

- Sidebar top-level admin entry: `WPGS`
- Global settings:
  - One-click secure account connect (popup key selector)
  - API Email
  - API Token
  - Plan (auto-detected from connected API key)
  - Account Base URL (advanced)
  - API Base URL
  - Cache TTL
- Lists managed as a native-style custom post type (`WPGS Lists`)
- Multi-game list support (`game_id` + `servers[]` groups)
- Dedicated visual template card above Server Groups (search + category + tags filters)
- Live template preview in admin (updates immediately when selecting a template)
- Per-list templates:
  - Table styles: `Classic`, `Compact`, `Minimal`, `Esports Table`, `Slate Table`, `Terminal Table`
  - Card themes: `Clean`, `Dark`, `Accent`, `Glass`, `Cyber`, `Warm`, `Outlined`, `Frosted`
- Shortcodes:
  - `[gamequery_123]`
  - `[gamequery id="123"]`
- Built-in analytics for each list (views/clicks + unique counts)
- WPGS Stats page with per-list performance table
  - Status filter + search
  - Row actions (open report, edit, trash)
  - Bulk move to trash
- WP-Cron background refresh + transient caching
- Quota-aware warnings for FREE plan
- Connect security hardening:
  - Ownership confirmation before approving a plugin connection
  - Rate-limited connect session creation, polling, and code exchange
  - Connection audit events + security notification/email on completed connect
- Plan safety hardening:
  - Plan cannot be manually changed in settings
  - If existing settings have a stale plan, the plugin auto-detects and fixes it on API fetch

## Install (repo-local)

Plugin path:

`deploy/wordpress/plugins/gamequery-servers-lists/`

Copy/sync the plugin folder into your WordPress plugins directory, then activate it from WP Admin.

## Usage

1. Open **WPGS -> Settings** and click **Connect with GameQuery** to select an API key (manual credentials are still available as fallback).
2. Open **WPGS -> Lists** and create a list.
3. Add one or more groups (`game_id` + servers).
4. Publish the list.
5. Embed in posts/pages:

```text
[gamequery_123]
```

or

```text
[gamequery id="123"]
```

## Notes

- For FREE plan, keep TTL at 60s or higher to align with the daily 1,440 request limit.
- Per API request limit is 1,000 servers per list payload.
- If cache is empty, shortcode falls back to a live API call and then caches the result.
- View counters are tracked per page load where a list is rendered.
- Click counters are tracked when visitors click rows in a rendered server table.
- Unique counters are deduplicated per visitor for 24 hours.

## License

This project is licensed under GNU GPL v2 or later (`GPL-2.0-or-later`).
See `LICENSE` for the full text.
