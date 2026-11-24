<?php
require_once 'includes/inc_all.php';
$vendor_id = intval($_GET['vendor_id'] ?? 0);
$vendor = onystEnforceVendorAccess($vendor_id);
$client_id = intval($vendor['vendor_client_id']);
$can_edit = lookupUserPermission($client_id ? 'module_client' : 'module_financial') >= 2 && empty($vendor['vendor_archived_at']);
$contacts = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_vendor_id=$vendor_id AND contact_client_id=0 ORDER BY contact_archived_at IS NOT NULL,contact_primary DESC,contact_name");
?>
<div class="card">
    <div class="card-header bg-dark d-flex justify-content-between align-items-center">
        <h3 class="card-title m-0"><i class="fas fa-address-book me-2"></i><?= escapeHtml($vendor['vendor_name']) ?> — Contacts</h3>
        <?php if ($can_edit) { ?><button class="btn btn-primary ajax-modal" data-modal-url="modals/vendor/vendor_contact_edit.php?vendor_id=<?= $vendor_id ?>"><i class="fas fa-plus me-2"></i>New Contact</button><?php } ?>
    </div>
    <div class="card-body">
        <p>These contacts can open tickets by email and receive ticket replies. <a href="vendors.php<?= $client_id ? '?client_id='.$client_id : '' ?>">Back to Vendors</a></p>
        <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Name</th><th>Title</th><th>Email</th><th>Phone</th><th>Actions</th></tr></thead><tbody>
        <?php while ($contact = mysqli_fetch_assoc($contacts)) { $id = intval($contact['contact_id']); $archived = !empty($contact['contact_archived_at']); ?>
            <tr class="<?= $archived ? 'text-muted' : '' ?>">
                <td><?= escapeHtml($contact['contact_name']) ?> <?= $contact['contact_primary'] ? '<span class="badge bg-primary">Primary</span>' : '' ?> <?= $archived ? '<span class="badge bg-secondary">Archived</span>' : '' ?></td>
                <td><?= escapeHtml($contact['contact_title']) ?></td><td><?= escapeHtml($contact['contact_email']) ?></td><td><?= escapeHtml($contact['contact_phone']) ?></td>
                <td class="text-nowrap">
                    <?php if (!$archived && lookupUserPermission('module_support') >= 2) { ?><button class="btn btn-sm btn-light ajax-modal" data-modal-url="modals/ticket/ticket_add.php?client_id=<?= $client_id ?>&vendor_id=<?= $vendor_id ?>&contact_id=<?= $id ?>">New Ticket</button><?php } ?>
                    <?php if ($can_edit) { ?>
                        <?php if (!$archived) { ?><button class="btn btn-sm btn-light ajax-modal" data-modal-url="modals/vendor/vendor_contact_edit.php?vendor_id=<?= $vendor_id ?>&contact_id=<?= $id ?>">Edit</button><?php } ?>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="vendor_id" value="<?= $vendor_id ?>"><input type="hidden" name="contact_id" value="<?= $id ?>">
                            <button class="btn btn-sm btn-light" name="<?= $archived ? 'restore_vendor_contact' : 'archive_vendor_contact' ?>"><?= $archived ? 'Restore' : 'Archive' ?></button>
                        </form>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody></table></div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
