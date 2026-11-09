import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ClipboardController from '../../../src/Core/Presentation/assets/controllers/clipboard_controller.js';

describe('clipboard_controller', () => {
    let application;
    let errors;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('clipboard', ClipboardController);
    });

    afterEach(() => {
        application.stop();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async () => {
        document.body.innerHTML = '<code id="pw" data-controller="clipboard" data-clipboard-text-value="Temp-1234">Temp-1234</code>';
        await vi.advanceTimersByTimeAsync(0);
    };

    it('copiesTheTextAndSaysSoForAMoment', async () => {
        // Initialize
        vi.useFakeTimers();
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        await render();
        const button = document.querySelector('.copy-btn');

        // Execute
        button.click();
        await vi.advanceTimersByTimeAsync(0);
        const right = button.textContent;
        await vi.advanceTimersByTimeAsync(1500);

        // Assert
        expect(writeText).toHaveBeenCalledWith('Temp-1234');
        expect(right).toBe('Copied');
        expect(button.textContent).toBe('Copy');
        expect(button.previousElementSibling.id).toBe('pw');
    });

    it('addsNoButtonWithoutAClipboard', async () => {
        // Initialize
        vi.useFakeTimers();
        vi.stubGlobal('navigator', {});

        // Execute
        await render();

        // Assert
        expect(document.querySelector('.copy-btn')).toBeNull();
    });

    it('removesTheButtonOnDisconnect', async () => {
        // Initialize
        vi.useFakeTimers();
        vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn() } });
        await render();

        // Execute
        document.getElementById('pw').removeAttribute('data-controller');
        await vi.advanceTimersByTimeAsync(0);

        // Assert
        expect(document.querySelector('.copy-btn')).toBeNull();
    });

    it('disconnectsCleanlyWhenNoButtonWasAdded', async () => {
        // Initialize
        vi.useFakeTimers();
        vi.stubGlobal('navigator', {});
        await render();

        // Execute
        document.getElementById('pw').removeAttribute('data-controller');
        await vi.advanceTimersByTimeAsync(0);

        // Assert
        expect(document.getElementById('pw')).not.toBeNull();
    });
});
