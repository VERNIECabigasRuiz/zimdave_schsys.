import { Controller } from '@hotwired/stimulus';

/*
 * Add/remove rows of a Symfony CollectionType.
 *
 *   <div data-controller="collection"
 *        data-collection-prototype-value="{{ form_widget(form.children.vars.prototype)|e('html_attr') }}"
 *        data-collection-index-value="{{ form.children|length }}">
 *     <div data-collection-target="rows">…rows, each with a remove button
 *         data-action="collection#remove"…</div>
 *     <button type="button" data-action="collection#add">Add</button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['rows', 'empty'];
    static values = { prototype: String, index: Number };

    add() {
        const html = this.prototypeValue.replaceAll('__name__', String(this.indexValue));
        this.indexValue++;
        this.rowsTarget.insertAdjacentHTML('beforeend', html);
        this.rowsTarget.lastElementChild?.querySelector('input')?.focus();
        this.toggleEmpty();
    }

    remove(event) {
        event.target.closest('[data-collection-row]')?.remove();
        this.toggleEmpty();
    }

    toggleEmpty() {
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = this.rowsTarget.children.length > 0;
        }
    }
}
