import SimpleTurnstilePlugin from './simple-turnstile.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'SimpleTurnstile',
    SimpleTurnstilePlugin,
    '[data-simple-turnstile="true"]'
);

const reinitializePlugins = () => {
    window.setTimeout(() => {
        if (PluginManager && typeof PluginManager.initializePlugins === 'function') {
            PluginManager.initializePlugins();
        }
    }, 0);
};

document.addEventListener('shown.bs.modal', reinitializePlugins);
document.addEventListener('shown.bs.offcanvas', reinitializePlugins);
