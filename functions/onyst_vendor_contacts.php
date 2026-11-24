<?php
/** Vendor contacts share native contact IDs, so ticket mail/replies need no parallel address book. */
function onystVendor(int $id): ?array {
    global $mysqli;
    $result = mysqli_query($mysqli, "SELECT * FROM vendors WHERE vendor_id=$id");
    return mysqli_fetch_assoc($result) ?: null;
}

function onystEnforceVendorAccess(int $id, int $level = 1): array {
    $vendor = onystVendor($id);
    if (!$vendor) { http_response_code(404); exit('Vendor not found.'); }
    $client = intval($vendor['vendor_client_id']);
    enforceUserPermission($client ? 'module_client' : 'module_financial', $level);
    if ($client) enforceClientAccess($client);
    return $vendor;
}

/** A global vendor may be used for any client; a client's vendor stays in that client. */
function onystVendorFitsTicket(array $vendor, int $client): bool {
    return empty($vendor['vendor_archived_at'])
        && (intval($vendor['vendor_client_id']) === 0 || intval($vendor['vendor_client_id']) === $client);
}

function onystContactFitsTicket(array $contact, int $client, int $vendor): bool {
    if (!empty($contact['contact_archived_at'])) return false;
    $owner = intval($contact['contact_vendor_id']);
    if ($owner) return $vendor > 0 && $owner === $vendor;
    return $client > 0 && intval($contact['contact_client_id']) === $client;
}

/** Reject forged or stale selections before changing a ticket or queueing its mail. */
function onystValidateTicketContact(int $client, int $vendor, int $contact): void {
    global $mysqli;
    if ($vendor) {
        $row = onystVendor($vendor);
        if (!$row || !onystVendorFitsTicket($row, $client)) {
            throw new InvalidArgumentException('Choose an active vendor available to this client.');
        }
    }
    if (!$contact) return;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_id=$contact"));
    if (!$row || !onystContactFitsTicket($row, $client, $vendor)) {
        throw new InvalidArgumentException('Choose an active contact belonging to this client or the selected vendor.');
    }
}

function onystRequireTicketContact(int $client, int $vendor, int $contact): void {
    try { onystValidateTicketContact($client, $vendor, $contact); }
    catch (InvalidArgumentException $error) { flashAlert(escapeHtml($error->getMessage()), 'error'); redirect(); }
}

function onystTicketContactScope(int $client, int $vendor): string {
    $parts = [];
    if ($client > 0) $parts[] = "(contact_vendor_id=0 AND contact_client_id=$client)";
    if ($vendor > 0) $parts[] = "(contact_vendor_id=$vendor AND contact_client_id=0)";
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '0=1';
}

/** Never guess between different clients/vendors sharing the same sender address. */
function onystChooseInboundContact(array $rows): array {
    $owners = [];
    foreach ($rows as $row) {
        if (!empty($row['contact_archived_at'])) continue;
        $vendor = intval($row['contact_vendor_id']);
        $client = intval($row['contact_client_id']);
        if ($vendor) {
            if (empty($row['vendor_id']) || !empty($row['vendor_archived_at'])) continue;
            $client = intval($row['vendor_client_id']);
        } elseif (!$client || !empty($row['client_archived_at'])) {
            continue;
        }
        $key = $vendor ? 'vendor:' . $vendor : 'client:' . $client;
        $candidate = $row;
        $candidate['owner_client_id'] = $client;
        $candidate['owner_vendor_id'] = $vendor;
        if (!isset($owners[$key]) || intval($row['contact_primary']) > intval($owners[$key]['contact_primary'])) $owners[$key] = $candidate;
    }
    if (count($owners) > 1) return ['state'=>'ambiguous', 'contact'=>null];
    return $owners ? ['state'=>'matched', 'contact'=>reset($owners)] : ['state'=>'none', 'contact'=>null];
}

function onystInboundContact(string $email, ?int $client = null, ?int $vendor = null, int $primaryContact = 0): array {
    global $mysqli;
    $email = mysqli_real_escape_string($mysqli, trim($email));
    $scope = $client === null ? '' : ' AND ' . onystTicketContactScope($client, $vendor ?? 0);
    $query = mysqli_query($mysqli, "SELECT c.*,v.vendor_id,v.vendor_client_id,v.vendor_archived_at,cl.client_archived_at
        FROM contacts c LEFT JOIN vendors v ON v.vendor_id=c.contact_vendor_id
        LEFT JOIN clients cl ON cl.client_id=c.contact_client_id
        WHERE c.contact_email='$email' AND c.contact_archived_at IS NULL $scope
        ORDER BY c.contact_primary DESC,c.contact_id");
    $rows = mysqli_fetch_all($query, MYSQLI_ASSOC);
    if ($client !== null && $primaryContact) {
        foreach ($rows as $row) {
            if (intval($row['contact_id']) === $primaryContact) {
                $primary = onystChooseInboundContact([$row]);
                if ($primary['state'] === 'matched') return $primary;
            }
        }
    }
    return onystChooseInboundContact($rows);
}

/** Contacts never receive a portal account or a client identity as a side effect. */
function onystSaveVendorContact(int $vendor, int $id, array $input): int {
    global $mysqli;
    $name = trim((string)($input['name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    if ($name === '' || mb_strlen($name) > 200 || strlen($email) > 200 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Enter a name and a valid email address (or leave email blank).');
    }
    $owner = onystVendor($vendor);
    if (!$owner || !empty($owner['vendor_archived_at'])) throw new InvalidArgumentException('This vendor is not active.');
    $fields = ['contact_name'=>$name,'contact_email'=>$email,'contact_title'=>trim((string)($input['title'] ?? '')),
        'contact_phone_country_code'=>preg_replace('/\D/', '', (string)($input['phone_country_code'] ?? '')),
        'contact_phone'=>preg_replace('/\D/', '', (string)($input['phone'] ?? '')),
        'contact_extension'=>preg_replace('/\D/', '', (string)($input['extension'] ?? '')),
        'contact_mobile'=>preg_replace('/\D/', '', (string)($input['mobile'] ?? '')),
        'contact_mobile_country_code'=>preg_replace('/\D/', '', (string)($input['mobile_country_code'] ?? '')),
        'contact_department'=>trim((string)($input['department'] ?? '')),
        'contact_notes'=>trim((string)($input['notes'] ?? ''))];
    foreach ($fields as $field=>$value) {
        if ($field !== 'contact_notes' && mb_strlen($value) > (str_ends_with($field, 'country_code') ? 10 : 200)) throw new InvalidArgumentException('A contact field is too long.');
    }
    mysqli_begin_transaction($mysqli);
    try {
        // Serialize changes to the primary flag for this vendor.
        mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_id=$vendor FOR UPDATE");
        if ($id) {
            $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_id=$id AND contact_vendor_id=$vendor AND contact_client_id=0 AND contact_user_id=0 AND contact_archived_at IS NULL FOR UPDATE"));
            if (!$existing) throw new InvalidArgumentException('The contact is not an active contact of this vendor.');
        }
        $primary = empty($input['primary']) ? 0 : 1;
        if ($primary) mysqli_query($mysqli, "UPDATE contacts SET contact_primary=0 WHERE contact_vendor_id=$vendor AND contact_client_id=0");
        $sets = ["contact_vendor_id=$vendor", 'contact_client_id=0', 'contact_user_id=0', "contact_primary=$primary"];
        foreach ($fields as $field=>$value) $sets[] = "$field='" . mysqli_real_escape_string($mysqli, $value) . "'";
        $sql = ($id ? 'UPDATE' : 'INSERT INTO') . ' contacts SET ' . implode(',', $sets) . ($id ? " WHERE contact_id=$id AND contact_vendor_id=$vendor" : '');
        if (!mysqli_query($mysqli, $sql)) throw new RuntimeException('Could not save the contact.');
        if (!$id) $id = mysqli_insert_id($mysqli);
        logAudit('Vendor Contact', 'Save', 'Saved vendor contact ' . escapeSql($name), intval($owner['vendor_client_id']), $id);
        mysqli_commit($mysqli);
        return $id;
    } catch (Throwable $error) { mysqli_rollback($mysqli); throw $error; }
}

function onystEnsureVendorContact(int $vendor): void {
    global $mysqli;
    $row = onystVendor($vendor);
    if (!$row || !empty($row['vendor_archived_at']) || !filter_var(trim((string)$row['vendor_email']), FILTER_VALIDATE_EMAIL)) return;
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_vendor_id=$vendor AND contact_archived_at IS NULL LIMIT 1"))) return;
    onystSaveVendorContact($vendor, 0, ['name'=>$row['vendor_contact_name'] ?: $row['vendor_name'],'email'=>$row['vendor_email'],
        'phone_country_code'=>$row['vendor_phone_country_code'],'phone'=>$row['vendor_phone'],'extension'=>$row['vendor_extension'],'primary'=>1]);
}

/** Search only records available in this context; typed labels never become ownership IDs. */
function onystPartySearch(string $search, string $context): array {
    global $mysqli;
    $ticket = $context === 'ticket';
    if ($ticket) enforceUserPermission('module_support');
    $clientPermission = lookupUserPermission('module_client') >= ($ticket ? 1 : 2);
    $financialPermission = lookupUserPermission('module_financial') >= ($ticket ? 1 : 2);
    $q = mysqli_real_escape_string($mysqli, str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], mb_substr(trim($search),0,100)));
    $out = [];
    if ($ticket || $clientPermission) {
        $scope = clientScopeSql('client_id');
        $query = mysqli_query($mysqli, "SELECT client_id,client_name FROM clients WHERE client_archived_at IS NULL AND client_name LIKE '%$q%' $scope ORDER BY client_name LIMIT 30");
        while ($row = mysqli_fetch_assoc($query)) $out[] = ['value'=>'client:'.$row['client_id'],'label'=>$row['client_name'].' — Client','client_id'=>intval($row['client_id']),'vendor_id'=>0,'contact_id'=>0];
    }
    $scope = clientScopeSql('vendor_client_id');
    $permission = $ticket ? '1=1' : (($financialPermission ? 'vendor_client_id=0' : '0=1') . ($clientPermission ? ' OR vendor_client_id>0' : ''));
    $query = mysqli_query($mysqli, "SELECT vendor_id,vendor_name,vendor_client_id FROM vendors WHERE vendor_archived_at IS NULL AND vendor_name LIKE '%$q%' AND ($permission) $scope ORDER BY vendor_name LIMIT 30");
    while ($row = mysqli_fetch_assoc($query)) $out[] = ['value'=>'vendor:'.$row['vendor_id'],'label'=>$row['vendor_name'].' — Vendor','client_id'=>intval($row['vendor_client_id']),'vendor_id'=>intval($row['vendor_id']),'contact_id'=>0];
    if ($ticket) {
        $scope = clientScopeSql('(CASE WHEN c.contact_vendor_id>0 THEN v.vendor_client_id ELSE c.contact_client_id END)');
        $query = mysqli_query($mysqli, "SELECT c.contact_id,c.contact_name,c.contact_email,c.contact_client_id,c.contact_vendor_id,v.vendor_client_id,COALESCE(v.vendor_name,cl.client_name) AS owner_name
            FROM contacts c LEFT JOIN vendors v ON v.vendor_id=c.contact_vendor_id LEFT JOIN clients cl ON cl.client_id=c.contact_client_id
            WHERE c.contact_archived_at IS NULL AND ((c.contact_vendor_id>0 AND v.vendor_archived_at IS NULL AND v.vendor_id IS NOT NULL) OR (c.contact_vendor_id=0 AND c.contact_client_id>0 AND cl.client_archived_at IS NULL))
            AND (c.contact_name LIKE '%$q%' OR c.contact_email LIKE '%$q%' OR v.vendor_name LIKE '%$q%' OR cl.client_name LIKE '%$q%') $scope ORDER BY c.contact_name LIMIT 50");
        while ($row = mysqli_fetch_assoc($query)) {
            $vendor = intval($row['contact_vendor_id']);
            $out[] = ['value'=>'contact:'.$row['contact_id'],'label'=>$row['contact_name'].' — '.$row['owner_name'].' — '.($row['contact_email'] ?: 'Contact'),
                'client_id'=>intval($vendor ? $row['vendor_client_id'] : $row['contact_client_id']),'vendor_id'=>$vendor,'contact_id'=>intval($row['contact_id'])];
        }
    }
    return $out;
}
