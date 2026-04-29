import Plugin from 'src/plugin-system/plugin.class';

const SCRIPT_ID = 'simple-turnstile-api-script';
const LOADING_PROMISE_KEY = '__simpleTurnstileLoadingPromise';

export default class SimpleTurnstilePlugin extends Plugin {
    init() {
        this.widgetId = null;
        this.widgetElement = this.el.querySelector('[data-simple-turnstile-widget="true"]');

        if (!this.widgetElement) {
            return;
        }

        this._loadTurnstile()
            .then(() => this._renderWidget())
            .catch(() => {
                // Silent by design. Server-side validation will still reject missing tokens.
            });
    }

    _renderWidget() {
        if (!window.turnstile || this.widgetId !== null || !this.el.isConnected) {
            return;
        }

        const siteKey = this.el.dataset.sitekey;

        if (!siteKey) {
            return;
        }

        this.widgetId = window.turnstile.render(this.widgetElement, {
            sitekey: siteKey,
            theme: this.el.dataset.theme || 'auto',
            size: this.el.dataset.size || 'normal',
            language: this.el.dataset.language || 'auto',
            'response-field': true,
            'response-field-name': 'cf-turnstile-response',
            'expired-callback': () => this.reset(),
            'error-callback': () => this.reset(),
        });
    }

    reset() {
        if (!window.turnstile || this.widgetId === null) {
            return;
        }

        window.turnstile.reset(this.widgetId);
    }

    _loadTurnstile() {
        if (window.turnstile) {
            return Promise.resolve(window.turnstile);
        }

        if (window[LOADING_PROMISE_KEY]) {
            return window[LOADING_PROMISE_KEY];
        }

        window[LOADING_PROMISE_KEY] = new Promise((resolve, reject) => {
            const existingScript = document.getElementById(SCRIPT_ID);

            if (existingScript) {
                this._waitForTurnstile(resolve, reject);
                return;
            }

            const script = document.createElement('script');
            script.id = SCRIPT_ID;
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.addEventListener('load', () => this._waitForTurnstile(resolve, reject));
            script.addEventListener('error', reject);

            document.head.appendChild(script);
        });

        return window[LOADING_PROMISE_KEY];
    }

    _waitForTurnstile(resolve, reject) {
        let attempts = 0;

        const interval = window.setInterval(() => {
            attempts += 1;

            if (window.turnstile) {
                window.clearInterval(interval);
                resolve(window.turnstile);
                return;
            }

            if (attempts >= 50) {
                window.clearInterval(interval);
                reject(new Error('Cloudflare Turnstile API was not available in time.'));
            }
        }, 100);
    }
}