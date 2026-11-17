import { Controller } from '@hotwired/stimulus';

const NS = 'http://www.w3.org/2000/svg';
const LABEL_W = 176;
const ROW_H = 34;
const AXIS_H = 28;
const BAR_H = 14;
const POINTS = new Set(['exception', 'http_response', 'transaction', 'log', 'deploy']);

const el = (name, attrs = {}, parent = null) => {
    const node = document.createElementNS(NS, name);
    for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined) node.setAttribute(k, v);
    if (parent) parent.appendChild(node);
    return node;
};
const fmtMs = (ms) => (Math.abs(ms) < 1 ? `${ms.toFixed(2)} ms` : Math.abs(ms) < 1000 ? `${ms.toFixed(ms < 10 ? 1 : 0)} ms` : `${(ms / 1000).toFixed(2)} s`);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/*
 * Reconstructed incident timeline: service swimlanes, spans as bars, points as markers.
 * Solid links are proven by span/trace ids; dashed links are inferred from timing only.
 */
export default class extends Controller {
    static targets = ['canvas', 'minimap', 'tooltip', 'readout', 'errorsOnly', 'showContext', 'service', 'frame'];
    static values = { data: Object, panelUrl: String };

    connect() {
        this.items = this.dataValue.items || [];
        this.full = [0, Math.max(this.dataValue.duration_ms || 1, 1)];
        this.home = this.provenBounds();
        this.view = [...this.home];
        this.selected = null;
        this.byId = new Map(this.items.map((i) => [i.id, i]));
        this.bySpan = new Map(this.items.filter((i) => i.span_id).map((i) => [i.span_id, i]));
        this.ro = new ResizeObserver(() => this.render());
        this.ro.observe(this.canvasTarget);
        this.render();
    }

    disconnect() {
        this.ro?.disconnect();
    }

    // Default view: the request itself. Inferred context stays in the minimap until you zoom out.
    provenBounds() {
        const proven = (this.dataValue.items || []).filter((i) => i.proven);
        if (proven.length === 0) return this.paddedFull();
        const a = Math.min(...proven.map((i) => i.offset_ms));
        const b = Math.max(...proven.map((i) => i.offset_ms + (i.duration_ms || 0)));
        const pad = Math.max((b - a) * 0.05, 1);
        return [a - pad, b + pad * 1.6];
    }

    paddedFull() {
        const span = this.full[1] - this.full[0];
        return [this.full[0] - span * 0.02, this.full[1] + span * 0.04];
    }

    // ---------- filters ----------
    visibleItems() {
        const errorsOnly = this.hasErrorsOnlyTarget && this.errorsOnlyTarget.checked;
        const context = !this.hasShowContextTarget || this.showContextTarget.checked;
        const services = new Set(this.serviceTargets.filter((c) => c.checked).map((c) => c.value));
        return this.items.filter((i) => (context || i.proven) && (!errorsOnly || i.error) && (services.size === 0 || services.has(i.service)));
    }

    visibleLanes(items) {
        const used = new Set(items.map((i) => i.lane));
        return this.dataValue.lanes.filter((l) => used.has(l.key));
    }

    // ---------- geometry ----------
    x(ms) {
        const [a, b] = this.view;
        return LABEL_W + ((ms - a) / (b - a)) * this.plotW;
    }

    msAt(px) {
        const [a, b] = this.view;
        return a + ((px - LABEL_W) / this.plotW) * (b - a);
    }

    // ---------- rendering ----------
    render() {
        const width = Math.max(this.canvasTarget.clientWidth, 480);
        this.plotW = width - LABEL_W - 12;
        const items = this.visibleItems();
        const lanes = this.visibleLanes(items);
        const laneIndex = new Map(lanes.map((l, i) => [l.key, i]));
        const height = AXIS_H + Math.max(lanes.length, 1) * ROW_H + 8;

        const svg = el('svg', { width, height, viewBox: `0 0 ${width} ${height}`, class: 'block select-none', 'font-family': 'JetBrains Mono, monospace', 'font-size': 11 });
        this.drawAxis(svg, height);

        lanes.forEach((lane, i) => {
            const y = AXIS_H + i * ROW_H;
            el('rect', { x: 0, y, width, height: ROW_H, fill: i % 2 ? 'transparent' : 'var(--surface)', opacity: 0.5 }, svg);
            el('line', { x1: 0, x2: width, y1: y + ROW_H, y2: y + ROW_H, stroke: 'var(--line)' }, svg);
            const label = el('text', { x: 12, y: y + ROW_H / 2 + 4, fill: 'var(--fg-2)', 'font-family': 'Geist, sans-serif', 'font-size': 12 }, svg);
            label.textContent = lane.label;
            const svc = el('text', { x: LABEL_W - 10, y: y + ROW_H / 2 + 4, fill: 'var(--fg-3)', 'text-anchor': 'end', 'font-size': 10 }, svg);
            svc.textContent = lane.service;
        });
        el('line', { x1: LABEL_W, x2: LABEL_W, y1: 0, y2: height, stroke: 'var(--line-strong)' }, svg);

        const plot = el('g', { 'clip-path': 'url(#tl-clip)' }, svg);
        const defs = el('defs', {}, svg);
        const clip = el('clipPath', { id: 'tl-clip' }, defs);
        el('rect', { x: LABEL_W, y: 0, width: this.plotW + 12, height }, clip);

        const pos = (item) => {
            const row = laneIndex.get(item.lane);
            const cy = AXIS_H + row * ROW_H + ROW_H / 2;
            const x1 = this.x(item.offset_ms);
            const x2 = item.duration_ms != null && !POINTS.has(item.type) ? Math.max(this.x(item.offset_ms + item.duration_ms), x1 + 2) : x1;
            return { cy, x1, x2 };
        };
        this.pos = pos;

        // First failure guide line
        const first = this.dataValue.first_failure && this.byId.get(this.dataValue.first_failure);
        if (first && laneIndex.has(first.lane)) {
            const p = pos(first);
            el('line', { x1: p.x2, x2: p.x2, y1: AXIS_H, y2: height, stroke: 'var(--err)', 'stroke-dasharray': '2 4', opacity: 0.6 }, plot);
        }

        // Links: proven cross-service hops, inferred context, and the selected item's chain.
        const links = el('g', { fill: 'none' }, plot);
        const visible = new Set(items.map((i) => i.id));
        const chain = this.selected ? this.ancestorIds(this.selected) : new Set();
        for (const link of this.dataValue.links) {
            if (!visible.has(link.from) || !visible.has(link.to)) continue;
            const onChain = chain.has(link.from) && chain.has(link.to);
            if (link.kind === "parent" && !onChain) continue;
            // Inferred links: only the deploy marker and the selected item, to keep the picture readable.
            if (!link.proven && this.byId.get(link.from)?.type !== "deploy" && this.selected !== link.from) continue;
            const a = this.byId.get(link.from); const b = this.byId.get(link.to);
            const pa = pos(a); const pb = pos(b);
            const sx = link.proven ? pa.x1 : pa.x2; const ex = pb.x1;
            const d = `M${sx},${pa.cy} C${sx + 30},${pa.cy} ${ex - 30},${pb.cy} ${ex},${pb.cy}`;
            const path = el('path', {
                d,
                stroke: link.proven ? (onChain ? 'var(--accent)' : 'var(--fg-3)') : 'var(--warn)',
                'stroke-width': onChain ? 1.8 : 1.2,
                'stroke-dasharray': link.proven ? null : '4 4',
                opacity: link.proven ? 0.9 : 0.75,
            }, links);
            const t = el('title', {}, path);
            t.textContent = (link.proven ? 'Proven: ' : 'Inferred: ') + link.reason;
        }

        // Items
        for (const item of items) {
            const p = pos(item);
            const g = el('g', { class: 'cursor-pointer', 'data-id': item.id, tabindex: -1 }, plot);
            const isSel = this.selected === item.id;
            const stroke = isSel ? 'var(--accent)' : item.error ? 'var(--err)' : !item.proven ? 'var(--warn)' : 'var(--line-strong)';
            if (POINTS.has(item.type) || item.duration_ms == null) {
                this.drawPoint(g, item, p, stroke, isSel);
            } else {
                const fill = item.error ? 'var(--err-soft)' : !item.proven ? 'transparent' : this.fillFor(item.type);
                el('rect', { x: p.x1, y: p.cy - BAR_H / 2, width: Math.max(p.x2 - p.x1, 2), height: BAR_H, rx: 3, fill, stroke, 'stroke-width': isSel ? 2 : 1, 'stroke-dasharray': item.proven ? null : '3 2' }, g);
                const w = p.x2 - p.x1;
                if (w > 60) {
                    const text = el('text', { x: p.x1 + 6, y: p.cy + 4, fill: item.error ? 'var(--err)' : 'var(--fg)', 'font-size': 10.5 }, g);
                    text.textContent = this.truncate(item.name, Math.floor((w - 10) / 6.4));
                }
            }
            el('rect', { x: Math.min(p.x1, p.x2) - 4, y: p.cy - ROW_H / 2, width: Math.max(p.x2 - p.x1, 0) + 8, height: ROW_H, fill: 'transparent' }, g);
            g.addEventListener('mouseenter', (e) => this.showTip(e, item));
            g.addEventListener('mousemove', (e) => this.moveTip(e));
            g.addEventListener('mouseleave', () => this.hideTip());
            g.addEventListener('click', () => this.select(item.id, true));
        }

        // First failure crosshair
        if (first && laneIndex.has(first.lane)) {
            const p = pos(first);
            const g = el('g', { 'pointer-events': 'none' }, plot);
            el('circle', { cx: p.x2, cy: p.cy, r: 9, fill: 'none', stroke: 'var(--err)', 'stroke-width': 1.5 }, g);
            for (const [dx, dy] of [[0, -13], [0, 13], [-13, 0], [13, 0]]) {
                el('line', { x1: p.x2 + dx * 0.55, y1: p.cy + dy * 0.55, x2: p.x2 + dx, y2: p.cy + dy, stroke: 'var(--err)', 'stroke-width': 1.5 }, g);
            }
        }

        this.attachPan(svg);
        this.canvasTarget.replaceChildren(svg);
        this.renderMinimap();
        if (this.hasReadoutTarget) {
            const [a, b] = this.view;
            this.readoutTarget.textContent = `${items.length} of ${this.items.length} events · showing ${fmtMs(Math.max(0, a))} – ${fmtMs(b)}${this.selected ? ' · ←/→ to move, Enter for details' : ''}`;
        }
    }

    drawAxis(svg, height) {
        const [a, b] = this.view;
        const span = b - a;
        const raw = span / 8;
        const pow = 10 ** Math.floor(Math.log10(raw));
        const step = [1, 2, 5, 10].map((m) => m * pow).find((s) => s >= raw) || pow * 10;
        const start = Math.ceil(a / step) * step;
        for (let t = start; t <= b; t += step) {
            const x = this.x(t);
            el('line', { x1: x, x2: x, y1: AXIS_H - 6, y2: height, stroke: 'var(--line)', 'stroke-dasharray': '1 3' }, svg);
            const label = el('text', { x, y: 16, fill: 'var(--fg-3)', 'text-anchor': 'middle', 'font-size': 10 }, svg);
            label.textContent = `+${fmtMs(t)}`;
        }
        el('line', { x1: LABEL_W, x2: LABEL_W + this.plotW + 12, y1: AXIS_H, y2: AXIS_H, stroke: 'var(--line-strong)' }, svg);
    }

    drawPoint(g, item, p, stroke, isSel) {
        const c = item.error ? 'var(--err)' : !item.proven ? 'var(--warn)' : item.type === 'deploy' ? 'var(--blue)' : 'var(--fg-2)';
        const x = p.x1; const y = p.cy;
        if (item.type === 'exception') {
            el('path', { d: `M${x},${y - 7} L${x + 7},${y} L${x},${y + 7} L${x - 7},${y} Z`, fill: 'var(--err)', stroke: isSel ? 'var(--accent)' : 'var(--bg)', 'stroke-width': isSel ? 2 : 1.5 }, g);
        } else if (item.type === 'deploy') {
            el('path', { d: `M${x},${y + 8} L${x},${y - 8} L${x + 9},${y - 5} L${x},${y - 2}`, fill: c, stroke: c, 'stroke-width': 1.5, 'stroke-linejoin': 'round' }, g);
        } else if (item.type === 'transaction') {
            el('rect', { x: x - 4, y: y - 4, width: 8, height: 8, rx: 1.5, fill: item.name === 'ROLLBACK' ? 'var(--warn)' : 'var(--surface-3)', stroke: isSel ? 'var(--accent)' : c, 'stroke-width': isSel ? 2 : 1 }, g);
        } else {
            el('circle', { cx: x, cy: y, r: item.type === 'http_response' ? 5 : 4, fill: item.error ? 'var(--err)' : 'var(--surface)', stroke: isSel ? 'var(--accent)' : c, 'stroke-width': isSel ? 2 : 1.5, 'stroke-dasharray': item.proven ? null : '2 2' }, g);
        }
    }

    fillFor(type) {
        return ({ http_request: 'var(--blue-soft)', external_http: 'var(--accent-soft)', db_query: 'var(--surface-3)', cache: 'var(--surface-3)', controller: 'var(--surface-2)' })[type] || 'var(--surface-2)';
    }

    truncate(s, n) {
        return s.length > n ? `${s.slice(0, Math.max(n - 1, 1))}…` : s;
    }

    renderMinimap() {
        if (!this.hasMinimapTarget) return;
        const width = this.minimapTarget.clientWidth || 600;
        const h = 40;
        const svg = el('svg', { width, height: h, class: 'block cursor-crosshair' });
        const [fa, fb] = this.paddedFull();
        const mx = (ms) => LABEL_W + ((ms - fa) / (fb - fa)) * (width - LABEL_W - 12);
        el('rect', { x: LABEL_W, y: 0, width: width - LABEL_W - 12, height: h, rx: 4, fill: 'var(--bg-deep)', stroke: 'var(--line)' }, svg);
        for (const i of this.items) {
            const x = mx(i.offset_ms);
            const w = i.duration_ms ? Math.max(mx(i.offset_ms + i.duration_ms) - x, 1) : 1;
            el('rect', { x, y: i.error ? 6 : 14, width: w, height: i.error ? 28 : 12, fill: i.error ? 'var(--err)' : i.proven ? 'var(--fg-3)' : 'var(--warn)', opacity: 0.6 }, svg);
        }
        const [a, b] = this.view;
        el('rect', { x: mx(a), y: 1, width: Math.max(mx(b) - mx(a), 3), height: h - 2, fill: 'var(--accent-soft)', stroke: 'var(--accent)', rx: 3 }, svg);
        const label = el('text', { x: LABEL_W - 10, y: 24, fill: 'var(--fg-3)', 'text-anchor': 'end', 'font-size': 10, 'font-family': 'Geist, sans-serif' }, svg);
        label.textContent = 'Drag to zoom';

        let startX = null;
        let brush = null;
        svg.addEventListener('mousedown', (e) => {
            const r = svg.getBoundingClientRect();
            startX = Math.max(e.clientX - r.left, LABEL_W);
            brush = el('rect', { x: startX, y: 1, width: 1, height: h - 2, fill: 'var(--accent-soft)', stroke: 'var(--accent)' }, svg);
        });
        svg.addEventListener('mousemove', (e) => {
            if (startX === null) return;
            const r = svg.getBoundingClientRect();
            const x = Math.max(e.clientX - r.left, LABEL_W);
            brush.setAttribute('x', Math.min(startX, x));
            brush.setAttribute('width', Math.abs(x - startX));
        });
        const finish = (e) => {
            if (startX === null) return;
            const r = svg.getBoundingClientRect();
            const x = Math.max(e.clientX - r.left, LABEL_W);
            const inv = (px) => fa + ((px - LABEL_W) / (width - LABEL_W - 12)) * (fb - fa);
            const [lo, hi] = [Math.min(startX, x), Math.max(startX, x)];
            if (hi - lo > 4) this.view = [inv(lo), inv(hi)];
            else { const c = inv(lo); const s = (this.view[1] - this.view[0]) / 2; this.view = [c - s, c + s]; }
            startX = null;
            this.render();
        };
        svg.addEventListener('mouseup', finish);
        svg.addEventListener('mouseleave', (e) => { if (startX !== null) finish(e); });
        this.minimapTarget.replaceChildren(svg);
    }

    attachPan(svg) {
        let drag = null;
        svg.addEventListener('mousedown', (e) => {
            if (e.button !== 0 || e.target.closest('[data-id]')) return;
            drag = { x: e.clientX, view: [...this.view] };
            svg.style.cursor = 'grabbing';
        });
        svg.addEventListener('mousemove', (e) => {
            if (!drag) return;
            const dms = ((e.clientX - drag.x) / this.plotW) * (drag.view[1] - drag.view[0]);
            this.view = [drag.view[0] - dms, drag.view[1] - dms];
            this.render();
        });
        const stop = () => { drag = null; };
        svg.addEventListener('mouseup', stop);
        svg.addEventListener('mouseleave', stop);
        svg.addEventListener('wheel', (e) => {
            if (!e.ctrlKey && !e.metaKey) return; // plain wheel scrolls the page
            e.preventDefault();
            const r = svg.getBoundingClientRect();
            this.zoomAt(this.msAt(e.clientX - r.left), e.deltaY < 0 ? 0.7 : 1.4);
        }, { passive: false });
    }

    // ---------- interactions ----------
    zoomAt(center, factor) {
        const [a, b] = this.view;
        const span = Math.max((b - a) * factor, 0.05);
        const ratio = (center - a) / (b - a);
        this.view = [center - span * ratio, center + span * (1 - ratio)];
        this.render();
    }

    zoomIn() {
        const c = this.selected ? this.byId.get(this.selected).offset_ms : (this.view[0] + this.view[1]) / 2;
        this.zoomAt(c, 0.5);
    }

    zoomOut() {
        this.zoomAt((this.view[0] + this.view[1]) / 2, 2);
    }

    reset() {
        this.view = [...this.home];
        this.render();
    }

    showAll() {
        this.view = this.paddedFull();
        this.render();
    }

    jumpToFirstFailure() {
        const id = this.dataValue.first_failure;
        if (!id) return;
        const item = this.byId.get(id);
        const pad = Math.max(item.duration_ms || 0, 20) * 6;
        this.view = [item.offset_ms - pad, item.offset_ms + (item.duration_ms || 0) + pad];
        this.select(id, false);
    }

    select(id, open) {
        this.selected = id;
        this.render();
        this.canvasTarget.focus({ preventScroll: true });
        if (open) this.openDetails(id);
    }

    openDetails(id) {
        if (!this.hasFrameTarget) return;
        this.frameTarget.src = this.panelUrlValue.replace('__ID__', id);
        const dialog = this.frameTarget.closest('dialog');
        if (dialog && !dialog.open) dialog.showModal();
    }

    ancestorIds(id) {
        const out = new Set([id]);
        let cur = this.byId.get(id);
        let guard = 0;
        while (cur && cur.parent_span_id && guard++ < 100) {
            const parent = this.bySpan.get(cur.parent_span_id);
            if (!parent) break;
            out.add(parent.id);
            cur = parent;
        }
        return out;
    }

    key(e) {
        const items = this.visibleItems();
        if (e.key === 'e' || e.key === 'E') {
            if (this.hasErrorsOnlyTarget) { this.errorsOnlyTarget.checked = !this.errorsOnlyTarget.checked; this.render(); }
            return;
        }
        if (e.key === '+' || e.key === '=') { this.zoomIn(); return; }
        if (e.key === '-') { this.zoomOut(); return; }
        if (e.key === 'Enter' && this.selected) { e.preventDefault(); this.openDetails(this.selected); return; }
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        e.preventDefault();
        const idx = items.findIndex((i) => i.id === this.selected);
        const next = e.key === 'ArrowRight' ? Math.min(idx + 1, items.length - 1) : Math.max(idx - 1, 0);
        const item = items[idx === -1 ? 0 : next];
        if (!item) return;
        const [a, b] = this.view;
        if (item.offset_ms < a || item.offset_ms > b) {
            const span = b - a;
            this.view = [item.offset_ms - span / 3, item.offset_ms + (span * 2) / 3];
        }
        this.select(item.id, false);
        this.announce(item);
    }

    announce(item) {
        this.canvasTarget.setAttribute('aria-label', `${item.type_label} ${item.name}, +${fmtMs(item.offset_ms)}${item.error ? ', failed' : ''}${item.proven ? '' : ', inferred context'}`);
    }

    // ---------- tooltip ----------
    showTip(e, item) {
        if (!this.hasTooltipTarget) return;
        const sel = this.selected && this.selected !== item.id ? this.byId.get(this.selected) : null;
        const ctx = Object.entries(item.context || {}).map(([k, v]) => `<div><span class="text-fg-3">${esc(k)}</span> ${esc(v)}</div>`).join('');
        this.tooltipTarget.innerHTML = `
            <div class="font-medium ${item.error ? 'text-err' : 'text-fg'}">${esc(item.exception ? item.exception.split('\\').pop() + ': ' : '')}${esc(item.name)}</div>
            <div class="mt-0.5 text-fg-3">${esc(item.service)} · ${esc(item.type_label)} · +${fmtMs(item.offset_ms)}${item.duration_ms != null ? ' · ' + fmtMs(item.duration_ms) : ''}</div>
            ${sel ? `<div class="mt-1 text-accent">Δt from selected: ${item.offset_ms - sel.offset_ms >= 0 ? '+' : '−'}${fmtMs(Math.abs(item.offset_ms - sel.offset_ms))}</div>` : ''}
            ${item.message && item.message !== item.name ? `<div class="mt-1 text-fg-2">${esc(item.message)}</div>` : ''}
            ${ctx ? `<div class="mt-1 font-mono text-2xs text-fg-2">${ctx}</div>` : ''}
            ${item.proven ? '' : `<div class="mt-1 text-warn">${esc(item.reason)}</div>`}`;
        this.tooltipTarget.classList.remove('hidden');
        this.moveTip(e);
    }

    moveTip(e) {
        if (!this.hasTooltipTarget) return;
        const t = this.tooltipTarget;
        const x = Math.min(e.clientX + 14, window.innerWidth - t.offsetWidth - 8);
        const y = e.clientY + 16 + t.offsetHeight > window.innerHeight ? e.clientY - t.offsetHeight - 10 : e.clientY + 16;
        t.style.left = `${x}px`;
        t.style.top = `${y}px`;
    }

    hideTip() {
        if (this.hasTooltipTarget) this.tooltipTarget.classList.add('hidden');
    }
}
