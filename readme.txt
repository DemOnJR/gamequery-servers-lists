=== GameQuery Server Lists ===
Contributors: pbdaemon
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build reusable GameQuery-powered server lists and embed them with shortcodes.

== Description ==

GameQuery Server Lists lets WordPress admins create multi-game server lists that can be embedded with:

* `[gamequery_123]`
* `[gamequery id="123"]`

It includes list templates, background cache refresh via WP-Cron, and built-in list analytics.

== Installation ==

1. Upload plugin files to `/wp-content/plugins/gamequery-server-lists`, or install through your deployment process.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Configure API credentials in `WPGS -> Settings`.
4. Create lists in `WPGS -> Lists`.

== Changelog ==

= 0.1.2 =
* Add widget support and shortcode rendering improvements.
* Add campaign goal controls and copy-IP display option.
* Improve cache-busting for admin/frontend assets.

= 0.1.1 =
* Improve cache-busting for admin/frontend assets.

= 0.1.0 =
* Initial MVP release.
