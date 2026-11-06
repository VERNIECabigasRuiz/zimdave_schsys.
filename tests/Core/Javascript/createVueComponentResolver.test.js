import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Greeting from './Fixture/Greeting.vue';
import { createVueComponentResolver } from '../../../src/Core/Presentation/assets/vue/createVueComponentResolver.js';

const RollCallGrid = { name: 'RollCallGrid' };
const GradeSheet = { name: 'GradeSheet' };

const modules = {
    '../src/Attendance/Presentation/assets/vue/RollCallGrid.vue': { default: RollCallGrid },
    '../src/Grading/Presentation/assets/vue/sheet/GradeSheet.vue': { default: GradeSheet },
};

describe('createVueComponentResolver', () => {
    it('resolvesByModuleAndPath', () => {
        // Initialize
        const resolve = createVueComponentResolver(modules);

        // Execute
        const grid = resolve('Attendance/RollCallGrid');
        const sheet = resolve('Grading/sheet/GradeSheet');

        // Assert
        expect(grid).toBe(RollCallGrid);
        expect(sheet).toBe(GradeSheet);
    });

    it('throwsForUnknownNameListingKnownOnes', () => {
        // Initialize
        const resolve = createVueComponentResolver(modules);

        // Execute
        const resolveUnknown = () => resolve('Nope');

        // Assert
        expect(resolveUnknown).toThrow(
            new Error('Vue component "Nope" not found. Known: Attendance/RollCallGrid, Grading/sheet/GradeSheet'),
        );
    });

    it('mountsAnOptionsApiComponent', () => {
        // Initialize
        const resolve = createVueComponentResolver({
            '../src/Core/Presentation/assets/vue/Greeting.vue': { default: Greeting },
        });

        // Execute
        const wrapper = mount(resolve('Core/Greeting'), { props: { name: 'Ana' } });

        // Assert
        expect(wrapper.text()).toBe('Hello Ana');
    });
});
