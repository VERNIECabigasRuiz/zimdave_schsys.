import { Controller } from '@hotwired/stimulus';

/*
 * Adds a "Copy" button next to an element and copies textValue when clicked.
 *
 *   <code data-controller="clipboard" data-clipboard-text-value="secret">secret</code>
 */
export default class extends Controller {
    static values = { text: String };

    connect() {
        if (!navigator.clipboard) {
            return;
        }

        this.button = document.createElement('button');
        this.button.type = 'button';
        this.button.className = 'copy-btn';
        this.button.textContent = 'Copy';
        this.button.addEventListener('click', () => this.copy());
        this.element.after(this.button);
    }

    disconnect() {
        this.button?.remove();
    }

    async copy() {
        await navigator.clipboard.writeText(this.textValue);
        this.button.textContent = 'Copied';
        setTimeout(() => (this.button.textContent = 'Copy'), 1500);
    }
}
