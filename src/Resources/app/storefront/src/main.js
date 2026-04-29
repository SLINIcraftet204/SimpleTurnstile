import SimpleTurnstilePlugin from './simple-turnstile.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'SimpleTurnstile',
    SimpleTurnstilePlugin,
    '[data-simple-turnstile="true"]'
);