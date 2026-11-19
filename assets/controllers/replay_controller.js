import { Controller } from '@hotwired/stimulus';

/*
 * Failure replay. Draws the failing request as a sequence diagram (participants = client, services,
 * database, cache) and plays it step by step: each arrow draws itself, the receiving participant lights
 * up, the failure snaps red, and past the ROLLBACK the undone writes are struck through while calls the
 * rollback could not reach stay lit. Space plays/pauses, ←/→ step, Home/End jump; click a step or the fuse.
 * All motion is 200 ms or less and is switched off with prefers-reduced-motion.
 */
const NS = 'http://www.w3.org/2000/svg';
const GUTTER = 64;
const HEADBAR = 48;
const TOP = 12; // first row inside the steps svg
const HEAD = HEADBAR + TOP; // first row inside the scroll container
const ROW = 36;
const CHAR = 6.3;

const el = (name, attrs = {}, parent = null, text = null) => {
    const node = document.createElementNS(NS, name);
    for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined) node.setAttribute(k, v);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);
    return node;
};
const clip = (s, max) => (s.length > max ? `${s.slice(0, Math.max(max - 1, 1))}…` : s);
const fmt = (ms) => (ms >= 1000 ? `${(ms / 1000).toFixed(2)} s` : `${ms.toFixed(ms < 10 ? 1 : 0)} ms`);

export default class extends Controller {
    static targets = ['step', 'dot', 'now', 'play', 'playIcon', 'counter', 'title', 'lit', 'broken', 'head', 'fuse', 'tip', 'speed', 'stage', 'list', 'viewButton', 'aftermath'];
    static values = { autoplay: Boolean, failure: Number, rollback: Number, sequence: Object };

    connect() {
        this.element.dataset.js = '';
        this.element.dataset.view = 'sequence';
        this.speed = 1;
        this.timer = null;
        this.index = -1;
        this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.icons = { play: this.playIconTarget.innerHTML, pause: this.element.querySelector('template[data-pause-icon]')?.innerHTML ?? '' };
        this.drawStage();
        this.ro = new ResizeObserver(() => this.drawStage());
        this.ro.observe(this.stageTarget);
        // Start on the whole story; Play replays it from the beginning.
        this.go(this.stepTargets.length - 1, { animate: false });
        if (this.autoplayValue && !this.reduced && 'IntersectionObserver' in window) {
            this.observer = new IntersectionObserver((entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    this.observer.disconnect();
                    this.start(true);
                }
            }, { threshold: 0.35 });
            this.observer.observe(this.element);
        }
    }

    disconnect() {
        this.stop();
        this.observer?.disconnect();
        this.ro?.disconnect();
    }

    // ---------- playback ----------
    toggle() {
        this.timer ? this.stop() : this.start(this.index >= this.count() - 1);
    }

    start(fromBeginning) {
        if (fromBeginning) this.go(0);
        this.setPlaying(true);
        const tick = () => {
            if (this.index >= this.count() - 1) return this.stop();
            this.go(this.index + 1);
            this.timer = setTimeout(tick, this.delayFor(this.index) / this.speed);
        };
        this.timer = setTimeout(tick, this.delayFor(this.index) / this.speed);
    }

    stop() {
        clearTimeout(this.timer);
        this.timer = null;
        this.setPlaying(false);
    }

    // Linger on the moments that matter, move quickly through the plumbing.
    delayFor(i) {
        const s = this.sequenceValue.steps?.[i];
        if (i === this.failureValue) return 2000;
        if (i === this.rollbackValue) return 2200;
        if (s?.effect === 'survived' || s?.kind === 'cache') return 1300;
        if (s?.kind === 'self') return 520;
        return 820;
    }

    count() { return this.stepTargets.length; }
    next() { this.stop(); this.go(this.index + 1); }
    previous() { this.stop(); this.go(this.index - 1); }
    jump({ params }) { this.stop(); this.go(Number(params.index)); }

    setSpeed({ params }) {
        this.speed = Number(params.speed) || 1;
        this.speedTargets.forEach((b) => b.setAttribute('aria-pressed', String(Number(b.dataset.replaySpeedParam) === this.speed)));
    }

    setView({ params }) {
        this.element.dataset.view = params.view;
        this.viewButtonTargets.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.replayViewParam === params.view)));
        if (params.view === 'sequence') this.drawStage();
    }

    key(event) {
        if (event.target.closest('input, select, textarea, summary')) return;
        const actions = {
            ' ': () => this.toggle(),
            k: () => this.toggle(),
            ArrowRight: () => this.next(),
            ArrowLeft: () => this.previous(),
            Home: () => { this.stop(); this.go(0); },
            End: () => { this.stop(); this.go(this.count() - 1); },
        };
        const action = actions[event.key];
        if (!action) return;
        event.preventDefault();
        action();
    }

    // ---------- fuse ----------
    nearestDot(clientX) {
        const rect = this.fuseTarget.getBoundingClientRect();
        const pct = ((clientX - rect.left) / rect.width) * 100;
        let best = 0;
        let bestDist = Infinity;
        this.dotTargets.forEach((dot, i) => {
            const d = Math.abs(parseFloat(dot.style.left) - pct);
            if (d < bestDist) { bestDist = d; best = i; }
        });
        return best;
    }

    seek(event) {
        this.stop();
        this.go(this.nearestDot(event.clientX));
    }

    hoverFuse(event) {
        const i = this.nearestDot(event.clientX);
        const s = this.sequenceValue.steps?.[i];
        if (!s) return;
        this.tipTarget.hidden = false;
        this.tipTarget.style.left = this.dotTargets[i].style.left;
        this.tipTarget.textContent = `+${fmt(s.t)} · ${clip(s.name, 40)}`;
        this.dotTargets.forEach((d, n) => d.toggleAttribute('data-hover', n === i));
    }

    leaveFuse() {
        this.tipTarget.hidden = true;
        this.dotTargets.forEach((d) => d.removeAttribute('data-hover'));
    }

    // ---------- the playhead ----------
    go(index, { animate = true } = {}) {
        const last = this.count() - 1;
        const i = Math.max(0, Math.min(last, index));
        const forward = i === this.index + 1;
        const crossedFailure = animate && this.failureValue >= 0 && i === this.failureValue && this.index !== i;
        this.index = i;

        const state = (n) => (n < i ? 'past' : n === i ? 'current' : 'future');
        this.stepTargets.forEach((step, n) => { step.dataset.state = state(n); });
        this.dotTargets.forEach((dot, n) => { dot.dataset.state = state(n); });
        this.seqSteps?.forEach((g, n) => {
            const next = state(n);
            // Draw the arrow afresh when it is the step just reached by playing forward.
            if (next === 'current' && forward && animate && !this.reduced) {
                g.dataset.state = 'future';
                g.getBoundingClientRect();
            }
            g.dataset.state = next;
        });

        const step = this.sequenceValue.steps?.[i];
        this.participantHeads?.forEach((h) => {
            h.toggleAttribute('data-active', !!step && (h.dataset.pid === step.to || (step.kind === 'self' && h.dataset.pid === step.from)));
            h.toggleAttribute('data-hit', !!step && step.error && h.dataset.pid === step.to);
        });

        const pos = (n) => parseFloat(this.dotTargets[n]?.style.left || '0');
        const failed = this.failureValue >= 0 && i >= this.failureValue;
        const failPos = failed ? pos(this.failureValue) : null;
        this.litTarget.style.width = `${failed ? failPos : pos(i)}%`;
        this.brokenTarget.style.left = failed ? `${failPos}%` : '0';
        this.brokenTarget.style.width = failed ? `${Math.max(0, pos(i) - failPos)}%` : '0';
        this.headTarget.style.left = `${pos(i)}%`;
        this.headTarget.toggleAttribute('data-failed', failed);
        this.flag('failed', failed);
        this.flag('afterRollback', this.rollbackValue >= 0 && i >= this.rollbackValue);

        if (crossedFailure && !this.reduced) {
            this.element.dataset.flash = '';
            setTimeout(() => delete this.element.dataset.flash, 200);
        }

        const detail = this.stepTargets[i].querySelector('.replay-detail');
        if (detail) this.nowTarget.innerHTML = detail.innerHTML;
        this.nowTarget.toggleAttribute('data-error', !!step?.error);
        if (step) this.titleTarget.textContent = (i === this.failureValue ? 'First failure: ' : i === this.rollbackValue ? 'Rolled back: ' : '') + step.name;
        this.counterTarget.textContent = `Step ${i + 1} of ${last + 1}${step ? ` · +${fmt(step.t)}` : ''}`;

        if (animate) this.follow(i);
    }

    follow(i) {
        const behavior = this.reduced ? 'auto' : 'smooth';
        if (this.element.dataset.view === 'sequence' && this.seqSteps?.[i]) {
            const y = HEAD + i * ROW;
            const box = this.stageTarget;
            if (y < box.scrollTop + HEAD || y + ROW > box.scrollTop + box.clientHeight) {
                box.scrollTo({ top: Math.max(0, y - box.clientHeight / 2), behavior });
            }
        } else {
            const row = this.stepTargets[i];
            const list = this.listTarget;
            if (row.offsetTop < list.scrollTop || row.offsetTop + row.offsetHeight > list.scrollTop + list.clientHeight) {
                list.scrollTo({ top: row.offsetTop - list.clientHeight / 2, behavior });
            }
        }
    }

    flag(name, on) {
        if (on) this.element.dataset[name] = '';
        else delete this.element.dataset[name];
    }

    setPlaying(playing) {
        this.playIconTarget.innerHTML = playing ? this.icons.pause : this.icons.play;
        this.playTarget.setAttribute('aria-label', playing ? 'Pause the replay' : 'Play the replay');
        this.flag('playing', playing);
    }

    // ---------- the sequence diagram ----------
    drawStage() {
        const seq = this.sequenceValue;
        if (!seq?.participants?.length || this.element.dataset.view === 'list') return;
        const width = Math.max(this.stageTarget.clientWidth, 560);
        const n = seq.participants.length;
        const colW = (width - GUTTER - 12) / n;
        const col = new Map(seq.participants.map((p, k) => [p.id, GUTTER + colW * k + colW / 2]));
        const height = TOP + seq.steps.length * ROW + 20;

        // Participant heads live in their own strip that stays pinned while the steps scroll.
        const headbar = document.createElement('div');
        headbar.className = 'seq-headbar';
        const headSvg = el('svg', { width, height: 48, viewBox: `0 0 ${width} 48`, class: 'seq', 'font-family': 'Geist, sans-serif', 'font-size': 12 }, headbar);
        const svg = el('svg', { width, height, viewBox: `0 0 ${width} ${height}`, class: 'seq', 'font-family': 'Geist, sans-serif', 'font-size': 12 });
        const defs = el('defs', {}, svg);
        for (const [id, color] of [['fg', 'var(--fg-2)'], ['err', 'var(--err)'], ['warn', 'var(--warn)'], ['accent', 'var(--accent)']]) {
            const m = el('marker', { id: `seq-arrow-${id}-${this.uid()}`, viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse' }, defs);
            el('path', { d: 'M0,0 L10,5 L0,10 z', fill: color }, m);
        }

        // Transaction band (BEGIN … ROLLBACK) on the database lifeline, revealed when the rollback is reached.
        const begin = seq.rollback !== null ? [...seq.steps].slice(0, seq.rollback).reverse().find((s) => s.type === 'transaction' && s.name.toUpperCase() === 'BEGIN') : null;
        if (begin && seq.rollback !== null) {
            const rb = seq.steps[seq.rollback];
            const x = col.get(rb.to);
            const y1 = TOP + begin.i * ROW + 6;
            const y2 = TOP + seq.rollback * ROW + ROW - 6;
            const band = el('g', { class: 'seq-txn' }, svg);
            el('rect', { x: x - 14, y: y1, width: 28, height: y2 - y1, rx: 6 }, band);
            el('text', { x: x + 20, y: y1 + 12, class: 'seq-txn-label' }, band, '↺ rolled back');
        }

        // Lifelines and participant heads
        this.participantHeads = seq.participants.map((p) => {
            const x = col.get(p.id);
            el('line', { x1: x, x2: x, y1: 0, y2: height - 8, class: 'seq-life' }, svg);
            el('line', { x1: x, x2: x, y1: 40, y2: 48, class: 'seq-life' }, headSvg);
            const g = el('g', { class: 'seq-head', 'data-pid': p.id, 'data-kind': p.kind }, headSvg);
            const w = Math.min(colW - 10, Math.max(64, p.label.length * CHAR + 24));
            el('rect', { x: x - w / 2, y: 10, width: w, height: 30, rx: 8 }, g);
            el('text', { x, y: 29, 'text-anchor': 'middle' }, g, clip(p.label, Math.floor((w - 14) / CHAR)));
            return g;
        });

        // Steps
        const markers = { fg: `url(#seq-arrow-fg-${this.uid()})`, err: `url(#seq-arrow-err-${this.uid()})`, warn: `url(#seq-arrow-warn-${this.uid()})`, accent: `url(#seq-arrow-accent-${this.uid()})` };
        this.seqSteps = seq.steps.map((s) => {
            const y = TOP + s.i * ROW + ROW / 2;
            const g = el('g', { class: 'seq-step', 'data-index': s.i, 'data-kind': s.kind, 'data-error': s.error ? '' : null, 'data-effect': s.effect, 'data-role': s.role, tabindex: -1 }, svg);
            el('rect', { x: 0, y: y - ROW / 2, width, height: ROW, class: 'seq-hit' }, g);
            el('text', { x: GUTTER - 10, y: y + 4, 'text-anchor': 'end', class: 'seq-time' }, g, `+${fmt(s.t)}`);
            const x1 = col.get(s.from);
            const x2 = col.get(s.to);
            if (s.kind !== 'arrive' && x1 !== x2) {
                const dir = x2 > x1 ? 1 : -1;
                const color = s.error ? 'err' : s.effect === 'survived' ? 'warn' : 'fg';
                el('path', { d: `M${x1 + dir * 4},${y} L${x2 - dir * 6},${y}`, class: 'seq-arrow', pathLength: 1, 'marker-end': markers[color], 'data-return': s.kind === 'return' ? '' : null }, g);
                const span = Math.abs(x2 - x1) - 20;
                el('text', { x: (x1 + x2) / 2, y: y - 7, 'text-anchor': 'middle', class: 'seq-label' }, g, clip(s.name, Math.max(6, Math.floor(span / CHAR))));
                if (s.error) {
                    const fx = x2 - dir * 16;
                    const fail = el('g', { class: 'seq-x', transform: `translate(${fx} ${y})` }, g);
                    el('circle', { r: 7 }, fail);
                    el('path', { d: 'M-3,-3 L3,3 M3,-3 L-3,3' }, fail);
                }
            } else {
                // Work inside one participant: a pill on its lifeline.
                const x = x2;
                const label = (s.kind === 'arrive' ? '↘ ' : s.kind === 'exception' ? '✕ ' : '') + s.name;
                const max = Math.max(8, Math.floor((colW * 1.7) / CHAR));
                const text = clip(label, max);
                const w = text.length * CHAR + 18;
                // Open to the right of the lifeline, or to the left when there is no room.
                const left = x + 8 + w <= width - 6 ? x + 8 : Math.max(GUTTER, x - 8 - w);
                el('rect', { x: left, y: y - 11, width: w, height: 22, rx: 11, class: 'seq-pill' }, g);
                el('text', { x: left + 9, y: y + 4, class: 'seq-label' }, g, text);
                el('circle', { cx: x, cy: y, r: 3, class: 'seq-node' }, g);
            }
            g.addEventListener('click', () => { this.stop(); this.go(s.i); });
            return g;
        });

        this.stageTarget.replaceChildren(headbar, svg);
        if (this.index >= 0) this.go(this.index, { animate: false });
    }

    uid() {
        this._uid ??= Math.random().toString(36).slice(2, 8);
        return this._uid;
    }
}
