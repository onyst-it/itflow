(function () {
    const form = document.querySelector('form[data-vendor-ticket-client]');
    if (!form) return;
    const vendor = form.querySelector('[name="vendor_id"]');
    const contact = form.querySelector('[name="contact_id"]');
    if (!vendor || !contact) return;
    let request = 0;
    vendor.addEventListener('change', function () {
        const current = ++request;
        const selected = contact.value;
        itflowGet('ajax.php', {get_client_contacts: 1, client_id: form.dataset.vendorTicketClient, vendor_id: vendor.value}, function (data) {
            if (current !== request) return;
            const rows = JSON.parse(data).contacts || [];
            contact.innerHTML = '';
            contact.appendChild(new Option('- No One -', '0'));
            rows.forEach(row => contact.appendChild(new Option(row.contact_name + (row.contact_owner_name ? ' — ' + row.contact_owner_name : ''), row.contact_id)));
            contact.value = rows.some(row => String(row.contact_id) === selected) ? selected : '0';
            refreshTomSelect(contact);
        });
    });
})();
