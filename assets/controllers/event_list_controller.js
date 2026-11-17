import { Controller } from '@hotwired/stimulus';

/* Highlights the selected row and keeps ?event= in the URL so the selection is shareable. */
export default class extends Controller {
    select(event) {
        this.element.querySelectorAll('[aria-current="true"]').forEach((el) => el.removeAttribute('aria-current'));
        event.currentTarget.setAttribute('aria-current', 'true');
        const id = event.currentTarget.getAttribute('href').split('/').pop();
        const url = new URL(window.location.href);
        url.searchParams.set('event', id);
        url.searchParams.delete('before');
        history.replaceState(history.state, '', url);
    }
}
