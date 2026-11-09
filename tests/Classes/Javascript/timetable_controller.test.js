import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import TimetableController from '../../../src/Classes/Presentation/assets/controllers/timetable_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const settle = async () => {
    for (let i = 0; i < 10; i += 1) {
        await tick();
    }
};

const GENERIC_ERROR = 'Could not save. Please try again.';
const SESSION_EXPIRED = 'Your session expired. Reload the page.';

const state = (changes = {}) => ({
    term: 0,
    terms: [0],
    days: [{ value: 1, label: 'Mon' }, { value: 2, label: 'Tue' }],
    periods: [
        { id: 10, label: 'Period 1', startsAt: '07:30', endsAt: '08:30', isBreak: false },
        { id: 11, label: 'Recess', startsAt: '08:30', endsAt: '08:50', isBreak: true },
        { id: 12, label: 'Period 2', startsAt: null, endsAt: null, isBreak: false },
    ],
    teachers: [{ id: 7, name: 'Ana Santos' }, { id: 8, name: 'Jose Reyes' }],
    cards: [
        { subjectId: 1, name: 'Mathematics', teacherId: 7, teacherName: 'Ana Santos', used: 1, perWeek: 5 },
        { subjectId: 2, name: 'Science', teacherId: null, teacherName: null, used: 0, perWeek: 4 },
        { subjectId: 3, name: 'English', teacherId: 8, teacherName: 'Jose Reyes', used: 0, perWeek: null },
        { subjectId: 4, name: 'Filipino', teacherId: 8, teacherName: 'Jose Reyes', used: 1, perWeek: 1 },
    ],
    cells: [{ entryId: 5, day: 1, periodId: 10, subjectId: 1, name: 'Mathematics', teacherName: 'Ana Santos' }],
    busy: { 7: { '2-10': 'Science, Grade 8 – Luna' } },
    ...changes,
});

/* A fetch answer: JSON unless another content type is given. */
const reply = (status, data, { type = 'application/json', redirected = false, badJson = false } = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    redirected,
    headers: { get: (name) => (name === 'Content-Type' ? type : null) },
    json: () => (badJson ? Promise.reject(new SyntaxError('Unexpected token')) : Promise.resolve(data)),
});

/* A fetch answer the test sends when it chooses. */
const deferred = () => {
    let answer;
    const promise = new Promise((resolve) => {
        answer = resolve;
    });

    return { promise, answer };
};

/* A drag event; jsdom has no DragEvent or DataTransfer. */
const drag = (type, element) => {
    const event = new Event(type, { bubbles: true, cancelable: true });
    const dataTransfer = { effectAllowed: 'none', dropEffect: 'none', setData: vi.fn() };
    Object.defineProperty(event, 'dataTransfer', { value: dataTransfer });
    element.dispatchEvent(event);

    return { event, dataTransfer };
};

describe('timetable_controller', () => {
    let application;
    let fetchMock;

    beforeAll(() => {
        // jsdom has no modal dialogs; open and close only toggle the attribute
        const dialog = HTMLDialogElement.prototype;
        if (!('open' in dialog)) {
            Object.defineProperty(dialog, 'open', { get() { return this.hasAttribute('open'); } });
        }
        if (typeof dialog.showModal !== 'function') {
            dialog.showModal = function showModal() { this.setAttribute('open', ''); };
        }
        if (typeof dialog.close !== 'function') {
            dialog.close = function close() { this.removeAttribute('open'); };
        }
    });

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        window.history.replaceState(null, '', '/schools/1/sections/2/timetable');
        application = Application.start();
        application.register('timetable', TimetableController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    const render = async ({ withTerms = true } = {}) => {
        document.body.innerHTML = `
            <div id="root" data-controller="timetable"
                 data-timetable-state-url-value="/t/state"
                 data-timetable-plot-url-value="/t/entries"
                 data-timetable-load-url-value="/t/loads/__SUBJECT__"
                 data-timetable-remove-url-value="/t/entries/__ENTRY__"
                 data-timetable-csrf-value="token-1"
                 data-timetable-term-value="0">
                ${withTerms ? '<nav id="terms" data-timetable-target="terms"></nav>' : ''}
                <div id="message" data-timetable-target="message" hidden></div>
                <div id="cards" data-timetable-target="cards"></div>
                <section id="grid" data-timetable-target="grid"></section>
                <dialog id="dialog" data-timetable-target="dialog">
                    <h2 id="dialog-title" data-timetable-target="dialogTitle"></h2>
                    <ul id="choices" data-timetable-target="choices"></ul>
                    <button type="button" id="cancel" data-action="timetable#closeDialog">Cancel</button>
                </dialog>
            </div>`;
        await settle();
    };

    /* Each request as [method, path with query, body]. */
    const requests = () => fetchMock.mock.calls.map(([url, options]) => [
        options.method,
        url.pathname + url.search,
        options.body === undefined ? undefined : JSON.parse(options.body),
    ]);

    const message = () => document.getElementById('message');
    const cell = (day, periodId) => document.querySelector(`#grid td[data-day="${day}"][data-period-id="${periodId}"]`);
    const card = (subjectId) => document.querySelector(`#cards li[data-subject-id="${subjectId}"]`);
    const select = (subjectId) => document.getElementById(`timetable-teacher-${subjectId}`);

    it('drawsTheTermsCardsAndGridTheServerSends', async () => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, state({ term: 1, terms: [1, 2] })));

        // Execute
        await render();

        // Assert
        const [, options] = fetchMock.mock.calls[0];
        expect(requests()).toEqual([['GET', '/t/state?term=0', undefined]]);
        expect(options.headers).toEqual({ Accept: 'application/json', 'X-CSRF-Token': 'token-1' });
        expect(document.getElementById('root').hasAttribute('aria-busy')).toBe(false);
        expect([...document.querySelectorAll('#terms button')].map((b) => [b.textContent, b.getAttribute('aria-pressed')]))
            .toEqual([['1st sem', 'true'], ['2nd sem', 'false']]);
        expect([...document.querySelectorAll('#cards .badge')].map((b) => [b.textContent, b.classList.contains('badge--green')]))
            .toEqual([['1/5', false], ['0/4', false], ['0/?', false], ['1/1', true]]);
        expect([1, 2, 3, 4].map((id) => card(id).getAttribute('draggable'))).toEqual(['true', 'false', 'false', 'false']);
        expect([...document.querySelectorAll('.timetable-card__note')].map((n) => n.textContent)).toEqual([
            'Pick a teacher for Science first.',
            'Set meetings per week for English on the Subjects page first.',
            'Filipino already has all 1 meetings this week.',
        ]);
        expect([select(1).value, select(2).value]).toEqual(['7', '']);
        expect([...document.querySelectorAll('#grid thead th')].map((th) => th.textContent)).toEqual(['Period', 'Mon', 'Tue']);
        expect(document.querySelector('#grid tr.timetable-break td').getAttribute('colspan')).toBe('2');
        expect([...document.querySelectorAll('#grid .timetable-time')].map((t) => t.textContent))
            .toEqual(['07:30–08:30', '08:30–08:50']);
        expect(cell(1, 10).textContent).toContain('Ana Santos');
        expect(cell(1, 10).querySelector('.timetable-remove').getAttribute('aria-label'))
            .toBe('Remove Mathematics from Mon, Period 1');
        expect(cell(2, 12).querySelector('.timetable-slot').getAttribute('aria-label')).toBe('Plot a class on Tue, Period 2');
    });

    it('saysSoWhenNoSubjectIsLeftToPlace', async () => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, state({ cards: [] })));

        // Execute
        await render({ withTerms: false });

        // Assert
        expect(document.getElementById('cards').textContent).toBe('No subjects to place for this section.');
    });

    it('switchesTheTermAndTheAddressOnceItsTimetableLoads', async () => {
        // Initialize
        fetchMock
            .mockResolvedValueOnce(reply(200, state({ term: 1, terms: [1, 2] })))
            .mockResolvedValueOnce(reply(200, state({ term: 2, terms: [1, 2] })));
        await render();

        // Execute
        document.querySelectorAll('#terms button')[1].click();
        await settle();

        // Assert
        expect(requests()[1]).toEqual(['GET', '/t/state?term=2', undefined]);
        expect(window.location.search).toBe('?term=2');
        expect(document.querySelector('#terms button[aria-pressed="true"]').textContent).toBe('2nd sem');
    });

    it('keepsTheTermWhenItsTimetableFailsToLoad', async () => {
        // Initialize
        fetchMock
            .mockResolvedValueOnce(reply(200, state({ term: 1, terms: [1, 2] })))
            .mockResolvedValueOnce(reply(500, { message: 'Server error' }));
        await render();

        // Execute
        document.querySelectorAll('#terms button')[1].click();
        await settle();

        // Assert
        expect(window.location.search).toBe('');
        expect(message().hidden).toBe(false);
        expect(message().textContent).toBe('Could not load the timetable. Reload the page.');
    });

    it('setsAndClearsATeacher', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();

        // Execute
        select(2).value = '7';
        select(2).dispatchEvent(new Event('change', { bubbles: true }));
        await settle();
        select(1).value = '';
        select(1).dispatchEvent(new Event('change', { bubbles: true }));
        await settle();

        // Assert
        const [, options] = fetchMock.mock.calls[1];
        expect(requests().slice(1)).toEqual([
            ['PUT', '/t/loads/2?term=0', { teacherId: 7 }],
            ['PUT', '/t/loads/1?term=0', { teacherId: null }],
        ]);
        expect(options.headers['Content-Type']).toBe('application/json');
        expect(message().hidden).toBe(true);
    });

    it('showsWhereTheTeacherIsBusyWhileACardIsDraggedAndPlotsOnDrop', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();

        // Execute
        const start = drag('dragstart', card(1));
        const painted = [cell(2, 10).className, cell(1, 12).className, cell(2, 10).textContent];
        const overBusy = drag('dragover', cell(2, 10));
        const overFree = drag('dragover', cell(1, 12));
        drag('drop', cell(2, 10));
        await settle();

        // Assert
        expect(start.event.defaultPrevented).toBe(false);
        expect(start.dataTransfer.effectAllowed).toBe('copy');
        expect(start.dataTransfer.setData).toHaveBeenCalledWith('text/plain', 'Mathematics');
        expect(painted).toEqual([
            'timetable-cell is-busy',
            'timetable-cell is-free',
            'Ana Santos teaches Science, Grade 8 – Luna',
        ]);
        expect([overBusy.event.defaultPrevented, overFree.event.defaultPrevented]).toEqual([true, true]);
        expect(overFree.dataTransfer.dropEffect).toBe('copy');
        expect(requests()[1]).toEqual(['POST', '/t/entries?term=0', { subjectId: 1, day: 2, periodId: 10 }]);
        expect(document.querySelectorAll('#grid .is-free, #grid .is-busy, #grid .timetable-busy')).toHaveLength(0);
    });

    it('cleansUpWhenADragEndsWithoutADrop', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();
        drag('dragstart', card(1));

        // Execute
        drag('dragend', card(1));
        const afterEnd = drag('dragover', cell(1, 12));
        drag('drop', cell(1, 12));
        await settle();

        // Assert
        expect(afterEnd.event.defaultPrevented).toBe(false);
        expect(document.querySelectorAll('#grid .is-free, #grid .is-busy, #grid .timetable-busy')).toHaveLength(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('refusesToDragABlockedCardOrOneNotYetLoaded', async () => {
        // Initialize
        const first = deferred();
        fetchMock.mockReturnValueOnce(first.promise);
        await render();
        document.getElementById('cards').innerHTML = `<li id="early" data-action="dragstart->timetable#dragStart"
            data-timetable-subject-id-param="1"></li>`;
        await settle();

        // Execute
        const early = drag('dragstart', document.getElementById('early'));
        first.answer(reply(200, state()));
        await settle();
        const blocked = drag('dragstart', card(2));

        // Assert
        expect([early.event.defaultPrevented, blocked.event.defaultPrevented]).toEqual([true, true]);
        expect(document.querySelectorAll('#grid .is-free, #grid .is-busy')).toHaveLength(0);
    });

    it('ignoresADragOverACellThatIsNotMarked', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();
        drag('dragstart', card(1));
        cell(1, 12).classList.remove('is-free');

        // Execute
        const over = drag('dragover', cell(1, 12));

        // Assert
        expect(over.event.defaultPrevented).toBe(false);
    });

    it('plotsAClassPickedInTheDialog', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();

        // Execute
        cell(1, 12).querySelector('.timetable-slot').click();
        await settle();
        const opened = document.getElementById('dialog').open;
        const title = document.getElementById('dialog-title').textContent;
        const choices = [...document.querySelectorAll('#choices button')].map((button) => [
            button.querySelector('strong').textContent,
            button.disabled,
            button.querySelector('.timetable-choice__why')?.textContent ?? null,
        ]);
        document.querySelector('#choices button').click();
        await settle();

        // Assert
        expect(opened).toBe(true);
        expect(title).toBe('Plot a class: Mon, Period 2');
        expect(choices).toEqual([
            ['Mathematics', false, null],
            ['Science', true, 'Pick a teacher for Science first.'],
            ['English', true, 'Set meetings per week for English on the Subjects page first.'],
            ['Filipino', true, 'Filipino already has all 1 meetings this week.'],
        ]);
        expect(document.getElementById('dialog').open).toBe(false);
        expect(requests()[1]).toEqual(['POST', '/t/entries?term=0', { subjectId: 1, day: 1, periodId: 12 }]);
    });

    it('showsInTheDialogWhenTheTeacherIsBusyThen', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();

        // Execute
        cell(2, 10).querySelector('.timetable-slot').click();

        // Assert
        const first = document.querySelector('#choices button');
        expect(first.disabled).toBe(true);
        expect(first.querySelector('.timetable-choice__why').textContent)
            .toBe('Ana Santos teaches Science, Grade 8 – Luna at this time.');
        expect(first.querySelector('.muted').textContent).toBe('Ana Santos');
        expect(document.querySelectorAll('#choices button')[1].querySelector('.muted').textContent).toBe('No teacher yet');
    });

    it('saysSoInTheDialogWhenNoSubjectIsLeftAndClosesOnCancel', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state({ cards: [] })));
        await render();

        // Execute
        cell(1, 12).querySelector('.timetable-slot').click();
        const text = document.getElementById('choices').textContent;
        document.getElementById('cancel').click();

        // Assert
        expect(text).toBe('No subjects to place for this section.');
        expect(document.getElementById('dialog').open).toBe(false);
    });

    it('closesAnOpenDialogWhenThePageLeaves', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();
        cell(1, 12).querySelector('.timetable-slot').click();
        const dialog = document.getElementById('dialog');

        // Execute
        document.getElementById('root').remove();
        await settle();

        // Assert
        expect(dialog.open).toBe(false);
    });

    it('leavesAClosedDialogAloneWhenThePageLeaves', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();
        const close = vi.spyOn(document.getElementById('dialog'), 'close');

        // Execute
        document.getElementById('root').remove();
        await settle();

        // Assert
        expect(close).not.toHaveBeenCalled();
    });

    it('removesAClass', async () => {
        // Initialize
        fetchMock.mockResolvedValue(reply(200, state()));
        await render();

        // Execute
        cell(1, 10).querySelector('.timetable-remove').click();
        await settle();

        // Assert
        const [, options] = fetchMock.mock.calls[1];
        expect(requests()[1]).toEqual(['DELETE', '/t/entries/5?term=0', undefined]);
        expect(options.headers['Content-Type']).toBeUndefined();
    });

    it('listsTheClashesOfARefusalAndRedrawsFromTheServer', async () => {
        // Initialize
        fetchMock
            .mockResolvedValueOnce(reply(200, state()))
            .mockResolvedValueOnce(reply(422, { message: 'First. Second.', clashes: ['First.', 'Second.'] }))
            .mockResolvedValueOnce(reply(200, state({ cells: [] })));
        await render();

        // Execute
        select(2).value = '7';
        select(2).dispatchEvent(new Event('change', { bubbles: true }));
        await settle();

        // Assert
        expect(requests()[2]).toEqual(['GET', '/t/state?term=0', undefined]);
        expect([...message().querySelectorAll('li')].map((li) => li.textContent)).toEqual(['First.', 'Second.']);
        expect(message().hidden).toBe(false);
        expect(select(2).value).toBe('');
        expect(document.querySelectorAll('#grid .timetable-cell--filled')).toHaveLength(0);
    });

    it.each([
        ['its message', { message: 'Mathematics already has all 5 meetings this week.' }, 'Mathematics already has all 5 meetings this week.'],
        ['a validation detail', { detail: 'day: This value should be between 1 and 5.' }, 'day: This value should be between 1 and 5.'],
        ['no message at all', {}, GENERIC_ERROR],
        ['an empty clash list', { message: 'Refused.', clashes: null }, 'Refused.'],
    ])('showsARefusalWith %s', async (_, body, expected) => {
        // Initialize
        fetchMock
            .mockResolvedValueOnce(reply(200, state()))
            .mockResolvedValueOnce(reply(422, body))
            .mockResolvedValueOnce(reply(500, null));
        await render();

        // Execute
        cell(1, 10).querySelector('.timetable-remove').click();
        await settle();

        // Assert
        expect(message().textContent).toBe(expected);
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it.each([
        ['a redirect to the login page', reply(200, null, { redirected: true })],
        ['an HTML page', reply(200, null, { type: 'text/html; charset=UTF-8' })],
        ['an answer without a content type', reply(204, null, { type: null })],
    ])('asksForAReloadAfter %s', async (_, answer) => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, state())).mockResolvedValueOnce(answer);
        await render();

        // Execute
        cell(1, 10).querySelector('.timetable-remove').click();
        await settle();

        // Assert
        expect(message().textContent).toBe(SESSION_EXPIRED);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('asksForAReloadWhenTheFirstLoadFindsTheSessionExpired', async () => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, null, { redirected: true }));

        // Execute
        await render();

        // Assert
        expect(message().textContent).toBe(SESSION_EXPIRED);
    });

    it.each([
        ['a network failure', () => Promise.reject(new TypeError('Failed to fetch'))],
        ['a server error', () => Promise.resolve(reply(500, { message: 'Internal error' }))],
        ['a broken JSON answer', () => Promise.resolve(reply(200, null, { badJson: true }))],
        ['a refusal with a broken body', () => Promise.resolve(reply(422, null, { badJson: true }))],
    ])('showsTheGenericErrorAfter %s', async (_, answer) => {
        // Initialize
        fetchMock
            .mockResolvedValueOnce(reply(200, state()))
            .mockImplementationOnce(answer)
            .mockResolvedValueOnce(reply(200, state()));
        await render();

        // Execute
        cell(1, 10).querySelector('.timetable-remove').click();
        await settle();

        // Assert
        expect(message().textContent).toBe(GENERIC_ERROR);
        expect(document.getElementById('root').hasAttribute('aria-busy')).toBe(false);
    });

    it('drawsOnlyTheNewestAnswerAndStaysBusyUntilAllSettle', async () => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, state()));
        await render();
        const older = deferred();
        const newer = deferred();
        fetchMock.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
        select(2).value = '7';
        select(2).dispatchEvent(new Event('change', { bubbles: true }));
        select(3).value = '7';
        select(3).dispatchEvent(new Event('change', { bubbles: true }));

        // Execute
        newer.answer(reply(200, state({ cells: [] })));
        await settle();
        const busyWhileOlderPending = document.getElementById('root').getAttribute('aria-busy');
        older.answer(reply(200, state()));
        await settle();

        // Assert
        expect(busyWhileOlderPending).toBe('true');
        expect(document.getElementById('root').hasAttribute('aria-busy')).toBe(false);
        expect(document.querySelectorAll('#grid .timetable-cell--filled')).toHaveLength(0);
    });

    it('skipsTheRedrawOfARefusalOvertakenByANewerRequest', async () => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, state()));
        await render();
        const older = deferred();
        fetchMock.mockReturnValueOnce(older.promise).mockResolvedValueOnce(reply(200, state({ cells: [] })));
        cell(1, 10).querySelector('.timetable-remove').click();
        select(2).value = '7';
        select(2).dispatchEvent(new Event('change', { bubbles: true }));
        await settle();

        // Execute
        older.answer(reply(422, { message: 'Refused.' }));
        await settle();

        // Assert
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(message().textContent).toBe('Refused.');
        expect(document.querySelectorAll('#grid .timetable-cell--filled')).toHaveLength(0);
    });

    it('keepsTheNewerTermWhenAnOlderSwitchAnswersLate', async () => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, state({ term: 1, terms: [1, 2] })));
        await render();
        const older = deferred();
        fetchMock.mockReturnValueOnce(older.promise).mockResolvedValueOnce(reply(200, state({ term: 1, terms: [1, 2] })));
        document.querySelectorAll('#terms button')[1].click();
        document.querySelectorAll('#terms button')[0].click();
        await settle();

        // Execute
        older.answer(reply(200, state({ term: 2, terms: [1, 2] })));
        await settle();

        // Assert
        expect(document.querySelector('#terms button[aria-pressed="true"]').textContent).toBe('1st sem');
        expect(window.location.search).toBe('?term=1');
    });

    it('keepsTheNewerStateWhenTheRedrawAfterARefusalAnswersLate', async () => {
        // Initialize
        fetchMock.mockResolvedValueOnce(reply(200, state()));
        await render();
        const reload = deferred();
        fetchMock
            .mockResolvedValueOnce(reply(422, { message: 'Refused.' }))
            .mockReturnValueOnce(reload.promise)
            .mockResolvedValueOnce(reply(200, state({ cells: [] })));
        cell(1, 10).querySelector('.timetable-remove').click();
        await settle();
        select(2).value = '7';
        select(2).dispatchEvent(new Event('change', { bubbles: true }));
        await settle();

        // Execute
        reload.answer(reply(200, state()));
        await settle();

        // Assert
        expect(document.querySelectorAll('#grid .timetable-cell--filled')).toHaveLength(0);
    });
});
