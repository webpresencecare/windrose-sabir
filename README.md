# Windrose Sabir - Languages

> Part of **[the Windrose Suite](https://www.webpresencecare.com/windrose/)**: WordPress for networks of sites, private by default, multilingual if you want it, free for everyone. Each Windrose plugin and theme works on its own.

Multilingual add-on for [Multisite Language Switcher](https://wordpress.org/plugins/multisite-language-switcher/) (MSLS), part of the Windrose Suite. One site per language, as MSLS does; Windrose Sabir adds what a real multilingual network needs on top. Version 0.2.0 adds **Copy**, the **language item** block, the **translation links** clean-up and the **Logbook** export to the language groups of 0.1.0; Translate and the Logbook import follow in a later version.

Requires WordPress 7.2, PHP 8.4 and Multisite Language Switcher (`Requires Plugins`). Network-activated only.

## What it does

- **Main site of a group** (optional): the site of the source language. With Windrose Anchors 0.4.0 or later active, Anchors' "Follow language groups" makes it the media hub of the group's sites: Sabir answers Anchors' filter `windrose_anchors_group_hubs` (site ID => main site ID); nothing happens without Anchors.
- **Copy** (module): the editor's **Languages** panel (`PluginDocumentSettingPanel`) lists the other sites of the group with the post's translation there (title, status, edit link) or a box "Copy to <language>", and says beforehand what happens to the media ("1 image will be shared with the network first"). "Copy as drafts" makes, on each chosen site, a **draft** by the person who copies, linked as translation in MSLS (the new draft joins the source's existing set). Also a bulk action "Copy to <language> as drafts" in the post lists. Copy runs MSLS's content import pipeline (`ContentImporter::import_content()`, its hooks and add-on importers run) with Windrose Sabir's own importer for every type (`msls_content_import_<type>_importer`): MSLS's importers switch sites without restoring, read the post type from the request and save links under `msls_0` outside wp-admin. Copied: title, blocks, excerpt, parent (its translation), menu order, discussion, password, featured image, terms, custom fields of an allow list (`_wp_page_template`, `footnotes`; filter `windrose_sabir_copy_meta_keys`), the post format. Checks: Copy on, both sites in the same group, the content type copied from this site, `read_post` here, the post type's `create_posts` **on the target site**, no translation there yet. Content passes the **target site's KSES rules** for the person (core's filters set up again on each switch). If something fails, the draft and its file copies are deleted; whatever an importer left switched is restored.
- **Media in a copy**: the panel's "Images" choice (REST `images`): network items (default) or "Copy the files to each site". With network items, Windrose Anchors 0.4.0 or later and the target site allowed, an image of the target's media hub (the source is that hub, or the image is a network item of it) becomes a **network item** there; a hub image not shared yet is **shared first** when the person may upload on the hub; the hub's own item when the target is the hub. An image attached to a post not published yet on the hub (a draft prepared in several languages) becomes a network item too with Anchors 0.5.0, when the person may read that post there. Any other case (file copies chosen, no Anchors, another hub, an unpublished post the person may not read, no right to share) **copies the file** into the target's media library through core's sideload (title, caption, alt, description; files only from inside the source's uploads folder; a later copy of the same file reuses it, `_windrose_sabir_source`). Without the right to upload on the target, the content keeps the file's address. Block references move (`id`, `mediaId`, `ids`, `wp-image-<ID>`, `data-id`, file addresses of copies; classic `<img>` too). MSLS's `_msls_linked` is never written on a copy.
- **Terms**: the translation linked in MSLS when it exists there; otherwise, by the target site's setting, a term of the same name or a new one, linked (default), or left out.
- **Language item** (`windrose/language-item`, Site Editor module): MSLS's links for the current page; inside a Navigation block one navigation item per language (core's Navigation accepts it through `allowedBlocks`, server and editor), elsewhere a list. Names in their own language (with the region when two sites share a language), codes (with the name for screen readers) or MSLS's labels; `lang`, `hreflang` (short when unique), `translate="no"`; the current language is plain text, not a link (`aria-current="true"`); options: show the current language, hide languages without a translation (otherwise they link to that site's home page).
- **Language display** (Network Admin, Windrose Sabir, Modules): MSLS's own switcher shows names (default) or codes through `msls_link_create`, so no flag on the front end; the toolbar (admin bar) follows the same choice, the name or code replacing MSLS's flag after site names (`Toolbar`, `admin_bar_menu` priority 1000, after MSLS's 999); "flags" leaves MSLS as configured. Only once a group exists.
- **Translation links** (Network Admin, Windrose Sabir, Translation links): the links of sites in a group that no longer fit (`no-site`, `missing`, `not-linked-back`, `own-language`, `invalid`), with the post or term and the site; nothing changes until "Remove" is confirmed (row, bulk or all), and each link is checked again first.
- **Site Health**: `windrose_sabir_language_item` (block themes: a language item in the header part, following patterns, template parts and the Navigation menu, also core's fallback menu) and `windrose_sabir_translation_links`.
- **Logbook** (module): Network Admin, Windrose Sabir, Logbook downloads the logbook (JSON, schema 1: groups, sites with locale and BCP 47 tag, translation sets with type, ID, status, address, title) and an address table (CSV, one column per site, one row per set of published translations; cells never start as formulas). WP-CLI: `wp windrose-sabir logbook export --file=logbook.json --csv=addresses.csv`.
- **Modules** (Network Admin, Windrose Sabir, Modules): switches for Copy, Site Editor and Logbook (language groups are always on), and the language display.
- **Per-site settings** in a "Copy (Windrose Sabir)" section of MSLS's settings page (saved by MSLS's form): the content types this site copies, and what Copy does with terms without a translation on this site.
- **Network Admin, Windrose Sabir, Language groups**: groups of sites that are translations of each other, for example "example.com: English, French", while a social wall or a presentation site stays outside every group. Several groups per network are allowed (two independent multilingual projects). A group holds at least two sites and one site per language (MSLS finds a site by its language); a site is in one group at most. DataViews table with search, **Add language group**, **Edit** and **Delete** (with a confirmation), and the list of sites in no group, each with its language and MSLS state ("not set up", "excluded", archived).
- **MSLS follows the groups**, through its documented filters only:
  - `msls_blog_collection_construct`: on a site in a group, MSLS's site list is that group's sites; on a site in no group, it is empty. MSLS uses this list for the language switcher (shortcode, widget, block), the hreflang links, the editor's translation box, the list columns and the admin bar. In a group, the group itself is the site list: MSLS's reference user no longer limits it. Archived, spam and deleted sites are left out (as in MSLS's own list), and MSLS still skips sites where it is not set up or excluded;
  - `msls_supported_post_types` and `msls_supported_taxonomies`: none on a site in no group, so MSLS shows no editor boxes and no list columns there.
- **No group, no change**: until a group is saved, MSLS works exactly as without Windrose Sabir (existing networks are not affected by installing it).
- **Settings, Multisite Language Switcher** on each site: a "Language group (Windrose Sabir)" section naming the site's group and the other sites of it, and a notice at the top when the site is in no group (MSLS's settings then have no effect there).
- **Site Health** (Tools, Site Health), test `windrose_sabir_language_group`: good when the site is in a group with MSLS set up, or in no group without MSLS; recommended when the network has no group yet, when a site in a group has no MSLS language, or when MSLS is set up on a site in no group (add it to a group, or tick MSLS's "Exclude this blog from output").

## Data

| Where | Key | Holds |
|---|---|---|
| Network option | `windrose_sabir_groups` | List of `{ id, label, main }` (`main`: main site ID, 0 for none) |
| Site meta (`wp_blogmeta`) | `windrose_sabir_group` | The site's group ID |
| Network option | `windrose_sabir_modules` | `{ copy, site_editor, logbook }` (booleans, all on by default) |
| Network option | `windrose_sabir_display` | `names` (default), `codes` or `flags` |
| Site option | `windrose_sabir_site` | `{ post_types: list or null (every type MSLS handles), terms: create or skip }` |
| Post meta on a file copy | `_windrose_sabir_source` | `<site ID>:<attachment ID>` of the real file (to reuse a copy) |
| MSLS options | `msls_<post ID>`, `msls_term_<term ID>` | MSLS's translation links, written by Copy through MSLS's option classes |

The site meta is the source of truth for membership; a value naming a group that no longer exists counts as no group. Core deletes a site's meta with the site. The meta is registered (`register_meta( 'blog', ... )`, integer, `show_in_rest`, writable with `manage_sites` only), ready for core's coming `wp/v2/sites` endpoint. MSLS will take its site list from site meta itself one day (Dennis Ploetner); Windrose Sabir then writes MSLS's key and drops its own filter.

**Uninstall** removes `windrose_sabir_groups`, `windrose_sabir_modules`, `windrose_sabir_display` (every network), the `windrose_sabir_group` site meta, `windrose_sabir_site` and `_windrose_sabir_source` on every site, and the cached update check. MSLS's own settings and translation links (also those Copy wrote), and the drafts and media Copy made, are not touched: MSLS then links the sites of its reference user again.

REST routes (`windrose-sabir/v1`, network administrators only, `manage_network_options`):

| Route | Methods | Does |
|---|---|---|
| `groups` | GET | Groups with their sites, and every site of the network (`locale`, `language`, `msls` = configured, excluded or missing, `status`, `group`, `settings` link) |
| `groups` | POST (`label`, `sites`, `main`) | Creates a group (201) |
| `groups/<id>` | POST, PUT (`label`, `sites`, `main`) | Changes a group |
| `groups/<id>` | DELETE | Deletes a group |

| `groups/preview` | POST (`id`, `sites`) | What saving would do to translation links, read only |
| `settings` | GET, POST (`modules`, `display`) | Module switches and the language display |
| `links` | GET | Translation links that no longer fit, with names (the dry run) |
| `links/remove` | POST (`items`: site, kind, id, locale) | Removes them, each checked again; answers `removed`, `kept` and the new list |
| `logbook` | GET | Counts and the download addresses (Logbook module) |
| `copy/<post>` | GET, POST (`sites`) | Copy (any logged-in person who may read the post; the target site's rights are checked per site) |

Errors answer 400 (name missing, too long or taken; fewer than two sites; unknown site; site in another group; two sites with the same language; a main site outside the group) or 404, with a sentence for people. Every success answers with the same body as GET.

Language names come from PHP's intl extension, in their own language ("Français (France)"); nothing is asked from wordpress.org. Without intl the locale code is shown.

Windrose Common (the shared update library) is bundled in `common/`, copied from Windrose Helm with `windrose-helm/bin/sync-common.sh`; never edit it here.

## Releasing

1. Set the version in the plugin header and the `WINDROSE_SABIR_VERSION` constant (and in `stubs/constants.php`, `package.json`, `readme.txt`); `npm run build`; run the checks; commit.
2. Wait for the green CI run of that commit, then tag `vX.Y.Z` and push the tag. `.github/workflows/release.yml` builds `windrose-sabir.zip` with `bin/build-zip.sh` and publishes the release.

## Development

JavaScript in `src/network` (the network screens), `src/editor` (the Languages panel) and `src/language-item` (the block's editor side; its `block.json` and front-end style are in `blocks/language-item`), built into `build/` (committed): React with WordPress components and DataViews (bundled: WordPress does not provide it).

```
npm install
npm run build
npx wp-scripts lint-js src
npx wp-scripts lint-style "src/**/*.scss"
```

PHP checks: `composer install`, then `vendor/bin/phpcs --standard=phpcs.xml.dist` and `vendor/bin/phpstan analyse -c phpstan.neon.dist` (PHP 8.4). PHPUnit runs in multisite mode against the WordPress test library (`bin/install-wp-tests.sh <folder>` installs it with a test database and MSLS; `WP_TESTS_DIR` points to it), never on real data, with MSLS next to the test library (`WINDROSE_MSLS_DIR` to use another copy) and Windrose Anchors from its working copy next to this one when present (`WINDROSE_ANCHORS_FILE`; Copy's network media tests are skipped without it, as on GitHub). PHPStan reads MSLS from `.msls` (`bin/get-msls.sh .msls`, or a link to an installed copy) and Anchors from `stubs/windrose-anchors.php`. The Playwright tests (`tests/e2e`) run on a WordPress multisite test network with WP-CLI and MSLS (main site, `/fr/`, `/ar/`, `/social/`), reached at `WINDROSE_E2E_HOST` with the administrator `WINDROSE_E2E_USER` and password `WINDROSE_PASS`; they delete everything they create, and groups that existed before are put back.

On GitHub, `.github/workflows/ci.yml` runs on every push to `main` and every pull request: `php -l` on PHP 8.4 and 8.5, PHPCS, PHPStan (PHP 8.4), JavaScript and style lint with a fresh build equal to the committed `build/`, PHPUnit multisite on PHP 8.4 and 8.5 against the WordPress nightly and MSLS from GitHub (`bin/install-wp-tests.sh`: a tested commit until MSLS 3.1.0 is released, then set `MSLS_REF` to its tag).

## Status

Updates come from this repository's GitHub releases: each download is checked against the sha256 digest GitHub publishes before it is installed. A wordpress.org version may come later, after independent reviews.

## Security

Please report vulnerabilities privately, never in a public issue: see [SECURITY.md](SECURITY.md).

## Contributing

Reviews, tests and issues are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md).

## How this code is written

I write this plugin with Claude Code (Anthropic's AI coding assistant): I design each feature, test it on real sites and review every release. Commits written with Claude say so in a `Co-Authored-By` line. Human review is especially welcome.

## Licence

GPL-2.0-or-later.
