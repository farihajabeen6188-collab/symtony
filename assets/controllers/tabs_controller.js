import { Controller } from '@hotwired/stimulus';

/* Client-side tabs with arrow-key navigation (WAI-ARIA tabs pattern). */
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
    }

    select(event) {
        this.activate(this.tabTargets.indexOf(event.currentTarget));
    }

    activate(index) {
        this.tabTargets.forEach((tab, i) => {
            tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
            tab.tabIndex = i === index ? 0 : -1;
        });
        this.panelTargets.forEach((panel, i) => { panel.hidden = i !== index; });
    }
}
