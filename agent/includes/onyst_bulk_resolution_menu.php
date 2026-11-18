<?php // ONYST_RESOLUTION_V3 ?>
<?php foreach (onystResolutionModes() as $mode=>$label) { ?>
    <button class="dropdown-item" type="submit" form="bulkActions" name="onyst_bulk_resolve" value="<?= $mode ?>"><i class="fas fa-fw <?= $mode==='silent' ? 'fa-check' : 'fa-envelope' ?> me-2"></i><?= $label ?></button>
<?php } ?>
