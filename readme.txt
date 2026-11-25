=== Windrose Sabir - Languages ===
Contributors: patricia70
Tags: multilingual, multisite, language switcher, translation, hreflang
Requires at least: 7.2
Tested up to: 7.2
Requires PHP: 8.4
Requires Plugins: multisite-language-switcher
Stable tag: 0.3.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual add-on for Multisite Language Switcher: language groups, Copy to other languages, a language item for the Navigation block, Logbook export.

== Description ==

Multisite Language Switcher links the sites of a network as translations of each other, one site per language. Many networks also hold sites that are not translations: a social wall, a presentation site, a second project. Windrose Sabir adds **language groups** (Network Admin, Windrose Sabir, Language groups):

* A group holds the sites that are translations of each other, one per language, for example English and French.
* Multisite Language Switcher then links only the sites of the same group: the language switcher, the hreflang links for search engines, the translation boxes in the editor and the admin columns.
* Sites in no group show none of them.
* Several groups per network are allowed.
* A group can name its main site (the source language). With Windrose Anchors, that site can be the media hub of the group.
* Until a group is saved, Multisite Language Switcher works exactly as before.

Each site's settings page of Multisite Language Switcher says which group the site is in, and Site Health (Tools, Site Health) says whether the site's group and its Multisite Language Switcher settings match.

**Copy**: in the block editor, the Languages panel copies a post to the other sites of its group as drafts, linked as translations, so an editor there translates and publishes it. Title, blocks, excerpt, captions and credits, the featured image, categories and tags (their translations, or new terms to translate) and the page template come along. Images: with Windrose Anchors, you choose network items of the target site's media hub (images not shared yet are shared first when you may; with Anchors 0.5.0 also images of drafts not published yet) or copies of the files; without Anchors the files are copied into the other site's media library. Rights are checked on the other site, and the copied content follows that site's rules. Also a bulk action in the post lists.

**Language item**: a block for the Navigation block (or anywhere) linking to the current page in the group's other languages, with each language's name in its own language (or its code), and the lang and hreflang attributes; the current language is plain text, not a link. A network setting shows Multisite Language Switcher's own switcher with names or codes instead of flags.

**Translation links**: Network Admin, Windrose Sabir, Translation links lists the links that no longer fit their group (after a site changed group, or a post was deleted), and removes them after a confirmation. Site Health warns about them on each site.

**Logbook**: export the groups, the sites' languages and every set of translations as JSON, and an address table as CSV (Network Admin or WP-CLI: `wp windrose-sabir logbook export`).

Windrose Sabir only uses the filters Multisite Language Switcher documents; it never changes that plugin.

Windrose Sabir is part of the Windrose Suite, a free set of plugins and themes for WordPress networks: https://www.webpresencecare.com/windrose/ . Each Windrose plugin and theme works on its own.

Source code, issues and build steps: https://github.com/webpresencecare/windrose-sabir

Security: please report vulnerabilities privately through GitHub: https://github.com/webpresencecare/windrose-sabir/security/advisories/new . Never in a public support thread.

== External services ==

None. The bundled update library contacts the GitHub API (api.github.com) to check for and download updates of Windrose plugins: sent are the repository name and a "Windrose" User-Agent. GitHub terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service, privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Privacy ==

Windrose Sabir collects nothing about visitors, sets no cookies and contacts no other service. Copy moves content between sites of the network only. Suggested privacy policy text is added under Settings, Privacy, Policy guide.

== Uninstall ==

Uninstalling removes the language groups, the module and display settings, each site's Windrose Sabir settings, and the marks on copied files. Multisite Language Switcher's settings and translation links (also those Copy made), the drafts and media Copy made, are kept; Multisite Language Switcher then links the sites of its reference user again.

== Frequently Asked Questions ==

= Is this plugin written with AI? =

Yes. I write the Windrose plugins and themes with Claude Code, Anthropic's AI coding assistant: I design each feature, test it on real sites and review every release, and every commit written with Claude says so. Independent reviews are welcome on GitHub.

== Changelog ==

= 0.3.5 =
* Windrose Common 1.4.0: Files, for reading, writing and copying uploads and temporary files through WordPress's Filesystem API.
* Copy: pictures copied to another site, and the Logbook export written by WP-CLI, go through WordPress's Filesystem API.
* Code: the generated script files (`*.asset.php`) stop when opened directly.

= 0.3.4 =
* Language item: the current language is plain text, no longer a link to the page itself.

= 0.3.3 =
* Windrose Common 1.3.0: updates come from the public GitHub repositories, without a token (WordPress 7.2 RC no longer has the Secrets API, where the token was kept).

= 0.3.2 =
* The menu icon and the Language item block's icon are WordPress's own "language" SVG icon instead of a Dashicon; the menu icon follows the admin color scheme.
* Language groups: site links are 24px high (WCAG 2.2, target size).
* Site Health: the header check reads blocks with WordPress's block processor.
* Notices use WordPress's own notice function (`wp_admin_notice()`).
* Code: all WordPress packages on their newest releases (DataViews 22).

= 0.3.1 =
* Copy: the Languages panel shows the title and status of a draft or private translation only to people who may read it on its own site (they were visible to any reader of the post); published ones stay visible to all.
* Site Health: a site that now uses the same language as another site of its group (after a change of Site Language) is reported as critical.
* Logbook: addresses of terms and custom post types on other sites are their plain addresses (?cat=, ?taxonomy=, ?post_type=), which each site redirects with its own rules; posts of types the main site does not have are listed too.
* Bulk copy: at most 10 copies per run; the notice says how many are left (run the same action again on the same selection: posts already copied are skipped).
* The Languages panel no longer reloads after autosaves.
* Code: Multisite Language Switcher's own list of post types; one reader of its translation links. Windrose Common 1.2.0.

= 0.3.0 =
* The toolbar follows the language display: with language names or codes, the language after site names in the toolbar replaces Multisite Language Switcher's flag. The setting now says so.

= 0.2.0 =
* Copy: the editor's Languages panel and a bulk action copy posts to other sites of the group as linked drafts, with media (network items with Windrose Anchors, also for drafts with Anchors 0.5.0, or file copies, as you choose), terms and allowed custom fields; rights and content rules of the target site apply.
* Language item block for the Navigation block; language display (names, codes or flags) for Multisite Language Switcher's switcher.
* Translation links: list and removal of links that no longer fit their group; Site Health tests for them and for the language item in the header.
* Logbook export (JSON and CSV), also with WP-CLI.
* Modules screen; per-site Copy settings on Multisite Language Switcher's settings page.

= 0.1.0 =
* Language groups: Network Admin screen, Multisite Language Switcher limited to the sites of the current site's group, nothing on sites in no group.
* Group section and notice on Multisite Language Switcher's settings page; Site Health test.
* Optional main site per group; Windrose Anchors can use it as the group's media hub.
