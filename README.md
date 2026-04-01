# GameQuery Server Lists

A WordPress plugin for building, embedding, and promoting GameQuery-powered game server lists with shortcodes, templates, and analytics.

![GameQuery Server Lists main preview](https://i.imgur.com/q8doEum.png)

## Gallery

![GameQuery Server Lists screenshot 1](https://i.imgur.com/rw32f1F.png)
![GameQuery Server Lists screenshot 2](https://i.imgur.com/GzvvM71.png)
![GameQuery Server Lists screenshot 3](https://i.imgur.com/oPiS3y3.png)
![GameQuery Server Lists screenshot 4](https://i.imgur.com/qnyk2EL.png)
![GameQuery Server Lists screenshot 5](https://i.imgur.com/inU5Qwv.png)
![GameQuery Server Lists screenshot 6](https://i.imgur.com/2rbq9Ib.png)
![GameQuery Server Lists screenshot 7](https://i.imgur.com/CCMXs6B.png)
![GameQuery Server Lists screenshot 8](https://i.imgur.com/s5P0efR.png)
![GameQuery Server Lists screenshot 9](https://i.imgur.com/gB0aEbW.png)
![GameQuery Server Lists screenshot 10](https://i.imgur.com/19Z4CTx.png)
![GameQuery Server Lists screenshot 11](https://i.imgur.com/uoW76jt.png)
![GameQuery Server Lists screenshot 12](https://i.imgur.com/fpMFOJG.png)

## Description

GameQuery Server Lists helps gaming blogs and community websites publish multiplayer server lists in minutes.
Site owners can connect their GameQuery account, organize servers by game, and embed lists anywhere in WordPress with shortcodes.

The plugin includes secure one-click account connection, multi-game list support, visual templates, WP-Cron background refresh, and built-in list analytics for views and clicks.

## Key Features

- One-click secure GameQuery account connection with popup API key selection
- Multi-game server groups per list (`game_id` + `servers[]`)
- Built-in templates with live admin preview before publishing
- Flexible shortcodes: `[wpgs_list_123]` and `[wpgs_list id="123"]`
- Built-in analytics (views, clicks, unique counts) with dedicated stats page
- Campaign goal automation to auto-hide lists after click/view limits are reached
- WP-Cron background refresh with transient caching
- Quota-aware guidance for FREE plan usage

## Built With

- WordPress Plugin API
- PHP 7.4+
- JavaScript (WordPress admin UI)
- GameQuery API
- WP-Cron and Transients API

## Preview URL

- https://wordpress.gamequery.dev/

## System Requirements

- WordPress 6.0+
- PHP 7.4+
- Active GameQuery account and API key

## Quick Start

1. Copy this plugin into your WordPress plugins directory: `wp-content/plugins/gamequery-servers-lists`.
2. Activate the plugin from **WP Admin -> Plugins**.
3. Open **WPGS -> Settings** and connect your GameQuery account.
4. Create a list in **WPGS -> Lists**, add server groups, then publish.
5. Embed your list in posts/pages with one of these shortcodes:

```text
[wpgs_list_123]
[wpgs_list id="123"]
```

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
[wpgs_list_123]
```

or

```text
[wpgs_list id="123"]
```

## Notes

- For FREE plan, keep TTL at 60s or higher to align with the daily 1,440 request limit.
- Per API request limit is 1,000 servers per list payload.
- If cache is empty, shortcode falls back to a live API call and then caches the result.
- View counters are tracked per page load where a list is rendered.
- Click counters are tracked when visitors click rows in a rendered server table.
- Unique counters are deduplicated per visitor for 24 hours.

## Community Market Checklist

- [x] Public repository (not archived)
- [x] `README.md` present and descriptive
- [x] Preview URL included in `README.md`
- [x] System requirements listed in `README.md`
- [x] `CHANGELOG.md` present with version sections
- [x] SPDX license detected (`LICENSE` file)
- [x] Images included in `README.md` (recommended for cover/gallery import)

## Changelog

See `CHANGELOG.md`.

## License

This project is licensed under GNU GPL v2 or later (`GPL-2.0-or-later`).
See `LICENSE` for the full text.
