import { Controller } from '@hotwired/stimulus';

/* Marks a form's button as busy while a long-running submit is in flight (prevents double submits). */
export default class extends Controller {
    static targets = ['button'];

    start() {
        if (!this.hasButtonTarget) return;
        this.buttonTarget.setAttribute('data-loading', '');
        this.buttonTarget.setAttribute('aria-busy', 'true');
    }
}
