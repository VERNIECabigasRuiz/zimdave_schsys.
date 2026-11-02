import { Controller } from '@hotwired/stimulus';

/*
 * Opens the roll call of the date picked: /teacher/class/attendance/2025-08-22.
 *
 *   <div data-controller="day-picker" data-day-picker-url-value="/teacher/class/attendance">
 *     <input type="date" data-action="change->day-picker#go">
 *   </div>
 */
export default class extends Controller {
    static values = { url: String };

    go(event) {
        if (event.target.value) {
            window.location.assign(`${this.urlValue}/${event.target.value}`);
        }
    }
}
