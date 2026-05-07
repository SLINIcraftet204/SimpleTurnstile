# Contributing to Simple Turnstile

Thank you for considering a contribution to Simple Turnstile.

This plugin touches Shopware's captcha system, plugin lifecycle hooks, storefront rendering, and persisted configuration. Please keep changes small, testable, and easy to review.

---

## Development principles

- Keep behavior compatible with Shopware's native plugin lifecycle.
- Do not overwrite merchant configuration unexpectedly.
- Respect uninstall with user data retained.
- Do not force Simple Turnstile active if another captcha method is active.
- Avoid broad rewrites when a focused fix is enough.
- Keep storefront behavior progressive and resilient: the captcha should render even when JavaScript initialization order changes.
- Never log secrets, captcha response tokens, or full customer data.

---

## Local setup

Place the plugin here:

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

After replacing plugin files in an OPcache/PHP-FPM environment, reload PHP-FPM or restart the container.

---

## Code style

- Use strict types in PHP files.
- Prefer explicit constructor dependencies.
- Keep services registered in `src/Resources/config/services.xml`.
- Use Shopware services where possible instead of bypassing framework behavior.
- Only use direct database writes when needed for lifecycle-safe persistence or exact `system_config` recovery.
- Keep migrations idempotent.
- Do not introduce hidden external dependencies without discussion.

---

## Manual test checklist

Before opening a pull request, test the relevant parts of this checklist.

### Installation and activation

- [ ] Fresh install works.
- [ ] Plugin activates without service/autowiring errors.
- [ ] `simple_turnstile_lifecycle_state` exists after migration/install.
- [ ] Simple Turnstile appears in Shopware's captcha configuration.

### Configuration

- [ ] Site Key and Secret Key can be saved.
- [ ] Theme, size, language, remote IP, and debug logging can be saved.
- [ ] Saved values are present in `system_config`.
- [ ] Saved values are mirrored to the lifecycle backup state.

### Storefront rendering

- [ ] Captcha container is rendered on protected forms.
- [ ] Cloudflare Turnstile iframe appears.
- [ ] `cf-turnstile-response` is created before submit.
- [ ] Protected form submission succeeds with valid Turnstile response.
- [ ] Protected form submission fails cleanly without a token.

### Lifecycle persistence

- [ ] Deactivate removes Simple Turnstile from `activeCaptchasV2`.
- [ ] Reactivate restores Simple Turnstile as active if it was active before and no other captcha is active.
- [ ] Reactivate does not override another active captcha method.
- [ ] Uninstall with user data retained preserves plugin config.
- [ ] Reinstall after retained-data uninstall restores config.
- [ ] Uninstall with user data removed cleans plugin config and lifecycle backup data.

### Logs and privacy

- [ ] Debug logs do not expose secrets.
- [ ] Debug logs do not expose full Turnstile response tokens.
- [ ] Error messages are understandable for merchants.

---

## Pull requests

Please keep PRs focused. A good PR includes:

- A clear explanation of the problem
- A clear explanation of the solution
- Screenshots or logs for UI/lifecycle/captcha changes where useful
- Manual test results
- Notes about migrations or backward compatibility

Large refactors should be discussed in an issue first.

---

## Reporting security issues

Please do not disclose security issues publicly before they can be reviewed. Open a private report if available on GitHub, or contact the maintainer directly.
