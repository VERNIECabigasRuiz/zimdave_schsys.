import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ImagePreviewController from '../../../src/Core/Presentation/assets/controllers/image_preview_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('image_preview_controller', () => {
    let application;
    let errors;
    let createObjectURL;
    let revokeObjectURL;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('image-preview', ImagePreviewController);
        createObjectURL = vi.fn().mockReturnValueOnce('blob:one').mockReturnValueOnce('blob:two');
        revokeObjectURL = vi.fn();
        vi.stubGlobal('URL', { createObjectURL, revokeObjectURL });
    });

    afterEach(() => {
        application.stop();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async () => {
        document.body.innerHTML = `
            <div id="box" data-controller="image-preview">
                <input type="file" id="file" data-action="image-preview#show">
                <img id="image" data-image-preview-target="image" hidden alt="">
                <p id="hint" data-image-preview-target="hint" hidden>Save to keep this photo.</p>
            </div>`;
        await tick();
    };

    const pick = (files) => {
        const input = document.getElementById('file');
        Object.defineProperty(input, 'files', { value: files, configurable: true });
        // An action without an event name listens to "input" on an input field
        input.dispatchEvent(new Event('input'));
    };

    const image = (type, size = 1000) => ({ type, size });
    const state = () => {
        const hint = document.getElementById('hint');
        return [document.getElementById('image').hidden, hint.hidden, hint.textContent, hint.classList.contains('preview-hint--error')];
    };

    it('previewsAGoodImageAndRevokesThePreviousOne', async () => {
        // Initialize
        await render();
        pick([image('image/png')]);

        // Execute
        pick([image('image/webp')]);

        // Assert
        expect(state()).toEqual([false, false, 'Save to keep this photo.', false]);
        expect(document.getElementById('image').getAttribute('src')).toBe('blob:two');
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:one');
    });

    it('refusesAFileThatIsNotAnImage', async () => {
        // Initialize
        await render();

        // Execute
        pick([image('application/pdf')]);

        // Assert
        expect(state()).toEqual([true, false, 'This file is not a JPG, PNG or WebP image.', true]);
        expect(createObjectURL).not.toHaveBeenCalled();
    });

    it('refusesAnImageOverFiveMegabytes', async () => {
        // Initialize
        await render();

        // Execute
        pick([image('image/jpeg', 5 * 1024 * 1024 + 1)]);

        // Assert
        expect(state()).toEqual([true, false, 'This image is bigger than 5 MB.', true]);
    });

    it('hidesEverythingWhenTheChoiceIsCleared', async () => {
        // Initialize
        await render();
        pick([image('image/jpeg')]);

        // Execute
        pick([]);

        // Assert
        expect(state().slice(0, 2)).toEqual([true, true]);
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:one');
    });

    it('revokesThePreviewOnDisconnect', async () => {
        // Initialize
        await render();
        pick([image('image/png')]);

        // Execute
        document.getElementById('box').removeAttribute('data-controller');
        await tick();

        // Assert
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:one');
    });
});
