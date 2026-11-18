import { Controller } from '@hotwired/stimulus';

/*
 * Re-fetches a turbo-frame every 2 s until the server marks it final, then reloads the page once so the
 * finished report renders. This is polling, not push — the UI never claims real-time delivery.
 */
export default class extends Controller {
    static values = { url: String, final: Boolean, interval: { type: Number, default: 2000 } };

    connect() {
        if (this.finalValue) {
            return;
        }
        this.timer = setTimeout(() => this.tick(), this.intervalValue);
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async tick() {
        try {
            const res = await fetch(this.urlValue, { headers: { 'Turbo-Frame': this.element.id } });
            if (!res.ok) throw new Error(res.status);
            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const frame = doc.getElementById(this.element.id);
            if (!frame) throw new Error('frame missing');
            const done = frame.dataset.pollFinalValue === 'true';
            this.element.innerHTML = frame.innerHTML;
            if (done) {
                window.location.reload();
                return;
            }
        } catch (e) { /* keep polling through transient errors */ }
        this.timer = setTimeout(() => this.tick(), this.intervalValue);
    }
}
