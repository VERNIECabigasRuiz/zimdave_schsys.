import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import CommentPickerController from '../../../src/Grading/Presentation/assets/controllers/comment_picker_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('comment_picker_controller', () => {
    let application;
    let errors;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('comment-picker', CommentPickerController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async (withTextarea = true) => {
        document.body.innerHTML = `
            <div data-controller="comment-picker" data-action="change->comment-picker#update input->comment-picker#update">
                <input type="checkbox" id="c1"><label for="c1"> Diligent </label>
                <input type="checkbox" id="c2"><label for="c2">Helpful</label>
                <input type="checkbox" id="c3">
                ${withTextarea ? '<textarea id="typed"></textarea>' : ''}
                <span id="count" data-comment-picker-target="count"></span>
                <span id="chosen" data-comment-picker-target="chosen"></span>
            </div>`;
        await tick();
    };

    const summary = () => [document.getElementById('count').textContent, document.getElementById('chosen').textContent];

    const tickBox = (id) => {
        const box = document.getElementById(id);
        box.checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    };

    it('showsNoneWhenNothingIsChosen', async () => {
        // Initialize
        await render();

        // Execute
        document.getElementById('typed').dispatchEvent(new Event('input', { bubbles: true }));

        // Assert
        expect(summary()).toEqual(['0 comments:', 'none']);
    });

    it('countsOneCommentInTheSingular', async () => {
        // Initialize
        await render();

        // Execute
        tickBox('c1');

        // Assert
        expect(summary()).toEqual(['1 comment:', 'Diligent']);
    });

    it('countsSeveralCommentsAndATypedOneButSkipsABoxWithoutALabel', async () => {
        // Initialize
        await render();
        document.getElementById('typed').value = ' Kind to others ';
        tickBox('c3');

        // Execute
        tickBox('c1');
        tickBox('c2');

        // Assert
        expect(summary()).toEqual(['2 comments + typed:', 'Diligent · Helpful']);
    });

    it('worksWithoutATextarea', async () => {
        // Initialize
        await render(false);

        // Execute
        tickBox('c2');

        // Assert
        expect(summary()).toEqual(['1 comment + typed:', 'Helpful']);
    });
});
