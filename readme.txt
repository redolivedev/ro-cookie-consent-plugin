=== Red Olive Cookie Opt-Out ===
Contributors: redolive
Tags: cookies, consent, gdpr, ccpa, privacy, opt-out, gpc
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.5.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A handsome, on-brand cookie-consent bar with real script gating. Geo-aware: opt-in for EU/UK, opt-out for US (honors Global Privacy Control).

== Description ==

Red Olive Cookie Opt-Out shows a clean, brand-styled bottom bar that visually
favors "Allow all" while keeping "Necessary only" one click away — compliant,
no dark patterns.

Key features:

* **Geo-aware behavior** — opt-in for EU/UK/EEA visitors (nothing non-essential
  loads until they accept), opt-out for US visitors (trackers load by default,
  with a clear opt-out). Detected from your CDN/host country header; unknown
  country is treated as strict opt-in.
* **Real script gating** — assign Google Analytics 4, Meta Pixel, or any custom
  `<script>` to a category; it is blocked until that category is consented.
* **Google Consent Mode v2 (advanced)** — optional. Load Google tags in a
  consent-aware state so declines fall back to cookieless pings and Google can
  model the lost conversions and sessions, instead of recording nothing. Maps
  Analytics to `analytics_storage` and Marketing to `ad_storage` /
  `ad_user_data` / `ad_personalization`. See the Setup tab.
* **Global Privacy Control** — automatically opts US visitors out of "sale/share"
  (Marketing) when the browser sends a GPC signal.
* **Owner-selectable accent color** plus background/text colors, a background
  opacity (see-through) slider with auto-blur, custom text, and button labels —
  all in wp-admin.
* **Goes away on save.** Once a visitor chooses, the bar disappears and nothing
  stays docked on screen. Let them re-open preferences with the
  `[rocoo_cookie_settings]` shortcode, or a footer/menu link with class
  `rocoo-open`.
* **Proof-of-consent log** (optional) with CSV export.
* **Developer API** — `window.roConsent` (`get`, `set`, `acceptAll`, `open`),
  a `roConsentChange` event, and a `dataLayer` push for GTM.

This plugin provides the consent mechanism; it is not legal advice. You are
responsible for accurate category descriptions, a current privacy policy, and
confirming the configuration meets the laws that apply to your visitors.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, or extract it to
   `wp-content/plugins/`.
2. Activate it.
3. Go to **Cookie Opt-Out** in the admin menu to set your accent color, text, and
   to add your analytics/marketing scripts.

== Developer notes ==

Gate your own scripts without the admin by tagging them:

    <template class="rocoo-gated" data-rocoo-cat="analytics">
      <script src="https://example.com/tracker.js"></script>
    </template>

React to consent:

    window.addEventListener('roConsentChange', function (e) {
      if (e.detail.consent.analytics) { /* start analytics */ }
    });

Filters: `rocoo_country`, `rocoo_should_render`, `rocoo_gated_blocks`.

== Changelog ==

= 1.5.8 =
* Removed the redundant "Gate WhatConverts under:" dropdown. WhatConverts is lead/call
  attribution that captures PII, so it is now always gated under **Marketing** (the correct
  category) — one fewer confusing option, and the choice no longer conflicts with the new
  "Load before consent (essential)" toggle.
* Clarified the "Geo-aware mode" setting: it only does anything when your host or CDN sends
  a visitor country header (e.g. Cloudflare). Most sites don't have one, where it's a no-op
  and every visitor is treated as opt-in — so the misleading "(recommended)" label is gone
  and the description now says so plainly.

= 1.5.7 =
* Setup polish: the "Setup status" panel is now a collapsible accordion (the status badge
  stays visible; click to expand the details), so it isn't taking up the right column at all
  times. In the WhatConverts box, the "Load WhatConverts (gated)" label is bolded and its
  follow-up note is set in smaller italics.

= 1.5.6 =
* The WhatConverts settings are now grouped in a branded, bordered box with the WhatConverts
  logo, so the Profile ID, gating, and "Load before consent (essential)" options read as one
  distinct section on the Setup tab.

= 1.5.5 =
* New **WhatConverts "Load before consent (essential)"** option. When enabled, WhatConverts
  loads ungated for every visitor so its first-party `wc_*` cookies are set immediately —
  for sites with a CRM/HubSpot routine that depends on those cookies. Everything else (Meta,
  Google Ads, GA4, GTM, custom scripts) stays fully gated. The Setup status panel flags that
  WhatConverts now fires before consent so you remember to disclose it in your privacy policy.

= 1.5.4 =
* The use & liability disclaimer is now a **first-run gate**: on a fresh install the
  settings screen shows only the disclaimer (as a modal) and blocks all configuration
  until it is accepted. Enforced server-side, so it can't be skipped. Sites that
  already accepted it are not asked again.
* Setup status now runs **deployment checks** against the site's active plugins:
  warns when another plugin injects tags outside this plugin's gate (Site Kit, GTM4WP,
  MonsterInsights/ExactMetrics, PixelYourSite, Header Footer Code Manager, WPCode, …)
  so the banner isn't silently decorative; flags full-page caching that can freeze the
  US-vs-EU/GPC decision on Basic/Balanced (Maximum is cache-safe); and reminds you to
  exclude banner.js from "delay JavaScript" optimizers (WP Rocket, LiteSpeed,
  Perfmatters, …).

= 1.5.3 =
* New **use & liability disclaimer** at the top of Setup, with a one-time "I accept"
  acknowledgment that records who accepted, when, and on which version. Once accepted it
  collapses to a dated line, and the Setup status panel shows whether it's been accepted.
  Replaces the narrower per-tier risk checkbox.

= 1.5.2 =
* Setup tab: the three protection levels now stay side by side (3-column grid) instead
  of wrapping the third card to a new row; removed the redundant intro line.
* Corrected the "How this affects your tracking data" note so it accurately describes
  each level — in particular that Basic and Balanced track US visitors who ignore the
  banner (opt-out), unlike Maximum.

= 1.5.1 =
* Maintenance: confirmed compatibility with WordPress 7.0 ("Tested up to" bump). This
  is also the first update delivered through the GitHub auto-update pipeline.

= 1.5.0 =
* Self-hosted **automatic updates**. The plugin now checks its GitHub repository for
  new releases, so every install shows the standard WordPress "update available"
  notice and can update with one click (or via auto-updates) — no more manual
  re-uploads across client sites. Bundles the Plugin Update Checker library.

= 1.4.9 =
* The "Setup status" health panel moved from the top of the Setup tab into a sticky
  right-hand column, so it stays glanceable without sitting on top of the setup steps.

= 1.4.8 =
* Setup tab redesigned into clearly-divided cards: numbered steps (1 Protection level,
  2 Connect trackers, 3 Google Consent Mode, 4 Privacy policy) plus distinct "Advanced"
  and "Manual override" cards, instead of run-together headings.
* The Records tab now shows a live count of stored consent records, e.g. "Records (1,203)".

= 1.4.7 =
* Admin consolidated onto the Setup tab. Google Consent Mode v2, the custom-script /
  gating "Advanced" block (no longer hidden behind an accordion), and the behavior
  controls (now "Manual override") all live under Setup. The Advanced tab is gone;
  consent Categories moved under Appearance. Tabs are now Setup / Appearance / Records.
* The admin header shows the square Red Olive logo instead of the "Red Olive" wordmark.

= 1.4.6 =
* Fix: saving the settings no longer trips a Web Application Firewall (e.g. Wordfence),
  which would 403 the save ("a potentially unsafe operation has been detected") when a
  custom-script box contained a raw <script>. The admin now base64-encodes those blobs
  on submit and decodes them server-side, so the firewall never sees script markup in
  the request. Plain submission is still accepted as a fallback.

= 1.4.5 =
* New **WhatConverts** field on Setup. Enter your WhatConverts Profile ID and enable
  it, and this plugin injects the WhatConverts tracking script **gated by consent** —
  replacing the standalone WhatConverts plugin, which loads the same script ungated
  (before any consent choice). Gate it under Marketing (default) or Analytics.
* The Setup status card now **detects the standalone WhatConverts plugin**: it warns
  when WhatConverts is active but ungated (firing pre-consent), and when it would load
  twice (both this plugin and the standalone plugin enabled).
* Note on double-firing: if the same Google Analytics ID is configured both in a
  GTM container and as a standalone tag, it sends two page-views. Keep each tag in a
  single place (e.g. inside GTM, or standalone — not both).

= 1.4.4 =
* Setup tab now opens with a **"Setup status" card** — an at-a-glance green-check
  readiness panel (protection level, Google Consent Mode on/off, which tracking
  codes are connected, privacy policy linked, "Do Not Sell" link, geo detection,
  consent log). It turns amber and points you to the fix when something essential
  is missing, and warns if the same tag is set in two places (double-fire).
* New **Google Tag Manager Container ID** field on Setup. The whole container is
  gated — nothing inside it fires until the visitor consents — so a GTM container
  can no longer leak trackers past the banner. Choose whether it gates under
  Marketing (default, safest) or Analytics in the Setup tab's Advanced disclosure.
* The **Privacy policy URL** moved from Appearance to Setup (step 3), next to the
  readiness check that flags it.
* Custom `<script>` boxes are now reachable from Setup via an "Advanced" disclosure
  instead of a separate tab, so every way of adding a tracker lives in one place.

= 1.4.3 =
* The Setup tab is now one blended screen: choose a protection level (each card shows
  its Google Consent Mode behavior), then enter your GA4 / Google Ads / Meta Pixel IDs
  right there. A short note explains whether your ads still track and how Consent Mode
  models the visitors who decline. The Categories, Custom-scripts, Behavior, and manual
  Consent-Mode controls are consolidated under a single Advanced tab.

= 1.4.2 =
* Fix: the chosen Protection Level now fully controls opt-in vs opt-out. Previously a
  left-over "Force a single mode" value (Behavior tab) could override Basic/Balanced
  and keep a site opt-in everywhere. With Advanced override off, the level owns it.

= 1.4.1 =
* New **Setup tab with one-choice Protection Levels** — Maximum Protection (default,
  fail-closed: nothing non-essential loads until consent), Balanced Protection (US
  opt-out but Google Ads and the Meta pixel wait for consent; Google models the gap),
  and Basic Protection (US opt-out; owner accepts the added risk). The chosen level
  drives geo/opt-in, Google Consent Mode, GPC scope, and logging, so a web designer
  configures the legal posture in one click. Fresh installs default to Maximum
  Protection.
* Each level is shown as plain-English **pros and cons**, spelling out exactly what
  happens to Google Analytics and Google Ads — including that visitors who decline or
  ignore the banner are not tracked (you get Consent Mode modeled estimates).
* Removed the **Functional** category — for most sites it overlaps "Strictly
  necessary." Categories are now Necessary / Analytics / Marketing.
* Choosing a level below Maximum Protection records a dated liability acknowledgment.
* Note: automatic detection/gating of trackers loaded outside the plugin, and an
  in-admin readiness check, are planned follow-ups.

= 1.3.0 =
* New **Consent Mode** tab with Google Consent Mode v2 (advanced) support. When
  enabled, Google tags (GA4 and a new Google Ads conversion ID field) load in a
  consent-aware state: the consent default is set in <head> before any tag, and
  banner.js pushes a consent update on the visitor's choice. Declines fall back
  to cookieless pings so Google can model lost conversions and sessions. Includes
  an in-admin "recommended settings to retain conversion tracking" guide.
* Honors GPC and geo in the initial consent default, and reads a returning
  visitor's stored choice so consented users start granted without a flash.
* Under advanced Consent Mode the GA4 preset loads in <head> rather than being
  hard-gated, to avoid double-loading. Meta Pixel and custom scripts stay gated.
* Security: the consent-log CSV export now neutralizes spreadsheet formula
  injection (cells beginning with = + - @ are quoted).
* Behavior tab now explains the default: US/non-EU visitors load non-essential
  cookies on first visit until they opt out, while EU/UK/EEA visitors are opt-in.

= 1.2.0 =
* New "Bar size" appearance option: **Compact** renders a slim, single-row bar
  about the height of a button. The notice sits on one line next to the buttons
  and the large heading is hidden; Customize still opens the full panel. Standard
  remains the default.

= 1.1.1 =
* Customize headings: force the category name color inline on each element so it
  always renders at full contrast, even when a host theme or a cached stylesheet
  would otherwise leave it a low-contrast charcoal on the dark panel.

= 1.1.0 =
* Customize panel: category headings are now brighter and bolder so they read
  clearly against the dark panel (no more low-contrast charcoal on black).
* Removed the persistent floating "Cookie settings" handle. The bar now simply
  goes away once a choice is made; nothing stays docked in the corner. Re-open
  preferences with the [rocoo_cookie_settings] shortcode or a footer/menu link
  with class "rocoo-open".
* Trimmed the default Marketing category description to "Used to deliver and
  measure relevant ads."

= 1.0.0 =
* Initial release.
