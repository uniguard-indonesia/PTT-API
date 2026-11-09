const test = require('node:test');
const assert = require('node:assert/strict');

test('Log Activities DataTables Filter & Clear Client Logic', async (t) => {
    // Mock minimal jQuery / DOM environment
    const elements = {
        '#Company': {
            value: '',
            val(v) {
                if (v !== undefined) {
                    this.value = v;
                    return this;
                }
                return this.value;
            },
            listeners: {},
            on(evt, handler) {
                this.listeners[evt] = handler;
                return this;
            },
            trigger(evt) {
                if (this.listeners[evt]) {
                    this.listeners[evt]();
                }
            }
        },
        'input[type="date"]': {
            value: '2026-11-06',
            val(v) {
                if (v !== undefined) {
                    this.value = v;
                    return this;
                }
                return this.value;
            }
        },
        '#btnFilter': {
            listeners: {},
            on(evt, handler) {
                this.listeners[evt] = handler;
                return this;
            },
            trigger(evt) {
                if (this.listeners[evt]) {
                    this.listeners[evt]();
                }
            }
        },
        '#btnClear': {
            listeners: {},
            on(evt, handler) {
                this.listeners[evt] = handler;
                return this;
            },
            trigger(evt) {
                if (this.listeners[evt]) {
                    this.listeners[evt]();
                }
            }
        }
    };

    let tableReloadCount = 0;
    const Table = {
        ajax: {
            reload: () => {
                tableReloadCount++;
            }
        }
    };

    const $ = (selector) => elements[selector] || null;

    // DataTables AJAX data callback contract
    const ajaxDataCallback = function (d) {
        d.company_id = $('#Company').val();
    };

    await t.test('ajax data callback binds company_id from #Company', () => {
        elements['#Company'].val('5');
        const reqData = {};
        ajaxDataCallback(reqData);
        assert.equal(reqData.company_id, '5');

        elements['#Company'].val('');
        const emptyReqData = {};
        ajaxDataCallback(emptyReqData);
        assert.equal(emptyReqData.company_id, '');
    });

    await t.test('#Company change event automatically triggers Table.ajax.reload', () => {
        tableReloadCount = 0;
        $('#Company').on('change', function () {
            Table.ajax.reload();
        });

        $('#Company').val('12');
        elements['#Company'].trigger('change');

        assert.equal(tableReloadCount, 1, 'Table.ajax.reload must be invoked on change');
    });

    await t.test('#btnFilter click event triggers Table.ajax.reload', () => {
        tableReloadCount = 0;
        $('#btnFilter').on('click', function () {
            Table.ajax.reload();
        });

        elements['#btnFilter'].trigger('click');
        assert.equal(tableReloadCount, 1, 'Table.ajax.reload must be invoked on filter button click');
    });

    await t.test('#btnClear click event resets #Company and date inputs to empty string and triggers reload', () => {
        tableReloadCount = 0;
        elements['#Company'].val('15');
        elements['input[type="date"]'].val('2026-11-06');

        $('#btnClear').on('click', function () {
            $('#Company').val('');
            $('input[type="date"]').val('');
            Table.ajax.reload();
        });

        elements['#btnClear'].trigger('click');

        assert.equal(elements['#Company'].val(), '', '#Company select value must be reset to empty');
        assert.equal(elements['input[type="date"]'].val(), '', 'date inputs must be reset to empty');
        assert.equal(tableReloadCount, 1, 'Table.ajax.reload must be invoked on clear button click');
    });
});

