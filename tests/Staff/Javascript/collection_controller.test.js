import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import CollectionController from '../../../src/Staff/Presentation/assets/controllers/collection_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

const PROTOTYPE = '<div data-collection-row><input name="pds[children][__name__][name]">'
    + '<button type="button" data-action="collection#remove">Remove</button></div>';

describe('collection_controller', () => {
    let application;
    let errors;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('collection', CollectionController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async ({ rows = '', index = 0, withEmpty = true } = {}) => {
        const prototype = PROTOTYPE.replaceAll('"', '&quot;');
        document.body.innerHTML = `
            <div data-controller="collection" data-collection-prototype-value="${prototype}"
                 data-collection-index-value="${index}">
                <div id="rows" data-collection-target="rows">${rows}</div>
                ${withEmpty ? '<p id="empty" data-collection-target="empty">No rows yet.</p>' : ''}
                <button type="button" id="add" data-action="collection#add">Add</button>
            </div>`;
        await tick();
    };

    const names = () => [...document.querySelectorAll('#rows input')].map((input) => input.name);

    it('addsARowWithTheNextIndexAndFocusesIt', async () => {
        // Initialize
        await render({ index: 2 });

        // Execute
        document.getElementById('add').click();
        document.getElementById('add').click();

        // Assert
        expect(names()).toEqual(['pds[children][2][name]', 'pds[children][3][name]']);
        expect(document.activeElement.name).toBe('pds[children][3][name]');
        expect(document.getElementById('empty').hidden).toBe(true);
    });

    it('removesARowAndShowsTheEmptyMessageWhenNoneAreLeft', async () => {
        // Initialize
        await render();
        document.getElementById('add').click();
        // Stimulus connects the new row's action after the DOM change is observed
        await tick();

        // Execute
        document.querySelector('#rows button').click();

        // Assert
        expect(names()).toEqual([]);
        expect(document.getElementById('empty').hidden).toBe(false);
    });

    it('ignoresAClickOutsideARowAndWorksWithoutAnEmptyMessage', async () => {
        // Initialize
        await render({ rows: '<span id="loose" data-action="click->collection#remove">x</span>', withEmpty: false });

        // Execute
        document.getElementById('loose').click();
        document.getElementById('add').click();

        // Assert
        expect(document.getElementById('loose')).not.toBeNull();
        expect(names()).toEqual(['pds[children][0][name]']);
    });

    it('addsRowsWithoutAnInputOrAnElementWithoutFailing', async () => {
        // Initialize
        await render();
        const controllerElement = document.querySelector('[data-controller="collection"]');

        // Execute
        controllerElement.setAttribute('data-collection-prototype-value', '<p class="note">Note</p>');
        await tick();
        document.getElementById('add').click();
        controllerElement.setAttribute('data-collection-prototype-value', 'Plain text');
        await tick();
        document.getElementById('rows').innerHTML = '';
        document.getElementById('add').click();

        // Assert
        expect(document.getElementById('rows').textContent).toBe('Plain text');
    });
});
