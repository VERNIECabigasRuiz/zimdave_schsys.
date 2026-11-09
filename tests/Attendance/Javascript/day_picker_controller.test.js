import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import DayPickerController from '../../../src/Attendance/Presentation/assets/controllers/day_picker_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('day_picker_controller', () => {
    let application;
    let errors;
    let assign;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('day-picker', DayPickerController);
        assign = vi.fn();
        vi.stubGlobal('location', { assign });
    });

    afterEach(() => {
        application.stop();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async () => {
        document.body.innerHTML = `
            <div data-controller="day-picker" data-day-picker-url-value="/teacher/class/attendance">
                <input id="day" type="date" data-action="change->day-picker#go">
            </div>`;
        await tick();
    };

    it('opensTheChosenDay', async () => {
        // Initialize
        await render();
        const day = document.getElementById('day');

        // Execute
        day.value = '2026-11-09';
        day.dispatchEvent(new Event('change'));

        // Assert
        expect(assign).toHaveBeenCalledWith('/teacher/class/attendance/2026-11-09');
    });

    it('doesNothingWhenTheDateIsCleared', async () => {
        // Initialize
        await render();
        const day = document.getElementById('day');

        // Execute
        day.value = '';
        day.dispatchEvent(new Event('change'));

        // Assert
        expect(assign).not.toHaveBeenCalled();
    });
});
