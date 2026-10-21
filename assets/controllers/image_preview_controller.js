import { Controller } from '@hotwired/stimulus';

const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 5 * 1024 * 1024;

/*
 * Shows the chosen image right away, with a reminder that it is not saved yet.
 *
 *   <form data-controller="image-preview">
 *     <input type="file" data-action="image-preview#show">
 *     <img data-image-preview-target="image" hidden>
 *     <p data-image-preview-target="hint" hidden>Click "Save" to upload it.</p>
 *   </form>
 */
export default class extends Controller {
    static targets = ['image', 'hint'];

    connect() {
        this.reminder = this.hintTarget.textContent;
    }

    disconnect() {
        this.revoke();
    }

    show(event) {
        const file = event.target.files[0];
        this.revoke();

        if (!file) {
            this.imageTarget.hidden = true;
            this.hintTarget.hidden = true;
            return;
        }

        const problem = !TYPES.includes(file.type)
            ? 'This file is not a JPG, PNG or WebP image.'
            : file.size > MAX_BYTES ? 'This image is bigger than 5 MB.' : null;

        this.hintTarget.textContent = problem ?? this.reminder;
        this.hintTarget.classList.toggle('preview-hint--error', problem !== null);
        this.hintTarget.hidden = false;

        if (problem) {
            this.imageTarget.hidden = true;
            return;
        }
        this.url = URL.createObjectURL(file);
        this.imageTarget.src = this.url;
        this.imageTarget.hidden = false;
    }

    revoke() {
        if (this.url) {
            URL.revokeObjectURL(this.url);
            this.url = null;
        }
    }
}
