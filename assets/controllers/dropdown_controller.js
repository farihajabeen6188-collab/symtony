import { Controller } from '@hotwired/stimulus';

/* Accessible menu: click/Enter opens, arrows move, Esc/outside click closes and restores focus. */
export default class extends Controller {
    static targets = ['button', 'menu'];

    connect() {
        this.outside = (e) => { if (!this.element.contains(e.target)) this.close(); };
        this.keys = (e) => this.onKey(e);
    }

    toggle() {
        this.menuTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.menuTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        document.addEventListener('click', this.outside, true);
        this.element.addEventListener('keydown', this.keys);
        this.items()[0]?.focus();
    }

    close(restoreFocus = false) {
        if (this.menuTarget.hidden) return;
        this.menuTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', this.outside, true);
        this.element.removeEventListener('keydown', this.keys);
        if (restoreFocus) this.buttonTarget.focus();
    }

    items() {
        return [...this.menuTarget.querySelectorAll('.menu-item:not([disabled])')];
    }

    onKey(e) {
        const items = this.items();
        const i = items.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); this.close(true); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length]?.focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length]?.focus(); }
        else if (e.key === 'Tab') { this.close(); }
    }

    disconnect() {
        document.removeEventListener('click', this.outside, true);
    }
}
