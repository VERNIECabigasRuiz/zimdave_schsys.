import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import GradingPeriodsController from '../../../src/Schools/Presentation/assets/controllers/grading_periods_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

const PROTOTYPE = '<div class="periods__row"><span>__label__</span>'
    + '<input type="date" name="sy[periods][__name__][start]"><input type="date" name="sy[periods][__name__][end]"></div>';

describe('grading_periods_controller', () => {
    let application;
    let errors;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('grading-periods', GradingPeriodsController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async ({ system = 'terms', start = '2026-06-01', end = '2027-03-31' } = {}) => {
        const prototype = PROTOTYPE.replaceAll('"', '&quot;');
        document.body.innerHTML = `
            <form data-controller="grading-periods" data-action="change->grading-periods#refresh"
                  data-grading-periods-prototype-value="${prototype}">
                <input type="date" id="start" data-grading-periods-target="start" value="${start}">
                <input type="date" id="end" data-grading-periods-target="end" value="${end}">
                <input type="radio" id="terms" name="sy[gradingSystem]" value="terms" ${system === 'terms' ? 'checked' : ''}>
                <input type="radio" id="quarters" name="sy[gradingSystem]" value="quarters" ${system === 'quarters' ? 'checked' : ''}>
                <input type="text" id="name" name="sy[name]">
                <div id="other"></div>
                <legend id="legend" data-grading-periods-target="legend">Saved dates</legend>
                <div id="rows" data-grading-periods-target="rows"><div class="periods__row">saved</div></div>
            </form>`;
        await tick();
    };

    const choose = (id) => {
        const radio = document.getElementById(id);
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const change = (id, value) => {
        const field = document.getElementById(id);
        if (value !== undefined) {
            field.value = value;
        }
        field.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const rows = () => [...document.querySelectorAll('#rows .periods__row')].map((row) => {
        const [startInput, endInput] = row.querySelectorAll('input[type="date"]');
        return [row.querySelector('span').textContent, startInput.value, endInput.value];
    });

    it('splitsTheSchoolYearEvenlyForAnotherSystem', async () => {
        // Initialize
        await render();

        // Execute
        choose('quarters');

        // Assert
        const drawn = rows();
        expect(document.getElementById('legend').textContent).toBe('Dates of each quarter');
        expect(drawn.map((row) => row[0])).toEqual(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4']);
        expect(drawn[0][1]).toBe('2026-06-01');
        expect(drawn[3][2]).toBe('2027-03-31');
        for (let i = 1; i < drawn.length; i++) {
            const dayAfter = new Date(Date.parse(drawn[i - 1][2]) + 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
            expect(drawn[i][1]).toBe(dayAfter);
        }
    });

    it('restoresTheSavedRowsWhenTheSavedSystemIsChosenAgain', async () => {
        // Initialize
        await render();
        choose('quarters');

        // Execute
        choose('terms');

        // Assert
        expect(document.getElementById('rows').innerHTML).toBe('<div class="periods__row">saved</div>');
        expect(document.getElementById('legend').textContent).toBe('Saved dates');
    });

    it('keepsTheSavedRowsWhenYearDatesChangeOnTheSavedSystem', async () => {
        // Initialize
        await render();

        // Execute
        change('end', '2027-04-30');

        // Assert
        expect(document.getElementById('rows').innerHTML).toBe('<div class="periods__row">saved</div>');
    });

    it('redrawsForNewYearDatesOnAnotherSystem', async () => {
        // Initialize
        await render();
        choose('quarters');

        // Execute
        change('end', '2027-04-30');

        // Assert
        expect(rows()[3][2]).toBe('2027-04-30');
    });

    it('leavesTheDatesEmptyWhenTheYearDatesAreMissingOrReversed', async () => {
        // Initialize
        await render({ start: '2027-03-31', end: '2026-06-01' });

        // Execute
        choose('quarters');
        const reversed = rows().map((row) => [row[1], row[2]]);
        change('start', '');

        // Assert
        expect(reversed).toEqual([['', ''], ['', ''], ['', ''], ['', '']]);
        expect(rows().map((row) => [row[1], row[2]])).toEqual([['', ''], ['', ''], ['', ''], ['', '']]);
    });

    it('ignoresOtherFields', async () => {
        // Initialize
        await render();

        // Execute
        change('name', 'S.Y. 2026-2027');
        document.getElementById('other').dispatchEvent(new Event('change', { bubbles: true }));

        // Assert
        expect(document.getElementById('rows').innerHTML).toBe('<div class="periods__row">saved</div>');
    });

    it('drawsRowsWhenNoSystemWasSaved', async () => {
        // Initialize
        await render({ system: 'none' });

        // Execute
        choose('terms');

        // Assert
        expect(rows().map((row) => row[0])).toEqual(['Term 1', 'Term 2', 'Term 3']);
    });
});
