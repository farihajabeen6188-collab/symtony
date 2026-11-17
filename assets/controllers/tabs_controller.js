import { Controller } from '@hotwired/stimulus';

/*
 * Client-side tabs with arrow-key navigation (WAI-ARIA tabs pattern).
 * Tabs with data-hash are deep-linkable: #credentials opens that tab and selecting a tab updates the URL hash.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.tabTargets.forEach((tab, i) => {
            tab.addEventListener('keydown', (e) => {
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
                const next = (i + (e.key === 'ArrowRight' ? 1 : -1) + this.tabTargets.length) % this.tabTargets.length;
                this.activate(next);
                this.tabTargets[next].focus();
            });
        });
        const hash = window.location.hash.slice(1);
        const fromHash = this.tabTargets.findIndex((t) => t.dataset.hash && t.dataset.hash === hash);
        this.activate(fromHash >= 0 ? fromHash : Math.max(0, this.tabTargets.findIndex((t) => t.getAttribute('aria-selected') === 'true')));
    }

    select(event) {
        const index = this.tabTargets.indexOf(event.currentTarget);
        this.activate(index);
        const hash = event.currentTarget.dataset.hash;
        if (hash) history.replaceState(null, '', `#${hash}`);
    }

    activate(index) {
        this.tabTargets.forEach((tab, i) => {
            tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
            tab.tabIndex = i === index ? 0 : -1;
        });
        this.panelTargets.forEach((panel, i) => { panel.hidden = i !== index; });
    }
}
