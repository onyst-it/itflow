<?php
$party_initial = [];
$party_label = '';
$party_value = '';
if ($contact_id) {
    $party_label = (string)getFieldById('contacts', $contact_id, 'contact_name');
    $party_value = 'contact:' . $contact_id;
} elseif ($vendor_id) {
    $party_label = (string)getFieldById('vendors', $vendor_id, 'vendor_name') . ' — Vendor';
    $party_value = 'vendor:' . $vendor_id;
} elseif ($client_id) {
    $party_label = (string)getFieldById('clients', $client_id, 'client_name') . ' — Client';
    $party_value = 'client:' . $client_id;
}
if ($party_value) $party_initial[] = ['value'=>$party_value,'label'=>$party_label,'client_id'=>$client_id,'vendor_id'=>$vendor_id,'contact_id'=>$contact_id];
?>
<input type="hidden" name="client_id" id="changeClientSelect" value="<?= $client_id ?>">
<select class="form-select" id="onystTicketParty" aria-label="Client, Vendor, or Contact" data-onyst-party-search="ticket" data-initial="<?= escapeHtml(json_encode($party_initial)) ?>">
    <option value="">- Search Clients, Vendors, or Contacts -</option>
    <?php if ($party_value) { ?><option selected value="<?= escapeHtml($party_value) ?>"><?= escapeHtml($party_label) ?></option><?php } ?>
</select>
