import { Controller } from '@hotwired/stimulus';

/*
 * Keeps a card slip comment dropdown's summary line in step with its ticked boxes and typed comment.
 *
 *   <details data-controller="comment-picker" data-action="change->comment-picker#update input->comment-picker#update">
 *     <summary><strong data-comment-picker-target="count"></strong> <span data-comment-picker-target="chosen"></span></summary>
 *     … checkboxes with their <label for>, a textarea …
 *   </details>
 */
export default class extends Controller {
    static targets = ['count', 'chosen'];

    update() {
        const labels = [...this.element.querySelectorAll('input[type=checkbox]:checked')]
            .map((box) => this.element.querySelector(`label[for="${box.id}"]`)?.textContent.trim())
            .filter(Boolean);
        const typed = this.element.querySelector('textarea')?.value.trim() !== '';

        this.countTarget.textContent = `${labels.length} ${labels.length === 1 ? 'comment' : 'comments'}${typed ? ' + typed' : ''}:`;
        this.chosenTarget.textContent = labels.join(' · ') || 'none';
    }
}
