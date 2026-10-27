import { Controller } from '@hotwired/stimulus';

const GENERIC_ERROR = 'Could not save. Please try again.';
const SESSION_EXPIRED = 'Your session expired. Reload the page.';

/*
 * Plots a section's weekly timetable: drag a subject card onto a cell, or
 * click an empty cell and pick a subject. Every change saves at once and the
 * page redraws from the state the server sends back. The server checks every
 * rule again; without this the page shows the read-only grid.
 */
export default class extends Controller {
    static targets = ['terms', 'message', 'cards', 'grid', 'dialog', 'dialogTitle', 'choices'];
    static values = {
        stateUrl: String,
        plotUrl: String,
        loadUrl: String,
        removeUrl: String,
        csrf: String,
        term: Number,
    };

    connect() {
        this.state = null;
        this.dragging = null;
        this.slot = null;
        this.load();
    }

    disconnect() {
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    // ---------- Actions ----------

    switchTerm({ params: { term } }) {
        this.termValue = term;
        const url = new URL(window.location.href);
        url.searchParams.set('term', term);
        window.history.replaceState(window.history.state, '', url);
        this.load();
    }

    setTeacher(event) {
        const subjectId = Number(event.target.dataset.subjectId);
        const teacherId = event.target.value === '' ? null : Number(event.target.value);
        this.save('PUT', this.loadUrlValue.replace('__SUBJECT__', subjectId), { teacherId });
    }

    dragStart(event) {
        const card = this.card(event.params.subjectId);
        if (!card || this.cardBlockedReason(card) !== null) {
            event.preventDefault();
            return;
        }
        this.dragging = card;
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', card.name);
        this.paintAvailability(card);
    }

    dragEnd() {
        this.dragging = null;
        this.gridTarget.querySelectorAll('.is-free, .is-busy').forEach((cell) => {
            cell.classList.remove('is-free', 'is-busy');
            cell.querySelector('.timetable-busy')?.remove();
        });
    }

    // Free and busy cells accept a drop; the server refuses a busy one with its message.
    dragOver(event) {
        const cell = event.currentTarget;
        if (this.dragging && (cell.classList.contains('is-free') || cell.classList.contains('is-busy'))) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        }
    }

    drop(event) {
        event.preventDefault();
        const card = this.dragging;
        const { day, periodId } = event.currentTarget.dataset;
        this.dragEnd();
        if (card) {
            this.plot(card.subjectId, Number(day), Number(periodId));
        }
    }

    openDialog({ params: { day, periodId } }) {
        this.slot = { day, periodId };
        this.dialogTitleTarget.textContent = `Plot a class: ${this.slotLabel(day, periodId)}`;
        const choices = this.state.cards.map((card) => {
            const reason = this.cardBlockedReason(card) ?? this.busyReason(card, day, periodId);
            return h('li', {},
                h('button', {
                    type: 'button',
                    class: 'timetable-choice',
                    disabled: reason !== null,
                    'data-action': 'timetable#choose',
                    'data-timetable-subject-id-param': card.subjectId,
                },
                h('strong', {}, card.name),
                h('span', { class: 'badge' }, this.countText(card)),
                h('span', { class: 'muted' }, card.teacherName ?? 'No teacher yet'),
                reason && h('span', { class: 'timetable-choice__why' }, reason)));
        });
        this.choicesTarget.replaceChildren(...(choices.length > 0
            ? choices
            : [h('li', { class: 'muted' }, 'No subjects to place for this section.')]));
        this.dialogTarget.showModal();
    }

    choose({ params: { subjectId } }) {
        const { day, periodId } = this.slot;
        this.closeDialog();
        this.plot(subjectId, day, periodId);
    }

    closeDialog() {
        this.dialogTarget.close();
    }

    remove({ params: { entryId } }) {
        this.save('DELETE', this.removeUrlValue.replace('__ENTRY__', entryId));
    }

    // ---------- Server ----------

    plot(subjectId, day, periodId) {
        this.save('POST', this.plotUrlValue, { subjectId, day, periodId });
    }

    async load() {
        const result = await this.request('GET', this.stateUrlValue);
        if (result.ok) {
            this.draw(result.data);
        } else {
            this.showMessage(result.expired ? result.message : 'Could not load the timetable. Reload the page.');
        }
    }

    async save(method, url, body = null) {
        this.element.setAttribute('aria-busy', 'true');
        const result = await this.request(method, url, body);
        this.element.removeAttribute('aria-busy');

        if (result.ok) {
            this.hideMessage();
            this.draw(result.data);
            return;
        }
        this.showMessage(result.message, result.clashes);
        if (!result.expired) {
            // Undo what the page shows (a changed dropdown) by redrawing from the server
            const reload = await this.request('GET', this.stateUrlValue);
            if (reload.ok) {
                this.draw(reload.data);
            }
        }
    }

    /* Resolves to {ok, data} or {ok: false, message, clashes, expired}; never throws. */
    async request(method, url, body = null) {
        const withTerm = new URL(url, window.location.origin);
        withTerm.searchParams.set('term', this.termValue);
        const headers = { Accept: 'application/json', 'X-CSRF-Token': this.csrfValue };
        if (body !== null) {
            headers['Content-Type'] = 'application/json';
        }

        let response;
        try {
            response = await fetch(withTerm, { method, headers, body: body === null ? undefined : JSON.stringify(body) });
        } catch {
            return { ok: false, message: GENERIC_ERROR };
        }

        // A bad token or a lost session redirects to the login page, which is HTML
        const isJson = (response.headers.get('Content-Type') ?? '').includes('json');
        if (response.redirected || !isJson) {
            return { ok: false, message: SESSION_EXPIRED, expired: true };
        }
        const data = await response.json().catch(() => null);
        if (response.ok && data !== null) {
            return { ok: true, data };
        }
        if (response.status === 422 && data !== null) {
            return { ok: false, message: data.message ?? data.detail ?? GENERIC_ERROR, clashes: data.clashes };
        }

        return { ok: false, message: GENERIC_ERROR };
    }

    // ---------- Drawing ----------

    draw(state) {
        this.state = state;
        this.termValue = state.term;
        this.drawTerms();
        this.drawCards();
        this.drawGrid();
    }

    drawTerms() {
        if (!this.hasTermsTarget) {
            return;
        }
        this.termsTarget.replaceChildren(...this.state.terms.map((term) => h('button', {
            type: 'button',
            class: `btn btn--sm ${term === this.state.term ? 'btn--primary' : 'btn--ghost'}`,
            'aria-pressed': String(term === this.state.term),
            'data-action': 'timetable#switchTerm',
            'data-timetable-term-param': term,
        }, term === 1 ? '1st sem' : '2nd sem')));
    }

    drawCards() {
        if (this.state.cards.length === 0) {
            this.cardsTarget.replaceChildren(h('p', { class: 'muted' }, 'No subjects to place for this section.'));
            return;
        }

        const hint = h('p', { class: 'muted timetable-hint' },
            'Drag a subject onto the timetable, or click an empty cell.');
        const cards = this.state.cards.map((card) => {
            const reason = this.cardBlockedReason(card);
            const full = card.perWeek !== null && card.used >= card.perWeek;
            const selectId = `timetable-teacher-${card.subjectId}`;
            const select = h('select', {
                id: selectId,
                'data-subject-id': card.subjectId,
                'data-action': 'timetable#setTeacher',
            },
            new Option('No teacher', '', false, card.teacherId === null),
            ...this.state.teachers.map((teacher) => new Option(teacher.name, teacher.id, false, teacher.id === card.teacherId)));

            return h('li', {
                class: `timetable-card${full ? ' is-full' : ''}`,
                draggable: reason === null ? 'true' : 'false',
                'data-subject-id': card.subjectId,
                'data-action': 'dragstart->timetable#dragStart dragend->timetable#dragEnd',
                'data-timetable-subject-id-param': card.subjectId,
            },
            h('div', { class: 'timetable-card__head' },
                h('strong', {}, card.name),
                h('span', { class: `badge${full ? ' badge--green' : ''}` }, this.countText(card))),
            h('label', { class: 'visually-hidden', for: selectId }, `Teacher for ${card.name}`),
            select,
            reason && h('p', { class: 'timetable-card__note' }, reason));
        });

        this.cardsTarget.replaceChildren(hint, h('ul', { class: 'timetable-card-list' }, ...cards));
    }

    drawGrid() {
        const { days, periods, cells } = this.state;
        const legend = h('p', { class: 'timetable-legend' },
            h('span', { class: 'timetable-legend__free' }, 'Teacher is free'),
            h('span', { class: 'timetable-legend__busy' }, 'Teacher is busy in another section'));

        const head = h('thead', {}, h('tr', {},
            h('th', {}, h('span', { class: 'visually-hidden' }, 'Period')),
            ...days.map((day) => h('th', {}, day.label))));

        const rows = periods.map((period) => {
            const label = h('th', { scope: 'row' },
                period.label,
                period.startsAt && h('span', { class: 'muted timetable-time' }, `${period.startsAt}–${period.endsAt}`));
            if (period.isBreak) {
                return h('tr', { class: 'timetable-break', 'data-period-id': period.id },
                    label, h('td', { colspan: days.length }, period.label));
            }

            return h('tr', { 'data-period-id': period.id }, label, ...days.map((day) => {
                const slot = this.slotLabel(day.value, period.id);
                const cell = cells.find((c) => c.day === day.value && c.periodId === period.id);
                if (cell) {
                    return h('td', {
                        class: 'timetable-cell timetable-cell--filled',
                        'data-day': day.value,
                        'data-period-id': period.id,
                        'data-entry-id': cell.entryId,
                    },
                    h('strong', {}, cell.name),
                    h('span', { class: 'muted' }, cell.teacherName),
                    h('button', {
                        type: 'button',
                        class: 'timetable-remove',
                        'aria-label': `Remove ${cell.name} from ${slot}`,
                        title: 'Remove',
                        'data-action': 'timetable#remove',
                        'data-timetable-entry-id-param': cell.entryId,
                    }, '✕'));
                }

                return h('td', {
                    class: 'timetable-cell',
                    'data-day': day.value,
                    'data-period-id': period.id,
                    'data-action': 'dragover->timetable#dragOver drop->timetable#drop',
                },
                h('button', {
                    type: 'button',
                    class: 'timetable-slot',
                    'aria-label': `Plot a class on ${slot}`,
                    'data-action': 'timetable#openDialog',
                    'data-timetable-day-param': day.value,
                    'data-timetable-period-id-param': period.id,
                }));
            }));
        });

        this.gridTarget.replaceChildren(legend, h('table', { class: 'table timetable-grid' }, head, h('tbody', {}, ...rows)));
    }

    paintAvailability(card) {
        this.gridTarget.querySelectorAll('.timetable-cell:not(.timetable-cell--filled)').forEach((cell) => {
            const busy = this.busyText(card.teacherId, cell.dataset.day, cell.dataset.periodId);
            cell.classList.add(busy ? 'is-busy' : 'is-free');
            if (busy) {
                cell.append(h('span', { class: 'timetable-busy' }, `${card.teacherName} teaches ${busy}`));
            }
        });
    }

    // The message of a clash refusal is its clashes joined, so they are listed instead of repeated
    showMessage(message, clashes = []) {
        const lines = clashes.length > 0
            ? [h('ul', {}, ...clashes.map((clash) => h('li', {}, clash)))]
            : [message];
        this.messageTarget.replaceChildren(...lines);
        this.messageTarget.hidden = false;
    }

    hideMessage() {
        this.messageTarget.hidden = true;
        this.messageTarget.replaceChildren();
    }

    // ---------- Rules (the server checks them again) ----------

    card(subjectId) {
        return this.state?.cards.find((card) => card.subjectId === subjectId);
    }

    cardBlockedReason(card) {
        if (card.teacherId === null) {
            return `Pick a teacher for ${card.name} first.`;
        }
        if (card.perWeek === null) {
            return `Set meetings per week for ${card.name} on the Subjects page first.`;
        }
        if (card.used >= card.perWeek) {
            return `${card.name} already has all ${card.perWeek} meetings this week.`;
        }

        return null;
    }

    busyReason(card, day, periodId) {
        const busy = this.busyText(card.teacherId, day, periodId);

        return busy ? `${card.teacherName} teaches ${busy} at this time.` : null;
    }

    busyText(teacherId, day, periodId) {
        return this.state.busy[teacherId]?.[`${day}-${periodId}`] ?? null;
    }

    countText(card) {
        return `${card.used}/${card.perWeek ?? '?'}`;
    }

    slotLabel(day, periodId) {
        const dayLabel = this.state.days.find((d) => d.value === Number(day))?.label;
        const periodLabel = this.state.periods.find((p) => p.id === Number(periodId))?.label;

        return `${dayLabel}, ${periodLabel}`;
    }
}

/* Builds an element; text children become text nodes, so names are never parsed as HTML. */
function h(tag, attributes = {}, ...children) {
    const element = document.createElement(tag);
    Object.entries(attributes).forEach(([name, value]) => {
        if (value !== false && value !== null && value !== undefined) {
            element.setAttribute(name, value === true ? '' : value);
        }
    });
    element.append(...children.filter((child) => child !== null && child !== false && child !== undefined && child !== ''));

    return element;
}
