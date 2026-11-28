<form action="post.php" method="post">
    <div class="d-flex align-items-center">
        <select onchange="this.form.submit()" class="form-select form-select-sm w-auto" name="change_records_per_page" aria-label="Records per page">
            <?php foreach ([5, 10, 20, 50, 100, 500] as $page_size) { ?>
                <option <?= $user_config_records_per_page == $page_size ? 'selected' : '' ?>><?= $page_size ?></option>
            <?php } ?>
        </select>
        <span class="text-muted small ms-2 d-none d-sm-inline">per page</span>
    </div>
</form>
