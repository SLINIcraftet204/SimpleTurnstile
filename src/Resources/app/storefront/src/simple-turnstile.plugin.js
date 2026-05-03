import Plugin from 'src/plugin-system/plugin.class';

const SCRIPT_ID = 'simple-turnstile-api-script';
const LOADING_PROMISE_KEY = '__simpleTurnstileLoadingPromise';
const FORM_SUBMIT_RESET_DELAY = 2500;

export default class SimpleTurnstilePlugin extends Plugin {
    init() {
        this.widgetId = null;
        this.resetTimeout = null;
        this.widgetElement = this.el.querySelector('[data-simple-turnstile-widget="true"]');
        this.form = this.el.closest('form');

        this._boundHandleSubmit = this._handleSubmit.bind(this);

        if (this.form) {
            this.form.addEventListener('submit', this._boundHandleSubmit);
        }

        if (!this.widgetElement) {
            return;
        }

        this._loadTurnstile()
            .then(() => this._renderWidget())
            .catch(() => {
                // Server-side validation will still reject missing tokens.
            });
    }

    destroy() {
        if (this.form) {
            this.form.removeEventListener('submit', this._boundHandleSubmit);
        }

        if (this.resetTimeout) {
            window.clearTimeout(this.resetTimeout);
            this.resetTimeout = null;
        }

        if (window.turnstile && this.widgetId !== null && typeof window.turnstile.remove === 'function') {
            window.turnstile.remove(this.widgetId);
        }

        this.widgetId = null;

        if (this.widgetElement) {
            delete this.widgetElement.dataset.simpleTurnstileRendered;
        }
    }

    _renderWidget() {
        if (!window.turnstile || this.widgetId !== null || !this.el.isConnected) {
            return;
        }

        if (this.widgetElement.dataset.simpleTurnstileRendered === 'true') {
            return;
        }

        const siteKey = this.el.dataset.sitekey;

        if (!siteKey) {
            return;
        }

        const widgetId = window.turnstile.render(this.widgetElement, {
            sitekey: siteKey,
            theme: this.el.dataset.theme || 'auto',
            size: this.el.dataset.size || 'normal',
            language: this.el.dataset.language || 'auto',
            'response-field': true,
            'response-field-name': this.el.dataset.responseFieldName || 'cf-turnstile-response',
            'refresh-expired': 'auto',
            callback: () => {
                this.el.dataset.simpleTurnstileSolved = 'true';
            },
            'expired-callback': () => {
                this.el.dataset.simpleTurnstileSolved = 'false';
            },
            'timeout-callback': () => {
                this.el.dataset.simpleTurnstileSolved = 'false';
            },
            'error-callback': () => {
                this.el.dataset.simpleTurnstileSolved = 'false';
                this._scheduleReset(1000);
            },
        });

        if (typeof widgetId === 'undefined' || widgetId === null) {
            return;
        }

        this.widgetId = widgetId;
        this.widgetElement.dataset.simpleTurnstileRendered = 'true';
    }

    _handleSubmit() {
        /*
         * Turnstile tokens are single-use.
         * If Shopware keeps the user on the same page after an AJAX validation error,
         * the old token must not be reused.
         */
        this._scheduleReset(FORM_SUBMIT_RESET_DELAY);
    }

    _scheduleReset(delay) {
        if (this.resetTimeout) {
            window.clearTimeout(this.resetTimeout);
        }

        this.resetTimeout = window.setTimeout(() => {
            this.resetTimeout = null;

            if (!this.el.isConnected) {
                return;
            }

            this.reset();
        }, delay);
    }

    reset() {
        if (!window.turnstile || this.widgetId === null) {
            return;
        }

        window.turnstile.reset(this.widgetId);
        this.el.dataset.simpleTurnstileSolved = 'false';
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