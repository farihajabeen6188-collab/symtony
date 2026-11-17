import { Controller } from '@hotwired/stimulus';

/* Multi-step form: steps 1–3 are client-side; the final step submits once to create app + token. */
export default class extends Controller {
    static targets = ['step', 'indicator', 'back', 'next', 'submit', 'required'];
    static values = { step: { type: Number, default: 1 } };

    connect() {
        this.last = this.stepTargets.length;
        this.render();
        this.element.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT' && this.stepValue < this.last) {
                e.preventDefault();
                this.next();
            }
        });
    }

    next() {
        if (!this.validStep()) return;
        this.stepValue = Math.min(this.last, this.stepValue + 1);
    }

    back() {
        this.stepValue = Math.max(1, this.stepValue - 1);
    }

    stepValueChanged() {
        if (this.last) this.render();
    }

    validStep() {
        const step = this.stepTargets[this.stepValue - 1];
        for (const input of step.querySelectorAll('[data-wizard-target~="required"]')) {
            if (!input.value.trim()) {
                input.setAttribute('aria-invalid', 'true');
                let msg = input.parentElement.querySelector('.field-error');
                if (!msg) {
                    msg = document.createElement('p');
                    msg.className = 'field-error';
                    input.insertAdjacentElement('afterend', msg);
                    input.parentElement.querySelector('.field-help')?.remove();
                }
                msg.textContent = 'Give the application a name.';
                input.focus();
                return false;
            }
            input.removeAttribute('aria-invalid');
        }
        return true;
    }

    render() {
        this.stepTargets.forEach((el) => { el.hidden = Number(el.dataset.index) !== this.stepValue; });
        this.indicatorTargets.forEach((el) => {
            const i = Number(el.dataset.index);
            el.querySelector('[data-bar]').className = `h-1 rounded-full ${i <= this.stepValue ? 'bg-accent' : 'bg-surface-3'}`;
            if (i === this.stepValue) el.setAttribute('aria-current', 'step'); else el.removeAttribute('aria-current');
        });
        this.backTarget.style.visibility = this.stepValue === 1 ? 'hidden' : 'visible';
        const final = this.stepValue === this.last;
        this.nextTarget.hidden = final;
        this.submitTarget.hidden = !final;
        const current = this.stepTargets[this.stepValue - 1];
        current?.querySelector('input:not([type=hidden]), select, textarea')?.focus({ preventScroll: true });
    }
}
