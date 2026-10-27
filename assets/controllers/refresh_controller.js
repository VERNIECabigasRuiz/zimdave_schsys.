import { Controller } from '@hotwired/stimulus';

// Reloads the turbo-frame it sits on every `interval` milliseconds.
export default class extends Controller {
    static values = { interval: { type: Number, default: 60000 } };

    connect() {
        this.timer = setInterval(() => {
            if (typeof this.element.reload === 'function') {
                this.element.reload();
            }
        }, this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }
}
