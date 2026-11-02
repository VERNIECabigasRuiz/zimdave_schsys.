import { Controller } from '@hotwired/stimulus';

/*
 * Narrows the Division dropdown to the divisions of the chosen Region.
 * The server still checks the pair; without this the full list is shown.
 */
export default class extends Controller {
    static targets = ['region', 'division'];
    static values = { map: Object };

    connect() {
        this.placeholder = this.divisionTarget.querySelector('option[value=""]')?.text ?? '';
        // Keeps a value typed before the dropdowns existed while its region is unchanged
        this.oldOption = [...this.divisionTarget.options].find(
            (option) => option.selected && option.value !== '' && !this.belongs(option.value),
        );
        this.render(this.divisionTarget.value);
    }

    change() {
        this.render('');
    }

    belongs(division) {
        return (this.mapValue[this.regionTarget.value] ?? []).includes(division);
    }

    render(selected) {
        const divisions = this.mapValue[this.regionTarget.value] ?? [];
        const options = [new Option(this.placeholder, '')];
        if (selected !== '' && this.oldOption && selected === this.oldOption.value) {
            options.push(new Option(this.oldOption.text, this.oldOption.value));
        }
        divisions.forEach((division) => options.push(new Option(division, division)));

        this.divisionTarget.replaceChildren(...options);
        this.divisionTarget.value = selected;
    }
}
