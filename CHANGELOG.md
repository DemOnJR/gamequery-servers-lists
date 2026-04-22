# Changelog

All notable changes to this project are documented in this file.

## 0.1.6 - 2026-04-22

- Security: stop pre-filling the API token field in Settings; the stored value is no longer echoed into the HTML. A "(saved)" indicator and optional "Clear saved token" checkbox replace the old behavior.
- Security: restrict the Account Base URL and API Base URL settings to `gamequery.dev` hosts by default and block loopback/private/link-local targets. Define `WPGS_ALLOW_CUSTOM_API_URL` in `wp-config.php` to opt-in to custom hosts for staging/dev setups.
- Security: require `https://` for the default GameQuery endpoints and reject URLs containing embedded credentials.

## 0.1.5 - 2026-04-01

- Remove per-list Custom CSS input and all frontend `<style>` injection paths.
- Replace admin inline `<script>` blocks with `wp_enqueue_script()` + `wp_add_inline_script()`.
- Move the plugin admin menu to a lower sidebar position.
- Escape stats progress bar output at render time with `wp_kses()`.
- Prefix shortcodes to `wpgs_list` and `wpgs_list_{id}`.
- Add explicit external service disclosure in `readme.txt` with data usage and Terms/Privacy links.

## 0.1.4 - 2026-03-19

- Replace browser datalist behavior with a dedicated searchable game selector in Server Groups.
- Add manual Game ID override support while keeping automatic game-name to game-id mapping.
- Store the games catalog in WordPress DB and refresh it every 7 days.
- Update manual Game ID placeholder example to `minecraft`.

## 0.1.3 - 2026-03-19

- Add secure one-click GameQuery account connect flow with popup key selection.
- Add plugin account base URL setting for the connect flow.
- Harden connect security with ownership confirmation, endpoint rate limits, and connection alerts.
- Lock plan field to auto-detected value and auto-correct stale plan settings during API fetch.
- Improve Stats page with status filtering, search, and trash actions (single + bulk).

## 0.1.2 - 2026-03-19

- Add widget support and shortcode rendering improvements.
- Add campaign goal controls and copy-IP display option.
- Improve cache-busting for admin/frontend assets.

## 0.1.1 - 2026-03-19

- Improve cache-busting for admin/frontend assets.

## 0.1.0 - 2026-03-19

- Initial MVP release.
