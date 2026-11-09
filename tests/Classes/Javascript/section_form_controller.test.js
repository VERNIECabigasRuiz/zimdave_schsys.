import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import SectionFormController from '../../../src/Classes/Presentation/assets/controllers/section_form_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('section_form_controller', () => {
    let application;

    beforeEach(() => {
        application = Application.start();
        application.register('section-form', SectionFormController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
    });

    const render = async (grade) => {
        document.body.innerHTML = `
            <form data-controller="section-form">
                <select data-section-form-target="grade" data-action="section-form#toggle">
                    <option value="7" ${grade === '7' ? 'selected' : ''}>Grade 7</option>
                    <option value="11" ${grade === '11' ? 'selected' : ''}>Grade 11</option>
                </select>
                <div data-section-form-target="shs" id="track"></div>
                <div data-section-form-target="shs" id="strand"></div>
            </form>`;
        await tick();
    };

    it('hidesTheSeniorHighRowsForLowerGradesOnConnect', async () => {
        // Initialize
        await render('7');

        // Execute
        const hidden = [...document.querySelectorAll('[data-section-form-target="shs"]')].map((row) => row.hidden);

        // Assert
        expect(hidden).toEqual([true, true]);
    });

    it('showsTheSeniorHighRowsWhenGrade11IsChosen', async () => {
        // Initialize
        await render('7');
        const grade = document.querySelector('select');

        // Execute
        grade.value = '11';
        grade.dispatchEvent(new Event('change'));

        // Assert
        expect(document.getElementById('track').hidden).toBe(false);
        expect(document.getElementById('strand').hidden).toBe(false);
    });
});
