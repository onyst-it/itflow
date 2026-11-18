<?php // ONYST_RESOLUTION_V3 - server-side POST actions; JavaScript is only used for the Bootstrap menu. ?>
<div class="btn-group" role="group" aria-label="Resolve ticket">
    <form action="post.php" method="post" class="d-inline-flex">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="resolution_mode" value="silent">
        <button type="submit" name="onyst_resolve_ticket" value="<?= intval($ticket_id) ?>" class="btn btn-dark rounded-end-0" id="ticket_close" <?= $tasks_block_resolve ? 'disabled' : '' ?> title="<?= $tasks_block_resolve ? 'Complete the open tasks before resolving' : 'Resolve without sending an email' ?>"><i class="fas fa-fw fa-check me-2"></i>Silently Resolve</button>
    </form>
    <button type="button" class="btn btn-dark dropdown-toggle dropdown-toggle-split rounded-start-0" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Choose resolution response" <?= $tasks_block_resolve ? 'disabled' : '' ?>><span class="visually-hidden">Choose resolution response</span></button>
    <div class="dropdown-menu dropdown-menu-end">
        <?php foreach (['standard'=>'Resolve - Send Standard Resolution Note','no_response'=>'Resolve - Send Inactivity Explanation'] as $mode=>$label) { ?>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="resolution_mode" value="<?= $mode ?>">
                <button class="dropdown-item" type="submit" name="onyst_resolve_ticket" value="<?= intval($ticket_id) ?>"><i class="fas fa-fw fa-envelope me-2"></i><?= $label ?></button>
            </form>
        <?php } ?>
    </div>
</div>
