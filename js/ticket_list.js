document.addEventListener('click', event => {
    const link = event.target.closest('a[data-ticket-page]');
    if (!link) return;

    const section = link.closest('tbody[data-ticket-priority]');
    const url = new URL(window.location.href);
    url.searchParams.set('priority_page[' + section.dataset.ticketPriority + ']', link.dataset.ticketPage);

    const overflow = link.hasAttribute('data-ticket-more') && section.querySelector('template[data-ticket-overflow]');
    if (!overflow || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        url.hash = section.id;
        link.href = url.href;
        return;
    }

    event.preventDefault();
    window.history.replaceState(window.history.state, '', url);

    const selectAll = document.getElementById('selectAllCheckbox');
    if (selectAll?.checked && overflow.content.querySelector('.bulk-select')) {
        selectAll.checked = false;
        selectAll.indeterminate = true;
    }

    const firstTicket = overflow.content.querySelector('a[href^="ticket.php"]');
    overflow.replaceWith(overflow.content);

    const pagination = section.querySelector('[data-ticket-page-links]');
    if (pagination) {
        pagination.hidden = false;
        link.remove();
    } else {
        link.closest('tr').remove();
    }
    firstTicket?.focus();
});
