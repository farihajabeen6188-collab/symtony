import { Controller } from '@hotwired/stimulus';
import hljs from 'highlight.js/lib/core';
import php from 'highlight.js/lib/languages/php';
import json from 'highlight.js/lib/languages/json';

hljs.registerLanguage('php', php);
hljs.registerLanguage('json', json);

export default class extends Controller {
    static targets = ['source'];
    static values = { lang: String, lines: Boolean };

    connect() {
        const el = this.sourceTarget;
        const raw = el.textContent;
        if (hljs.getLanguage(this.langValue)) {
            el.innerHTML = hljs.highlight(raw, { language: this.langValue, ignoreIllegals: true }).value;
        }
        if (this.linesValue) {
            el.innerHTML = el.innerHTML.split('\n').map((line, i) => `<span class="ln">${i + 1}</span>${line}`).join('\n');
        }
        const pre = el.closest('pre');
        if (pre?.dataset.maxHeight) pre.style.maxHeight = pre.dataset.maxHeight;
    }
}
