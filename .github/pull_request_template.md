## Summary

Describe what this pull request changes and why.

## Type of change

- [ ] Bug fix
- [ ] Feature
- [ ] Refactor
- [ ] Documentation
- [ ] Maintenance / release preparation
- [ ] Other:

## Related issue

Closes #

## What changed?

-
-
-

## Shopware compatibility

Tested with:

- Shopware version:
- PHP version:
- Installation type: Docker / local / production-like / other

## Manual test checklist

### General

- [ ] `bin/console plugin:refresh` works
- [ ] `bin/console cache:clear` works
- [ ] `bin/console theme:compile` works
- [ ] PHP files pass syntax checks

### Captcha behavior

- [ ] Simple Turnstile appears in Basic information > Captcha
- [ ] Captcha widget renders in the storefront
- [ ] `cf-turnstile-response` is submitted with the protected form
- [ ] Valid captcha response passes validation
- [ ] Missing/invalid captcha response fails validation cleanly

### Configuration

- [ ] Site Key and Secret Key can be saved
- [ ] Theme, size, language, remote IP, and debug logging can be saved
- [ ] No secrets are logged

### Lifecycle behavior

- [ ] Deactivate removes Simple Turnstile from `activeCaptchasV2`
- [ ] Reactivate restores active state when appropriate
- [ ] Another active captcha method is not overwritten
- [ ] Uninstall with user data retained preserves config
- [ ] Reinstall after retained-data uninstall restores config
- [ ] Uninstall with user data removed cleans plugin data

## Database / migration impact

- [ ] No database changes
- [ ] Includes database migration
- [ ] Migration is idempotent
- [ ] Destructive changes are documented

Details:

## Screenshots / logs

Add screenshots, SQL output, or relevant logs if the change affects UI, rendering, validation, or lifecycle behavior.

## Breaking changes

- [ ] No breaking changes
- [ ] Breaking changes are documented below

Details:

## Additional notes

