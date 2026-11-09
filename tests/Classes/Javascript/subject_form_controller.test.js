import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import SubjectFormController from '../../../src/Classes/Presentation/assets/controllers/subject_form_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('subject_form_controller', () => {
    let application;

    beforeEach(() => {
        application = Application.start();
        application.register('subject-form', SubjectFormController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
    });

    const render = async ({ grade, parent = '', hasComponents = false }) => {
        document.body.innerHTML = `
            <form data-controller="subject-form" data-subject-form-has-components-value="${hasComponents}">
                <select id="grade" data-subject-form-target="grade" data-action="subject-form#toggle">
                    <option value="7" ${grade === '7' ? 'selected' : ''}>Grade 7</option>
                    <option value="11" ${grade === '11' ? 'selected' : ''}>Grade 11</option>
                </select>
                <select id="parent" data-subject-form-target="parent" data-action="subject-form#toggle">
                    <option value="">Not a component</option>
                    <option value="1" data-grade="7" ${parent === '1' ? 'selected' : ''}>MAPEH7</option>
                    <option value="2" data-grade="11" ${parent === '2' ? 'selected' : ''}>PE11</option>
                </select>
                <div id="meetings" data-subject-form-target="meetings"></div>
                <div id="semester" data-subject-form-target="shs"></div>
            </form>`;
        await tick();
    };

    const option = (value) => document.querySelector(`#parent option[value="${value}"]`);

    it('listsOnlyTheChosenGradesParentsAndHidesSeniorHighRowsForGrade7', async () => {
        // Initialize
        await render({ grade: '7' });

        // Execute
        const hidden = [option('1').hidden, option('2').hidden, document.getElementById('semester').hidden];

        // Assert
        expect(hidden).toEqual([false, true, true]);
        expect(document.getElementById('meetings').hidden).toBe(false);
    });

    it('clearsAParentOfAnotherGradeAndShowsSeniorHighRows', async () => {
        // Initialize
        await render({ grade: '7', parent: '1' });
        const grade = document.getElementById('grade');

        // Execute
        grade.value = '11';
        grade.dispatchEvent(new Event('change'));

        // Assert
        expect(document.getElementById('parent').value).toBe('');
        expect(document.getElementById('semester').hidden).toBe(false);
    });

    it('hidesMeetingsForASubjectWithComponentsAndSeniorHighRowsForAComponent', async () => {
        // Initialize
        await render({ grade: '11', parent: '2', hasComponents: true });

        // Execute
        const hidden = [document.getElementById('meetings').hidden, document.getElementById('semester').hidden];

        // Assert
        expect(hidden).toEqual([true, true]);
    });
});
