import template from './simple-turnstile-settings-index.html.twig';

const { Component } = Shopware;

Component.register('simple-turnstile-settings-index', {
    template,

    mounted() {
        this.redirectToPluginConfiguration();
    },

    methods: {
        redirectToPluginConfiguration() {
            this.$router.replace({
                name: 'sw.extension.config',
                params: {
                    namespace: 'SimpleTurnstile',
                },
            });
        },
    },
});
