<?php
defined('FROM_DB_UPDATER') || die('Direct file access is not allowed');

$auto_reply_columns = [
    'clients' => ['client_ticket_auto_reply' => 'TINYINT(1) NOT NULL DEFAULT 1'],
    'vendors' => ['vendor_ticket_auto_reply' => 'TINYINT(1) NOT NULL DEFAULT 1'],
    'tickets' => [
        'ticket_auto_reply_due_at' => 'DATETIME DEFAULT NULL',
        'ticket_auto_reply_email' => 'VARCHAR(320) DEFAULT NULL',
    ],
    'ticket_replies' => ['ticket_reply_auto_ack' => 'TINYINT(1) NOT NULL DEFAULT 0'],
];
foreach ($auto_reply_columns as $table => $columns) {
    foreach ($columns as $column => $definition) {
        if (!mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `$table` WHERE Field = '$column'"))) {
            mysqli_query($mysqli, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}
if (!mysqli_num_rows(mysqli_query($mysqli, "SHOW INDEX FROM tickets WHERE Key_name = 'ticket_auto_reply_due_at'"))) {
    mysqli_query($mysqli, 'ALTER TABLE tickets ADD INDEX ticket_auto_reply_due_at (ticket_auto_reply_due_at)');
}

// Carry over opt-outs already recorded as client tags in this database.
mysqli_query($mysqli, "UPDATE clients
    JOIN client_tags ON client_tags.client_id = clients.client_id
    JOIN tags ON tags.tag_id = client_tags.tag_id
    SET client_ticket_auto_reply = 0, client_updated_at = client_updated_at
    WHERE LOWER(TRIM(tag_name)) = 'no auto-responder'");

unset($auto_reply_columns, $table, $columns, $column, $definition);
