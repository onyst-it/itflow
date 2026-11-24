<?php
require_once '../../../includes/modal_header.php';
$vendor_id = intval($_GET['vendor_id'] ?? 0);
$vendor = onystEnforceVendorAccess($vendor_id, 2);
$contact_id = intval($_GET['contact_id'] ?? 0);
$contact = [];
if ($contact_id) {
    $contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_id=$contact_id AND contact_vendor_id=$vendor_id AND contact_client_id=0 AND contact_user_id=0 AND contact_archived_at IS NULL"));
    if (!$contact) { http_response_code(404); exit; }
}
ob_start();
?>
<div class="modal-header bg-dark"><h5 class="modal-title"><?= $contact_id ? 'Edit' : 'New' ?> Contact — <?= escapeHtml($vendor['vendor_name']) ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="vendor_id" value="<?= $vendor_id ?>"><input type="hidden" name="contact_id" value="<?= $contact_id ?>">
    <div class="modal-body">
        <?php foreach (['name'=>'Name','title'=>'Title','email'=>'Email','phone_country_code'=>'Phone country code','phone'=>'Phone','extension'=>'Extension','mobile'=>'Mobile'] as $field=>$label) { ?>
            <div class="mb-3"><label for="vendor_contact_<?= $field ?>" class="form-label"><?= $label ?></label><input class="form-control" id="vendor_contact_<?= $field ?>" name="<?= $field ?>" type="<?= $field === 'email' ? 'email' : 'text' ?>" maxlength="<?= $field === 'phone_country_code' ? 10 : 200 ?>" value="<?= escapeHtml($contact['contact_'.$field] ?? '') ?>" <?= $field === 'name' ? 'required' : '' ?>></div>
        <?php } ?>
        <div class="mb-3"><label for="vendor_contact_notes" class="form-label">Notes</label><textarea class="form-control" id="vendor_contact_notes" name="notes"><?= escapeHtml($contact['contact_notes'] ?? '') ?></textarea></div>
        <div class="form-check"><input class="form-check-input" id="vendor_contact_primary" type="checkbox" name="primary" value="1" <?= !empty($contact['contact_primary']) ? 'checked' : '' ?>><label class="form-check-label" for="vendor_contact_primary">Primary vendor contact</label></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary" type="submit" name="save_vendor_contact">Save Contact</button><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button></div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
