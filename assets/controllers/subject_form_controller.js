import { Controller } from '@hotwired/stimulus';

/*
 * Shows the semester, track and strand fields only for Grade 11 and 12 subjects
 * that are not components, and lists only the chosen grade's subjects under
 * "Component of". The server still checks both rules.
 */
export default class extends Controller {
    static targets = ['grade', 'parent', 'shs'];

    connect() {
        this.toggle();
    }

    toggle() {
        const grade = this.gradeTarget.value;
        [...this.parentTarget.options].forEach((option) => {
            if (option.value === '') {
                return;
            }
            option.hidden = option.dataset.grade !== grade;
            if (option.hidden && option.selected) {
                this.parentTarget.value = '';
            }
        });

        const showShs = Number(grade) >= 11 && this.parentTarget.value === '';
        this.shsTargets.forEach((row) => {
            row.hidden = !showShs;
        });
    }
}
