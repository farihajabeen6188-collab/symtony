import { Controller } from '@hotwired/stimulus';

/* Opens a native <dialog> by id and returns focus to the trigger on close. */
export default class extends Controller {
    open(event) {
        const dialog = document.getElementById(event.params.id);
        if (!dialog) return;
        const trigger = event.currentTarget;
        dialog.showModal();
        dialog.addEventListener('close', () => trigger?.focus(), { once: true });
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    }

    close(event) {
        document.getElementById(event.params.id)?.close();
    }
}
