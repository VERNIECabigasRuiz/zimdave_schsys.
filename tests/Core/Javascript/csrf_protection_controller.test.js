import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import controllerName, {
    generateCsrfHeaders,
} from '../../../src/Core/Presentation/assets/csrf_protection_controller.js';

const NAME = 'csrf-token';
const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

describe('csrf_protection_controller', () => {
    let writes;

    beforeEach(() => {
        writes = [];
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            get: () => '',
            set: (value) => {
                writes.push(value);
            },
        });
    });

    afterEach(() => {
        delete document.cookie;
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    const form = (field) => {
        document.body.innerHTML = `<form id="form">${field}</form>`;

        return document.getElementById('form');
    };

    const submit = (formElement) => formElement.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    const turbo = (type, formElement) => {
        const detail = { formSubmission: { formElement, fetchRequest: { headers: {} } } };
        document.dispatchEvent(new CustomEvent(type, { detail }));

        return detail.formSubmission.fetchRequest.headers;
    };

    it('turnsTheFieldsNameIntoACookieAndARandomTokenOnSubmit', () => {
        // Initialize
        const formElement = form(`<input type="hidden" data-controller="csrf-protection" value="${NAME}">`);
        const field = formElement.querySelector('input');
        const changes = vi.fn();
        field.addEventListener('change', changes);

        // Execute
        submit(formElement);

        // Assert
        expect(field.getAttribute('data-csrf-protection-cookie-value')).toBe(NAME);
        expect(field.value).toMatch(/^[-_/+a-zA-Z0-9]{24,}$/);
        expect(changes).toHaveBeenCalledOnce();
        expect(writes).toEqual([`${NAME}_${field.value}=${NAME}; path=/; samesite=strict`]);
    });

    it('reusesACookieNameAlreadyChosen', () => {
        // Initialize
        const formElement = form(
            `<input type="hidden" name="_csrf_token" data-csrf-protection-cookie-value="${NAME}" value="${TOKEN}">`,
        );

        // Execute
        submit(formElement);

        // Assert
        expect(formElement.querySelector('input').value).toBe(TOKEN);
        expect(writes).toEqual([`${NAME}_${TOKEN}=${NAME}; path=/; samesite=strict`]);
    });

    it('leavesAServerTokenAndFormsWithoutTheFieldAlone', () => {
        // Initialize
        const fields = [
            '<input type="hidden" name="_csrf_token" value="a.b.c">',
            `<input type="hidden" name="_csrf_token" data-csrf-protection-cookie-value="${NAME}" value="short">`,
            '<input type="text" name="title" value="x">',
        ];

        // Execute
        const values = fields.map((field) => {
            const formElement = form(field);
            submit(formElement);

            return formElement.querySelector('input').value;
        });

        // Assert
        expect(writes).toEqual([]);
        expect(values).toEqual(['a.b.c', 'short', 'x']);
    });

    it('usesTheOlderCryptoObjectWhenTheStandardOneIsMissing', () => {
        // Initialize
        const getRandomValues = vi.fn((bytes) => bytes.fill(65));
        vi.stubGlobal('crypto', undefined);
        vi.stubGlobal('msCrypto', { getRandomValues });
        const formElement = form(`<input type="hidden" data-controller="csrf-protection" value="${NAME}">`);

        // Execute
        submit(formElement);

        // Assert
        expect(getRandomValues).toHaveBeenCalledOnce();
        expect(formElement.querySelector('input').value).toBe(btoa('A'.repeat(18)));
    });

    it('sendsTheTokenInAHeaderForTurboSubmits', () => {
        // Initialize
        const formElement = form(
            `<input type="hidden" data-controller="csrf-protection" data-csrf-protection-cookie-value="${NAME}" value="${TOKEN}">`,
        );

        // Execute
        const headers = turbo('turbo:submit-start', formElement);

        // Assert
        expect(headers).toEqual({ [NAME]: TOKEN });
    });

    it('sendsNoHeaderWithoutAFieldOrAValidToken', () => {
        // Initialize
        const plain = form('<input type="text" name="title">');
        const invalid = form('<input type="hidden" name="_csrf_token" value="short">');

        // Execute
        const plainHeaders = turbo('turbo:submit-start', plain);
        const invalidHeaders = turbo('turbo:submit-start', invalid);
        const direct = generateCsrfHeaders(plain);

        // Assert
        expect(plainHeaders).toEqual({});
        expect(invalidHeaders).toEqual({});
        expect(direct).toEqual({});
    });

    it('expiresTheCookieWhenATurboSubmitEnds', () => {
        // Initialize
        const formElement = form(
            `<input type="hidden" data-controller="csrf-protection" data-csrf-protection-cookie-value="${NAME}" value="${TOKEN}">`,
        );

        // Execute
        turbo('turbo:submit-end', formElement);

        // Assert
        expect(writes).toEqual([`${NAME}_${TOKEN}=0; path=/; samesite=strict; max-age=0`]);
    });

    it('expiresNothingWithoutAFieldOrAValidToken', () => {
        // Initialize
        const plain = form('<input type="text" name="title">');
        const invalid = form('<input type="hidden" name="_csrf_token" value="short">');

        // Execute
        turbo('turbo:submit-end', plain);
        turbo('turbo:submit-end', invalid);

        // Assert
        expect(writes).toEqual([]);
    });

    it('exportsTheMarkerName', () => {
        // Execute
        const name = controllerName;

        // Assert
        expect(name).toBe('csrf-protection-controller');
    });
});
