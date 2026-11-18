import { Controller } from '@hotwired/stimulus';

/*
 * Command palette: Ctrl/Cmd+K. Static navigation is filtered locally; incidents, applications,
 * investigations, events and contextual actions come from the server as you type.
 * Recent picks are remembered in this browser only. "?" (outside text fields) lists the shortcuts.
 */
const RECENT_KEY = 'symtony.palette.recent';

export default class extends Controller {
    static targets = ['dialog', 'input', 'item', 'empty', 'list', 'shortcut', 'results', 'recent', 'spinner', 'help', 'navLabel'];
    static values = { url: String, context: String };

    connect() {
        if (this.hasShortcutTarget && /Mac|iPhone|iPad/.test(navigator.platform)) this.shortcutTarget.textContent = '⌘ K';
        this.onKey = (e) => {
            if (e.key !== '?' || e.ctrlKey || e.metaKey || e.altKey) return;
            const t = e.target;
            if (t instanceof HTMLElement && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return;
            if (document.querySelector('dialog[open]')) return;
            e.preventDefault();
            this.helpTarget.showModal();
        };
        window.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        window.removeEventListener('keydown', this.onKey);
        this.abort?.abort();
    }

    open() {
        if (this.dialogTarget.open) return;
        this.dialogTarget.showModal();
        this.inputTarget.value = '';
        this.filter();
        this.inputTarget.focus();
        this.dialogTarget.addEventListener('click', (e) => { if (e.target === this.dialogTarget) this.dialogTarget.close(); }, { once: true });
    }

    closeHelp() {
        this.helpTarget.close();
    }

    reset() {
        this.inputTarget.value = '';
        this.resultsTarget.innerHTML = '';
    }

    visible() {
        return this.itemTargets.filter((el) => !el.closest('li').hidden);
    }

    filter() {
        const q = this.inputTarget.value.trim().toLowerCase();
        const words = q.split(/\s+/).filter(Boolean);
        this.listTarget.querySelectorAll('[data-palette-target="item"]').forEach((el) => {
            el.closest('li').hidden = words.length > 0 && !words.every((w) => el.dataset.keywords.includes(w));
        });
        this.navLabelTarget.hidden = this.listTarget.querySelectorAll('li:not([hidden]) [data-palette-target="item"]').length === 0;
        this.renderRecent(q === '');
        this.refresh();
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetchResults(q), q === '' ? 0 : 150);
    }

    async fetchResults(q) {
        if (!this.hasUrlValue) return;
        this.abort?.abort();
        this.abort = new AbortController();
        this.spinnerTarget.classList.remove('hidden');
        try {
            const params = new URLSearchParams({ q, context: this.contextValue || '' });
            const response = await fetch(`${this.urlValue}?${params}`, { signal: this.abort.signal, headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            if (this.inputTarget.value.trim().toLowerCase() !== q) return;
            this.resultsTarget.innerHTML = await response.text();
        } catch (error) {
            if (error.name === 'AbortError') return;
            this.resultsTarget.innerHTML = `<li class="px-2 py-2 text-xs text-err" role="presentation">Search failed (${error.message}). Navigation below still works.</li>`;
        } finally {
            this.spinnerTarget.classList.add('hidden');
        }
        this.refresh();
    }

    refresh() {
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
        else if (e.key === 'Enter') {
            e.preventDefault();
            const el = vis[i];
            if (!el) return;
            this.remember({ target: el });
            el.click();
            this.dialogTarget.close();
        }
    }

    remember(event) {
        const link = event.target.closest?.('a[data-palette-target="item"][data-recent]');
        if (!link) return;
        const entry = { href: link.getAttribute('href'), label: link.dataset.recent };
        const list = [entry, ...this.readRecent().filter((r) => r.href !== entry.href)].slice(0, 5);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch { /* storage unavailable: recents are optional */ }
    }

    readRecent() {
        try {
            const parsed = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
            return Array.isArray(parsed) ? parsed.filter((r) => typeof r.href === 'string' && r.href.startsWith('/app/') && typeof r.label === 'string') : [];
        } catch {
            return [];
        }
    }

    renderRecent(show) {
        this.recentTarget.replaceChildren();
        const recent = show ? this.readRecent() : [];
        if (recent.length === 0) return;
        const label = document.createElement('li');
        label.className = 'px-2 pb-1 pt-3 text-2xs font-medium text-fg-3';
        label.setAttribute('role', 'presentation');
        label.textContent = 'Recent';
        this.recentTarget.append(label);
        recent.forEach((r) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = r.href;
            a.className = 'menu-item';
            a.setAttribute('role', 'option');
            a.dataset.paletteTarget = 'item';
            a.dataset.keywords = '';
            a.dataset.recent = r.label;
            const span = document.createElement('span');
            span.className = 'flex-1 truncate';
            span.textContent = r.label;
            a.append(span);
            li.append(a);
            this.recentTarget.append(li);
        });
    }
}
