# Simple Turnstile

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![Shopware 6.7](https://img.shields.io/badge/Shopware-6.7.x-blue)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4)

**Simple Turnstile** adds [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) as a clean, privacy-friendly captcha option for **Shopware 6**.

It integrates into Shopware's native storefront captcha system, can be selected in **Settings > Basic information > Captcha**, and keeps its configuration stable across plugin deactivate/uninstall/reinstall workflows when user data is retained.

> This plugin is not affiliated with, endorsed by, or sponsored by Cloudflare or Shopware.

---

## Features

- Cloudflare Turnstile integration for Shopware storefront forms
- Native Shopware captcha registration via `shopware.storefront.captcha`
- Configurable **Site Key** and **Secret Key**
- Theme options: `auto`, `light`, `dark`
- Size options: `normal`, `compact`, `flexible`
- Language options: `auto`, `de`, `en`
- Optional remote IP forwarding to Cloudflare validation
- Optional debug logging for validation troubleshooting
- German and English storefront snippets
- Lifecycle-safe configuration backup for normal uninstall/reinstall flows
- Does not overwrite another active Shopware captcha method during restore

---

## Roadmap

Simple Turnstile is intended to stay lightweight, free, and focused on a clean Cloudflare Turnstile integration for Shopware 6. The roadmap below shows the current direction of the project and may change depending on Shopware compatibility requirements, user feedback, and long-term maintainability.

### 0.1.x - Foundation and stability

Current focus:

- Stable Cloudflare Turnstile integration for Shopware storefront captcha handling
- Reliable lifecycle handling for deactivate, uninstall, reinstall, and retained user data
- Configuration backup through `simple_turnstile_lifecycle_state`
- Storefront widget rendering fallback for reliable Turnstile display
- English and German snippets
- Public documentation, contribution guidelines, and issue templates

### 0.2.x - Administration and usability polish

Planned improvements:

- Reliable settings entry under **Settings > Extensions > Simple Turnstile**
- Better administration snippets and descriptions
- Clearer configuration hints for Site Key, Secret Key, domain setup, and debug mode
- Improved validation messages for common setup mistakes
- Release packaging cleanup for the Shopware Store

### 0.3.x - Diagnostics and health checks

Planned improvements:

- Administration health check page
- Checks for configured Site Key and Secret Key
- Check whether Simple Turnstile is selected as the active Shopware captcha method
- Check whether the lifecycle backup table exists and contains a current snapshot
- Display the last known validation status without exposing secrets or full response tokens
- Human-readable explanations for common Cloudflare validation errors

### 0.4.x - Advanced Turnstile options

Planned improvements:

- Additional Turnstile widget modes where technically safe and useful
- More flexible widget appearance and rendering options
- Optional hostname validation
- Optional action validation for protected forms
- Better support for different storefront layouts and custom themes

### 0.5.x - Form protection manager

Planned improvements:

- More granular control over which storefront forms should be protected
- Optional per-form actions for better validation context
- Better handling for newsletter, contact, registration, login, password reset, and review forms
- Extensible structure for custom forms in individual storefront projects

### Future ideas

Ideas under evaluation:

- Optional Turnstile protection for the Shopware Administration login
- Protection only after failed login attempts
- IP allowlist or emergency bypass for administration access
- Additional developer hooks/events for custom validation flows

Administration login protection is intentionally not part of the initial roadmap because it must be implemented very carefully to avoid locking shop owners out of their own administration.

---

## Compatibility

| Requirement | Version |
| --- | --- |
| PHP | `>= 8.2` |
| Shopware Core | `~6.7.0` |
| Shopware Storefront | `~6.7.0` |

The plugin is currently targeted at Shopware **6.7.x**.

Tested in Shopware from Version 6.7.9.1 to 6.7.11.1.

---

## Installation

Copy the plugin into your Shopware installation:

```bash
custom/plugins/SimpleTurnstile
```

Then run:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SimpleTurnstile
bin/console database:migrate SimpleTurnstile --all
bin/console cache:clear
bin/console theme:compile
```

If your environment uses PHP-FPM with OPcache, reload PHP-FPM after replacing plugin files:

```bash
systemctl reload php8.3-fpm
```

Adjust the PHP-FPM service name to match your server.

---

## Configuration

Open the Shopware Administration:

```text
Extensions > My extensions > Simple Turnstile > Configure
```

Set the following values:

| Setting | Description |
| --- | --- |
| Site Key | Public Cloudflare Turnstile site key |
| Secret Key | Private Cloudflare Turnstile secret key used for server-side validation |
| Theme | Widget theme: `auto`, `light`, `dark` |
| Size | Widget size: `normal`, `compact`, `flexible` |
| Language | Widget language: `auto`, `de`, `en` |
| Send visitor IP to Cloudflare | Optional `remoteip` parameter for Cloudflare validation |
| Enable debug logging | Writes additional validation information to the Shopware log |

After saving the plugin configuration, enable Simple Turnstile as the active captcha method:

```text
Settings > Basic information > Captcha
```

Select **Simple Turnstile** and save.

---

## Cloudflare setup

Create a Turnstile widget in your Cloudflare dashboard and add your shop domain to the widget's allowed hostnames.

Use:

- **Site Key** in the plugin's `siteKey` setting
- **Secret Key** in the plugin's `secretKey` setting

For local testing, make sure your local/test hostname is also allowed by Cloudflare or use a dedicated Cloudflare test widget.

---

## Lifecycle behavior

Simple Turnstile stores its normal Shopware configuration in `system_config` using these keys:

```text
SimpleTurnstile.config.siteKey
SimpleTurnstile.config.secretKey
SimpleTurnstile.config.theme
SimpleTurnstile.config.size
SimpleTurnstile.config.language
SimpleTurnstile.config.sendRemoteIp
SimpleTurnstile.config.debugLogging
```

Shopware's active captcha configuration is stored in:

```text
core.basicInformation.activeCaptchasV2
```

To avoid configuration loss during plugin lifecycle operations, the plugin additionally uses an internal backup table:

```text
simple_turnstile_lifecycle_state
```

Expected behavior:

| Action | Behavior |
| --- | --- |
| Install | Registers Simple Turnstile as an available captcha method without forcing it active |
| Activate | Restores previous config and active state when no other captcha method is active |
| Deactivate | Backs up config and captcha state, then removes Simple Turnstile from active captcha settings |
| Uninstall with user data retained | Backs up config, removes active captcha entry, and restores retained plugin config |
| Uninstall with user data removed | Removes plugin config and drops the lifecycle backup table |

The plugin intentionally does **not** reactivate itself if another captcha method is currently active.

---

## Debugging

Enable **Debug logging** in the plugin configuration to write additional validation details to the Shopware log.

Useful SQL checks during development:

```sql
SELECT
    configuration_key,
    JSON_PRETTY(configuration_value) AS configuration_value,
    sales_channel_id,
    created_at,
    updated_at
FROM system_config
WHERE configuration_key = 'core.basicInformation.activeCaptchasV2'
   OR configuration_key LIKE 'SimpleTurnstile.config.%'
ORDER BY configuration_key;
```

```sql
SELECT
    state_key,
    JSON_PRETTY(state_value) AS state_value,
    created_at,
    updated_at
FROM simple_turnstile_lifecycle_state;
```

Browser-side checks:

```js
document.querySelector('.simple-turnstile-captcha')
document.querySelector('.simple-turnstile-widget iframe')
document.querySelector('input[name="cf-turnstile-response"]')
window.turnstile
```

Common causes for failed validation:

- Missing or wrong Secret Key
- Turnstile widget domain mismatch in Cloudflare
- Browser extension, CSP, firewall, or proxy blocking Cloudflare's Turnstile script
- Missing `cf-turnstile-response` token on form submit
- Stale Shopware cache, theme cache, or PHP OPcache

---

## Development

Recommended development cycle:

```bash
bin/console plugin:refresh
bin/console cache:clear
bin/console theme:compile
```

Before opening a pull request, run at least:

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
bin/console cache:clear
bin/console theme:compile
```

For lifecycle-related changes, manually test:

1. Save real Turnstile keys in the plugin configuration.
2. Activate Simple Turnstile in Basic information.
3. Deactivate the plugin.
4. Activate it again.
5. Uninstall with user data retained.
6. Reinstall and activate.
7. Verify that config values and captcha state are restored correctly.
8. Uninstall with user data removed and verify cleanup.

---

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening issues or pull requests.

---

## License

This project is licensed under the [MIT License](LICENSE).
