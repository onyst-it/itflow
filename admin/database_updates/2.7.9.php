<?php
/** Activate the native vendor-contact relationship without modifying client contacts. */
defined('FROM_DB_UPDATER') || die('Direct file access is not allowed');
$column = mysqli_query($mysqli, "SHOW COLUMNS FROM contacts LIKE 'contact_vendor_id'");
if (!mysqli_num_rows($column)) {
    if (!mysqli_query($mysqli, 'ALTER TABLE contacts ADD contact_vendor_id INT NOT NULL DEFAULT 0')) throw new RuntimeException(mysqli_error($mysqli));
}
foreach (['contacts'=>['contact_vendor_active_email'=>'(contact_vendor_id,contact_archived_at,contact_email)'],
    'tickets'=>['ticket_vendor_active'=>'(ticket_vendor_id,ticket_resolved_at,ticket_created_at)']] as $table=>$indexes) {
    foreach ($indexes as $name=>$columns) {
        $exists = mysqli_query($mysqli, "SHOW INDEX FROM `$table` WHERE Key_name='$name'");
        if (!mysqli_num_rows($exists) && !mysqli_query($mysqli, "ALTER TABLE `$table` ADD INDEX `$name` $columns")) throw new RuntimeException(mysqli_error($mysqli));
    }
}
// Preserve each existing vendor's published email as a real contact. Re-runs are safe.
$vendors = mysqli_query($mysqli, 'SELECT * FROM vendors WHERE vendor_archived_at IS NULL AND vendor_email IS NOT NULL AND vendor_email<>\'\'');
while ($vendor = mysqli_fetch_assoc($vendors)) {
    $id = intval($vendor['vendor_id']);
    $email = trim($vendor['vendor_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $email = mysqli_real_escape_string($mysqli, $email);
    $name = mysqli_real_escape_string($mysqli, trim($vendor['vendor_contact_name'] ?: $vendor['vendor_name']));
    $phone = mysqli_real_escape_string($mysqli, (string)$vendor['vendor_phone']);
    $country = mysqli_real_escape_string($mysqli, (string)$vendor['vendor_phone_country_code']);
    $extension = mysqli_real_escape_string($mysqli, (string)$vendor['vendor_extension']);
    $hasPrimary = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM contacts WHERE contact_vendor_id=$id AND contact_primary=1 AND contact_archived_at IS NULL"))[0]);
    $primary = $hasPrimary ? 0 : 1;
    $existing = mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_vendor_id=$id AND contact_email='$email' AND contact_archived_at IS NULL LIMIT 1");
    if (!mysqli_num_rows($existing) && !mysqli_query($mysqli, "INSERT INTO contacts SET contact_name='$name',contact_email='$email',contact_phone='$phone',contact_phone_country_code='$country',contact_extension='$extension',contact_primary=$primary,contact_vendor_id=$id,contact_client_id=0,contact_user_id=0")) throw new RuntimeException(mysqli_error($mysqli));
}
unset($column,$table,$indexes,$name,$columns,$exists,$vendors,$vendor,$id,$email,$phone,$country,$extension,$hasPrimary,$primary);
