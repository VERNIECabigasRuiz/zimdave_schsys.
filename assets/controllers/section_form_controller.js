import { Controller } from '@hotwired/stimulus';

/*
 * Shows the Track and Strand fields only for Grade 11 and 12 sections.
 * The server still checks the rule; this only keeps the form short.
 */
export default class extends Controller {
    static targets = ['grade', 'shs'];

    connect() {
        this.toggle();
    }

    toggle() {
        const isSeniorHigh = Number(this.gradeTarget.value) >= 11;
        this.shsTargets.forEach((row) => {
            row.hidden = !isSeniorHigh;
        });
    }
}
