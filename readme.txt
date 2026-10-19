=== Katina Link Manager ===
Contributors: jeangalea
Tags: affiliate links, link management, link cloaking, redirect, link tracking
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The intelligent link manager for WordPress. Manage, cloak, track, and optimize your outbound links.

== Description ==

Katina Link Manager helps you manage all your outbound links in one place. Create cloaked, trackable links for affiliate programs, partner referrals, or any URL you want to manage centrally.

**Why Katina Link Manager?**

* Zero nag screens, zero upsell banners, zero review popups
* GDPR-compliant click tracking (no IP addresses stored by default)
* One-click migration from ThirstyAffiliates, Pretty Links, LinkCentral, and Easy Affiliate Links
* Link health monitoring catches broken links before your visitors do
* Ad-blocker safe
* Clean, modern admin interface

**Features:**

* Custom link management with cloaked URLs
* 301, 302, 307 redirects
* Click tracking with bot filtering
* Link categories and tags
* Gutenberg block and Classic Editor integration
* Link Fixer — automatically syncs link attributes in your content
* Link Index: scans your content for internal and external links, counts them per post, and flags orphan pages with no incoming links
* Link health monitoring (optional, off by default — makes outbound requests to your link destinations)
* CSV import/export
* Nofollow, sponsored, UGC rel attributes
* External links: open links to other sites in a new tab and add nofollow, noreferrer or ugc, with excluded domains
* Query string passthrough
* Dashboard with click statistics and health overview
* Reports: clicks per day, top referrers, top countries, per link or site-wide, with CSV export
* Setup wizard with migration detection

== Installation ==

1. Upload `katina-link-manager` to `/wp-content/plugins/`
2. Activate the plugin
3. The setup wizard will guide you through configuration and migration

== Frequently Asked Questions ==

= Will this plugin nag me for reviews or show upgrade banners? =

No. Never. This is a core principle of Katina Link Manager.

= Is click tracking GDPR compliant? =

Yes. By default, Katina Link Manager does not store IP addresses. Country data is derived at click time and the IP is immediately discarded.

= Can I migrate from ThirstyAffiliates or Pretty Links? =

Yes. Katina Link Manager includes built-in migration from ThirstyAffiliates, Pretty Links, LinkCentral, and Easy Affiliate Links. The setup wizard detects these automatically, or you can use the Migrate page at any time.

= How does link health monitoring work? =

Link health monitoring is optional and turned off by default. When you enable it under Settings → Modules, the plugin sends outbound HTTP requests to each of your link destinations twice daily in the background to detect broken links (404s, server errors), which are then flagged in the admin list and on the dashboard. You can also run a manual check at any time. No requests are made until you enable the feature.

== Screenshots ==

1. Dashboard — total links, clicks, and link-health at a glance.
2. Add or edit a cloaked link: destination, redirect type, rel attributes, categories, and tags.
3. Reports — clicks over time, top referrers, and top countries, for one link or all of them.
4. All your links in one list with click counts and health status.
5. Guided setup wizard with automatic migration detection.

== Changelog ==

= 1.4.0 =
* New External Links settings: open links to other sites in a new tab, add nofollow, noreferrer or ugc to them, and exclude domains you trust. Applies to post content and, optionally, comments. Anything already set on a link is kept.
* Fixed: saving one settings tab cleared the settings on the other tabs, including the URL prefix, which broke every cloaked link. Settings cleared this way are restored to their defaults on update.

= 1.3.6 =
* New "Never" choice for Default Sponsored. It removes rel="sponsored" from every link, including links set to Yes and custom rel tags.
* Links you paste into posts by hand now get the link's nofollow, sponsored and new-window settings when the page renders. Attributes already on the link are kept. Turn it off under Settings → Apply to Links in Content.

= 1.3.5 =
* `<ta>` tags and `[thirstylink]` shortcodes pointing to a ThirstyAffiliates link that no longer exists now show as plain text, as they did under ThirstyAffiliates, instead of linking to a missing page.

= 1.3.4 =
* Links set to pass the query string on now forward it intact. Encoded characters such as %2B were previously stripped or turned into spaces.
* `<ta>` tags from ThirstyAffiliates only resolve to a Katina link when their URL uses your link prefix.

= 1.3.3 =
* Destination URLs keep their percent-encoded characters (like %2B or %2F) when saved, imported or migrated. Previously these were stripped, which could break affiliate tracking codes. Re-save or re-migrate any link whose destination contains encoded characters.

= 1.3.2 =
* Migrating from ThirstyAffiliates now keeps your existing links working. Katina adopts ThirstyAffiliates' URL prefix and global defaults (redirect type, nofollow, new window, sponsored) when it has no links yet, or when you run `wp katina-link-manager migrate thirstyaffiliates --adopt-prefix`.
* Links set to use ThirstyAffiliates' global redirect type no longer end up with an invalid one after migration. Global settings are resolved to their real values.
* With ThirstyAffiliates deactivated, `[thirstylink]` shortcodes and `<ta>` tags in your content now render as proper Katina links, with the link's rel and target attributes. Your posts are not modified.
* The content rewrite option now handles `<ta>` tags too, and `--rewrite-content` runs it from WP-CLI.
* Imported ThirstyAffiliates clicks are no longer all marked as bots when no browser data was recorded. Country codes are imported, and referrer query strings are stripped as for native clicks.
* The click import resumes where it stopped and picks up new ThirstyAffiliates clicks on a re-run. It reads clicks in batches by ID, so a million clicks migrate in under a minute with modest memory.
* Draft ThirstyAffiliates links stay drafts, and trashed ones are skipped.
* New `--dry-run` flag on `wp katina-link-manager migrate` reports what would be imported without writing anything.

= 1.3.1 =
* Link health now follows the redirect chain to where it actually ends. Links that land on an affiliate network's "merchant closed" page, a generic comparison page, a private form, a closure notice or a "not found" page are marked Broken instead of Healthy.
* A tracking link that returns 400 Bad Request to a browser as well is now marked Broken. Before, it was reported as bot protection.
* Links that end up on another site with none of their tracking parameters are flagged for review, since that usually means the affiliate program dropped them.
* Hover a health badge to see why a link was flagged.
* The health checker now follows redirects itself and applies its private-network check to every hop, not just the first URL.

= 1.3.0 =
* New Link Index screen. It reads every link out of your content directly from the database, so drafts, private posts, password-protected pages and noindexed content are all covered. A crawler-based tool sees none of those.
* Each post, page and custom post type shows its incoming internal links, outgoing internal links, external links, and cloaked Katina links, with sorting and a post type filter.
* Orphan pages, meaning nothing on your site links to them, are flagged in the list and can be filtered on their own.
* Export the index to CSV or JSON, optionally including the SEO title and description from Yoast, Rank Math or SEOPress.
* Scanning runs in batches from the admin screen so large sites don't time out, and individual posts are reindexed as you save them.
* Uninstalling now removes the plugin's options. They were being looked up under the old pre-rename prefix, so they were being left behind.

= 1.2.2 =
* Click logging on a redirect now happens after the visitor has already been sent on their way, instead of before. On PHP-FPM the redirect response is flushed and the connection closed first, then the click is written, so the database write no longer sits on the visitor's path. This keeps redirects fast under bursts of traffic to a single link (for example a newsletter landing on a large list), where the cloaked URL is the one request that can't be served from cache. Hosts without fastcgi_finish_request() keep the previous inline behaviour.

= 1.2.1 =
* Cloaked link paths are now disallowed in robots.txt (using your configured prefix). The redirects already sent X-Robots-Tag: noindex; the Disallow also keeps crawlers from spending crawl budget fetching redirect URLs just to be told noindex. Sites that discourage search engines are left untouched.

= 1.2.0 =
* New Reports screen. Clicks per day, top referrers, top countries, and top links, over a date range you pick (or one of the presets: 7, 30, 90, 365 days, this month, last month, year to date).
* Reports work across every link or for a single link — pick one from the Link filter, or click through from Top links.
* Export the clicks in the selected range to CSV.
* The chart is drawn server-side as inline SVG, so the screen pulls in no charting library and no external assets.

= 1.1.5 =
* Link health checks are now off by default and require explicit opt-in. They no longer run on activation; enable them under Settings → Modules to start the background checks. This makes the plugin's outbound requests fully opt-in.
* Removed the load_plugin_textdomain() call — WordPress.org loads translations automatically since WordPress 4.6.

= 1.1.4 =
* Admin styles now load on every Katina admin screen. The dashboard, settings, tools, and setup wizard could render unstyled because the asset gate keyed only on the post type, which some of those screens don't carry in their URL.
* Cleaned up the "Skip setup" link on the setup wizard so it reads as a plain text link instead of an outlined button.

= 1.1.3 =
* Link health: only 404 and 410 count as broken now. Auth and anti-bot refusals (400/401/403/405/406/429) get a browser-profile retry and, if still refused, are marked "unknown" for manual review instead of "broken" — affiliate networks routinely block automated checks while serving real visitors fine.
* Follow up to 5 redirects when checking link health.

= 1.1.2 =
* Sanitize and validate the remaining $_SERVER reads (user agent, request URI, query string, and the link fixer's REMOTE_ADDR rate-limit key).
* Sanitize the uploaded file's tmp_name and unslash $_POST values before sanitize_key in the migration screen.

= 1.1.1 =
* Sanitize $_SERVER values used for client IP detection.
* Inline known sanitize_callback functions in register_setting() calls for clarity.
* Rename internal JavaScript globals to the plugin prefix.

= 1.0.0 =
* Initial release
* Link management with cloaked URLs and redirects
* Click tracking with bot filtering (GDPR-compliant)
* Categories and tags
* Gutenberg block and Classic Editor integration
* Link Fixer for content attribute sync
* Link health monitoring (automated + manual)
* Migration from ThirstyAffiliates, Pretty Links, LinkCentral, Easy Affiliate Links
* Content scanner for shortcode replacement
* CSV import/export
* Setup wizard with migration detection
* Dashboard with click stats and health overview
