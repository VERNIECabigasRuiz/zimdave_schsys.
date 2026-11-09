import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import RegionDivisionController from '../../../src/Schools/Presentation/assets/controllers/region_division_controller.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

const MAP = { 'Region VII': ['Cebu City', 'Mandaue City'], 'Region VI': ['Iloilo City'] };

describe('region_division_controller', () => {
    let application;
    let errors;

    beforeEach(() => {
        application = Application.start();
        // Stimulus only logs a controller's errors; collect them so a test fails on one
        errors = [];
        application.handleError = (error) => errors.push(error);
        application.register('region-division', RegionDivisionController);
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
        expect(errors).toEqual([]);
    });

    const render = async ({ region, divisionOptions, placeholder = true }) => {
        const map = JSON.stringify(MAP).replaceAll('"', '&quot;');
        document.body.innerHTML = `
            <div data-controller="region-division" data-region-division-map-value="${map}">
                <select id="region" data-region-division-target="region" data-action="region-division#change">
                    <option value="">Choose</option>
                    <option value="Region VII" ${region === 'Region VII' ? 'selected' : ''}>Region VII</option>
                    <option value="Region VI" ${region === 'Region VI' ? 'selected' : ''}>Region VI</option>
                    <option value="Region X" ${region === 'Region X' ? 'selected' : ''}>Region X</option>
                </select>
                <select id="division" data-region-division-target="division">
                    ${placeholder ? '<option value="">Choose a division</option>' : ''}
                    ${divisionOptions}
                </select>
            </div>`;
        await tick();
    };

    const options = () => [...document.getElementById('division').options].map((option) => option.value);
    const division = () => document.getElementById('division').value;

    it('listsTheRegionsDivisionsAndKeepsTheChosenOne', async () => {
        // Initialize
        const chosen = '<option value="Mandaue City" selected>Mandaue City</option>';

        // Execute
        await render({ region: 'Region VII', divisionOptions: chosen });

        // Assert
        expect(options()).toEqual(['', 'Cebu City', 'Mandaue City']);
        expect(division()).toBe('Mandaue City');
    });

    it('keepsAnOldValueWhileItsRegionStaysAndDropsItOnARegionChange', async () => {
        // Initialize
        await render({ region: 'Region VII', divisionOptions: '<option value="Old Division" selected>Old Division</option>' });
        const kept = [options(), division()];
        const region = document.getElementById('region');

        // Execute
        region.value = 'Region VI';
        region.dispatchEvent(new Event('change'));

        // Assert
        expect(kept).toEqual([['', 'Old Division', 'Cebu City', 'Mandaue City'], 'Old Division']);
        expect(options()).toEqual(['', 'Iloilo City']);
        expect(division()).toBe('');
    });

    it('keepsATypedValueForARegionWithoutDivisions', async () => {
        // Initialize
        const typed = '<option value="Typed Division" selected>Typed Division</option>';

        // Execute
        await render({ region: 'Region X', divisionOptions: typed });

        // Assert
        expect(options()).toEqual(['', 'Typed Division']);
        expect(division()).toBe('Typed Division');
    });

    it('offersOnlyThePlaceholderForAnUnknownRegionAndWorksWithoutOne', async () => {
        // Initialize
        await render({ region: 'Region X', divisionOptions: '', placeholder: false });

        // Execute
        const shown = options();

        // Assert
        expect(shown).toEqual(['']);
        expect(document.getElementById('division').options[0].text).toBe('');
    });
});
