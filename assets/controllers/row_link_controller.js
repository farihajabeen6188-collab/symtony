import { Controller } from '@hotwired/stimulus';

/* Makes table rows with data-href clickable while keeping a real <a> inside each row for keyboard users. */
export default class extends Controller {
    open(event) {
        if (event.target.closest('a, button, input, select, label, form')) return;
        const href = event.currentTarget.dataset.href;
        if (!href) return;
        if (event.metaKey || event.ctrlKey) window.open(href, '_blank');
        else window.location.assign(href);
    }
}
