<?php
require_once dirname(__DIR__,3) . '/includes/modal_header.php';
require_once dirname(__DIR__,3) . '/functions/onyst_ticket_resolution.php';
$ticket_ids = array_values(array_unique(array_filter(array_map('intval',(array)($_GET['ticket_ids'] ?? [])))));
ob_start();
?>
<div class="modal-header bg-dark"><h5 class="modal-title">Resolve <?= count($ticket_ids) ?> Tickets</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form action="post.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
    <?php foreach ($ticket_ids as $id) { ?><input type="hidden" name="ticket_ids[]" value="<?= $id ?>"><?php } ?>
    <div class="modal-body"><label for="onyst_resolution_mode" class="form-label">Resolution action</label><select class="form-select" id="onyst_resolution_mode" name="resolution_mode"><?php foreach (onystResolutionModes() as $mode=>$label) { ?><option value="<?= $mode ?>"><?= $label ?></option><?php } ?></select></div>
    <div class="modal-footer"><button class="btn btn-primary" type="submit" name="bulk_resolve_tickets">Resolve Tickets</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button></div>
</form>
<?php require_once dirname(__DIR__,3) . '/includes/modal_footer.php'; ?>
