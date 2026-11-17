import { Controller } from '@hotwired/stimulus';

/* Shared confirmation dialog; content is set by confirm_controller. Clicking the backdrop cancels. */
export default class extends Controller {
    static targets = ['title', 'message', 'confirm'];

    connect() {
        this.element.addEventListener('click', (e) => { if (e.target === this.element) this.element.close('cancel'); });
    }
}
