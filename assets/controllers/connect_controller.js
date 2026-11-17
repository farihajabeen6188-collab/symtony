import { Controller } from '@hotwired/stimulus';

/*
 * Wizard steps 5–6: polls the application status until the first event arrives,
 * and can send a real test event through the public ingestion API using the one-time token.
 */
export default class extends Controller {
    static targets = ['waiting', 'success', 'statusLabel', 'statusBadge', 'latest', 'testButton', 'testError', 'verifyStep', 'doneStep'];
    static values = { statusUrl: String, endpoint: String, service: String, connected: Boolean };

    connect() {
        if (!this.connectedValue) this.timer = setInterval(() => this.poll(), 2000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async poll() {
        try {
            const res = await fetch(this.statusUrlValue, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (data.first_event_at) this.showConnected(data);
        } catch (e) { /* transient network error: keep polling */ }
    }

    showConnected(data) {
        clearInterval(this.timer);
        this.connectedValue = true;
        this.waitingTarget.hidden = true;
        this.successTarget.hidden = false;
        this.statusLabelTarget.textContent = data.label;
        const dot = this.statusBadgeTarget.querySelector('.dot');
        dot.className = 'dot dot-live text-ok';
        [this.verifyStepTarget, this.doneStepTarget].forEach((el) => {
            el.querySelector('[data-bar]').className = 'h-1 rounded-full bg-accent';
        });
        if (data.latest) {
            this.latestTarget.replaceChildren();
            const meta = document.createElement('p');
            meta.className = 't-meta';
            meta.textContent = `${data.latest.occurred_at} · ${data.latest.service} · ${data.latest.type}`;
            const msg = document.createElement('p');
            msg.className = 'mt-1 truncate text-fg';
            msg.textContent = data.latest.message;
            this.latestTarget.append(meta, msg);
        }
        window.dispatchEvent(new CustomEvent('symtony:toast', { detail: { message: 'First event received. The application is connected.', type: 'success' } }));
    }

    async sendTest(event) {
        const button = event.currentTarget;
        button.setAttribute('data-loading', '');
        this.testErrorTarget.classList.add('hidden');
        const body = {
            timestamp: new Date().toISOString(),
            severity: 'info',
            type: 'log',
            service: this.serviceValue,
            message: 'SYMTONY connection test',
            name: 'connection-test',
            context: { sent_from: 'symtony-connect-wizard' },
        };
        try {
            const res = await fetch(this.endpointValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${button.dataset.token}`, 'X-Symtony-Source': 'http' },
                body: JSON.stringify(body),
            });
            if (res.status !== 202) {
                const problem = await res.json().catch(() => ({}));
                throw new Error(problem.detail || `Ingestion API answered ${res.status}.`);
            }
            await this.poll();
        } catch (e) {
            this.testErrorTarget.textContent = `Test event failed: ${e.message}`;
            this.testErrorTarget.classList.remove('hidden');
        } finally {
            button.removeAttribute('data-loading');
        }
    }
}
