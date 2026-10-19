<h1 align="center">Katina Link Manager</h1>

<p align="center">
  <a href="https://wordpress.org/plugins/katina-link-manager/"><img src="https://img.shields.io/badge/WORDPRESS.ORG-katina--link--manager-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress.org"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/LICENSE-GPLv2%20or%20later-5C9E31?style=for-the-badge" alt="License"></a>
  <a href="https://rebelcode.com"><img src="https://img.shields.io/badge/BUILT%20BY-REBELCODE-8A2BE2?style=for-the-badge" alt="Built by RebelCode"></a>
</p>

<p align="center"><b>Affiliate link cloaking, redirects and click tracking for WordPress, with no upsell banners.</b></p>

## What it does

- Short branded URLs (`/go/your-slug/`) that redirect with 301, 302 or 307
- Nofollow, sponsored and UGC rel attributes per link or site-wide, applied to links you paste into posts as well
- Click tracking that stores no IP addresses by default
- External link settings: open other sites in a new tab and add rel attributes, with excluded domains
- Link health checks, a link index with orphan-page detection, and block editor and Classic Editor integration
- Migration from ThirstyAffiliates, Pretty Links, LinkCentral and Easy Affiliate Links, including click history. Existing `/link/` URLs, `<ta>` tags and `[thirstylink]` shortcodes keep working.

## Install

Install it from **Plugins → Add New** in WordPress, or download it from [WordPress.org](https://wordpress.org/plugins/katina-link-manager/). It needs WordPress 6.2 and PHP 8.0 or later.

## WP-CLI

```
wp katina-link-manager migrate thirstyaffiliates
```

## Development

Releases deploy to WordPress.org from GitHub releases. The tag is the plain version number, without a `v`. `.distignore` lists what stays out of the plugin zip.

## License

GPLv2 or later.
