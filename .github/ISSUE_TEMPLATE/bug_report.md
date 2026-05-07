---
name: Bug report
about: Report a reproducible problem with Simple Turnstile
title: "[Bug]: "
labels: bug
assignees: ""
---

## Description

Describe the bug clearly.

## Expected behavior

What should happen?

## Actual behavior

What happens instead?

## Steps to reproduce

1.
2.
3.
4.

## Environment

- Simple Turnstile version:
- Shopware version:
- PHP version:
- Web server: Apache / Nginx / other
- Installation type: Docker / local / production / other
- Browser and version:
- Theme: default / custom / other

## Captcha configuration

- [ ] Site Key is configured
- [ ] Secret Key is configured
- [ ] Simple Turnstile is selected in Basic information > Captcha
- [ ] Another captcha method is active
- [ ] Debug logging is enabled
- [ ] Remote IP forwarding is enabled

## Lifecycle context

Did this happen after one of these actions?

- [ ] Fresh install
- [ ] Update
- [ ] Activate
- [ ] Deactivate
- [ ] Uninstall with user data retained
- [ ] Uninstall with user data removed
- [ ] Reinstall
- [ ] Cache clear / theme compile

## Logs

Paste relevant Shopware logs or browser console errors.

```text

```

## SQL/debug output

If the problem is related to config persistence or captcha activation, please include sanitized output from:

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

Remove or mask real secrets before posting.

## Screenshots

Add screenshots if they help explain the issue.

## Additional context

Add anything else that might be relevant.
