import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Reloads the turbo-frame it sits on every `interval` milliseconds.
export default class extends Controller {
    static values = { interval: { type: Number, default: 60000 } };

    connect() {
        this.missing = (event) => this.frameMissing(event);
        this.element.addEventListener('turbo:frame-missing', this.missing);
        this.timer = setInterval(() => {
            if (typeof this.element.reload === 'function') {
                this.element.reload();
            }
        }, this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
        this.element.removeEventListener('turbo:frame-missing', this.missing);
    }

    // The session expired and the reload got the login page: show it as a whole page
    frameMissing(event) {
        event.preventDefault();
        Turbo.visit(event.detail.response.url);
    }
}
