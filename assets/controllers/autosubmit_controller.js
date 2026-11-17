import { Controller } from '@hotwired/stimulus';

/* Filter forms: submit on change, or after typing pauses. */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }

    debounced() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.element.requestSubmit(), 350);
    }
}
