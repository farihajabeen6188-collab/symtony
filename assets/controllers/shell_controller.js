import { Controller } from '@hotwired/stimulus';

/* Sidebar: collapse on desktop (persisted in a cookie), drawer on mobile. */
export default class extends Controller {
    static targets = ['root', 'scrim', 'collapseButton'];

    connect() {
        this.syncButton();
        this.onKey = (e) => { if (e.key === 'Escape') this.closeMobile(); };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    toggleCollapse() {
        const collapsed = this.element.toggleAttribute('data-collapsed');
        document.cookie = `symtony_sidebar=${collapsed ? 'collapsed' : 'open'}; path=/; max-age=31536000; samesite=lax`;
        this.syncButton();
    }

    openMobile() {
        this.element.setAttribute('data-mobile-open', '');
        if (this.hasScrimTarget) this.scrimTarget.hidden = false;
        this.element.querySelector('.sidebar a')?.focus();
    }

    closeMobile() {
        if (!this.element.hasAttribute('data-mobile-open')) return;
        this.element.removeAttribute('data-mobile-open');
        if (this.hasScrimTarget) this.scrimTarget.hidden = true;
    }

    syncButton() {
        if (!this.hasCollapseButtonTarget) return;
        const collapsed = this.element.hasAttribute('data-collapsed');
        this.collapseButtonTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        this.collapseButtonTarget.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        this.element.querySelectorAll('.nav-item[data-shell-tip]').forEach((a) => {
            if (collapsed) a.dataset.tip = a.dataset.shellTip; else delete a.dataset.tip;
        });
    }
}
