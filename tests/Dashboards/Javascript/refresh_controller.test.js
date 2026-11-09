import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import RefreshController from '../../../src/Dashboards/Presentation/assets/controllers/refresh_controller.js';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));
vi.mock('@hotwired/turbo', () => ({ visit }));

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('refresh_controller', () => {
    let application;
    let errors;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('refresh', RefreshController);
        visit.mockClear();
    });

    afterEach(() => {
        application.stop();
        vi.useRealTimers();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async (interval = '') => {
        document.body.innerHTML = `<div id="now" data-controller="refresh" ${interval}></div>`;
        await tick();

        return document.getElementById('now');
    };

    it('reloadsTheFrameEveryInterval', async () => {
        // Initialize
        vi.useFakeTimers();
        document.body.innerHTML = '<div id="now" data-controller="refresh" data-refresh-interval-value="1000"></div>';
        const frame = document.getElementById('now');
        frame.reload = vi.fn();
        await vi.advanceTimersByTimeAsync(0);

        // Execute
        await vi.advanceTimersByTimeAsync(2500);

        // Assert
        expect(frame.reload).toHaveBeenCalledTimes(2);
    });

    it('waitsAMinuteByDefaultAndSkipsAnElementThatCannotReload', async () => {
        // Initialize
        vi.useFakeTimers();
        document.body.innerHTML = '<div id="now" data-controller="refresh"></div>';
        await vi.advanceTimersByTimeAsync(0);

        // Execute
        await vi.advanceTimersByTimeAsync(60000);

        // Assert
        expect(document.getElementById('now')).not.toBeNull();
    });

    it('showsTheLoginPageAsAWholePageWhenTheFrameIsMissing', async () => {
        // Initialize
        const frame = await render();
        const event = new CustomEvent('turbo:frame-missing', {
            cancelable: true,
            detail: { response: { url: 'http://localhost/login' } },
        });

        // Execute
        frame.dispatchEvent(event);

        // Assert
        expect(event.defaultPrevented).toBe(true);
        expect(visit).toHaveBeenCalledWith('http://localhost/login');
    });

    it('stopsReloadingAndListeningOnceDisconnected', async () => {
        // Initialize
        vi.useFakeTimers();
        document.body.innerHTML = '<div id="now" data-controller="refresh" data-refresh-interval-value="1000"></div>';
        const frame = document.getElementById('now');
        frame.reload = vi.fn();
        await vi.advanceTimersByTimeAsync(0);

        // Execute
        frame.removeAttribute('data-controller');
        await vi.advanceTimersByTimeAsync(3000);
        frame.dispatchEvent(new CustomEvent('turbo:frame-missing', { detail: { response: { url: '/login' } } }));

        // Assert
        expect(frame.reload).not.toHaveBeenCalled();
        expect(visit).not.toHaveBeenCalled();
    });
});
