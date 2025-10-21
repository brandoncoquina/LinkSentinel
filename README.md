# LinkSentinel

> Keep your WordPress site healthy by finding and fixing redirected or broken **internal links**—automatically.

[![License: GPLv2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](#license)  
**Stable tag:** 1.8.7 · **Requires WP:** 5.8+ · **Tested up to:** 6.9 · **Requires PHP:** 7.4+

**Feel free to donate to us! We're still in starup mode 🚀 [ko-fi.com/coquinaworks](https://www.ko-fi.com/coquinaworks)**

---

## What it does
**LinkSentinel** scans your site’s posts and pages, classifies every internal link it finds, and helps you correct problems fast.

- **Auto‑fix permanent redirects** (HTTP **301/308**) so your content points directly to the final URL—no manual edits.  
- **Queue temporary redirects** (**302/307**) and **broken links** (**4xx/5xx**) for **review & inline fixes**.
- **Run on demand or on a schedule** (asynchronous processing via Action Scheduler) so scans don’t time out.
- **See everything at a glance** in a clean dashboard with tabs for **Resolved Links**, **Pending Redirects**, **Broken Links**, and **Settings**.
- **Export** resolved items to **CSV** for audits, client handoff, or version control notes.

> Designed for site owners, editors, and SEO teams who want clean internal links, fewer 404s, and less manual cleanup after URL changes or migrations.

---

## Feature overview (Free)
- **Automatic scans**: Run any time, or let the daily schedule handle it.
- **Accurate detection** of 3xx, 4xx, and 5xx link states across posts, pages, and media.
- **Smart handling**
  - 301/308 can be **auto‑resolved** to the final target (toggle in Settings).
  - 302/307 and 4xx/5xx are **queued** for manual review.
  - **Inline edit** broken links directly in the table.
  - **Resolve All** pending redirects in a click.
  - **CSV export** of resolved links; **Clear Table** when you’re done.
  - **Progress & history** indicators (scan status, last run, manual vs. scheduled).
  - **Ignores admin/login** paths to cut down noise.
- **Safe & fast**
  - Runs asynchronously via **Action Scheduler** (bundled or detected from WooCommerce) to avoid timeouts.
  - Honors WordPress capabilities; only admins can run scans/change settings.
  - All processing stays on your server.

---

## How it works
1. **Scan**: Renders post/page/media content and extracts internal `<a>` links.
2. **Classify**: Checks destinations and labels them as **Permanent**/**Temporary Redirect** or **Broken**.
3. **Resolve**: Applies your preference for 301/308 auto‑fixes; everything else is queued for review.
4. **Record**: Tracks actions and lets you **download CSV** of resolved entries.

---

## Quick start
1. Upload the plugin folder to `/wp-content/plugins/` (or install from the Plugins screen).
2. Activate **LinkSentinel**.
3. Go to **Tools → LinkSentinel** and click **Start Scan**.  
   (A daily scan is scheduled by default; you can configure time and behavior in **Settings**.)

---

## Screens you’ll use
- **Dashboard** – progress + tabs for Resolved Links, Pending Redirects, Broken Links, and Settings.
- **Settings** – set scan time, enable/disable auto‑resolve, pick post types to scan.
- **Pending Redirects** – review, resolve individually, or **Resolve All**.
- **Broken Links** – find 4xx results; **inline Change Link** for quick fixes.
- **Resolved Links** – see what was fixed, when, and export to CSV.

---

## Roadmap
**Planned (Pro / upcoming):**
- External (outbound) link checks
- Real‑time checks on publish/update
- Image/PDF/media link checks
- Email summaries after scheduled scans
- Bulk actions: dismiss / unlink / re‑check
- Keyword → URL auto‑linking rules

Have a feature request? Open an issue or discussion.

---

## Changelog highlights
- **1.8.7** – polish & submission readiness; clearer descriptions, cleaned internal comments.
- **1.8.6** – tab persistence after scans; toggle for 301/308 auto‑resolve; counts on tabs; better date handling.
- **1.8.4–1.8.5** – **Resolve All** for redirects, **inline Change Link**, **Clear Table**, CSV improvements.
- **1.8** – redesigned dashboard with tabs, progress, scheduling, and export.

> Full changelog lives in the WordPress readme and release notes.

---

## FAQ
**Does it scan external links?**  
Not in the free version (internal links only). External checks are planned for Pro.

**Does it scan image or media URLs?**  
Currently only anchor (`<a>`) links. Media/link scanning is on the roadmap.

**Can I turn off the daily scan?**  
Yes—disable the scheduled event (e.g., via WP Crontrol) or deactivate the plugin.

---

## Contributing
PRs and issues are welcome! If you’re proposing a larger change, please start a discussion first.

---

## Support the project
If LinkSentinel saves you time, consider supporting development via Ko‑fi.

---

## License
GPLv2 or later. See `LICENSE` for details.

---

### Notes
- WordPress® is a trademark of the WordPress Foundation.

