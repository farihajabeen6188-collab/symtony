import { Controller } from '@hotwired/stimulus';
import uPlot from 'uplot';

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

/*
 * Time-series bars from server data: series = [[unixSeconds, value], ...].
 * Colors are read from tokens, and the chart re-themes when the theme changes.
 */
export default class extends Controller {
    static values = { series: Array, label: { type: String, default: 'Count' }, tone: { type: String, default: 'accent' } };

    connect() {
        this.draw();
        this.onResize = () => this.plot?.setSize(this.size());
        this.onTheme = () => requestAnimationFrame(() => this.draw());
        this.ro = new ResizeObserver(this.onResize);
        this.ro.observe(this.element);
        window.addEventListener('symtony:theme', this.onTheme);
    }

    disconnect() {
        this.ro?.disconnect();
        window.removeEventListener('symtony:theme', this.onTheme);
        this.plot?.destroy();
    }

    size() {
        return { width: Math.max(this.element.clientWidth, 100), height: Math.max(this.element.clientHeight, 80) };
    }

    draw() {
        this.plot?.destroy();
        const xs = this.seriesValue.map((p) => p[0]);
        const ys = this.seriesValue.map((p) => p[1]);
        const color = css(`--${this.toneValue}`);
        const grid = css('--line');
        const text = css('--fg-3');
        const font = "11px 'JetBrains Mono', monospace";
        const step = xs.length > 1 ? xs[1] - xs[0] : 60;

        this.plot = new uPlot({
            ...this.size(),
            legend: { show: false },
            cursor: { points: { show: false }, drag: { x: false, y: false } },
            scales: { x: { time: true }, y: { range: (u, min, max) => [0, Math.max(1, max * 1.15)] } },
            axes: [
                { stroke: text, font, grid: { show: false }, ticks: { stroke: grid } },
                { stroke: text, font, grid: { stroke: grid, width: 1 }, ticks: { show: false }, size: 36, splits: (u, i, min, max) => [0, Math.round(max / 2), Math.round(max)] },
            ],
            series: [
                {},
                {
                    label: this.labelValue,
                    stroke: color,
                    fill: color + '40',
                    width: 0,
                    paths: uPlot.paths.bars({ size: [0.7, 24], align: 0 }),
                },
            ],
            hooks: {
                setCursor: [(u) => this.tooltip(u, step)],
            },
        }, [xs, ys], this.element);

        this.tip = document.createElement('div');
        this.tip.className = 'popover pointer-events-none px-2 py-1 text-2xs';
        this.tip.hidden = true;
        this.element.style.position = 'relative';
        this.element.appendChild(this.tip);
    }

    tooltip(u, step) {
        const i = u.cursor.idx;
        if (i == null || !this.tip) { if (this.tip) this.tip.hidden = true; return; }
        const t = new Date(u.data[0][i] * 1000);
        const end = new Date((u.data[0][i] + step) * 1000);
        const fmt = (d) => d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        this.tip.innerHTML = `<span class="text-fg">${u.data[1][i]} ${this.labelValue.toLowerCase()}</span><br><span class="text-fg-3">${fmt(t)} – ${fmt(end)}</span>`;
        this.tip.hidden = false;
        const left = Math.min(u.cursor.left + 50, this.element.clientWidth - 160);
        this.tip.style.left = `${left}px`;
        this.tip.style.top = '4px';
    }
}
