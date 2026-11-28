// A real client/vendor/contact ID is selected from scoped server results.
(function () {
    document.querySelectorAll('select[data-onyst-party-search]').forEach(function (select) {
        if (select.tomselect) return;
        const form = select.closest('form');
        const context = select.dataset.onystPartySearch;
        let initial = [];
        try { initial = JSON.parse(select.dataset.initial || '[]'); } catch (_) { /* empty picker */ }
        const picker = initTomSelect(select, {
            valueField: 'value', labelField: 'label', searchField: 'label', options: initial,
            placeholder: context === 'ticket' ? 'Search clients, vendors, or contacts' : 'Search clients or vendors',
            preload: 'focus', loadThrottle: 250, maxOptions: 110,
            load: function (query, callback) {
                itflowGet('ajax.php', {onyst_party_search: 1, context: context, q: query}, function (data) {
                    try { callback(JSON.parse(data).parties || []); } catch (_) { callback(); }
                }, function () { callback(); });
            },
            onChange: function (value) {
                const selected = this.options[value] || {client_id: 0, vendor_id: 0, contact_id: 0};
                const client = form.querySelector('[name="client_id"]');
                if (context === 'ticket') {
                    const vendor = form.querySelector('#vendorSelect');
                    const contact = form.querySelector('#contactSelect');
                    if (vendor) vendor.dataset.selected = String(selected.vendor_id || 0);
                    if (contact) contact.dataset.selected = String(selected.contact_id || 0);
                    client.value = String(selected.client_id || 0);
                    client.dispatchEvent(new Event('change', {bubbles: true}));
                } else {
                    client.value = String(selected.vendor_id ? 0 : (selected.client_id || 0));
                    form.querySelector('[name="onyst_vendor_id"]').value = String(selected.vendor_id || 0);
                    const vendor = Boolean(selected.vendor_id);
                    const accessTab = form.querySelector('[href="#pills-access"]');
                    if (accessTab) accessTab.closest('li').hidden = vendor;
                    form.querySelectorAll('#pills-access input,#pills-access select,[name="location"],[name="contact_billing"],[name="contact_technical"]').forEach(function (input) {
                        input.disabled = vendor;
                        if (vendor && input.tagName === 'SELECT') { input.value = ''; refreshTomSelect(input); }
                    });
                }
            }
        });
        if (initial.length) picker.setValue(initial[0].value);
    });
})();
