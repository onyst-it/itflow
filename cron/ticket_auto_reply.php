<?php
chdir(__DIR__);

if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line.\n");
}

$cron_lock_script = __FILE__;
require_once 'includes/cron_lock.php';
require_once '../config.php';
require_once '../includes/inc_set_timezone.php';
require_once '../functions.php';
require_once '../functions/onyst_ticket_auto_reply.php';

$ack_settings = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_enable_cron, config_smtp_provider,
    config_ticket_from_email, config_ticket_from_name FROM settings WHERE company_id = 1'));
if (empty($ack_settings['config_enable_cron'])) {
    cronJobStop('Cron is disabled.');
}
$ack_from_email = trim((string) $ack_settings['config_ticket_from_email']);
if (empty($ack_settings['config_smtp_provider']) || !filter_var($ack_from_email, FILTER_VALIDATE_EMAIL)) {
    cronJobStop('Configure outgoing ticket mail before running ticket auto-replies.');
}

$ack_count = onystRunTicketAutoReplies($ack_from_email, (string) $ack_settings['config_ticket_from_name']);
if ($ack_count) {
    echo "Queued $ack_count automatic ticket acknowledgment(s).\n";
}
