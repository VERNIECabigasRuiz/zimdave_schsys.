import { Controller } from '@hotwired/stimulus';

const LABELS = { '': 'Present', absent: 'Absent', late: 'Late', cutting: 'Cutting' };

/*
 * Turns each learner's mark <select> into a chip that cycles Present → Absent → Late →
 * Cutting → Present on a tap, and keeps the counters up to date. Without JavaScript the
 * selects work as they are.
 *
 *   <div data-controller="attendance-mark">
 *     <p data-attendance-mark-target="counts"></p>
 *     <select data-attendance-mark-target="select" data-action="attendance-mark#count">…</select>
 *   </div>
 */
export default class extends Controller {
    static targets = ['select', 'counts'];

    selectTargetConnected(select) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'mark-chip';
        chip.addEventListener('click', () => {
            const values = Array.from(select.options, (option) => option.value);
            select.value = values[(values.indexOf(select.value) + 1) % values.length];
            this.paint(select, chip);
            this.count();
        });
        select.hidden = true;
        select.after(chip);
        this.paint(select, chip);
    }

    paint(select, chip) {
        chip.textContent = LABELS[select.value] ?? select.value;
        chip.className = 'mark-chip' + (select.value ? ` mark-chip--${select.value}` : '');
        chip.setAttribute('aria-label', `${select.labels[0]?.textContent.trim() ?? ''}: ${chip.textContent}. Tap to change.`);
    }

    count() {
        if (!this.hasCountsTarget) {
            return;
        }
        const values = this.selectTargets.map((select) => select.value);
        const n = (value) => values.filter((v) => v === value).length;
        this.countsTarget.textContent = `${values.length - n('absent')} present · ${n('absent')} absent · ${n('late')} late · ${n('cutting')} cutting`;
    }
}
