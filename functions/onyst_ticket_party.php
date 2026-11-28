<?php

function onystTicketForPartyChange(int $ticket_id, bool $lock = false): array
{
    global $mysqli;

    $scope = clientScopeSql('ticket_client_id');
    $locking = $lock ? ' FOR UPDATE' : '';
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number,
        ticket_client_id, ticket_vendor_id, ticket_contact_id, ticket_source, ticket_details
        FROM tickets WHERE ticket_id = $ticket_id $scope LIMIT 1$locking"));
    if (!$ticket) {
        throw new InvalidArgumentException('The ticket is no longer available.');
    }

    return $ticket;
}

function onystCanCreateClientFromTicket(): bool
{
    global $session_is_admin, $client_access_array;

    return lookupUserPermission('module_client') >= 2 && ($session_is_admin || empty($client_access_array));
}

function onystResolveTicketParty(string $party): array
{
    global $mysqli;

    if (!preg_match('/\A(client|vendor|contact):([1-9][0-9]*)\z/', $party, $matches)) {
        throw new InvalidArgumentException('Choose a client, vendor, or contact from the search.');
    }

    $id = (int) $matches[2];
    $client_id = $matches[1] === 'client' ? $id : 0;
    $vendor_id = $matches[1] === 'vendor' ? $id : 0;
    $contact_id = $matches[1] === 'contact' ? $id : 0;

    if ($contact_id) {
        $contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_client_id, contact_vendor_id
            FROM contacts WHERE contact_id = $contact_id AND contact_archived_at IS NULL LIMIT 1 FOR UPDATE"));
        if (!$contact) {
            throw new InvalidArgumentException('Choose an active contact.');
        }

        $client_id = (int) $contact['contact_client_id'];
        $vendor_id = (int) $contact['contact_vendor_id'];
    }

    if ($vendor_id) {
        $scope = clientScopeSql('vendor_client_id');
        $vendor = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT vendor_client_id
            FROM vendors WHERE vendor_id = $vendor_id AND vendor_archived_at IS NULL $scope LIMIT 1 FOR UPDATE"));
        if (!$vendor) {
            throw new InvalidArgumentException('Choose an active vendor available to you.');
        }

        $client_id = (int) $vendor['vendor_client_id'];
    } elseif (!$client_id) {
        throw new InvalidArgumentException('Choose a contact with an active client or vendor.');
    }

    if ($client_id) {
        $scope = clientScopeSql('client_id');
        $client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id
            FROM clients WHERE client_id = $client_id AND client_archived_at IS NULL $scope LIMIT 1 FOR UPDATE"));
        if (!$client) {
            throw new InvalidArgumentException('Choose an active client available to you.');
        }
    }

    return [$client_id, $vendor_id, $contact_id];
}

function onystCreateTicketParty(string $mode, string $name): array
{
    global $mysqli, $session_company_currency, $config_default_hourly_rate, $config_default_net_terms;

    $name = trim(strip_tags($name));
    if ($name === '' || mb_strlen($name) > 200) {
        throw new InvalidArgumentException('Enter a client or vendor name of up to 200 characters.');
    }

    if ($mode === 'new_client') {
        if (!onystCanCreateClientFromTicket()) {
            throw new InvalidArgumentException('Your role cannot create a client from this ticket.');
        }

        mysqli_execute_query($mysqli, "INSERT INTO clients
            (client_name, client_abbreviation, client_currency_code, client_rate, client_net_terms, client_accessed_at)
            VALUES (?, ?, ?, ?, ?, NOW())",
            [$name, shortenClientName($name), $session_company_currency, $config_default_hourly_rate, $config_default_net_terms]);
        $client_id = mysqli_insert_id($mysqli);
        if (!hasClientAccess($client_id)) {
            throw new InvalidArgumentException('Your role cannot access the new client.');
        }

        return [$client_id, 0, 0];
    }

    if ($mode !== 'new_vendor' || lookupUserPermission('module_financial') < 2) {
        throw new InvalidArgumentException('Your role cannot create a vendor from this ticket.');
    }

    mysqli_execute_query($mysqli, "INSERT INTO vendors (vendor_name) VALUES (?)", [$name]);

    return [0, mysqli_insert_id($mysqli), 0];
}

/** The selected owner row is locked before matching or creating a contact. */
function onystMatchOrCreateTicketContact(int $client_id, int $vendor_id, string $name, string $email, bool $primary): array
{
    global $mysqli;

    $name = trim(strip_tags($name));
    $email = trim($email);
    if ($name === '' && $email === '') {
        return [0, false];
    }
    if (mb_strlen($name) > 200 || strlen($email) > 200 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Enter a valid contact email or leave it blank. Contact fields can be up to 200 characters.');
    }

    $contact_client_id = $vendor_id ? 0 : $client_id;
    if ($email !== '') {
        $match = onystInboundContact($email, $contact_client_id, $vendor_id);
        if ($match['state'] === 'matched') {
            return [(int) $match['contact']['contact_id'], false];
        }
    } else {
        $matches = mysqli_fetch_all(mysqli_execute_query($mysqli, "SELECT contact_id FROM contacts
            WHERE contact_client_id = ? AND contact_vendor_id = ? AND contact_name = ?
              AND contact_archived_at IS NULL LIMIT 2",
            [$contact_client_id, $vendor_id, $name]), MYSQLI_ASSOC);
        if (count($matches) > 1) {
            throw new InvalidArgumentException('More than one contact matches that name. Select the contact from the search.');
        }
        if ($matches) {
            return [(int) $matches[0]['contact_id'], false];
        }
    }

    $permission = $client_id ? 'module_client' : 'module_financial';
    if (lookupUserPermission($permission) < 2) {
        throw new InvalidArgumentException('Your role cannot create a contact for this organization. Select an existing contact or clear the match or create option.');
    }

    mysqli_execute_query($mysqli, "INSERT INTO contacts
        (contact_name, contact_email, contact_client_id, contact_vendor_id, contact_primary)
        VALUES (?, ?, ?, ?, ?)",
        [$name, $email, $contact_client_id, $vendor_id, (int) $primary]);

    return [mysqli_insert_id($mysqli), true];
}
