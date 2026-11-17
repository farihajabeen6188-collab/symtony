import { Controller } from '@hotwired/stimulus';

/* Command palette: Ctrl/Cmd+K, type to filter, arrows to move, Enter to open. */
export default class extends Controller {
    static targets = ['dialog', 'input', 'item', 'empty', 'list', 'shortcut'];

    connect() {
        if (this.hasShortcutTarget && /Mac|iPhone|iPad/.test(navigator.platform)) this.shortcutTarget.textContent = '⌘ K';
    }

    open() {
        if (this.dialogTarget.open) return;
        this.dialogTarget.showModal();
        this.inputTarget.value = '';
        this.filter();
        this.inputTarget.focus();
        this.dialogTarget.addEventListener('click', (e) => { if (e.target === this.dialogTarget) this.dialogTarget.close(); }, { once: true });
    }

    reset() {
        this.inputTarget.value = '';
    }

    visible() {
        return this.itemTargets.filter((el) => !el.closest('li').hidden);
    }

    filter() {
        const q = this.inputTarget.value.trim().toLowerCase();
        const words = q.split(/\s+/).filter(Boolean);
        this.itemTargets.forEach((el) => {
            const hay = el.dataset.keywords;
            el.closest('li').hidden = words.length > 0 && !words.every((w) => hay.includes(w));
        });
        const vis = this.visible();
        this.emptyTarget.hidden = vis.length > 0;
        this.select(vis[0]);
    }

    select(el) {
        this.itemTargets.forEach((i) => i.setAttribute('aria-selected', i === el ? 'true' : 'false'));
        el?.scrollIntoView({ block: 'nearest' });
    }

    navigate(e) {
        const vis = this.visible();
        const i = vis.findIndex((el) => el.getAttribute('aria-selected') === 'true');
        if (e.key === 'ArrowDown') { e.preventDefault(); this.select(vis[(i + 1) % vis.length]); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); this.select(vis[(i - 1 + vis.length) % vis.length]); }
        else if (e.key === 'Enter') { e.preventDefault(); vis[i]?.click(); this.dialogTarget.close(); }
    }
}
