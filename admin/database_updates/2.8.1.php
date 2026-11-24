<?php
defined('FROM_DB_UPDATER') || die('Direct file access is not allowed');

if (!mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM ticket_attachments WHERE Field = 'ticket_attachment_private'"))) {
    mysqli_query($mysqli, 'ALTER TABLE ticket_attachments ADD COLUMN ticket_attachment_private TINYINT(1) NOT NULL DEFAULT 0');
}
