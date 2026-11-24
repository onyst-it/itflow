<?php
$directory_views = [];
if (lookupUserPermission('module_client') >= 1) {
    $directory_views['clients'] = ['Clients', 'fa-user-friends', 'clients.php'];
    $directory_views['leads'] = ['Leads', 'fa-bullhorn', 'clients.php?leads=1'];
}
$directory_views['vendors'] = ['Vendors', 'fa-building', 'clients.php?view=vendors'];
?>
<div class="btn-group me-2" role="group" aria-label="Directory view">
    <?php foreach ($directory_views as $view => [$label, $icon, $href]) { ?>
        <a href="<?= $href ?>" class="btn btn-<?= $directory_view === $view ? 'primary' : 'default' ?>"
           title="<?= $label ?>" aria-label="<?= $label ?>" <?= $directory_view === $view ? 'aria-current="page"' : '' ?>>
            <i class="fa fa-fw <?= $icon ?>" aria-hidden="true"></i><span class="d-none d-sm-inline ms-2"><?= $label ?></span>
        </a>
    <?php } ?>
</div>
