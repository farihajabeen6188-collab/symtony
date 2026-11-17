import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['idle', 'done'];
    static values = { value: String };

    async copy(event) {
        event.preventDefault();
        try {
            await navigator.clipboard.writeText(this.valueValue);
        } catch (e) {
            // Fallback for non-secure contexts.
            const ta = document.createElement('textarea');
            ta.value = this.valueValue;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            ta.remove();
            if (!ok) {
                window.dispatchEvent(new CustomEvent('symtony:toast', { detail: { message: 'Copy failed. Select the text and copy it manually.', type: 'error' } }));
                return;
            }
        }
        this.idleTarget.hidden = true;
        this.doneTarget.hidden = false;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.idleTarget.hidden = false; this.doneTarget.hidden = true; }, 1600);
    }
}
