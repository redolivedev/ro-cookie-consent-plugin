# Red Olive Cookie Opt-Out

A self-contained WordPress plugin that renders a geo-aware cookie-consent bar and
performs **real script gating** — non-essential trackers do not execute until their
category is consented. Opt-in for EU/UK visitors, opt-out for US visitors (honors
Global Privacy Control). Built to be reusable across client sites from a single
wp-admin **Setup** screen with selectable protection levels.

- **Plugin slug / folder:** `red-olive-cookie-opt-out` (must not change — it is the
  text domain and the install directory).
- **Repo:** `redolivedev/ro-cookie-consent-plugin` (the repo name differs from the
  slug; that's fine).

## Installation

Upload the plugin to `wp-content/plugins/red-olive-cookie-opt-out/` and activate, or
install the zip from **Plugins → Add New → Upload Plugin**. Configure under
**Cookie Opt-Out** in the admin menu.

## Automatic updates

This plugin updates itself from this repository's **GitHub Releases** using the
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library
(bundled in `plugin-update-checker/`). Once installed, every site shows the normal
WordPress "update available" notice for this plugin and can update with one click, or
via per-plugin auto-updates. No tokens are required because the repo is public.

## Releasing a new version (how to ship an update to every install)

Versions live in **three files that must all match**:

1. `red-olive-cookie-opt-out.php` — the `Version:` header **and** `ROCOO_VERSION`
2. `readme.txt` — `Stable tag:` **and** a new changelog entry

Then:

```bash
# 1. Bump the version in the three places above and add a changelog entry.
# 2. Commit and push to main.
git add -A
git commit -m "vX.Y.Z — short summary"
git push origin main

# 3. Cut a GitHub Release whose tag matches the new version.
gh release create X.Y.Z --title "X.Y.Z" --notes "What changed in this release."
```

That's it. Within ~12 hours (WordPress's update-check cycle) every install offers the
update; sites with auto-updates enabled take it on their own. To verify a release
immediately on a site, go to **Dashboard → Updates → Check Again**.

**Notes**

- The release **tag** should equal the plugin version (e.g. `1.5.0`). A leading `v`
  is tolerated.
- `ROCOO_VERSION` is also the asset cache-buster for `banner.css` / `banner.js`, so
  bumping it busts the browser cache for those files.
- Do not rename the plugin folder/slug; auto-updates install back into
  `red-olive-cookie-opt-out/` regardless of the repo or zip name.

## License

GPL-3.0. See `LICENSE`.
