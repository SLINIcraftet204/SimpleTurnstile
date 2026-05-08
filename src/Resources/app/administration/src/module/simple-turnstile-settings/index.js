import './page/simple-turnstile-settings-index';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('simple-turnstile', {
    type: 'plugin',
    name: 'SimpleTurnstile',
    title: 'simple-turnstile.general.title',
    description: 'simple-turnstile.general.description',
    color: '#f38020',
    icon: 'regular-shield',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'simple-turnstile-settings-index',
            path: 'index',
        },
    },

    settingsItem: [
        {
            id: 'simple-turnstile-settings',
            group: 'plugins',
            icon: 'regular-shield',
            to: 'simple.turnstile.index',
            name: 'simple-turnstile.general.settingsItemLabel',
            label: 'simple-turnstile.general.settingsItemLabel',
        },
    ],
});
