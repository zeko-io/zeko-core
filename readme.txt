=== Zeko Core ===
Contributors: zeko
Tags: ecosystem, activity, helpers, database, logging
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shared foundation for the Zeko ecosystem: unified activity logging, helper APIs, and shared database schema used by every Zeko module.

== Description ==

Zeko Core is the shared foundation plugin for the Zeko ecosystem. It provides a single source of truth for the pieces every Zeko module (Jobs, Learn, QA, Mentor, Love, Freelance, Shop, Rewards, AI, Pay) needs, so no module re-implements them.

= Features =

* **Activity Logging** — `Zeko_Core_Activity` is the single writer/reader for the activity table; front-end feeds consume it with transient caching
* **Shared Helpers** — `Zeko_Core_Helpers` for profile URLs, avatars, nonces, and module page URLs
* **Database Schema** — Canonical activity table definition that themes and plugins delegate to
* **AJAX Foundation** — Shared AJAX base class with nonce verification and rate limiting
* **Registry Filters** — `zeko_dashboard_tabs`, `zeko_dashboard_widgets`, and related hooks that modules use to self-register UI
* **`[zeko_activity_feed]`** — Activity stream shortcode used by themes and other plugins

= Ecosystem Integration =

Zeko Core is deliberately dependency-free. Every Zeko plugin checks `class_exists( 'Zeko_Core' )` and falls back gracefully when the plugin is absent, so activating it is always safe.

== Installation ==

1. Upload the `zeko-core` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The shared database schema is created automatically on activation

== Frequently Asked Questions ==

= Is Zeko Core required? =

Recommended. Zeko Core must be active for full ecosystem functionality (dashboard tabs, activity feed, shared helpers). Every Zeko module also works standalone with graceful degradation.

= Does it drop the activity table on uninstall? =

No. The activity table is shared with themes, so Zeko Core never drops it on deactivation or uninstall.

== Dependencies ==

Zeko Core itself has no dependencies and is safe to activate at any time. Other Zeko modules, the optional Zeko PRO premium companion (a separate paid product distributed from the author's site, never from this directory — no free feature here depends on it), and the theme integrate with it when present.

== Privacy ==

Activity log rows store the acting user's IP address at log time. Zeko Core contributes a public-privacy section (Tools > Privacy) describing ecosystem data, email/SMTP handling, lesson-video embeds, and payment records. It registers no exporters/erasers itself; modules provide those.

== Uninstall ==

Deleting Zeko Core removes only the `zeko_core_db_version` option. The shared activity table is intentionally left in place because other modules and the theme own rows in it.

== Troubleshooting ==

* Activity lost after a permalink change? Explicitly save permalinks and flush caches; the feed uses page URLs from `Zeko_Core_Helpers`.
* Module features missing? Confirm Zeko Core is active — modules degrade to standalone mode when it is absent.
* Rate limits hit unexpectedly? The shared AJAX base class rate-limits by user ID or IP; check the limiter configuration.

== External Services ==

* **Zeko license server** — If you enter a license key under Settings >
  Zeko License, the key, this site's home URL, and a random per-site token are
  posted to `https://ozconsultz.com/` (overridable via the `ZEKO_LICENSE_SERVER`
  constant) for activation, status checks, and — when the optional Zeko PRO
  premium companion is installed — update-package lookups. Zeko PRO is a
  separate paid product distributed from the author's site, not from this
  directory; no free feature here requires it. No key, no request. Full details
  (including payload fields, filters, and codes) are in `REMOTE-SERVICES.txt`
  in this plugin folder.

Avatars are served by WordPress core's `get_avatar()` (typically Gravatar);
that request is made by the visitor's browser, not by this plugin.

== Third-party resources ==

Zeko Core bundles the Quill Editor 1.3.7 rich-text library (BSD 3-Clause) used
by the Zeko QA ask/answer forms and any module calling
`Zeko_Core_Assets::enqueue_quill()`. Zeko Core is the single distributor of
Quill across the ecosystem; no other third-party fonts, icons, images, or
scripts are bundled (the only image is this plugin's own screenshot). Full
attribution, license text, and GPL-compatibility notes are in
`THIRD-PARTY-NOTICES.txt` in this plugin folder.

== Screenshots ==

1. Zeko Core - license and shared services screen


== Changelog ==

= 1.0.0 =
* Initial release
* Zeko_Core_Activity — single writer/reader for the activity table with transient caching
* Zeko_Core_Helpers — profile URLs, avatars, nonce helpers, page-URL lookup
* Canonical activity table schema
* Shared AJAX base class with nonce verification and rate limiting
* `[zeko_activity_feed]` shortcode
* `zeko_dashboard_tabs` registry filter

== Upgrade Notice ==

= 1.0.0 =
Initial release of Zeko Core.
