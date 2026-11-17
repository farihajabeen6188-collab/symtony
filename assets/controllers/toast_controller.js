import { Controller } from '@hotwired/stimulus';

const ICONS = {
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-ok"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-err"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-accent"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
};

/* Flash messages + `window.dispatchEvent(new CustomEvent('symtony:toast', {detail: {message, type}}))`. */
export default class extends Controller {
    static targets = ['stack', 'item'];

    connect() {
        this.onToast = (e) => this.show(e.detail.message, e.detail.type || 'info');
        window.addEventListener('symtony:toast', this.onToast);
    }

    disconnect() {
        window.removeEventListener('symtony:toast', this.onToast);
    }

    itemTargetConnected(el) {
        setTimeout(() => el.remove(), 6000);
    }

    show(message, type) {
        const el = document.createElement('div');
        el.className = 'toast';
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = `${ICONS[type] || ICONS.info}<div class="flex-1 text-fg"></div><button type="button" class="text-fg-3 hover:text-fg" aria-label="Dismiss"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg></button>`;
        el.querySelector('div').textContent = message;
        el.querySelector('button').addEventListener('click', () => el.remove());
        el.dataset.toastTarget = 'item';
        this.stackTarget.appendChild(el);
    }

    dismiss(event) {
        event.currentTarget.closest('.toast')?.remove();
    }
}
