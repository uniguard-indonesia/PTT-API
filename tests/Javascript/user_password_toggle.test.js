const { test, describe, beforeEach } = require('node:test');
const assert = require('node:assert');

describe('User Modals Password Visibility Toggle Logic', () => {
    let mockInputs;
    let mockButtons;

    function setupMockDOM() {
        // Simulates input-group containing an input and button
        mockInputs = {
            password: { type: 'password', val: 'secret123' },
            new_password: { type: 'password', val: 'newsecret' },
            confirm_password: { type: 'password', val: 'newsecret' }
        };

        mockButtons = {
            password: {
                htmlContent: '<i class="fa fa-eye"></i>',
                ariaLabel: 'Show password',
                inputKey: 'password'
            },
            new_password: {
                htmlContent: '<i class="fa fa-eye"></i>',
                ariaLabel: 'Show password',
                inputKey: 'new_password'
            },
            confirm_password: {
                htmlContent: '<i class="fa fa-eye"></i>',
                ariaLabel: 'Show password',
                inputKey: 'confirm_password'
            }
        };
    }

    // Implementation of showPassword to test
    function showPassword(btnObj) {
        const inputObj = mockInputs[btnObj.inputKey];
        if (inputObj.type === 'password') {
            inputObj.type = 'text';
            btnObj.htmlContent = '<i class="fa fa-eye-slash"></i>';
            btnObj.ariaLabel = 'Hide password';
        } else {
            inputObj.type = 'password';
            btnObj.htmlContent = '<i class="fa fa-eye"></i>';
            btnObj.ariaLabel = 'Show password';
        }
    }

    // Implementation of resetPasswordVisibility to test
    function resetPasswordVisibility() {
        Object.keys(mockInputs).forEach(key => {
            mockInputs[key].type = 'password';
        });
        Object.keys(mockButtons).forEach(key => {
            mockButtons[key].htmlContent = '<i class="fa fa-eye"></i>';
            mockButtons[key].ariaLabel = 'Show password';
        });
    }

    beforeEach(() => {
        setupMockDOM();
    });

    test('showPassword toggles from masked to revealed with eye-slash and Hide password aria-label', () => {
        const btn = mockButtons.password;
        const input = mockInputs.password;

        assert.strictEqual(input.type, 'password');
        assert.strictEqual(btn.htmlContent, '<i class="fa fa-eye"></i>');
        assert.strictEqual(btn.ariaLabel, 'Show password');

        // User clicks toggle to show password
        showPassword(btn);

        assert.strictEqual(input.type, 'text');
        assert.strictEqual(btn.htmlContent, '<i class="fa fa-eye-slash"></i>');
        assert.strictEqual(btn.ariaLabel, 'Hide password');
    });

    test('showPassword toggles from revealed back to masked with eye and Show password aria-label', () => {
        const btn = mockButtons.new_password;
        const input = mockInputs.new_password;

        // First click: reveal
        showPassword(btn);
        assert.strictEqual(input.type, 'text');

        // Second click: mask
        showPassword(btn);
        assert.strictEqual(input.type, 'password');
        assert.strictEqual(btn.htmlContent, '<i class="fa fa-eye"></i>');
        assert.strictEqual(btn.ariaLabel, 'Show password');
    });

    test('resetPasswordVisibility resets all revealed inputs and buttons to default masked state', () => {
        // Reveal all fields
        showPassword(mockButtons.password);
        showPassword(mockButtons.new_password);
        showPassword(mockButtons.confirm_password);

        assert.strictEqual(mockInputs.password.type, 'text');
        assert.strictEqual(mockInputs.new_password.type, 'text');
        assert.strictEqual(mockInputs.confirm_password.type, 'text');

        // Modal closed or opened -> reset
        resetPasswordVisibility();

        assert.strictEqual(mockInputs.password.type, 'password');
        assert.strictEqual(mockInputs.new_password.type, 'password');
        assert.strictEqual(mockInputs.confirm_password.type, 'password');

        assert.strictEqual(mockButtons.password.htmlContent, '<i class="fa fa-eye"></i>');
        assert.strictEqual(mockButtons.new_password.htmlContent, '<i class="fa fa-eye"></i>');
        assert.strictEqual(mockButtons.confirm_password.htmlContent, '<i class="fa fa-eye"></i>');

        assert.strictEqual(mockButtons.password.ariaLabel, 'Show password');
        assert.strictEqual(mockButtons.new_password.ariaLabel, 'Show password');
        assert.strictEqual(mockButtons.confirm_password.ariaLabel, 'Show password');
    });
});
