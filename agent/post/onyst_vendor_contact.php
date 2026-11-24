<?php
defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');
if (isset($_POST['save_vendor_contact']) || isset($_POST['archive_vendor_contact']) || isset($_POST['restore_vendor_contact'])) {
    validateCSRFToken();
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $vendor = onystEnforceVendorAccess($vendor_id, 2);
    $contact_id = intval($_POST['contact_id'] ?? 0);
    try {
        if (isset($_POST['save_vendor_contact'])) {
            onystSaveVendorContact($vendor_id, $contact_id, $_POST);
            flashAlert('Vendor contact saved.');
        } else {
            if (!$contact_id) throw new InvalidArgumentException('Choose a vendor contact.');
            $archived = isset($_POST['archive_vendor_contact']) ? 'NOW()' : 'NULL';
            mysqli_query($mysqli, "UPDATE contacts SET contact_archived_at=$archived,contact_primary=0 WHERE contact_id=$contact_id AND contact_vendor_id=$vendor_id AND contact_client_id=0 AND contact_user_id=0");
            logAudit('Vendor Contact', $archived === 'NULL' ? 'Restore' : 'Archive', 'Changed vendor contact archive state', intval($vendor['vendor_client_id']), $contact_id);
            flashAlert('Vendor contact updated.');
        }
    } catch (Throwable $error) { flashAlert(escapeHtml($error->getMessage()), 'error'); }
    redirect('vendor_contacts.php?vendor_id=' . $vendor_id);
}
