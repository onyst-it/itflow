<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/../../../functions/onyst_ticket_party.php';
require_once __DIR__ . '/../../../functions/onyst_ticket_sender.php';

enforceUserPermission('module_support', 2);

$ticket_id = (int) ($_GET['ticket_id'] ?? 0);
try {
    $ticket = onystTicketForPartyChange($ticket_id);
} catch (InvalidArgumentException $error) {
    http_response_code(404);
    exit(escapeHtml($error->getMessage()));
}

$ticket_reference = escapeHtml($ticket['ticket_prefix'] . $ticket['ticket_number']);
$client_id = (int) $ticket['ticket_client_id'];
$vendor_id = (int) $ticket['ticket_vendor_id'];
$contact_id = (int) $ticket['ticket_contact_id'];
$sender = onystTicketEmailSender($ticket);
$auto_contact = $sender['name'] !== '' || $sender['email'] !== '';
$can_create_client = onystCanCreateClientFromTicket();
$can_create_vendor = lookupUserPermission('module_financial') >= 2;
$party_field_name = 'ticket_party';

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-people-carry me-2"></i>Change Client / Vendor</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="changeTicketPartyForm" action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <input type="hidden" name="ticket_party_before" value="<?= "$client_id:$vendor_id:$contact_id" ?>">

    <div class="modal-body">
        <div class="small text-muted mb-3"><?= $ticket_reference ?></div>
        <?php if ($can_create_client || $can_create_vendor) { ?>
            <div class="mb-3">
                <label for="ticketPartyMode">Organization</label>
                <select class="form-select" id="ticketPartyMode" name="ticket_party_mode">
                    <option value="existing">Find existing</option>
                    <?php if ($can_create_client) { ?><option value="new_client">Create a client</option><?php } ?>
                    <?php if ($can_create_vendor) { ?><option value="new_vendor">Create a vendor</option><?php } ?>
                </select>
            </div>
        <?php } ?>

        <div class="mb-3" id="existingTicketParty">
            <label for="onystTicketParty">Client, Vendor, or Contact</label>
            <?php require __DIR__ . '/../../includes/onyst_ticket_party_picker.php'; ?>
            <div class="form-text">Selecting a contact fills in its client or vendor.</div>
        </div>

        <div class="mb-3" id="newTicketParty" hidden>
            <label for="newTicketPartyName" id="newTicketPartyLabel">Organization name</label>
            <input class="form-control" type="text" id="newTicketPartyName" name="ticket_party_name" maxlength="200" disabled>
        </div>

        <div id="autoTicketContact">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="matchTicketContact" name="ticket_match_contact"
                    value="1" <?= $auto_contact ? 'checked' : '' ?>>
                <label class="form-check-label" for="matchTicketContact">Match or create a contact</label>
            </div>
            <div class="row g-2" id="ticketContactFields" <?= $auto_contact ? '' : 'hidden' ?>>
                <div class="col-sm-6">
                    <label for="ticketPartyContactName">Contact name</label>
                    <input class="form-control" type="text" id="ticketPartyContactName" name="ticket_contact_name"
                        maxlength="200" value="<?= escapeHtml($sender['name']) ?>">
                </div>
                <div class="col-sm-6">
                    <label for="ticketPartyContactEmail">Contact email</label>
                    <input class="form-control" type="email" id="ticketPartyContactEmail" name="ticket_contact_email"
                        maxlength="200" value="<?= escapeHtml($sender['email']) ?>">
                </div>
                <div class="col-12 form-text">Uses a matching contact under the selected organization or creates one if needed.</div>
            </div>
        </div>
    </div>

    <div class="modal-footer">
        <button type="submit" id="saveTicketParty" name="change_ticket_party" class="btn btn-primary text-bold">
            <i class="fa fa-check me-2"></i>Save
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>

<script src="/agent/js/onyst_party_search.js?v=ticket-party-1"></script>
<script>
    (() => {
        const form = document.getElementById('changeTicketPartyForm');
        const mode = form.querySelector('#ticketPartyMode');
        const searchGroup = form.querySelector('#existingTicketParty');
        const picker = form.querySelector('#onystTicketParty');
        const newGroup = form.querySelector('#newTicketParty');
        const newName = form.querySelector('#newTicketPartyName');
        const newLabel = form.querySelector('#newTicketPartyLabel');
        const contactGroup = form.querySelector('#autoTicketContact');
        const contactChoice = form.querySelector('#matchTicketContact');
        const contactFields = form.querySelector('#ticketContactFields');
        const save = form.querySelector('#saveTicketParty');

        function render() {
            const existing = !mode || mode.value === 'existing';
            const contactChosen = existing && picker.value.startsWith('contact:');

            searchGroup.hidden = !existing;
            newGroup.hidden = existing;
            newName.disabled = existing;
            newName.required = !existing;
            newLabel.textContent = mode?.value === 'new_vendor' ? 'Vendor name' : 'Client name';
            contactGroup.hidden = contactChosen;
            contactChoice.disabled = contactChosen;
            contactFields.hidden = contactChosen || !contactChoice.checked;
            contactFields.querySelectorAll('input').forEach(input => {
                input.disabled = contactFields.hidden;
            });
            save.disabled = existing && !picker.value;
        }

        mode?.addEventListener('change', render);
        picker.addEventListener('change', render);
        contactChoice.addEventListener('change', render);
        render();
    })();
</script>

<?php require_once '../../../includes/modal_footer.php'; ?>
