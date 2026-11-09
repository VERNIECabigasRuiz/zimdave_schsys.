import { describe, expect, it } from 'vitest';

// The Stimulus name each controller registers under, which the templates' data-controller values use
const EXPECTED = {
    '/src/Attendance/Presentation/assets/controllers/attendance_mark_controller.js': 'attendance-mark',
    '/src/Attendance/Presentation/assets/controllers/day_picker_controller.js': 'day-picker',
    '/src/Classes/Presentation/assets/controllers/section_form_controller.js': 'section-form',
    '/src/Classes/Presentation/assets/controllers/subject_form_controller.js': 'subject-form',
    '/src/Classes/Presentation/assets/controllers/timetable_controller.js': 'timetable',
    '/src/Core/Presentation/assets/controllers/clipboard_controller.js': 'clipboard',
    '/src/Core/Presentation/assets/controllers/image_preview_controller.js': 'image-preview',
    '/src/Dashboards/Presentation/assets/controllers/refresh_controller.js': 'refresh',
    '/src/Grading/Presentation/assets/controllers/comment_picker_controller.js': 'comment-picker',
    '/src/Schools/Presentation/assets/controllers/grading_periods_controller.js': 'grading-periods',
    '/src/Schools/Presentation/assets/controllers/region_division_controller.js': 'region-division',
    '/src/Staff/Presentation/assets/controllers/collection_controller.js': 'collection',
};

describe('module controller identifiers', () => {
    it('declaresTheStimulusNameTheTemplatesUse', () => {
        // Initialize
        const sources = import.meta.glob('/src/*/Presentation/assets/controllers/*_controller.js', {
            query: '?raw',
            import: 'default',
            eager: true,
        });

        // Execute
        const declared = Object.fromEntries(Object.entries(sources).map(([path, source]) => [
            path,
            source.match(/import\.meta\.stimulusIdentifier = '([a-z-]+)';/)?.[1] ?? null,
        ]));

        // Assert
        expect(declared).toEqual(EXPECTED);
    });
});
