import { Controller } from '@hotwired/stimulus';

const ORDER = ['dark', 'light', 'system'];

/* Cycles dark → light → system. Signed-in users persist to their profile; guests to localStorage. */
export default class extends Controller {
    static values = { url: String, token: String };

    connect() {
        this.render(document.documentElement.dataset.themePref || 'dark');
    }

    cycle() {
        const current = document.documentElement.dataset.themePref || 'dark';
        const next = ORDER[(ORDER.indexOf(current) + 1) % ORDER.length];
        this.apply(next);
    }

    apply(theme) {
        const root = document.documentElement;
        root.dataset.theme = theme;
        root.dataset.themePref = theme;
        this.render(theme);
        try { localStorage.setItem('symtony.theme', theme); } catch (e) { /* storage unavailable */ }

        if (this.urlValue) {
            const body = new FormData();
            body.set('theme', theme);
            body.set('_token', this.tokenValue);
            fetch(this.urlValue, { method: 'POST', body, headers: { Accept: 'application/json' } }).catch(() => {});
        }
        window.dispatchEvent(new CustomEvent('symtony:theme', { detail: { theme } }));
    }

    render(theme) {
        document.querySelectorAll('[data-theme-icon]').forEach((el) => {
            el.hidden = el.dataset.themeIcon !== theme;
        });
        document.querySelectorAll('[data-theme-target="button"]').forEach((btn) => {
            btn.setAttribute('aria-label', `Theme: ${theme}. Switch theme`);
            btn.title = `Theme: ${theme}`;
        });
    }
}
