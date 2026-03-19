=== GameQuery Server Lists ===
Contributors: pbdaemon
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, open-source GPL plugin for WordPress gaming sites to build and promote game server lists.

== Description ==

GameQuery Server Lists is a free, open-source WordPress plugin released under GPL-2.0-or-later.
It is built for gaming blogs and community websites that want a fast, reliable way to promote game servers.

With this plugin, a site owner can add server addresses, select the game, and publish a server list in minutes.
Lists can be embedded anywhere with shortcodes and integrated in widget areas across pages.

WordPress admins can embed lists with:

* `[gamequery_123]`
* `[gamequery id="123"]`

Key features include:

* One-click secure GameQuery account connection with API key selection.
* Multi-game list support with templates and background refresh via WP-Cron.
* Built-in analytics for views and clicks.
* Campaign goal automation that can auto-hide a server list after owner-defined click/view limits are reached.

== Installation ==

1. Upload plugin files to `/wp-content/plugins/gamequery-servers-lists`, or install through your deployment process.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. In `WPGS -> Settings`, connect your account with the one-click GameQuery popup (or use manual credentials).
4. Create lists in `WPGS -> Lists`.

== Changelog ==

= 0.1.4 =
* Replace browser datalist behavior with a dedicated searchable game selector in Server Groups.
* Add manual Game ID override support while keeping automatic game-name to game-id mapping.
* Store the games catalog in WordPress DB and refresh it every 7 days.
* Update manual Game ID placeholder example to `minecraft`.

= 0.1.3 =
* Add secure one-click GameQuery account connect flow with popup key selection.
* Add plugin account base URL setting for the connect flow.
* Harden connect security with ownership confirmation, endpoint rate limits, and connection alerts.
* Lock plan field to auto-detected value and auto-correct stale plan settings during API fetch.
* Improve Stats page with status filtering, search, and trash actions (single + bulk).

= 0.1.2 =
* Add widget support and shortcode rendering improvements.
* Add campaign goal controls and copy-IP display option.
* Improve cache-busting for admin/frontend assets.

= 0.1.1 =
* Improve cache-busting for admin/frontend assets.

= 0.1.0 =
* Initial MVP release.
