import { Controller } from '@hotwired/stimulus';

/*
 * Failure replay: steps through the failing request. Space plays/pauses, ←/→ step, Home/End jump.
 * The server renders every step and the consequences; this controller only moves the playhead.
 * Autoplay (landing page) runs once when the replay scrolls into view, never with reduced motion.
 */
export default class extends Controller {
    static targets = ['step', 'dot', 'now', 'play', 'playIcon', 'playLabel', 'counter', 'lit', 'broken', 'fuse', 'speed', 'context', 'aftermath'];
    static values = { autoplay: Boolean, failure: Number, rollback: Number };

    connect() {
        this.element.dataset.js = '';
        this.speed = 1;
        this.timer = null;
        this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.icons = { play: this.playIconTarget.innerHTML, pause: this.pauseIcon() };
        // Start on the full story (everything visible, playhead at the end) until someone presses play.
        this.go(this.stepTargets.length - 1, false);
        if (this.autoplayValue && !this.reduced && 'IntersectionObserver' in window) {
            this.observer = new IntersectionObserver((entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    this.observer.disconnect();
                    this.start(true);
                }
            }, { threshold: 0.4 });
            this.observer.observe(this.element);
        }
    }

    disconnect() {
        this.stop();
        this.observer?.disconnect();
    }

    toggle() {
        this.timer ? this.stop() : this.start(this.index >= this.stepTargets.length - 1);
    }

    start(fromBeginning) {
        if (fromBeginning) this.go(0, true);
        this.setPlaying(true);
        const tick = () => {
            if (this.index >= this.stepTargets.length - 1) {
                this.stop();
                return;
            }
            this.go(this.index + 1, true);
            this.timer = setTimeout(tick, this.delayFor(this.index) / this.speed);
        };
        this.timer = setTimeout(tick, this.delayFor(this.index) / this.speed);
    }

    stop() {
        clearTimeout(this.timer);
        this.timer = null;
        this.setPlaying(false);
    }

    // Linger on the moments that matter.
    delayFor(i) {
        if (i === this.failureValue || i === this.rollbackValue) return 1800;
        return 700;
    }

    next() { this.stop(); this.go(this.index + 1, true); }
    previous() { this.stop(); this.go(this.index - 1, true); }
    jump({ params }) { this.stop(); this.go(Number(params.index), true); }

    seek(event) {
        const rect = this.fuseTarget.getBoundingClientRect();
        const pct = ((event.clientX - rect.left) / rect.width) * 100;
        let best = 0;
        let bestDist = Infinity;
        this.dotTargets.forEach((dot, i) => {
            const d = Math.abs(parseFloat(dot.style.left) - pct);
            if (d < bestDist) { bestDist = d; best = i; }
        });
        this.stop();
        this.go(best, true);
    }

    setSpeed({ params }) {
        this.speed = Number(params.speed) || 1;
        this.speedTargets.forEach((b) => b.setAttribute('aria-pressed', String(Number(b.dataset.replaySpeedParam) === this.speed)));
    }

    toggleContext(event) {
        if (this.hasContextTarget) this.contextTarget.hidden = !event.target.checked;
    }

    key(event) {
        if (event.target.closest('input, select, textarea')) return;
        const actions = {
            ' ': () => this.toggle(),
            k: () => this.toggle(),
            ArrowRight: () => this.next(),
            ArrowLeft: () => this.previous(),
            Home: () => { this.stop(); this.go(0, true); },
            End: () => { this.stop(); this.go(this.stepTargets.length - 1, true); },
        };
        const action = actions[event.key];
        if (!action) return;
        event.preventDefault();
        action();
    }

    go(index, scroll) {
        const last = this.stepTargets.length - 1;
        const i = Math.max(0, Math.min(last, index));
        const crossedFailure = this.failureValue >= 0 && i === this.failureValue && this.index !== i;
        this.index = i;

        this.stepTargets.forEach((step, n) => { step.dataset.state = n < i ? 'past' : n === i ? 'current' : 'future'; });
        this.dotTargets.forEach((dot, n) => { dot.dataset.state = n < i ? 'past' : n === i ? 'current' : 'future'; });

        const pos = (n) => parseFloat(this.dotTargets[n]?.style.left || '0');
        const failed = this.failureValue >= 0 && i >= this.failureValue;
        const failPos = failed ? pos(this.failureValue) : null;
        this.litTarget.style.width = `${failed ? failPos : pos(i)}%`;
        this.brokenTarget.style.left = failed ? `${failPos}%` : '0';
        this.brokenTarget.style.width = failed ? `${Math.max(0, pos(i) - failPos)}%` : '0';
        this.toggleAttr('failed', failed);
        this.toggleAttr('afterRollback', this.rollbackValue >= 0 && i >= this.rollbackValue);

        if (crossedFailure && !this.reduced) {
            this.element.dataset.flash = '';
            setTimeout(() => delete this.element.dataset.flash, 200);
        }

        const detail = this.stepTargets[i].querySelector('.replay-detail');
        if (detail) this.nowTarget.innerHTML = detail.innerHTML;
        this.counterTarget.textContent = `Step ${i + 1} of ${last + 1}`;

        if (scroll) {
            const list = this.stepTargets[i].parentElement;
            const row = this.stepTargets[i];
            if (row.offsetTop < list.scrollTop || row.offsetTop + row.offsetHeight > list.scrollTop + list.clientHeight) {
                list.scrollTop = row.offsetTop - list.clientHeight / 2;
            }
        }
    }

    toggleAttr(name, on) {
        if (on) this.element.dataset[name] = '';
        else delete this.element.dataset[name];
    }

    setPlaying(playing) {
        this.playIconTarget.innerHTML = playing ? this.icons.pause : this.icons.play;
        this.playLabelTarget.textContent = playing ? 'Pause' : 'Replay';
    }

    pauseIcon() {
        const t = this.element.querySelector('template[data-pause-icon]');
        return t ? t.innerHTML : '';
    }
}
