import { Controller } from '@hotwired/stimulus';

const SYSTEMS = {
    terms: { count: 3, name: 'Term' },
    quarters: { count: 4, name: 'Quarter' },
};
const DAY = 24 * 60 * 60 * 1000;

/*
 * Redraws the school year's grading period rows when "Terms (3)" / "Quarters (4)" is picked,
 * pre-filled with the school year split evenly (same split as SchoolYear::rebuildGradingPeriods()).
 * Picking the saved system again restores the saved rows.
 *
 *   <form data-controller="grading-periods" data-action="change->grading-periods#refresh"
 *         data-grading-periods-prototype-value="...row html with __name__ and __label__...">
 */
export default class extends Controller {
    static targets = ['rows', 'legend', 'start', 'end'];
    static values = { prototype: String };

    connect() {
        this.savedSystem = this.selectedSystem();
        this.savedRows = this.rowsTarget.innerHTML;
        this.savedLegend = this.legendTarget.textContent;
    }

    refresh(event) {
        const changedSystem = event.target.name?.endsWith('[gradingSystem]');
        const changedYearDates = event.target === this.startTarget || event.target === this.endTarget;
        if (!changedSystem && !changedYearDates) {
            return;
        }

        const system = this.selectedSystem();
        if (system === this.savedSystem) {
            // Typing new school year dates keeps the saved period dates as they are
            if (changedSystem) {
                this.rowsTarget.innerHTML = this.savedRows;
                this.legendTarget.textContent = this.savedLegend;
            }
            return;
        }

        this.drawRows(SYSTEMS[system]);
    }

    drawRows({ count, name }) {
        const dates = this.evenSplit(count);
        this.legendTarget.textContent = `Dates of each ${name.toLowerCase()}`;
        this.rowsTarget.innerHTML = Array.from({ length: count }, (_, index) =>
            this.prototypeValue
                .replaceAll('__name__', String(index))
                .replaceAll('__label__', `${name} ${index + 1}`),
        ).join('');

        this.rowsTarget.querySelectorAll('.periods__row').forEach((row, index) => {
            const [startInput, endInput] = row.querySelectorAll('input[type="date"]');
            startInput.value = dates[index]?.start ?? '';
            endInput.value = dates[index]?.end ?? '';
        });
    }

    /** @returns {{start: string, end: string}[]} empty when the school year dates aren't set */
    evenSplit(count) {
        const start = Date.parse(this.startTarget.value);
        const end = Date.parse(this.endTarget.value);
        if (Number.isNaN(start) || Number.isNaN(end) || end < start) {
            return [];
        }

        const totalDays = Math.round((end - start) / DAY) + 1;
        const day = (offset) => new Date(start + offset * DAY).toISOString().slice(0, 10);

        return Array.from({ length: count }, (_, index) => ({
            start: day(Math.floor((totalDays * index) / count)),
            end: day(Math.floor((totalDays * (index + 1)) / count) - 1),
        }));
    }

    selectedSystem() {
        return this.element.querySelector('input[name$="[gradingSystem]"]:checked')?.value;
    }
}
