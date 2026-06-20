# GF CardDAV Server

[![Latest Release](https://img.shields.io/github/v/release/guilamu/gf-carddav-server?color=blue)](https://github.com/guilamu/gf-carddav-server/releases) [![License: AGPL-3.0-or-later](https://img.shields.io/badge/license-AGPL--3.0--or--later-green.svg)](https://www.gnu.org/licenses/agpl-3.0.html) [![WordPress: 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org) [![PHP: 8.0+](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

Expose active Gravity Forms entries as a native CardDAV address book for DAVx5 and other compatible clients.

## Export Contacts
- Publish one Gravity Forms form as a shared CardDAV address book.
- Generate vCard 4.0 contacts from active entries with stable ETags and collection CTags.
- Serve discovery, principal, collection, and direct contact endpoints for standard CardDAV clients.

## Configure Access
- Choose the source form and map first name, last name, email, phone, union, and department fields (supporting multiple fields per property).
- Select which WordPress users can authenticate against the CardDAV directory.
- Enable request logging when you need to troubleshoot client requests.

## Operate Safely
- Require HTTPS before serving any CardDAV response.
- Report issues from the plugin row when Guilamu Bug Reporter is installed.
- Update the plugin through GitHub releases directly from the WordPress admin area.

## Key Features
- **Gravity Forms Mapping:** Export entry data through configurable field mappings, including compound sub-fields and combining multiple fields per property.
- **Native CardDAV Routes:** Supports `.well-known/carddav`, PROPFIND, REPORT, and direct vCard downloads.
- **Multilingual:** Works with WordPress localization and includes a French translation source file.
- **Translation-Ready:** All user-facing strings use the `gf-carddav-server` text domain.
- **Secure:** Requires HTTPS and authenticates access against selected WordPress accounts.
- **GitHub Updates:** Supports update checks and plugin details directly from GitHub releases.

## Requirements
- WordPress 6.0 or higher
- PHP 8.0 or higher
- Gravity Forms 2.5 or higher
- HTTPS enabled on the site used by CardDAV clients

## Installation
1. Upload the `gf-carddav-server` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Forms → Settings → CardDAV Server** and choose the source form and field mappings.
4. Select the WordPress users allowed to authenticate, then save the settings.
5. Connect your CardDAV client to your site root URL over HTTPS.

## FAQ
### Which URL should a CardDAV client use?
Use the site root URL over HTTPS. The plugin redirects discovery requests from `/.well-known/carddav` to the authenticated principal and contacts endpoints.

### Does the plugin require Gravity Forms to stay active?
Yes. The plugin remains loadable without Gravity Forms, but the CardDAV directory stays unavailable until Gravity Forms is active again.

### Can I limit who can access the address book?
Yes. Only the WordPress users selected in the plugin settings can authenticate against the CardDAV endpoints.

### How do updates work?
Release tags published on GitHub are exposed through the WordPress update system. The plugin details modal also reads this `README.md` and upcoming release notes.

### How do I report a bug?
If Guilamu Bug Reporter is installed, use the **Report a Bug** link directly from the plugin row in WordPress. Otherwise, install the Bug Reporter plugin from its GitHub releases page first.

## Known Issues
- CardDAV clients that do not support HTTPS cannot connect because the plugin rejects non-SSL requests.
- If Gravity Forms is deactivated, the plugin still loads but returns an unavailable response for directory requests.

## Troubleshooting
- Confirm that Gravity Forms is active and that the selected form still exists.
- Verify that the WordPress users allowed in the plugin settings can log in normally.
- Check that the site serves valid HTTPS responses and that the client can reach `/.well-known/carddav`.
- Enable request logging temporarily when you need to inspect CardDAV client behavior.

## Project Structure
```text
.
├── gf-carddav-server.php                    # Main plugin bootstrap and headers
├── README.md                                # GitHub README and plugin details content
├── uninstall.php                            # Cleanup on uninstall
├── assets
│   ├── class-gf-carddav-addon.php           # Gravity Forms add-on settings UI
│   ├── class-gf-carddav-auth.php            # HTTP Basic Auth handling
│   ├── class-gf-carddav-directory.php       # Entry-to-directory lookups
│   ├── class-gf-carddav-github-updater.php  # GitHub auto-updates and modal integration
│   ├── class-gf-carddav-logger.php          # Optional request logging
│   ├── class-gf-carddav-plugin.php          # Core service wiring
│   ├── class-gf-carddav-principal.php       # Principal resource builder
│   ├── class-gf-carddav-server.php          # CardDAV HTTP endpoint controller
│   ├── class-gf-carddav-settings.php        # Settings persistence and field loading
│   ├── class-gf-carddav-vcf.php             # vCard generation
│   └── Parsedown.php                        # Markdown parser for the plugin details modal
└── languages
    ├── gf-carddav-server-fr_FR.po           # French translation source
    └── gf-carddav-server.pot                # Translation template
```

## Changelog

### 1.1.4 - 2026-06-20
- **Fix:** Fixed translation support for settings page elements, layout labels, and custom vCard properties.

### 1.1.3 - 2026-06-20
- **Fix:** Moved the "+ Add field" button out of the "Combine with" footer section into the field-rows section, and aligned it vertically with the mapped field dropdowns.

### 1.1.2 - 2026-06-20
- **New:** Add per-field case transformation dropdown (None, UPPER CASE, lower case, First letter upper case, First letters Upper Case) for mapped fields.
- **Removed:** Removed hardcoded case normalizations (First letter uppercase for first name, UPPERCASE for last name).
- **Fix:** Fixed spacing in the "Combine with" separator so "Space" works correctly.
- **Improved:** Redesigned field mapping card footer with a separate cream background and aligned layout.

### 1.1.1 - 2026-06-18
- **Improved:** Reworked the Field Mapping card into a single 4-column CSS grid so dropdowns, remove buttons, and the add-field button line up consistently across rows.
- **New:** Drag-and-drop reordering of mapped fields within a property (HTML5, vanilla JS, same-card only).
- **Fix:** The "Combine with" separator row no longer wraps its label and its dropdown is fixed-width, with the custom separator input shown only when "Custom..." is selected.
- **Fix:** The "+ Add field" control now uses the standard WordPress button style and aligns with the dropdowns above it.

### 1.1.0 - 2026-06-15
- **New:** Map multiple Gravity Forms fields to a single vCard property with a configurable join separator (space, comma, new line, or custom).

### 1.0.1 - 2026-06-15
- **Fix:** "View Details" link on plugins page now works correctly (plugin information API result now survives WordPress core filter mutations).

### 1.0.0 - 2026-06-14
- **New:** Initial CardDAV server release for Gravity Forms entries.

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/gf-carddav-server/security/advisories/new). Do not open a public issue for security reports.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/gf-carddav-server).

For translations, the plugin uses WordPress i18n. You can contribute translations by editing the `.po` files in the `languages/` directory and generating the corresponding `.mo` files manually when you need them.

## License
This project is licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later) — see the [GNU AGPL v3.0 text](https://www.gnu.org/licenses/agpl-3.0.html) for details.

---

Made with love for the WordPress community
