/**
 * @vitest-environment-options {"url": "https://localhost/"}
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import '../../../src/Core/Presentation/assets/csrf_protection_controller.js';

const NAME = 'csrf-token';
const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

describe('csrf_protection_controller over https', () => {
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
        document.body.innerHTML = '';
    });

    const form = () => {
        document.body.innerHTML = `<form id="form"><input type="hidden" data-controller="csrf-protection"
            data-csrf-protection-cookie-value="${NAME}" value="${TOKEN}"></form>`;

        return document.getElementById('form');
    };

    it('setsAndExpiresHostOnlySecureCookies', () => {
        // Initialize
        const formElement = form();

        // Execute
        formElement.dispatchEvent(new Event('submit', { bubbles: true }));
        document.dispatchEvent(new CustomEvent('turbo:submit-end', {
            detail: { formSubmission: { formElement, fetchRequest: { headers: {} } } },
        }));

        // Assert
        expect(writes).toEqual([
            `__Host-${NAME}_${TOKEN}=${NAME}; path=/; samesite=strict; secure`,
            `__Host-${NAME}_${TOKEN}=0; path=/; samesite=strict; max-age=0; secure`,
        ]);
    });
});
