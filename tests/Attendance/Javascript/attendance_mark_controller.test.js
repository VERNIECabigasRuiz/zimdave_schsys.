import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import AttendanceMarkController from '../../../src/Attendance/Presentation/assets/controllers/attendance_mark_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('attendance_mark_controller', () => {
    let application;
    let errors;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('attendance-mark', AttendanceMarkController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const select = (id, value, withLabel = true) => `
        ${withLabel ? `<label for="${id}">Ana Cruz</label>` : ''}
        <select id="${id}" data-attendance-mark-target="select">
            <option value="" ${value === '' ? 'selected' : ''}>Present</option>
            <option value="absent" ${value === 'absent' ? 'selected' : ''}>Absent</option>
            <option value="late" ${value === 'late' ? 'selected' : ''}>Late</option>
            <option value="cutting" ${value === 'cutting' ? 'selected' : ''}>Cutting</option>
        </select>`;

    it('replacesEachSelectWithAChipShowingItsMark', async () => {
        // Initialize
        document.body.innerHTML = `<form data-controller="attendance-mark">${select('ana', 'late')}</form>`;

        // Execute
        await tick();

        // Assert
        const chip = document.querySelector('.mark-chip');
        expect(document.getElementById('ana').hidden).toBe(true);
        expect(chip.textContent).toBe('Late');
        expect(chip.className).toBe('mark-chip mark-chip--late');
        expect(chip.getAttribute('aria-label')).toBe('Ana Cruz: Late. Tap to change.');
    });

    it('cyclesThroughTheMarksAndUpdatesTheCounts', async () => {
        // Initialize
        document.body.innerHTML = `
            <form data-controller="attendance-mark">
                ${select('ana', '')}
                ${select('ben', 'absent')}
                <p id="counts" data-attendance-mark-target="counts"></p>
            </form>`;
        await tick();
        const chip = document.querySelector('#ana + .mark-chip');
        const seen = [];

        // Execute
        for (let i = 0; i < 4; i++) {
            chip.click();
            seen.push([document.getElementById('ana').value, chip.textContent, document.getElementById('counts').textContent]);
        }

        // Assert
        expect(seen).toEqual([
            ['absent', 'Absent', '0 present · 2 absent · 0 late · 0 cutting'],
            ['late', 'Late', '1 present · 1 absent · 1 late · 0 cutting'],
            ['cutting', 'Cutting', '1 present · 1 absent · 0 late · 1 cutting'],
            ['', 'Present', '1 present · 1 absent · 0 late · 0 cutting'],
        ]);
        expect(chip.className).toBe('mark-chip');
    });

    it('labelsAChipWithoutALabelAndAnUnknownMarkByItsValue', async () => {
        // Initialize
        document.body.innerHTML = `
            <form data-controller="attendance-mark">
                <select id="cara" data-attendance-mark-target="select">
                    <option value="excused" selected>Excused</option>
                </select>
            </form>`;

        // Execute
        await tick();
        document.querySelector('.mark-chip').click();

        // Assert
        const chip = document.querySelector('.mark-chip');
        expect(chip.textContent).toBe('excused');
        expect(chip.getAttribute('aria-label')).toBe(': excused. Tap to change.');
    });
});
