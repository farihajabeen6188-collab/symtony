import { Controller } from '@hotwired/stimulus';

/* Put on a <form>: asks for confirmation in the shared dialog before submitting. */
export default class extends Controller {
    static values = { title: String, message: String, label: { type: String, default: 'Confirm' } };

    connect() {
        this.confirmed = false;
        this.onSubmit = (e) => this.intercept(e);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }

    intercept(event) {
        if (this.confirmed) { this.confirmed = false; return; }
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        const dialog = document.getElementById('confirm-dialog');
        dialog.querySelector('[data-confirm-dialog-target="title"]').textContent = this.titleValue || 'Are you sure?';
        dialog.querySelector('[data-confirm-dialog-target="message"]').textContent = this.messageValue;
        dialog.querySelector('[data-confirm-dialog-target="confirm"]').textContent = this.labelValue;
        dialog.returnValue = '';
        dialog.showModal();
        dialog.addEventListener('close', () => {
            if (dialog.returnValue === 'confirm') {
                this.confirmed = true;
                this.element.requestSubmit(submitter || undefined);
            } else {
                submitter?.focus();
            }
        }, { once: true });
    }
}
