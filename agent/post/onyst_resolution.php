<?php
defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');
require_once __DIR__ . '/../../functions/onyst_ticket_resolution.php';
if (isset($_POST['onyst_resolve_ticket'])) {
    onystHandleResolution([intval($_POST['onyst_resolve_ticket'])],(string)($_POST['resolution_mode'] ?? 'silent'));
}
if (isset($_GET['resolve_ticket'])) {
    // Old/bookmarked Resolve URLs are always silent, regardless of extra query parameters.
    onystHandleResolution([intval($_GET['resolve_ticket'])],'silent');
}
if (isset($_POST['onyst_bulk_resolve']) || isset($_POST['bulk_resolve_tickets'])) {
    $mode = (string)($_POST['onyst_bulk_resolve'] ?? $_POST['resolution_mode'] ?? 'silent');
    onystHandleResolution((array)($_POST['ticket_ids'] ?? []),$mode);
}
