<?php

/**
 * WordPress shortcode: IMAP sign-in + filtered inbox list.
 *
 * Usage:
 * [bingo]
 * [bingo server="imap.titan.email" port="993" folder="INBOX" subject_filter="Damie Lee Burton Howard Family Reunion | New Membership"]
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

if (!function_exists('dlbh_inbox_decode_header_text')) {
function dlbh_inbox_decode_header_text($value) {
    if (!is_string($value) || $value === '') return '';
    if (!function_exists('imap_mime_header_decode')) return $value;

    $parts = imap_mime_header_decode($value);
    if (!is_array($parts) || empty($parts)) return $value;

    $output = '';
    foreach ($parts as $part) {
        $text = isset($part->text) ? (string)$part->text : '';
        $charset = isset($part->charset) ? strtoupper((string)$part->charset) : 'DEFAULT';

        if ($text !== '' && $charset !== 'DEFAULT' && $charset !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if ($converted !== false) $text = $converted;
        }
        $output .= $text;
    }

    return $output;
}
}

if (!function_exists('dlbh_inbox_decode_body_text_part')) {
function dlbh_inbox_decode_body_text_part($rawBody, $encoding) {
    $body = (string)$rawBody;
    $enc = (int)$encoding;
    if ($enc === 3) {
        $decoded = base64_decode($body, true);
        if ($decoded !== false) $body = $decoded;
    } elseif ($enc === 4) {
        $body = quoted_printable_decode($body);
    }
    return $body;
}
}

if (!function_exists('dlbh_inbox_part_charset')) {
function dlbh_inbox_part_charset($part) {
    if (!is_object($part)) return '';
    $sources = array();
    if (isset($part->parameters) && is_array($part->parameters)) $sources[] = $part->parameters;
    if (isset($part->dparameters) && is_array($part->dparameters)) $sources[] = $part->dparameters;
    foreach ($sources as $params) {
        foreach ($params as $param) {
            $attr = isset($param->attribute) ? strtoupper(trim((string)$param->attribute)) : '';
            if ($attr === 'CHARSET') {
                return trim((string)(isset($param->value) ? $param->value : ''));
            }
        }
    } 
    return '';
}
}

if (!function_exists('dlbh_inbox_decode_part_to_utf8')) {
function dlbh_inbox_decode_part_to_utf8($rawBody, $part) {
    $encoding = isset($part->encoding) ? (int)$part->encoding : 0;
    $decoded = dlbh_inbox_decode_body_text_part($rawBody, $encoding);
    $charset = dlbh_inbox_part_charset($part);
    if ($charset !== '' && strtoupper($charset) !== 'UTF-8') {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
        if ($converted !== false) $decoded = $converted;
    }
    return (string)$decoded;
}
}

if (!function_exists('dlbh_inbox_normalize_body_text')) {
function dlbh_inbox_normalize_body_text($value) {
    $text = (string)$value;
    if ($text === '') return '';

    $text = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', ' ', $text);
    $text = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', ' ', $text);
    if (!is_string($text)) return '';

    // Preserve HTML form-control values before stripping tags.
    if (strpos($text, '<') !== false && strpos($text, '>') !== false) {
        $text = preg_replace_callback('/<textarea\b[^>]*>(.*?)<\/textarea>/is', function($m) {
            $inner = isset($m[1]) ? (string)$m[1] : '';
            $inner = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $inner = trim((string)$inner);
            return ($inner !== '' ? ("\n" . $inner . "\n") : "\n");
        }, $text);

        $text = preg_replace_callback('/<select\b[^>]*>(.*?)<\/select>/is', function($m) {
            $inner = isset($m[1]) ? (string)$m[1] : '';
            $chosen = '';
            if (preg_match('/<option\b[^>]*selected[^>]*>(.*?)<\/option>/is', $inner, $optSel)) {
                $chosen = isset($optSel[1]) ? (string)$optSel[1] : '';
            } elseif (preg_match('/<option\b[^>]*>(.*?)<\/option>/is', $inner, $optFirst)) {
                $chosen = isset($optFirst[1]) ? (string)$optFirst[1] : '';
            }
            $chosen = html_entity_decode(strip_tags($chosen), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $chosen = trim((string)$chosen);
            return ($chosen !== '' ? ("\n" . $chosen . "\n") : "\n");
        }, $text);

        $text = preg_replace_callback('/<input\b[^>]*>/i', function($m) {
            $tag = isset($m[0]) ? (string)$m[0] : '';
            $val = '';
            if (preg_match('/\bvalue\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $vm)) {
                if (isset($vm[2]) && $vm[2] !== '') $val = (string)$vm[2];
                elseif (isset($vm[3]) && $vm[3] !== '') $val = (string)$vm[3];
                elseif (isset($vm[4]) && $vm[4] !== '') $val = (string)$vm[4];
            }
            $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $val = trim((string)$val);
            return ($val !== '' ? ("\n" . $val . "\n") : "\n");
        }, $text);
    }

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/<\s*br\b[^>]*>/i', "\n", $text);
    $text = preg_replace('/<\s*\/\s*(p|div|tr|td|th|li|h[1-6])\s*>/i', "\n", $text);
    $text = preg_replace('/<[^>]+>/', ' ', $text);
    if (!is_string($text)) return '';

    $text = preg_replace('/\r\n|\r/', "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{2,}/', "\n", $text);
    if (!is_string($text)) return '';

    return trim($text);
}
}

if (!function_exists('dlbh_inbox_find_text_part')) {
function dlbh_inbox_find_text_part($part, $prefix, $wantedSubtype) {
    if (!is_object($part)) return null;
    $type = isset($part->type) ? (int)$part->type : -1;
    $subtype = isset($part->subtype) ? strtoupper((string)$part->subtype) : '';
    if ($type === 0 && $subtype === strtoupper((string)$wantedSubtype)) {
        return array('partnum' => $prefix === '' ? '1' : $prefix, 'part' => $part);
    }
    if (isset($part->parts) && is_array($part->parts)) {
        foreach ($part->parts as $i => $child) {
            $childNum = ($prefix === '' ? (string)($i + 1) : $prefix . '.' . (string)($i + 1));
            $found = dlbh_inbox_find_text_part($child, $childNum, $wantedSubtype);
            if ($found !== null) return $found;
        }
    }
    return null;
}
}

if (!function_exists('dlbh_inbox_get_part_filename')) {
function dlbh_inbox_get_part_filename($part) {
    if (!is_object($part)) return '';
    $sources = array();
    if (isset($part->dparameters) && is_array($part->dparameters)) $sources[] = $part->dparameters;
    if (isset($part->parameters) && is_array($part->parameters)) $sources[] = $part->parameters;
    foreach ($sources as $params) {
        foreach ($params as $param) {
            $attr = isset($param->attribute) ? strtoupper(trim((string)$param->attribute)) : '';
            if ($attr === 'FILENAME' || $attr === 'NAME') {
                return trim((string)(isset($param->value) ? $param->value : ''));
            }
        }
    }
    return '';
}
}

if (!function_exists('dlbh_inbox_collect_csv_parts')) {
function dlbh_inbox_collect_csv_parts($part, $prefix = '') {
    $results = array();
    if (!is_object($part)) return $results;
    $type = isset($part->type) ? (int)$part->type : -1;
    $subtype = isset($part->subtype) ? strtoupper(trim((string)$part->subtype)) : '';
    $disposition = isset($part->disposition) ? strtoupper(trim((string)$part->disposition)) : '';
    $filename = dlbh_inbox_get_part_filename($part);
    $isCsv = ($subtype === 'CSV' || preg_match('/\.csv$/i', $filename));
    $looksLikeAttachment = ($filename !== '' || $disposition === 'ATTACHMENT' || $disposition === 'INLINE');
    if (($type === 0 || $type === 3 || $type === 5) && $isCsv && $looksLikeAttachment) {
        $results[] = array(
            'partnum' => ($prefix === '' ? '1' : $prefix),
            'part' => $part,
            'filename' => $filename,
        );
    }
    if (isset($part->parts) && is_array($part->parts)) {
        foreach ($part->parts as $i => $child) {
            $childNum = ($prefix === '' ? (string)($i + 1) : $prefix . '.' . (string)($i + 1));
            $results = array_merge($results, dlbh_inbox_collect_csv_parts($child, $childNum));
        }
    }
    return $results;
}
}

if (!function_exists('dlbh_inbox_parse_csv_string_rows')) {
function dlbh_inbox_parse_csv_string_rows($csvText) {
    $rows = array();
    $csvText = trim((string)$csvText);
    if ($csvText === '') return $rows;
    $handle = fopen('php://temp', 'r+');
    if (!$handle) return $rows;
    fwrite($handle, $csvText);
    rewind($handle);
    $headers = fgetcsv($handle);
    if (!is_array($headers) || empty($headers)) {
        fclose($handle);
        return $rows;
    }
    $headers = array_map(function($value) {
        return trim((string)$value);
    }, $headers);
    while (($data = fgetcsv($handle)) !== false) {
        if (!is_array($data)) continue;
        $assoc = array();
        foreach ($headers as $idx => $header) {
            if ($header === '') continue;
            $assoc[$header] = isset($data[$idx]) ? trim((string)$data[$idx]) : '';
        }
        if (!empty($assoc)) $rows[] = $assoc;
    }
    fclose($handle);
    return $rows;
}
}

if (!function_exists('dlbh_inbox_get_csv_attachment_rows')) {
function dlbh_inbox_get_csv_attachment_rows($connection, $msgNum) {
    $output = array();
    if (!function_exists('imap_fetchstructure') || !function_exists('imap_fetchbody')) return $output;
    $structure = @imap_fetchstructure($connection, (int)$msgNum);
    if (!$structure) return $output;
    $csvParts = dlbh_inbox_collect_csv_parts($structure, '');
    if (empty($csvParts)) return $output;

    foreach ($csvParts as $csvPart) {
        $partnum = isset($csvPart['partnum']) ? (string)$csvPart['partnum'] : '';
        $part = isset($csvPart['part']) ? $csvPart['part'] : null;
        if ($partnum === '' || !is_object($part)) continue;
        $raw = @imap_fetchbody($connection, (int)$msgNum, $partnum, FT_PEEK);
        if (!is_string($raw) || $raw === '') continue;
        $decoded = dlbh_inbox_decode_part_to_utf8($raw, $part);
        if ($decoded === '') continue;
        $parsedRows = dlbh_inbox_parse_csv_string_rows($decoded);
        foreach ($parsedRows as $parsedRow) {
            $output[] = array(
                'filename' => isset($csvPart['filename']) ? (string)$csvPart['filename'] : '',
                'row' => $parsedRow,
            );
        }
    }

    return $output;
}
}

if (!function_exists('dlbh_inbox_collect_stripe_rows_from_connection')) {
function dlbh_inbox_collect_stripe_rows_from_connection($connection) {
    $stripeRows = array();
    if (!$connection) return $stripeRows;
    $emailNumbers = @imap_search($connection, 'ALL');
    if (!is_array($emailNumbers) || empty($emailNumbers)) return $stripeRows;

    rsort($emailNumbers, SORT_NUMERIC);
    foreach ($emailNumbers as $num) {
        $overview = @imap_fetch_overview($connection, (string)$num, 0);
        $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
        if (!$item) continue;

        $csvRows = dlbh_inbox_get_csv_attachment_rows($connection, (int)$num);
        if (empty($csvRows)) continue;

        foreach ($csvRows as $csvRow) {
            $rowData = isset($csvRow['row']) && is_array($csvRow['row']) ? $csvRow['row'] : array();
            $createdUtcRaw = isset($rowData['Created date (UTC)']) ? (string)$rowData['Created date (UTC)'] : '';
            $createdTs = strtotime($createdUtcRaw);
            if ($createdTs === false) $createdTs = 0;
            $amountRaw = isset($rowData['Amount']) ? (string)$rowData['Amount'] : '';
            $feeRaw = isset($rowData['Fee']) ? (string)$rowData['Fee'] : '';
            $amountNumeric = preg_replace('/[^0-9.\-]/', '', $amountRaw);
            $feeNumeric = preg_replace('/[^0-9.\-]/', '', $feeRaw);
            $emailReceivedRaw = isset($item->date) ? trim((string)$item->date) : '';
            if ($emailReceivedRaw === '' && isset($item->udate) && (int)$item->udate > 0) {
                $emailReceivedRaw = gmdate('D, d M Y H:i:s O', (int)$item->udate);
            }
            $emailReceivedTs = strtotime($emailReceivedRaw);
            if ($emailReceivedTs === false) $emailReceivedTs = 0;
            $stripeRows[] = array(
                'Transaction ID' => isset($rowData['id']) ? (string)$rowData['id'] : '',
                'Description' => isset($rowData['Description']) ? (string)$rowData['Description'] : '',
                'Payment Received Date' => dlbh_inbox_format_utc_date_only($createdUtcRaw),
                'Payment Received Amount' => dlbh_inbox_format_currency_value($amountRaw),
                'Payment Fee' => dlbh_inbox_format_currency_value($feeRaw),
                'Family ID' => (trim((string)(isset($rowData['Checkout Custom Field 1 Value']) ? $rowData['Checkout Custom Field 1 Value'] : '')) !== '')
                    ? (string)$rowData['Checkout Custom Field 1 Value']
                    : 'SUSPENSE',
                '_amount_numeric' => is_numeric($amountNumeric) ? (float)$amountNumeric : 0.0,
                '_fee_numeric' => is_numeric($feeNumeric) ? (float)$feeNumeric : 0.0,
                '_sort_ts' => (int)$createdTs,
                '_created_date_utc_raw' => $createdUtcRaw,
                '_source_email_received' => dlbh_inbox_format_central_date_only($emailReceivedRaw),
                '_source_email_ts' => (int)$emailReceivedTs,
            );
        }
    }

    return $stripeRows;
}
}

if (!function_exists('dlbh_inbox_process_inbox_stripe_messages')) {
function dlbh_inbox_process_inbox_stripe_messages($email, $password, $server, $port) {
    $result = array('kept' => 0, 'trashed' => 0);
    $folders = array(
        'inbox' => 'INBOX',
        'stripe' => 'Stripe',
        'stripe_fallback' => 'INBOX.Stripe',
    );

    $connections = array();
    $openFolder = function($key, $folderName) use (&$connections, $email, $password, $server, $port) {
        if (isset($connections[$key])) return $connections[$key];
        $connections[$key] = dlbh_inbox_open_connection_rw($email, $password, $server, $port, $folderName);
        return $connections[$key];
    };

    $inboxRw = $openFolder('inbox', $folders['inbox']);
    if ($inboxRw === false) return $result;

    $stripeFolderName = '';
    $stripeRw = $openFolder('stripe', $folders['stripe']);
    if ($stripeRw !== false) {
        $stripeFolderName = $folders['stripe'];
    } else {
        $stripeRw = $openFolder('stripe_fallback', $folders['stripe_fallback']);
        if ($stripeRw !== false) $stripeFolderName = $folders['stripe_fallback'];
    }

    $collectCsvEmails = function($connection, $folderKey) {
        $emails = array();
        if ($connection === false) return $emails;
        $emailNumbers = @imap_search($connection, 'ALL');
        if (!is_array($emailNumbers) || empty($emailNumbers)) return $emails;
        foreach ($emailNumbers as $num) {
            $csvRows = dlbh_inbox_get_csv_attachment_rows($connection, (int)$num);
            if (empty($csvRows)) continue;
            $overview = @imap_fetch_overview($connection, (string)$num, 0);
            $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
            $receivedRaw = $item && isset($item->date) ? trim((string)$item->date) : '';
            if ($receivedRaw === '' && $item && isset($item->udate) && (int)$item->udate > 0) {
                $receivedRaw = gmdate('D, d M Y H:i:s O', (int)$item->udate);
            }
            $receivedTs = strtotime($receivedRaw);
            if ($receivedTs === false) $receivedTs = 0;
            $emails[] = array(
                'folder_key' => $folderKey,
                'msg_num' => (int)$num,
                'received_ts' => (int)$receivedTs,
            );
        }
        return $emails;
    };

    $csvEmails = array_merge(
        $collectCsvEmails($inboxRw, 'inbox'),
        $collectCsvEmails($stripeRw, $stripeFolderName !== '' ? $stripeFolderName : 'stripe')
    );

    if (empty($csvEmails)) {
        foreach ($connections as $connection) {
            if ($connection !== false) @imap_close($connection);
        }
        return $result;
    }

    usort($csvEmails, function($a, $b) {
        $aTs = isset($a['received_ts']) ? (int)$a['received_ts'] : 0;
        $bTs = isset($b['received_ts']) ? (int)$b['received_ts'] : 0;
        if ($aTs === $bTs) return 0;
        return ($aTs > $bTs) ? -1 : 1;
    });

    $latest = array_shift($csvEmails);
    $latestFolderKey = isset($latest['folder_key']) ? (string)$latest['folder_key'] : '';
    $latestMsgNum = isset($latest['msg_num']) ? (int)$latest['msg_num'] : 0;

    if ($latestMsgNum > 0) {
        $latestConnection = ($latestFolderKey === 'inbox')
            ? $inboxRw
            : (($latestFolderKey === $folders['stripe'] || $latestFolderKey === $folders['stripe_fallback']) ? $stripeRw : false);
        if ($latestConnection !== false) {
            @imap_setflag_full($latestConnection, (string)$latestMsgNum, "\\Seen");
            if ($latestFolderKey === 'inbox') {
                $moved = @imap_mail_move($latestConnection, (string)$latestMsgNum, 'Stripe');
                if (!$moved) $moved = @imap_mail_move($latestConnection, (string)$latestMsgNum, 'INBOX.Stripe');
                if ($moved) $result['kept']++;
            } else {
                $result['kept']++;
            }
        }
    }

    foreach ($csvEmails as $csvEmail) {
        $msgNum = isset($csvEmail['msg_num']) ? (int)$csvEmail['msg_num'] : 0;
        $folderKey = isset($csvEmail['folder_key']) ? (string)$csvEmail['folder_key'] : '';
        if ($msgNum <= 0) continue;
        $sourceConnection = ($folderKey === 'inbox')
            ? $inboxRw
            : (($folderKey === $folders['stripe'] || $folderKey === $folders['stripe_fallback']) ? $stripeRw : false);
        if ($sourceConnection === false) continue;
        @imap_setflag_full($sourceConnection, (string)$msgNum, "\\Seen");
        $moved = @imap_mail_move($sourceConnection, (string)$msgNum, 'Trash');
        if (!$moved) $moved = @imap_mail_move($sourceConnection, (string)$msgNum, 'INBOX.Trash');
        if ($moved) $result['trashed']++;
    }

    foreach ($connections as $connection) {
        if ($connection === false) continue;
        @imap_expunge($connection);
        @imap_close($connection);
    }
    return $result;
}
}

if (!function_exists('dlbh_inbox_format_currency_value')) {
function dlbh_inbox_format_currency_value($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $numeric = preg_replace('/[^0-9.\-]/', '', $raw);
    if ($numeric === '' || !is_numeric($numeric)) return $raw;
    return '$' . number_format((float)$numeric, 2, '.', '');
}
}

if (!function_exists('dlbh_inbox_format_stripe_created_date_ct')) {
function dlbh_inbox_format_stripe_created_date_ct($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) return $raw;
    try {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone('America/Chicago'));
        return $dt->format('F j, Y g:i A');
    } catch (Exception $e) {
        return $raw;
    }
}
}

if (!function_exists('dlbh_inbox_format_central_date_only')) {
function dlbh_inbox_format_central_date_only($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) return $raw;
    try {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone('America/Chicago'));
        return $dt->format('F j, Y');
    } catch (Exception $e) {
        return $raw;
    }
}
}

if (!function_exists('dlbh_inbox_format_utc_date_only')) {
function dlbh_inbox_format_utc_date_only($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) return $raw;
    try {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('F j, Y');
    } catch (Exception $e) {
        return $raw;
    }
}
}

if (!function_exists('dlbh_inbox_get_roster_family_ids')) {
function dlbh_inbox_get_roster_family_ids($email, $password, $server, $port) {
    $familyIds = array();
    $folderSets = array(
        array('Roster', 'INBOX.Roster'),
        array('Roster/Aging Report', 'Roster.Aging Report', 'INBOX.Roster/Aging Report', 'INBOX.Roster.Aging Report'),
    );
    foreach ($folderSets as $folderCandidates) {
        $open = dlbh_inbox_open_connection_rw_with_fallbacks($email, $password, $server, $port, $folderCandidates);
        $connection = isset($open['connection']) ? $open['connection'] : false;
        if ($connection === false) continue;
        $emailNumbers = @imap_search($connection, 'ALL');
        if (is_array($emailNumbers) && !empty($emailNumbers)) {
            foreach ($emailNumbers as $num) {
                $bodyText = dlbh_inbox_get_plain_text_body($connection, (int)$num);
                $overview = @imap_fetch_overview($connection, (string)$num, 0);
                $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
                $receivedRaw = isset($item->date) ? trim((string)$item->date) : '';
                if ($receivedRaw === '' && isset($item->udate) && (int)$item->udate > 0) {
                    $receivedRaw = gmdate('D, d M Y H:i:s O', (int)$item->udate);
                }
                $parsed = dlbh_inbox_parse_body_fields($bodyText, array('received_date' => $receivedRaw));
                $fields = isset($parsed['fields']) && is_array($parsed['fields']) ? $parsed['fields'] : array();
                $familyId = dlbh_inbox_normalize_family_id_lookup_key(dlbh_inbox_get_field_value($fields, 'Family ID'));
                if ($familyId !== '') $familyIds[$familyId] = true;
            }
        }
        @imap_close($connection);
    }
    return $familyIds;
}
}

if (!function_exists('dlbh_inbox_get_roster_folder_candidates')) {
function dlbh_inbox_get_roster_folder_candidates($type) {
    $type = strtolower(trim((string)$type));
    if ($type === 'aging') {
        return array('Roster/Aging Report', 'Roster.Aging Report', 'INBOX.Roster/Aging Report', 'INBOX.Roster.Aging Report');
    }
    return array('Roster', 'INBOX.Roster');
}
}

if (!function_exists('dlbh_inbox_move_message_to_folder_candidates')) {
function dlbh_inbox_move_message_to_folder_candidates($rw, $msgNum, $targetFolders) {
    if (!$rw || (int)$msgNum <= 0) return false;
    $targetFolders = is_array($targetFolders) ? $targetFolders : array($targetFolders);
    @imap_setflag_full($rw, (string)$msgNum, "\\Seen");
    foreach ($targetFolders as $targetFolder) {
        $targetFolder = trim((string)$targetFolder);
        if ($targetFolder === '') continue;
        if (@imap_mail_move($rw, (string)$msgNum, $targetFolder)) {
            @imap_expunge($rw);
            return true;
        }
    }
    return false;
}
}

if (!function_exists('dlbh_inbox_get_bingo_inventory_folder_candidates')) {
function dlbh_inbox_get_bingo_inventory_folder_candidates() {
    return array(
        'Bingo/Inventory',
        'Bingo.Inventory',
        'INBOX.Bingo/Inventory',
        'INBOX.Bingo.Inventory',
    );
}
}

if (!function_exists('dlbh_inbox_append_html_message_to_folder_candidates')) {
function dlbh_inbox_append_html_message_to_folder_candidates($email, $password, $server, $port, $targetFolders, $from, $to, $subject, $htmlBody) {
    if (!function_exists('imap_open') || !function_exists('imap_append')) return false;
    $email = trim((string)$email);
    $password = (string)$password;
    $server = trim((string)$server);
    $port = (int)$port;
    if ($email === '' || $password === '' || $server === '' || $port <= 0) return false;

    $rw = dlbh_inbox_open_connection_rw($email, $password, $server, $port, 'INBOX');
    if ($rw === false) return false;

    $safeFrom = trim((string)$from);
    $safeTo = trim((string)$to);
    $safeSubject = trim((string)$subject);
    $safeHtml = (string)$htmlBody;
    $encodedSubject = '=?UTF-8?B?' . base64_encode($safeSubject) . '?=';
    $rawMessage = ''
        . 'Date: ' . gmdate('r') . "\r\n"
        . 'From: ' . $safeFrom . "\r\n"
        . 'To: ' . $safeTo . "\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n"
        . 'MIME-Version: 1.0' . "\r\n"
        . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: 8bit' . "\r\n"
        . "\r\n"
        . $safeHtml;

    $targets = is_array($targetFolders) ? $targetFolders : array($targetFolders);
    $appended = false;
    foreach ($targets as $targetFolder) {
        $targetFolder = trim((string)$targetFolder);
        if ($targetFolder === '') continue;
        $mailbox = '{' . $server . ':' . $port . '/imap/ssl/novalidate-cert}' . $targetFolder;
        if (@imap_append($rw, $mailbox, $rawMessage, "\\Seen")) {
            $appended = true;
            break;
        }
        if (function_exists('imap_createmailbox')) {
            $created = @imap_createmailbox($rw, imap_utf7_encode($mailbox));
            if ($created && @imap_append($rw, $mailbox, $rawMessage, "\\Seen")) {
                $appended = true;
                break;
            }
        }
    }

    @imap_close($rw);
    return $appended;
}
}

if (!function_exists('dlbh_inbox_get_profile_folder_type_for_aging')) {
function dlbh_inbox_get_profile_folder_type_for_aging($profileFields, $receivedDate, $stripeRows) {
    $summaryOffset = dlbh_inbox_get_current_statement_offset($profileFields, $receivedDate);
    $summaryLabel = dlbh_inbox_get_statement_label_for_offset($profileFields, $receivedDate, $summaryOffset);
    $summaryFields = dlbh_inbox_build_account_summary_fields_with_payments(
        $profileFields,
        $receivedDate,
        $summaryOffset,
        $stripeRows,
        $summaryLabel
    );
    $currentStatementTotalDueRaw = trim((string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Total Due'));
    $currentStatementTotalDue = (float)preg_replace('/[^0-9.\-]/', '', $currentStatementTotalDueRaw);
    $allPaymentsTotal = dlbh_inbox_get_family_payments_total($profileFields, $stripeRows);
    $agingTotalDue = $currentStatementTotalDue - $allPaymentsTotal;
    if ($agingTotalDue <= 0.0) return 'roster';
    $bucket = dlbh_inbox_get_aging_bucket_for_summary($summaryFields, date('F j, Y'), $agingTotalDue);
    return ($bucket === 'current') ? 'roster' : 'aging';
}
}

if (!function_exists('dlbh_inbox_get_profile_aging_notice_mode')) {
function dlbh_inbox_get_profile_aging_notice_mode($profileFields, $receivedDate, $stripeRows) {
    $summaryOffset = dlbh_inbox_get_current_statement_offset($profileFields, $receivedDate);
    $summaryLabel = dlbh_inbox_get_statement_label_for_offset($profileFields, $receivedDate, $summaryOffset);
    $summaryFields = dlbh_inbox_build_account_summary_fields_with_payments(
        $profileFields,
        $receivedDate,
        $summaryOffset,
        $stripeRows,
        $summaryLabel
    );
    $currentStatementTotalDueRaw = trim((string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Total Due'));
    $currentStatementTotalDue = (float)preg_replace('/[^0-9.\-]/', '', $currentStatementTotalDueRaw);
    $allPaymentsTotal = dlbh_inbox_get_family_payments_total($profileFields, $stripeRows);
    $agingTotalDue = $currentStatementTotalDue - $allPaymentsTotal;
    if ($agingTotalDue <= 0.0) return '';
    $bucket = dlbh_inbox_get_aging_bucket_for_summary($summaryFields, date('F j, Y'), $agingTotalDue);
    if ($bucket === 'delinquent') return 'aging_delinquent';
    if ($bucket === 'past_due_1_15' || $bucket === 'past_due_16_30') return 'aging_past_due';
    return '';
}
}

if (!function_exists('dlbh_inbox_get_aging_notice_status_map')) {
function dlbh_inbox_get_aging_notice_status_map() {
    $map = function_exists('get_option') ? get_option('dlbh_aging_notice_status_map', array()) : array();
    return is_array($map) ? $map : array();
}
}

if (!function_exists('dlbh_inbox_set_aging_notice_status_map_value')) {
function dlbh_inbox_set_aging_notice_status_map_value($familyId, $mode) {
    $familyId = strtoupper(trim((string)$familyId));
    if ($familyId === '') return;
    $map = dlbh_inbox_get_aging_notice_status_map();
    $mode = trim((string)$mode);
    if ($mode === '') {
        unset($map[$familyId]);
    } else {
        $map[$familyId] = $mode;
    }
    if (function_exists('update_option')) update_option('dlbh_aging_notice_status_map', $map, false);
}
}

if (!function_exists('dlbh_inbox_extract_roster_people_from_fields')) {
function dlbh_inbox_extract_roster_people_from_fields($fields) {
    $people = array();
    $familyId = trim((string)dlbh_inbox_get_field_value($fields, 'Family ID'));
    $commencementDate = trim((string)dlbh_inbox_get_field_value($fields, 'Commencement Date'));
    $householdAllergy = trim((string)dlbh_inbox_get_field_value($fields, 'Do you or anyone your enrolling today have any allergies or food restrictions?'));
    $householdMilitary = trim((string)dlbh_inbox_get_field_value($fields, 'Have you or anyone your enrolling today served or are serving in the United States Armed Forces?'));
    $sharedFields = array();
    $sharedHeaders = array(
        'Pre-Enrollment Questionnaire' => true,
        'Membership Information' => true,
        'Account Summary Information' => true,
        'Contact Information' => true,
    );
    $activeSharedHeader = '';
    foreach ((array)$fields as $field) {
        if (!is_array($field)) continue;
        $type = isset($field['type']) ? strtolower(trim((string)$field['type'])) : 'field';
        $label = trim((string)(isset($field['label']) ? $field['label'] : ''));
        $value = trim((string)(isset($field['value']) ? $field['value'] : ''));
        if ($type === 'header') {
            if (isset($sharedHeaders[$label])) {
                $activeSharedHeader = $label;
                $sharedFields[] = array('type' => 'header', 'label' => $label, 'value' => '');
            } else {
                $activeSharedHeader = '';
            }
            continue;
        }
        if ($activeSharedHeader === '') continue;
        $sharedFields[] = array('type' => 'field', 'label' => $label, 'value' => $value);
    }
    $currentRelationship = '';
    $currentHeaderLabel = '';
    $currentName = '';
    $currentDob = '';
    $currentAllergy = '';
    $currentMilitary = '';
    $currentShirtSize = '';
    $currentDetailFields = array();
    $allergyLabels = array(
        'do you or anyone your enrolling today have any allergies or food restrictions?' => true,
        'do you have any allergies or food restrictions?' => true,
    );
    $militaryLabels = array(
        'have you or anyone your enrolling today served or are serving in the united states armed forces?' => true,
        'have you or are you serving in the united states armed forces?' => true,
    );
    $flushCurrent = function() use (&$people, &$currentRelationship, &$currentHeaderLabel, &$currentName, &$currentDob, &$currentAllergy, &$currentMilitary, &$currentShirtSize, &$currentDetailFields, $familyId, $commencementDate, $sharedFields, $fields) {
        if ($currentRelationship === '' || $currentName === '') return;
        $memberKey = md5(strtolower($familyId . '|' . $currentRelationship . '|' . $currentName . '|' . $currentDob . '|' . $currentHeaderLabel));
        $people[] = array(
            'Member Key' => $memberKey,
            'Relationship' => $currentRelationship,
            'Name' => $currentName,
            'Date of Birth' => $currentDob,
            'Commencement Date' => $commencementDate,
            'Family ID' => $familyId,
            'Allergies & Food Restrictions' => $currentAllergy,
            'Military Status' => $currentMilitary,
            'Household Allergies & Food Restrictions' => $householdAllergy,
            'Household Military Status' => $householdMilitary,
            'T-Shirt Size' => $currentShirtSize,
            'Detail Fields' => array_merge($sharedFields, $currentDetailFields),
            'Profile Fields' => $fields,
        );
    };

    foreach ((array)$fields as $field) {
        if (!is_array($field)) continue;
        $type = isset($field['type']) ? strtolower(trim((string)$field['type'])) : 'field';
        if ($type === 'header') {
            $label = trim((string)(isset($field['label']) ? $field['label'] : ''));
            if (
                strcasecmp($label, 'Primary Member Information') === 0 ||
                strcasecmp($label, 'Spouse Information') === 0 ||
                preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $label)
            ) {
                $currentHeaderLabel = $label;
            }
            continue;
        }

        $label = trim((string)(isset($field['label']) ? $field['label'] : ''));
        $value = trim((string)(isset($field['value']) ? $field['value'] : ''));
        $labelKey = strtolower($label);

        if (strcasecmp($label, 'Relationship') === 0) {
            $flushCurrent();
            $relationship = strtolower($value);
            if ($relationship === 'spouse/partner' || $relationship === 'partner') $relationship = 'spouse';
            if ($relationship === 'primary member') $currentRelationship = 'Primary Member';
            elseif ($relationship === 'spouse') $currentRelationship = 'Spouse';
            elseif ($relationship === 'dependent') $currentRelationship = 'Dependent';
            else $currentRelationship = '';
            $currentName = '';
            $currentDob = '';
            $currentAllergy = '';
            $currentMilitary = '';
            $currentShirtSize = '';
            $currentDetailFields = array();
            if ($currentHeaderLabel !== '') {
                $currentDetailFields[] = array('type' => 'header', 'label' => $currentHeaderLabel, 'value' => '');
            }
            $currentDetailFields[] = array('type' => 'field', 'label' => 'Relationship', 'value' => $value);
            continue;
        }

        if ($currentRelationship === '') continue;
        if (strcasecmp($label, 'Name') === 0) {
            $currentName = $value;
            $currentDetailFields[] = array('type' => 'field', 'label' => 'Name', 'value' => $value);
            continue;
        }
        if (strcasecmp($label, 'Date of Birth') === 0) {
            $currentDob = $value;
            $currentDetailFields[] = array('type' => 'field', 'label' => 'Date of Birth', 'value' => $value);
            continue;
        }
        if (isset($allergyLabels[$labelKey])) {
            $currentAllergy = $value;
            $currentDetailFields[] = array('type' => 'field', 'label' => $label, 'value' => $value);
            continue;
        }
        if (isset($militaryLabels[$labelKey])) {
            $currentMilitary = $value;
            $currentDetailFields[] = array('type' => 'field', 'label' => $label, 'value' => $value);
            continue;
        }
        if (strcasecmp($label, 'T-Shirt Size') === 0) {
            $currentShirtSize = $value;
            $currentDetailFields[] = array('type' => 'field', 'label' => 'T-Shirt Size', 'value' => $value);
            continue;
        }
    }

    $flushCurrent();
    return $people;
}
}

if (!function_exists('dlbh_inbox_collect_roster_rows')) {
function dlbh_inbox_collect_roster_rows($email, $password, $server, $port, $stripeRows = array()) {
    $rosterRows = array();
    $agingNoticeStatusMap = dlbh_inbox_get_aging_notice_status_map();
    $folderSpecs = array(
        array('type' => 'roster', 'folders' => dlbh_inbox_get_roster_folder_candidates('roster')),
        array('type' => 'aging', 'folders' => dlbh_inbox_get_roster_folder_candidates('aging')),
    );
    foreach ($folderSpecs as $folderSpec) {
        $folderType = isset($folderSpec['type']) ? (string)$folderSpec['type'] : 'roster';
        $folderCandidates = isset($folderSpec['folders']) && is_array($folderSpec['folders']) ? $folderSpec['folders'] : array();
        $open = dlbh_inbox_open_connection_with_fallbacks($email, $password, $server, $port, $folderCandidates);
        $connection = isset($open['connection']) ? $open['connection'] : false;
        $openedFolderName = isset($open['folder']) ? (string)$open['folder'] : '';
        if ($connection === false) continue;

        $emailNumbers = @imap_search($connection, 'ALL');
        if (is_array($emailNumbers) && !empty($emailNumbers)) {
            foreach ($emailNumbers as $num) {
                $bodyText = dlbh_inbox_get_plain_text_body($connection, (int)$num);
                $overview = @imap_fetch_overview($connection, (string)$num, 0);
                $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
                $receivedRaw = isset($item->date) ? trim((string)$item->date) : '';
                if ($receivedRaw === '' && isset($item->udate) && (int)$item->udate > 0) {
                    $receivedRaw = gmdate('D, d M Y H:i:s O', (int)$item->udate);
                }
                $parsed = dlbh_inbox_parse_body_fields($bodyText, array('received_date' => $receivedRaw));
                $fields = isset($parsed['fields']) && is_array($parsed['fields']) ? $parsed['fields'] : array();
                $familyId = strtoupper(trim((string)dlbh_inbox_get_field_value($fields, 'Family ID')));
                $desiredFolderType = (!empty($stripeRows) ? dlbh_inbox_get_profile_folder_type_for_aging($fields, $receivedRaw, $stripeRows) : $folderType);
                $currentNoticeMode = (!empty($stripeRows) ? dlbh_inbox_get_profile_aging_notice_mode($fields, $receivedRaw, $stripeRows) : '');
                $storedNoticeMode = ($familyId !== '' && isset($agingNoticeStatusMap[$familyId])) ? trim((string)$agingNoticeStatusMap[$familyId]) : '';
                $effectiveFolderType = $folderType;
                if (
                    $folderType === 'aging' && (
                        $desiredFolderType === 'roster' ||
                        ($storedNoticeMode !== '' && $currentNoticeMode !== $storedNoticeMode)
                    )
                ) {
                    $moved = dlbh_inbox_move_message_to_folder_candidates($connection, (int)$num, dlbh_inbox_get_roster_folder_candidates('roster'));
                    if ($moved) {
                        $effectiveFolderType = 'roster';
                        if ($familyId !== '') {
                            unset($agingNoticeStatusMap[$familyId]);
                            dlbh_inbox_set_aging_notice_status_map_value($familyId, '');
                        }
                    }
                }
                $messagePeople = dlbh_inbox_extract_roster_people_from_fields($fields);
                foreach ($messagePeople as $personRow) {
                    if (!is_array($personRow)) continue;
                    $personRow['Source Folder'] = $openedFolderName;
                    $personRow['Source Folder Type'] = $effectiveFolderType;
                    $personRow['Source Msg Num'] = (int)$num;
                    $rosterRows[] = $personRow;
                }
            }
        }

        @imap_close($connection);
    }

    if (!empty($rosterRows)) {
        $deduped = array();
        foreach ($rosterRows as $rosterRow) {
            if (!is_array($rosterRow)) continue;
            $memberKey = isset($rosterRow['Member Key']) ? (string)$rosterRow['Member Key'] : '';
            if ($memberKey === '') {
                $deduped[] = $rosterRow;
                continue;
            }
            $deduped[$memberKey] = $rosterRow;
        }
        $rosterRows = array_values($deduped);
    }

    usort($rosterRows, function($a, $b) {
        $familyCompare = strcasecmp((string)(isset($a['Family ID']) ? $a['Family ID'] : ''), (string)(isset($b['Family ID']) ? $b['Family ID'] : ''));
        if ($familyCompare !== 0) return $familyCompare;
        $relationshipOrder = array('Primary Member' => 0, 'Spouse' => 1, 'Dependent' => 2);
        $aRelationship = isset($a['Relationship']) ? (string)$a['Relationship'] : '';
        $bRelationship = isset($b['Relationship']) ? (string)$b['Relationship'] : '';
        $aOrder = isset($relationshipOrder[$aRelationship]) ? $relationshipOrder[$aRelationship] : 99;
        $bOrder = isset($relationshipOrder[$bRelationship]) ? $relationshipOrder[$bRelationship] : 99;
        if ($aOrder !== $bOrder) return $aOrder - $bOrder;
        return strcasecmp((string)(isset($a['Name']) ? $a['Name'] : ''), (string)(isset($b['Name']) ? $b['Name'] : ''));
    });

    return $rosterRows;
}
}

if (!function_exists('dlbh_inbox_normalize_family_id_lookup_key')) {
function dlbh_inbox_normalize_family_id_lookup_key($value) {
    $raw = strtoupper(trim((string)$value));
    if ($raw === '') return '';

    if (preg_match('/^DLBHF-\s*(\d{5})$/', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^DLBHF(\d{5})$/', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('/^\d{5}$/', $raw)) {
        return $raw;
    }

    return '';
}
}

if (!function_exists('dlbh_inbox_find_member_roster_row_by_login')) {
function dlbh_inbox_find_member_roster_row_by_login($rosterRows, $email, $familyIdInput) {
    $email = strtolower(trim((string)$email));
    $familyIdKey = dlbh_inbox_normalize_family_id_lookup_key($familyIdInput);
    if ($email === '' || $familyIdKey === '' || !is_array($rosterRows)) return null;

    foreach ($rosterRows as $rosterRow) {
        if (!is_array($rosterRow)) continue;
        $rowFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($rosterRow['Family ID']) ? $rosterRow['Family ID'] : '');
        if ($rowFamilyId === '' || $rowFamilyId !== $familyIdKey) continue;
        $profileFields = isset($rosterRow['Profile Fields']) && is_array($rosterRow['Profile Fields']) ? $rosterRow['Profile Fields'] : array();
        $rowEmail = strtolower(trim((string)dlbh_inbox_get_field_value($profileFields, 'Email')));
        if ($rowEmail === '' || $rowEmail !== $email) continue;
        if (strcasecmp((string)(isset($rosterRow['Relationship']) ? $rosterRow['Relationship'] : ''), 'Primary Member') === 0) {
            return $rosterRow;
        }
    }

    foreach ($rosterRows as $rosterRow) {
        if (!is_array($rosterRow)) continue;
        $rowFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($rosterRow['Family ID']) ? $rosterRow['Family ID'] : '');
        if ($rowFamilyId !== $familyIdKey) continue;
        $profileFields = isset($rosterRow['Profile Fields']) && is_array($rosterRow['Profile Fields']) ? $rosterRow['Profile Fields'] : array();
        $rowEmail = strtolower(trim((string)dlbh_inbox_get_field_value($profileFields, 'Email')));
        if ($rowEmail !== '' && $rowEmail === $email) {
            return $rosterRow;
        }
    }

    return null;
}
}

if (!function_exists('dlbh_inbox_score_body_richness')) {
function dlbh_inbox_score_body_richness($text) {
    $text = trim((string)$text);
    if ($text === '') return -1;

    $score = 0;
    $markers = array(
        'Pre-Enrollment Questionnaire',
        'Contact Information',
        'Primary Member Information',
        'Spouse Information',
        'Dependent Information',
        'Address',
        'City',
        'State',
        'Zip Code',
        'Country',
        'Email',
        'Phone',
        'Preferred Method of Contact',
        'Relationship',
        'Name',
        'Date of Birth',
        'T-Shirt Size',
    );
    foreach ($markers as $marker) {
        if (stripos($text, $marker) !== false) $score += 3;
    }

    if (preg_match_all('/\b(?:Yes|No)\b/i', $text, $m)) $score += count($m[0]);
    if (preg_match_all('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $text, $m)) $score += count($m[0]) * 2;
    if (preg_match_all('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $text, $m)) $score += count($m[0]) * 2;
    if (preg_match_all('/\+\d{10,}/', $text, $m)) $score += count($m[0]) * 2;
    if (preg_match_all('/\b(?:3XL|2XL|XL|L|M|S)\b/', $text, $m)) $score += count($m[0]);

    return $score;
}
}

if (!function_exists('dlbh_inbox_extract_reply_message_from_plain')) {
function dlbh_inbox_extract_reply_message_from_plain($text) {
    $text = trim((string)$text);
    if ($text === '') return '';
    $cutPos = false;
    $onPos = stripos($text, "\nOn ");
    $fromPos = stripos($text, "\nFrom:");
    if ($onPos !== false) $cutPos = $onPos;
    if ($fromPos !== false && ($cutPos === false || $fromPos < $cutPos)) $cutPos = $fromPos;
    if ($cutPos === false) {
        $onPos = stripos($text, 'On ');
        $fromPos = stripos($text, 'From:');
        if ($onPos !== false) $cutPos = $onPos;
        if ($fromPos !== false && ($cutPos === false || $fromPos < $cutPos)) $cutPos = $fromPos;
    }
    if ($cutPos === false) return '';

    $reply = dlbh_inbox_clean_reply_message_text((string)substr($text, 0, (int)$cutPos));
    $looksLikeCss = (
        stripos($reply, 'wpforms') !== false ||
        stripos($reply, '@media') !== false ||
        stripos($reply, '.wpforms') !== false ||
        preg_match('/\{[^}]+\}/', $reply)
    );
    $hasEnoughSignal = (
        preg_match('/[A-Za-z]/', $reply) &&
        !preg_match('/^\s*(on|from)\b/i', $reply)
    );
    if ($reply !== '' && !$looksLikeCss && $hasEnoughSignal && stripos($reply, 'membership committee') === false) {
        return $reply;
    }
    return '';
}
}

if (!function_exists('dlbh_inbox_get_plain_text_body')) {
function dlbh_inbox_get_plain_text_body($connection, $msgNum) {
    if (!function_exists('imap_fetchstructure') || !function_exists('imap_fetchbody') || !function_exists('imap_body')) {
        return '';
    }

    $structure = @imap_fetchstructure($connection, (int)$msgNum);
    if (!$structure) {
        $raw = @imap_body($connection, (int)$msgNum, FT_PEEK);
        return is_string($raw) ? $raw : '';
    }

    if (!isset($structure->parts) || !is_array($structure->parts) || empty($structure->parts)) {
        $raw = @imap_body($connection, (int)$msgNum, FT_PEEK);
        if (!is_string($raw)) return '';
        return dlbh_inbox_normalize_body_text(dlbh_inbox_decode_part_to_utf8($raw, $structure));
    }

    $plainNormalized = '';
    $htmlNormalized = '';

    $plainMatch = dlbh_inbox_find_text_part($structure, '', 'PLAIN');
    if ($plainMatch !== null) {
        $rawPlain = @imap_fetchbody($connection, (int)$msgNum, (string)$plainMatch['partnum'], FT_PEEK);
        if (is_string($rawPlain) && $rawPlain !== '') {
            $plainText = dlbh_inbox_decode_part_to_utf8($rawPlain, $plainMatch['part']);
            $plainNormalized = dlbh_inbox_normalize_body_text($plainText);
        }
    }

    $htmlMatch = dlbh_inbox_find_text_part($structure, '', 'HTML');
    if ($htmlMatch !== null) {
        $rawHtml = @imap_fetchbody($connection, (int)$msgNum, (string)$htmlMatch['partnum'], FT_PEEK);
        if (is_string($rawHtml) && $rawHtml !== '') {
            $decodedHtml = dlbh_inbox_decode_part_to_utf8($rawHtml, $htmlMatch['part']);
            if ($decodedHtml !== '') {
                $htmlNormalized = dlbh_inbox_normalize_body_text($decodedHtml);
            }
        }
    }

    if ($plainNormalized !== '' || $htmlNormalized !== '') {
        $plainScore = dlbh_inbox_score_body_richness($plainNormalized);
        $htmlScore = dlbh_inbox_score_body_richness($htmlNormalized);
        if ($htmlScore > $plainScore) {
            $replyPrefix = dlbh_inbox_extract_reply_message_from_plain($plainNormalized);
            if ($replyPrefix !== '' && stripos($htmlNormalized, $replyPrefix) === false) {
                return trim("__DLBH_REPLY_START__\n" . $replyPrefix . "\n__DLBH_REPLY_END__\n\n" . $htmlNormalized);
            }
            return $htmlNormalized;
        }
        return $plainNormalized;
    }

    $rawFallback = @imap_body($connection, (int)$msgNum, FT_PEEK);
    if (!is_string($rawFallback)) return '';
    $rawFallback = quoted_printable_decode($rawFallback);
    return dlbh_inbox_normalize_body_text($rawFallback);
}
}

if (!function_exists('dlbh_inbox_extract_primary_member_name')) {
function dlbh_inbox_extract_primary_member_name($bodyText) {
    $text = trim((string)$bodyText);
    if ($text === '') return '';

    $sanitizeName = function($value) {
        $candidate = trim(strip_tags(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($candidate === '') return '';
        $candidate = preg_replace('/\s+/', ' ', $candidate);
        if (!is_string($candidate)) return '';
        $candidate = trim($candidate);
        $candidate = preg_replace('/^(Name|Primary Member)\s*/i', '', $candidate);
        if (!is_string($candidate)) return '';
        $candidate = preg_replace('/\b(Date of Birth|T-?Shirt Size|Relationship|Dependent Information|Email|Phone|Address)\b.*$/i', '', $candidate);
        if (!is_string($candidate)) return '';
        $candidate = trim($candidate, " \t\n\r\0\x0B,;:-");
        if ((bool)preg_match("/^[A-Za-z][A-Za-z'\\-\\. ]+[A-Za-z]$/", $candidate)) return $candidate;
        return '';
    };

    $normalized = preg_replace('/\r\n|\r/', "\n", $text);
    if (!is_string($normalized)) $normalized = $text;
    $normalized = preg_replace('/[ \t]+/', ' ', $normalized);
    if (!is_string($normalized)) $normalized = $text;

    $flat = str_replace("\n", ' ', $normalized);

    if (preg_match('/Relationship\s*Primary Member\s*Name\s*([^\n]+)/i', $flat, $m1)) {
        $candidate = $sanitizeName((string)$m1[1]);
        if ($candidate !== '') return $candidate;
    }

    if (preg_match('/Relationship\s*\n+\s*Primary Member\s*\n+\s*Name\s*\n+\s*([^\n]+)/i', $normalized, $m2)) {
        $candidate = $sanitizeName((string)$m2[1]);
        if ($candidate !== '') return $candidate;
    }

    if (preg_match('/Primary Member[\s\S]{0,600}?Name\s*([A-Za-z][A-Za-z\'\-\.\s]{1,80}?)(?:\s{2,}|Date of Birth|T-?Shirt Size|Relationship|Dependent Information|Email|Phone|Address|$)/i', $flat, $m3)) {
        $candidate = $sanitizeName((string)$m3[1]);
        if ($candidate !== '') return $candidate;
    }

    if (preg_match('/Name\s*([A-Za-z][A-Za-z\'\-\.\s]{1,80}?)\s*Date of Birth/i', $flat, $m4)) {
        $candidate = $sanitizeName((string)$m4[1]);
        if ($candidate !== '') return $candidate;
    }

    $lines = preg_split('/\n+/', $normalized);
    if (is_array($lines) && !empty($lines)) {
        $cleanLines = array();
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') $cleanLines[] = $line;
        }

        $count = count($cleanLines);
        for ($i = 0; $i < $count; $i++) {
            if (stripos($cleanLines[$i], 'Primary Member') === false) continue;
            for ($j = $i; $j < min($count, $i + 18); $j++) {
                $line = $cleanLines[$j];
                if (preg_match('/^Name\b\s*(.*)$/i', $line, $nameLineMatch)) {
                    $candidate = $sanitizeName((string)$nameLineMatch[1]);
                    if ($candidate !== '') return $candidate;
                    if (($j + 1) < $count) {
                        $candidate = $sanitizeName((string)$cleanLines[$j + 1]);
                        if ($candidate !== '') return $candidate;
                    }
                }
            }
        }
    }

    return '';
}
}

if (!function_exists('dlbh_inbox_get_html_body')) {
function dlbh_inbox_get_html_body($connection, $msgNum) {
    if (!function_exists('imap_fetchstructure') || !function_exists('imap_fetchbody') || !function_exists('imap_body')) {
        return '';
    }
    $structure = @imap_fetchstructure($connection, (int)$msgNum);
    if (!$structure) {
        $raw = @imap_body($connection, (int)$msgNum, FT_PEEK);
        return is_string($raw) ? $raw : '';
    }
    if (!isset($structure->parts) || !is_array($structure->parts) || empty($structure->parts)) {
        $raw = @imap_body($connection, (int)$msgNum, FT_PEEK);
        if (!is_string($raw)) return '';
        return dlbh_inbox_decode_part_to_utf8($raw, $structure);
    }
    $htmlMatch = dlbh_inbox_find_text_part($structure, '', 'HTML');
    if ($htmlMatch !== null) {
        $rawHtml = @imap_fetchbody($connection, (int)$msgNum, (string)$htmlMatch['partnum'], FT_PEEK);
        if (is_string($rawHtml) && $rawHtml !== '') {
            return (string)dlbh_inbox_decode_part_to_utf8($rawHtml, $htmlMatch['part']);
        }
    }
    return '';
}
}

if (!function_exists('dlbh_bingo_extract_family_id_from_text')) {
function dlbh_bingo_extract_family_id_from_text($text) {
    $body = (string)$text;
    if (preg_match('/Family\\s*ID[^0-9A-Z]*([A-Z\\-]*\\d{5,})/i', $body, $m)) {
        return dlbh_inbox_normalize_family_id_lookup_key((string)$m[1]);
    }
    if (preg_match('/\\b(\\d{5})\\b/', $body, $m2)) {
        return dlbh_inbox_normalize_family_id_lookup_key((string)$m2[1]);
    }
    return '';
}
}

if (!function_exists('dlbh_bingo_extract_cards_from_order_email_html')) {
function dlbh_bingo_extract_cards_from_order_email_html($html) {
    $cards = array();
    $markup = (string)$html;
    if ($markup === '') return $cards;

    $matches = array();
    if (!preg_match_all('/Bingo Card\\s*\\d+\\s*-\\s*(\\d{5}).*?<table\\b[^>]*>(.*?)<\\/table>/is', $markup, $matches, PREG_SET_ORDER)) {
        return $cards;
    }

    foreach ($matches as $match) {
        $cardId = isset($match[1]) ? preg_replace('/[^0-9]/', '', (string)$match[1]) : '';
        if (!is_string($cardId) || strlen($cardId) !== 5) continue;
        $tableHtml = isset($match[2]) ? (string)$match[2] : '';
        if ($tableHtml === '') continue;

        $rowMatches = array();
        if (!preg_match_all('/<tr\\b[^>]*>(.*?)<\\/tr>/is', $tableHtml, $rowMatches)) continue;
        $gridRows = array();
        foreach ($rowMatches[1] as $rowHtml) {
            if (stripos((string)$rowHtml, '<th') !== false) continue;
            $cellMatches = array();
            if (!preg_match_all('/<t[dh]\\b[^>]*>(.*?)<\\/t[dh]>/is', (string)$rowHtml, $cellMatches)) continue;
            $rowVals = array();
            foreach ($cellMatches[1] as $cellHtml) {
                $cellText = html_entity_decode(strip_tags((string)$cellHtml), ENT_QUOTES, 'UTF-8');
                $cellText = trim((string)$cellText);
                if (strcasecmp($cellText, 'FREE') === 0) {
                    $rowVals[] = 'FREE';
                } else {
                    $num = preg_replace('/[^0-9]/', '', $cellText);
                    $rowVals[] = ($num !== '') ? (int)$num : 0;
                }
            }
            if (count($rowVals) === 5) $gridRows[] = $rowVals;
        }
        if (count($gridRows) !== 5) continue;

        $B = array($gridRows[0][0], $gridRows[1][0], $gridRows[2][0], $gridRows[3][0], $gridRows[4][0]);
        $I = array($gridRows[0][1], $gridRows[1][1], $gridRows[2][1], $gridRows[3][1], $gridRows[4][1]);
        $N = array($gridRows[0][2], $gridRows[1][2], 'FREE', $gridRows[3][2], $gridRows[4][2]);
        $G = array($gridRows[0][3], $gridRows[1][3], $gridRows[2][3], $gridRows[3][3], $gridRows[4][3]);
        $O = array($gridRows[0][4], $gridRows[1][4], $gridRows[2][4], $gridRows[3][4], $gridRows[4][4]);
        $cards[] = array('card_id' => $cardId, 'B' => $B, 'I' => $I, 'N' => $N, 'G' => $G, 'O' => $O);
    }

    return $cards;
}
}

if (!function_exists('dlbh_inbox_open_connection')) {
function dlbh_inbox_open_connection($email, $password, $server, $port, $folder) {
    if (!function_exists('imap_open')) return false;
    $mailbox = '{' . $server . ':' . (int)$port . '/imap/ssl/novalidate-cert}' . $folder;
    return @imap_open($mailbox, $email, $password, OP_READONLY);
}
}

if (!function_exists('dlbh_inbox_open_connection_rw')) {
function dlbh_inbox_open_connection_rw($email, $password, $server, $port, $folder) {
    if (!function_exists('imap_open')) return false;
    $mailbox = '{' . $server . ':' . (int)$port . '/imap/ssl/novalidate-cert}' . $folder;
    return @imap_open($mailbox, $email, $password);
}
}

if (!function_exists('dlbh_inbox_open_connection_with_fallbacks')) {
function dlbh_inbox_open_connection_with_fallbacks($email, $password, $server, $port, $folders) {
    $folders = is_array($folders) ? $folders : array($folders);
    foreach ($folders as $folderName) {
        $folderName = trim((string)$folderName);
        if ($folderName === '') continue;
        $conn = dlbh_inbox_open_connection($email, $password, $server, $port, $folderName);
        if ($conn !== false) {
            return array('connection' => $conn, 'folder' => $folderName);
        }
    }
    return array('connection' => false, 'folder' => '');
}
}

if (!function_exists('dlbh_inbox_open_connection_rw_with_fallbacks')) {
function dlbh_inbox_open_connection_rw_with_fallbacks($email, $password, $server, $port, $folders) {
    $folders = is_array($folders) ? $folders : array($folders);
    foreach ($folders as $folderName) {
        $folderName = trim((string)$folderName);
        if ($folderName === '') continue;
        $conn = dlbh_inbox_open_connection_rw($email, $password, $server, $port, $folderName);
        if ($conn !== false) {
            return array('connection' => $conn, 'folder' => $folderName);
        }
    }
    return array('connection' => false, 'folder' => '');
}
}

if (!function_exists('dlbh_inbox_parse_body_fields')) {
function dlbh_inbox_parse_body_fields($bodyText, $context = array()) {
    $text = dlbh_inbox_normalize_body_text($bodyText);
    if ($text === '') return array('fields' => array(), 'primary_member' => '');

    $replyMessage = '';
    if (preg_match('/__DLBH_REPLY_START__\s*(.*?)\s*__DLBH_REPLY_END__/is', $text, $replyMarkerMatch)) {
        $replyMessage = dlbh_inbox_clean_reply_message_text((string)$replyMarkerMatch[1]);
        $text = preg_replace('/__DLBH_REPLY_START__\s*.*?\s*__DLBH_REPLY_END__\s*/is', '', $text);
        if (!is_string($text)) $text = dlbh_inbox_normalize_body_text($bodyText);
    }
    if ($replyMessage === '') {
        $replyMessage = dlbh_inbox_extract_reply_message($text);
    }

    $formText = $text;
    $formMarkers = array(
        'Pre-Enrollment Questionnaire',
        'Membership Information',
        'Contact Information',
        'Primary Member Information',
        'Dependent Information',
        'Do you or anyone your enrolling today have any allergies or food restrictions?',
    );
    $bestPos = false;
    foreach ($formMarkers as $marker) {
        $pos = stripos($text, $marker);
        if ($pos !== false && ($bestPos === false || $pos < $bestPos)) {
            $bestPos = $pos;
        }
    }
    if ($bestPos !== false && $bestPos > 0) {
        if ($replyMessage === '') {
            $replyPrefix = dlbh_inbox_clean_reply_message_text((string)substr($text, 0, (int)$bestPos));
            $looksLikeCss = (
                stripos($replyPrefix, 'wpforms') !== false ||
                stripos($replyPrefix, '@media') !== false ||
                stripos($replyPrefix, '.wpforms') !== false ||
                preg_match('/\{[^}]+\}/', $replyPrefix)
            );
            $hasReplyMarkerLater = (
                stripos($text, 'On ') !== false ||
                stripos($text, 'From:') !== false
            );
            if (
                $replyPrefix !== '' &&
                !$looksLikeCss &&
                $hasReplyMarkerLater &&
                stripos($replyPrefix, 'membership committee') === false
            ) {
            $replyMessage = $replyPrefix;
            if (!dlbh_inbox_is_real_reply_message($replyMessage)) $replyMessage = '';
        }
        }
        $formText = trim((string)substr($text, (int)$bestPos));
    }

    $linesRaw = preg_split('/\n+/', $formText);
    if (!is_array($linesRaw)) return array('fields' => array(), 'primary_member' => '');

    $lines = array();
    foreach ($linesRaw as $line) {
        $line = trim((string)$line);
        if ($line !== '') $lines[] = $line;
    }
    if (empty($lines)) return array('fields' => array(), 'primary_member' => '');

    $knownLabels = array(
        'Do you or anyone your enrolling today have any allergies or food restrictions?',
        'Have you or anyone your enrolling today served or are serving in the United States Armed Forces?',
        'Will you be enrolling a Spouse/Partner today?',
        'Will you be enrolling a Dependent today?',
        'Primary Member',
        'Commencement Date',
        'Group',
        'Family ID',
        'Class',
        'Bill Group',
        'Location Code',
        'Previous Balance',
        'Last Payment Received Amount',
        'Last Payment Received Date',
        'Remaining Previous Balance',
        'Period Start',
        'Period End',
        'Charges',
        'Total Due',
        'Due Date',
        'Grace Period End Date',
        'Delinquency Date',
        'Address',
        'City',
        'State',
        'Zip Code',
        'Country',
        'Email',
        'Phone',
        'Preferred Method of Contact',
        'Relationship',
        'Name',
        'Date of Birth',
        'T-Shirt Size',
        'Do you have any allergies or food restrictions?',
        'Have you or are you serving in the United States Armed Forces?',
    );
    $stopLabels = array(
        'Family Dues',
        'Total',
        'Item',
        'Quantity',
        'Sent from Damie Lee Burton Howard Family Reunion',
    );
    $knownLookup = array();
    foreach ($knownLabels as $lbl) $knownLookup[strtolower($lbl)] = true;
    $stopLookup = array();
    foreach ($stopLabels as $lbl) $stopLookup[strtolower($lbl)] = true;
    $knownHeaders = array(
        'pre-enrollment questionnaire' => true,
        'membership information' => true,
        'account summary information' => true,
        'contact information' => true,
        'primary member information' => true,
        'spouse information' => true,
    );
    $dependentOnlyLabels = array(
        'do you have any allergies or food restrictions?' => true,
        'have you or are you serving in the united states armed forces?' => true,
    );
    $yesNoLabels = array(
        'do you or anyone your enrolling today have any allergies or food restrictions?' => true,
        'have you or anyone your enrolling today served or are serving in the united states armed forces?' => true,
        'will you be enrolling a spouse/partner today?' => true,
        'will you be enrolling a dependent today?' => true,
        'do you have any allergies or food restrictions?' => true,
        'have you or are you serving in the united states armed forces?' => true,
    );

    $nextNonEmptyIndex = function($arr, $start) {
        $count = count($arr);
        for ($k = $start; $k < $count; $k++) {
            if (trim((string)$arr[$k]) !== '') return $k;
        }
        return -1;
    };
    $isKnownLabel = function($line) use ($knownLookup, $stopLookup, $knownHeaders) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') return false;
        if (isset($stopLookup[strtolower($trimmed)])) return true;
        if (isset($knownHeaders[strtolower($trimmed)])) return true;
        if (preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $trimmed)) return true;
        return isset($knownLookup[strtolower($trimmed)]);
    };
    $extractInlineValue = function($line, $label) {
        $line = trim((string)$line);
        $label = trim((string)$label);
        if ($line === '' || $label === '') return '';
        if (strcasecmp($line, $label) === 0) return '';
        $pattern = '/^' . preg_quote($label, '/') . '\s*[:\-]?\s*(.+)$/i';
        if (preg_match($pattern, $line, $m)) {
            return trim((string)$m[1]);
        }
        return '';
    };
        $normalizeParsedValue = function($label, $value) use ($yesNoLabels) {
            $labelKey = strtolower(trim((string)$label));
            $raw = trim((string)$value);
            if ($raw === '') return '';
            $flat = preg_replace('/\s+/', ' ', $raw);
            if (!is_string($flat)) $flat = $raw;
            $flat = trim($flat);

            if (strpos($labelKey, 'date') !== false) {
                if (preg_match('/\b([A-Z][a-z]+ \d{1,2}, \d{4})\b/', $flat, $m)) {
                    return trim((string)$m[1]);
                }
                if (preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{4})\b/', $flat, $m)) {
                    return trim((string)$m[1]);
                }
                if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $flat, $m)) {
                    return trim((string)$m[1]);
                }
            }

            if (isset($yesNoLabels[$labelKey])) {
                if (preg_match('/\b(yes|no)\b(?!.*\b(?:yes|no)\b)/i', $flat, $m)) {
                    return ucfirst(strtolower((string)$m[1]));
                }
        }

        if (strcasecmp($label, 'T-Shirt Size') === 0) {
            if (preg_match('/\b(3XL|2XL|XL|L|M|S)\b(?!.*\b(?:3XL|2XL|XL|L|M|S)\b)/i', $flat, $m)) {
                return strtoupper((string)$m[1]);
            }
        }

        if (strcasecmp($label, 'Preferred Method of Contact') === 0) {
            if (preg_match('/\b(text message|text|phone|email|call)\b(?!.*\b(?:text message|text|phone|email|call)\b)/i', $flat, $m)) {
                $v = strtolower((string)$m[1]);
                if ($v === 'text') $v = 'text message';
                return ucwords($v);
            }
        }

        return $flat;
    };

    $fields = array();
    $primaryMember = '';
    $currentRelationship = '';
    $inDependentSection = false;
    $inPersonRecord = false;
    $sectionHasAllergyQuestion = false;
    $sectionHasMilitaryQuestion = false;
    $addedContactHeader = false;
    $addedPreEnrollHeader = false;
    $addedPrimaryInfoHeader = false;
    $addedSpouseInfoHeader = false;
    $requiredAllergyLabel = 'Do you or anyone your enrolling today have any allergies or food restrictions?';
    $requiredMilitaryLabel = 'Have you or anyone your enrolling today served or are serving in the United States Armed Forces?';
    $allergyLabelKeys = array(
        strtolower($requiredAllergyLabel) => true,
        'do you have any allergies or food restrictions?' => true,
    );
    $militaryLabelKeys = array(
        strtolower($requiredMilitaryLabel) => true,
        'have you or are you serving in the united states armed forces?' => true,
    );
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $label = $lines[$i];
        $labelLower = strtolower(trim((string)$label));

        if (isset($stopLookup[$labelLower])) {
            break;
        }

        if (isset($knownHeaders[$labelLower])) {
            if ($labelLower === 'pre-enrollment questionnaire') $addedPreEnrollHeader = true;
            if ($labelLower === 'contact information') $addedContactHeader = true;
            if ($labelLower === 'primary member information') $addedPrimaryInfoHeader = true;
            if ($labelLower === 'spouse information') $addedSpouseInfoHeader = true;
            $fields[] = array('type' => 'header', 'label' => $label, 'value' => '');
            continue;
        }

        if (preg_match('/^Dependent Information(?:\s*#\d+)?\s*:?$/i', $label)) {
            $inDependentSection = true;
            $fields[] = array('type' => 'header', 'label' => $label, 'value' => '');
            continue;
        }

        if (!$addedPreEnrollHeader && strcasecmp($label, $requiredAllergyLabel) === 0) {
            $fields[] = array('type' => 'header', 'label' => 'Pre-Enrollment Questionnaire', 'value' => '');
            $addedPreEnrollHeader = true;
        }

        if (strcasecmp($label, 'Address') === 0) {
            if (!$addedContactHeader) {
                $fields[] = array('type' => 'header', 'label' => 'Contact Information', 'value' => '');
                $addedContactHeader = true;
            }
            $parts = array();
            $j = $i + 1;
            while ($j < $count && count($parts) < 5 && !$isKnownLabel($lines[$j])) {
                $parts[] = $lines[$j];
                $j++;
            }
            $cleanParts = array();
            foreach ($parts as $p) {
                $p = trim((string)$p);
                if ($p !== '') $cleanParts[] = $p;
            }

            // Submit-generated emails already carry Address/City/State/Zip/Country as separate labels.
            $nextKnownIdx = $nextNonEmptyIndex($lines, $j);
            $nextKnownLabel = ($nextKnownIdx !== -1) ? strtolower(trim((string)$lines[$nextKnownIdx])) : '';
            if (in_array($nextKnownLabel, array('city', 'state', 'zip code', 'country'), true)) {
                $streetOnly = !empty($cleanParts) ? (string)$cleanParts[0] : '';
                if ($streetOnly !== '') {
                    $fields[] = array('label' => 'Address', 'value' => $streetOnly);
                }
                $i = ($i + 1 <= $count) ? ($j - 1) : $i;
                continue;
            }

            $street = '';
            $city = '';
            $state = '';
            $zipCode = '';
            $country = '';

            if (!empty($cleanParts)) {
                $street = $cleanParts[0];
            }

            $tokenSource = implode(', ', $cleanParts);
            $tokens = preg_split('/\s*,\s*/', $tokenSource);
            $flat = array();
            if (is_array($tokens)) {
                foreach ($tokens as $t) {
                    $t = trim((string)$t);
                    if ($t !== '') $flat[] = $t;
                }
            }

            $m = count($flat);
            if ($m >= 2 && preg_match('/^[A-Za-z]{2,}$/', $flat[$m - 1])) {
                $country = 'United States of America';
            }
            if ($m >= 2 && preg_match('/^\d{5}(?:-\d{4})?$/', $flat[$m - 2])) {
                $zipCode = $flat[$m - 2];
            }
            if ($m >= 3 && preg_match('/^[A-Za-z]{2}$/', $flat[$m - 3])) {
                $state = strtoupper($flat[$m - 3]);
            }
            if ($m >= 4) {
                $city = $flat[$m - 4];
            } elseif (count($cleanParts) >= 2) {
                $cityStateRaw = $cleanParts[1];
                if (preg_match('/^\s*([^,]+)\s*,\s*([A-Za-z]{2})\s*$/', $cityStateRaw, $cityStateMatch)) {
                    $city = trim((string)$cityStateMatch[1]);
                    $state = strtoupper(trim((string)$cityStateMatch[2]));
                }
            }

            // If zip exists in the US ZIP lookup, force City/State from authoritative data.
            if ($zipCode !== '') {
                $zipLookup = dlbh_inbox_get_zip_city_state_lookup();
                if (isset($zipLookup[$zipCode]) && is_array($zipLookup[$zipCode])) {
                    $lookupCity = isset($zipLookup[$zipCode]['city']) ? trim((string)$zipLookup[$zipCode]['city']) : '';
                    $lookupStateFull = isset($zipLookup[$zipCode]['state_full']) ? trim((string)$zipLookup[$zipCode]['state_full']) : '';
                    $lookupState = isset($zipLookup[$zipCode]['state']) ? strtoupper(trim((string)$zipLookup[$zipCode]['state'])) : '';
                    if ($lookupCity !== '') $city = $lookupCity;
                    if ($lookupStateFull !== '') {
                        $state = $lookupStateFull;
                    } elseif ($lookupState !== '') {
                        $state = $lookupState;
                    }
                }
            }

            $country = 'United States of America';

            $fields[] = array('label' => 'Address', 'value' => $street);
            $fields[] = array('label' => 'City', 'value' => $city);
            $fields[] = array('label' => 'State', 'value' => $state);
            $fields[] = array('label' => 'Zip Code', 'value' => $zipCode);
            $fields[] = array('label' => 'Country', 'value' => $country);
            $i = $j - 1;
            continue;
        }

        if (strcasecmp($label, 'Relationship') === 0) {
            $inlineValue = $extractInlineValue($label, 'Relationship');
            $idx = $nextNonEmptyIndex($lines, $i + 1);
            $value = $inlineValue !== '' ? $inlineValue : (($idx !== -1) ? trim((string)$lines[$idx]) : '');
            $currentRelationship = $value;
            $inPersonRecord = true;
            $sectionHasAllergyQuestion = false;
            $sectionHasMilitaryQuestion = false;
            if (strcasecmp($value, 'Dependent') !== 0) {
                $inDependentSection = false;
            }
            if (strcasecmp($value, 'Primary Member') === 0) {
                if (!$addedPrimaryInfoHeader) {
                    $fields[] = array('type' => 'header', 'label' => 'Primary Member Information', 'value' => '');
                    $addedPrimaryInfoHeader = true;
                }
            } elseif (strcasecmp($value, 'Spouse/Partner') === 0 || strcasecmp($value, 'Spouse') === 0 || strcasecmp($value, 'Partner') === 0) {
                if (!$addedSpouseInfoHeader) {
                    $fields[] = array('type' => 'header', 'label' => 'Spouse Information', 'value' => '');
                    $addedSpouseInfoHeader = true;
                }
            }
            $fields[] = array('label' => 'Relationship', 'value' => $value);
            if ($inlineValue === '' && $idx !== -1) $i = $idx;
            continue;
        }

        if (strcasecmp($label, 'Name') === 0) {
            $inlineValue = $extractInlineValue($label, 'Name');
            $idx = $nextNonEmptyIndex($lines, $i + 1);
            $value = $inlineValue !== '' ? $inlineValue : (($idx !== -1) ? trim((string)$lines[$idx]) : '');
            $fields[] = array('label' => 'Name', 'value' => $value);
            if ($primaryMember === '' && strcasecmp($currentRelationship, 'Primary Member') === 0) {
                $primaryMember = $value;
            }
            if ($inlineValue === '' && $idx !== -1) $i = $idx;
            continue;
        }

        if ($isKnownLabel($label)) {
            if (isset($dependentOnlyLabels[$labelLower]) && !$inDependentSection && !$inPersonRecord) {
                continue;
            }
            $inlineValue = $extractInlineValue($label, $label);
            $idx = $nextNonEmptyIndex($lines, $i + 1);
            $value = '';
            $nextConsumedIndex = -1;
            if ($inlineValue !== '') {
                $value = $inlineValue;
            } elseif ($idx !== -1 && !$isKnownLabel($lines[$idx])) {
                $parts = array();
                $j = $idx;
                while ($j < $count && !$isKnownLabel($lines[$j])) {
                    $part = trim((string)$lines[$j]);
                    if ($part !== '') $parts[] = $part;
                    $j++;
                    if (count($parts) >= 6) break;
                }
                $value = trim(implode(' ', $parts));
                $nextConsumedIndex = $j - 1;
            }
            $value = $normalizeParsedValue($label, $value);

            // Preferred contact can legitimately be "Phone", which is also a known label.
            if (strcasecmp($label, 'Preferred Method of Contact') === 0 && $idx !== -1) {
                $candidate = trim((string)$lines[$idx]);
                if (in_array(strtolower($candidate), array('phone', 'email', 'text message', 'text', 'call'), true)) {
                    $value = $candidate;
                }
            }

            if ($value === '') {
                continue;
            }

            if ($inPersonRecord && isset($allergyLabelKeys[$labelLower])) {
                $sectionHasAllergyQuestion = true;
            }
            if ($inPersonRecord && isset($militaryLabelKeys[$labelLower])) {
                $sectionHasMilitaryQuestion = true;
            }

            if ($inPersonRecord && strcasecmp($label, 'T-Shirt Size') === 0) {
                if (!$sectionHasAllergyQuestion) {
                    $fields[] = array('label' => $requiredAllergyLabel, 'value' => 'No');
                    $sectionHasAllergyQuestion = true;
                }
                if (!$sectionHasMilitaryQuestion) {
                    $fields[] = array('label' => $requiredMilitaryLabel, 'value' => 'No');
                    $sectionHasMilitaryQuestion = true;
                }
            }

            $fields[] = array('label' => $label, 'value' => $value);
            if ($inlineValue === '' && $nextConsumedIndex !== -1 && $value !== '') $i = $nextConsumedIndex;
            continue;
        }
    }

    if ($primaryMember === '') {
        $primaryMember = dlbh_inbox_extract_primary_member_name($formText);
    }
    if ($primaryMember === '') {
        $primaryMember = dlbh_inbox_extract_primary_member_name($text);
    }

    $receivedDate = '';
    if (is_array($context) && isset($context['received_date'])) {
        $receivedDate = trim((string)$context['received_date']);
    }
    $fields = dlbh_inbox_insert_membership_information($fields, $primaryMember, $receivedDate);
    if ($replyMessage !== '' && dlbh_inbox_is_real_reply_message($replyMessage)) {
        array_unshift($fields,
            array('type' => 'field', 'label' => 'Message', 'value' => $replyMessage),
            array('type' => 'header', 'label' => 'Reply Message', 'value' => '')
        );
    }

    return array('fields' => $fields, 'primary_member' => $primaryMember);
}
}

if (!function_exists('dlbh_inbox_get_field_value_by_label')) {
function dlbh_inbox_get_field_value_by_label($fields, $targetLabel) {
    foreach ((array)$fields as $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        if ($type === 'header') continue;
        $label = isset($row['label']) ? trim((string)$row['label']) : '';
        $value = isset($row['value']) ? trim((string)$row['value']) : '';
        if ($label === $targetLabel && $value !== '') return $value;
    }
    return '';
}
}

if (!function_exists('dlbh_inbox_parse_timestamp_flexible')) {
function dlbh_inbox_parse_timestamp_flexible($rawDate) {
    $raw = trim((string)$rawDate);
    if ($raw === '') return false;
    $central = new DateTimeZone('America/Chicago');
    $dateOnlyFormats = array('!m/d/Y', '!n/j/Y', '!Y-m-d', '!F j, Y', '!M j, Y');
    foreach ($dateOnlyFormats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw, $central);
        if ($dt instanceof DateTime) {
            return $dt->getTimestamp();
        }
    }
    $ts = strtotime($raw);
    return ($ts !== false && $ts > 0) ? $ts : false;
}
}

if (!function_exists('dlbh_inbox_is_real_reply_message')) {
function dlbh_inbox_is_real_reply_message($value) {
    $value = trim((string)$value);
    if ($value === '') return false;
    $normalized = preg_replace('/\s+/', ' ', strtolower($value));
    $normalized = trim((string)$normalized);
    if ($normalized === '') return false;
    $blocked = array(
        'damie lee burton howard family reunion membership enrollment form',
        'damie lee burton howard family reunion membership committee',
        'membership enrollment form',
    );
    if (in_array($normalized, $blocked, true)) return false;
    $blockedPrefixes = array(
        'damie lee burt',
        'damie lee burton',
        'damie lee burton howard family reunion',
        'membership enrollment form',
        'membership committee',
    );
    foreach ($blockedPrefixes as $blockedPrefix) {
        if (strpos($normalized, $blockedPrefix) === 0) {
            return false;
        }
    }
    if (strpos($normalized, 'damie lee burton howard family reunion') !== false && strpos($normalized, 'membership enrollment form') !== false && substr_count($normalized, ' ') <= 8) {
        return false;
    }
    return true;
}
}

if (!function_exists('dlbh_inbox_clean_reply_message_text')) {
function dlbh_inbox_clean_reply_message_text($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/__DLBH_REPLY_START__\s*/i', '', $value);
    $value = preg_replace('/\s*__DLBH_REPLY_END__/i', '', $value);
    $value = preg_replace('/(?:\r\n|\r)/', "\n", $value);
    $value = preg_replace('/(?:\s*\n?\s*>\s*)+$/', '', $value);
    $value = preg_replace('/^\s*[\x{FEFF}\x{200B}]+/u', '', $value);
    $value = trim((string)$value);
    return is_string($value) ? $value : '';
}
}

if (!function_exists('dlbh_inbox_normalize_field_value_for_compare')) {
function dlbh_inbox_normalize_field_value_for_compare($label, $value) {
    $labelNorm = strtolower(trim((string)$label));
    $valueNorm = trim((string)$value);
    $valueNorm = preg_replace('/\s+/', ' ', $valueNorm);
    if (!is_string($valueNorm)) $valueNorm = trim((string)$value);

    if (strpos($labelNorm, 'date') !== false) {
        $datePlaceholder = strtolower(trim($valueNorm));
        if ($datePlaceholder === '' || $datePlaceholder === '-' || $datePlaceholder === 'n/a' || $datePlaceholder === 'na') {
            return '';
        }
        $ts = strtotime($valueNorm);
        if ($ts !== false && $ts > 0) return date('Y-m-d', $ts);
    }
    if (strpos($labelNorm, 'phone') !== false) {
        return preg_replace('/[^0-9]/', '', $valueNorm);
    }
    if (strpos($labelNorm, 'email') !== false) {
        return strtolower($valueNorm);
    }
    if (strpos($labelNorm, 'balance') !== false || strpos($labelNorm, 'charges') !== false || strpos($labelNorm, 'due') !== false || strpos($labelNorm, 'payment') !== false) {
        return (string)preg_replace('/[^0-9.\-]/', '', $valueNorm);
    }
    return strtolower(trim((string)$valueNorm));
}
}

if (!function_exists('dlbh_inbox_build_enrollment_group_key')) {
function dlbh_inbox_build_enrollment_group_key($fields) {
    if (!is_array($fields) || empty($fields)) return '';

    $parts = array();
    $activeHeader = '';
    $skipSection = false;
    foreach ($fields as $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        $label = trim((string)(isset($row['label']) ? $row['label'] : ''));
        $value = (string)(isset($row['value']) ? $row['value'] : '');
        if ($label === '') continue;

        if ($type === 'header') {
            $headerKey = strtolower($label);
            $skipSection = ($headerKey === 'membership information' || $headerKey === 'account summary information' || $headerKey === 'reply message');
            if ($skipSection) {
                $activeHeader = '';
                continue;
            }
            $activeHeader = $label;
            $parts[] = 'h|' . strtolower($label);
            continue;
        }

        if ($skipSection) continue;
        if (strcasecmp($label, 'Message') === 0 || strcasecmp($label, 'Commencement Date') === 0) continue;
        $parts[] = 'f|' . strtolower($activeHeader) . '|' . strtolower($label) . '|' . dlbh_inbox_normalize_field_value_for_compare($label, $value);
    }

    if (empty($parts)) return '';
    return md5(implode("\n", $parts));
}
}

if (!function_exists('dlbh_inbox_merge_reply_message_entries')) {
function dlbh_inbox_merge_reply_message_entries($existing, $incoming) {
    $merged = array();
    $seen = array();
    foreach (array_merge(is_array($existing) ? $existing : array(), is_array($incoming) ? $incoming : array()) as $entry) {
        if (!is_array($entry)) continue;
        $message = dlbh_inbox_clean_reply_message_text(isset($entry['message']) ? $entry['message'] : '');
        if (!dlbh_inbox_is_real_reply_message($message)) continue;
        $signature = strtolower(preg_replace('/\s+/', ' ', $message));
        if (!is_string($signature) || $signature === '' || isset($seen[$signature])) continue;
        $seen[$signature] = true;
        $entry['message'] = $message;
        $entry['received'] = isset($entry['received']) ? trim((string)$entry['received']) : '';
        $entry['received_raw'] = isset($entry['received_raw']) ? trim((string)$entry['received_raw']) : '';
        $entry['received_ts'] = isset($entry['received_ts']) ? (int)$entry['received_ts'] : 0;
        $merged[] = $entry;
    }
    usort($merged, function($a, $b) {
        $aTs = isset($a['received_ts']) ? (int)$a['received_ts'] : 0;
        $bTs = isset($b['received_ts']) ? (int)$b['received_ts'] : 0;
        if ($aTs === $bTs) return 0;
        return ($aTs > $bTs) ? -1 : 1;
    });
    return $merged;
}
}

if (!function_exists('dlbh_inbox_collect_reply_message_entries_from_row')) {
function dlbh_inbox_collect_reply_message_entries_from_row($row) {
    $entries = array();
    if (!is_array($row)) return $entries;
    $received = isset($row['received']) ? trim((string)$row['received']) : '';
    $receivedRaw = isset($row['received_raw']) ? trim((string)$row['received_raw']) : '';
    $receivedTs = isset($row['received_ts']) ? (int)$row['received_ts'] : 0;

    if (isset($row['reply_messages']) && is_array($row['reply_messages']) && !empty($row['reply_messages'])) {
        return dlbh_inbox_merge_reply_message_entries(array(), $row['reply_messages']);
    }

    $bestMessage = '';
    if (isset($row['fields']) && is_array($row['fields'])) {
        $bestMessage = dlbh_inbox_choose_better_reply_message($bestMessage, dlbh_inbox_get_reply_message_value($row['fields']));
    }
    if (isset($row['body_text'])) {
        $bestMessage = dlbh_inbox_choose_better_reply_message($bestMessage, dlbh_inbox_extract_best_reply_message((string)$row['body_text']));
        $bestMessage = dlbh_inbox_expand_reply_message_from_body($bestMessage, (string)$row['body_text']);
    }
    if ($bestMessage !== '' && dlbh_inbox_is_real_reply_message($bestMessage)) {
        $entries[] = array(
            'message' => $bestMessage,
            'received' => $received,
            'received_raw' => $receivedRaw,
            'received_ts' => $receivedTs,
        );
    }
    return dlbh_inbox_merge_reply_message_entries(array(), $entries);
}
}

if (!function_exists('dlbh_inbox_group_membership_rows')) {
function dlbh_inbox_group_membership_rows($rows) {
    if (!is_array($rows) || empty($rows)) return array();

    $grouped = array();
    $groupOrder = array();
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $fields = isset($row['fields']) && is_array($row['fields']) ? $row['fields'] : array();
        $groupKey = dlbh_inbox_build_enrollment_group_key($fields);
        if ($groupKey === '') {
            $groupKey = 'msg:' . (string)(isset($row['msg_num']) ? (int)$row['msg_num'] : count($groupOrder));
        }
        $replyEntries = dlbh_inbox_collect_reply_message_entries_from_row($row);

        if (!isset($grouped[$groupKey])) {
            $row['reply_messages'] = $replyEntries;
            $row['group_msg_nums'] = array(isset($row['msg_num']) ? (int)$row['msg_num'] : 0);
            $grouped[$groupKey] = $row;
            $groupOrder[] = $groupKey;
            continue;
        }

        $existing = $grouped[$groupKey];
        $existing['reply_messages'] = dlbh_inbox_merge_reply_message_entries(
            isset($existing['reply_messages']) ? $existing['reply_messages'] : array(),
            $replyEntries
        );

        $existingMsgNums = isset($existing['group_msg_nums']) && is_array($existing['group_msg_nums']) ? $existing['group_msg_nums'] : array();
        $existingMsgNums[] = isset($row['msg_num']) ? (int)$row['msg_num'] : 0;
        $existing['group_msg_nums'] = array_values(array_unique(array_map('intval', $existingMsgNums)));

        $existingTs = isset($existing['received_ts']) ? (int)$existing['received_ts'] : 0;
        $rowTs = isset($row['received_ts']) ? (int)$row['received_ts'] : 0;
        if ($rowTs > $existingTs) {
            $row['reply_messages'] = $existing['reply_messages'];
            $row['group_msg_nums'] = $existing['group_msg_nums'];
            $grouped[$groupKey] = $row;
        } else {
            $grouped[$groupKey] = $existing;
        }
    }

    $out = array();
    foreach ($groupOrder as $groupKey) {
        if (isset($grouped[$groupKey]) && is_array($grouped[$groupKey])) $out[] = $grouped[$groupKey];
    }
    return $out;
}
}

if (!function_exists('dlbh_inbox_get_row_message_numbers')) {
function dlbh_inbox_get_row_message_numbers($row) {
    $msgNums = array();
    if (!is_array($row)) return $msgNums;
    if (isset($row['group_msg_nums']) && is_array($row['group_msg_nums'])) {
        $msgNums = $row['group_msg_nums'];
    } elseif (isset($row['msg_num'])) {
        $msgNums = array($row['msg_num']);
    }
    $msgNums = array_values(array_filter(array_unique(array_map('intval', $msgNums)), function($value) {
        return $value > 0;
    }));
    rsort($msgNums, SORT_NUMERIC);
    return $msgNums;
}
}

if (!function_exists('dlbh_inbox_move_row_messages')) {
function dlbh_inbox_move_row_messages($rw, $row, $targetFolder, $fallbackFolder) {
    if (!$rw || !is_array($row)) return false;
    $msgNums = dlbh_inbox_get_row_message_numbers($row);
    if (empty($msgNums)) return false;

    $allMoved = true;
    foreach ($msgNums as $msgNum) {
        @imap_setflag_full($rw, (string)$msgNum, "\\Seen");
        $moved = @imap_mail_move($rw, (string)$msgNum, $targetFolder);
        if (!$moved) $moved = @imap_mail_move($rw, (string)$msgNum, $fallbackFolder);
        if (!$moved) $allMoved = false;
    }
    if ($allMoved) {
        @imap_expunge($rw);
    }
    return $allMoved;
}
}

if (!function_exists('dlbh_inbox_get_class_code_for_counts')) {
function dlbh_inbox_get_class_code_for_counts($spouseCount, $dependentCount) {
    $sp = (int)$spouseCount;
    $dp = (int)$dependentCount;
    if ($sp > 0 && $dp > 0) return 'A004';
    if ($sp > 0) return 'A002';
    if ($dp > 0) return 'A003';
    return 'A001';
}
}

if (!function_exists('dlbh_inbox_get_class_label_from_code')) {
function dlbh_inbox_get_class_label_from_code($classCode) {
    $classCode = strtoupper(trim((string)$classCode));
    if ($classCode === 'A004') return 'A004 All Eligible Primary Members and Family';
    if ($classCode === 'A003') return 'A003 All Eligible Primary Members and Dependents';
    if ($classCode === 'A002') return 'A002 All Eligible Primary Members and Spouses';
    return 'A001 All Eligible Primary Members';
}
}

if (!function_exists('dlbh_inbox_state_abbreviation')) {
function dlbh_inbox_state_abbreviation($stateValue) {
    $stateValue = trim((string)$stateValue);
    if ($stateValue === '') return '';
    $states = array(
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia'
    );
    $upper = strtoupper($stateValue);
    if (isset($states[$upper])) return $upper;
    foreach ($states as $abbr => $fullName) {
        if (strcasecmp($fullName, $stateValue) === 0) return $abbr;
    }
    return $upper;
}
}

if (!function_exists('dlbh_inbox_multiply_big_integers')) {
function dlbh_inbox_multiply_big_integers($a, $b) {
    $a = ltrim((string)$a, '0');
    $b = ltrim((string)$b, '0');
    if ($a === '' || $b === '') return '0';
    $aLen = strlen($a);
    $bLen = strlen($b);
    $result = array_fill(0, $aLen + $bLen, 0);
    for ($i = $aLen - 1; $i >= 0; $i--) {
        for ($j = $bLen - 1; $j >= 0; $j--) {
            $mul = ((int)$a[$i]) * ((int)$b[$j]);
            $sum = $mul + $result[$i + $j + 1];
            $result[$i + $j + 1] = $sum % 10;
            $result[$i + $j] += (int)floor($sum / 10);
        }
    }
    $product = ltrim(implode('', $result), '0');
    return $product === '' ? '0' : $product;
}
}

if (!function_exists('dlbh_inbox_get_family_id')) {
function dlbh_inbox_get_family_id($factors) {
    if (!is_array($factors) || empty($factors)) return '';
    $cleanFactors = array();
    foreach ($factors as $factor) {
        $digits = preg_replace('/[^0-9]/', '', (string)$factor);
        if ($digits !== '') $cleanFactors[] = $digits;
    }
    if (empty($cleanFactors)) return '';
    $product = '1';
    foreach ($cleanFactors as $factor) {
        $product = dlbh_inbox_multiply_big_integers($product, $factor);
    }
    return 'DLBHF-' . str_pad(substr($product, -5), 5, '0', STR_PAD_LEFT);
}
}

if (!function_exists('dlbh_inbox_build_membership_info_fields')) {
function dlbh_inbox_build_membership_info_fields($fields, $primaryMember, $receivedDate) {
    $spouseCount = 0;
    $dependentCount = 0;
    foreach ((array)$fields as $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        if ($type === 'header') continue;
        $label = isset($row['label']) ? trim((string)$row['label']) : '';
        $value = isset($row['value']) ? trim((string)$row['value']) : '';
        if (strcasecmp($label, 'Relationship') !== 0) continue;
        $rel = strtolower($value);
        if ($rel === 'dependent') $dependentCount++;
        if ($rel === 'spouse/partner' || $rel === 'spouse' || $rel === 'partner') $spouseCount++;
    }

    $zip = dlbh_inbox_get_field_value_by_label($fields, 'Zip Code');
    $state = dlbh_inbox_get_field_value_by_label($fields, 'State');
    $phone = dlbh_inbox_get_field_value_by_label($fields, 'Phone');
    $stateAbbr = dlbh_inbox_state_abbreviation($state);
    $zipDigits = preg_replace('/[^0-9]/', '', (string)$zip);
    $locationCode = ($stateAbbr !== '' && $zipDigits !== '') ? ($stateAbbr . $zipDigits) : '';

    $commencementRaw = dlbh_inbox_get_field_value_by_label($fields, 'Commencement Date');
    if ($commencementRaw === '') {
        $commencementRaw = dlbh_inbox_get_field_value($fields, 'Commencement Date');
    }
    if ($commencementRaw === '') $commencementRaw = trim((string)$receivedDate);
    $commencementTs = dlbh_inbox_parse_timestamp_flexible($commencementRaw);
    $commencementDisplay = $commencementTs ? date('F j, Y', $commencementTs) : trim((string)$commencementRaw);
    $commencementDateForId = $commencementTs ? date('mdY', $commencementTs) : '';
    $phoneDigits = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($phoneDigits) > 10) $phoneDigits = substr($phoneDigits, -10);

    $familyId = dlbh_inbox_get_family_id(array($commencementDateForId, $phoneDigits, $zipDigits));
    $classCode = dlbh_inbox_get_class_code_for_counts($spouseCount, $dependentCount);
    $classLabel = dlbh_inbox_get_class_label_from_code($classCode);
    $billGroup = $commencementTs ? str_pad(date('j', $commencementTs), 4, '0', STR_PAD_LEFT) : '';

    return array(
        array('label' => 'Primary Member', 'value' => trim((string)$primaryMember)),
        array('label' => 'Commencement Date', 'value' => $commencementDisplay),
        array('label' => 'Group', 'value' => 'G000DLBHF'),
        array('label' => 'Family ID', 'value' => $familyId),
        array('label' => 'Class', 'value' => $classLabel),
        array('label' => 'Bill Group', 'value' => $billGroup),
        array('label' => 'Location Code', 'value' => $locationCode),
    );
}
}

if (!function_exists('dlbh_inbox_build_account_summary_fields')) {
function dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $statementOffset = 0, $statementLabel = '') {
    $commencementRaw = dlbh_inbox_get_field_value_by_label($fields, 'Commencement Date');
    if ($commencementRaw === '') {
        $commencementRaw = dlbh_inbox_get_field_value($fields, 'Commencement Date');
    }
    if ($commencementRaw === '') $commencementRaw = trim((string)$receivedDate);
    $commencementTs = dlbh_inbox_parse_timestamp_flexible($commencementRaw);
    $statementOffset = max(0, (int)$statementOffset);
    $statementLabel = trim((string)$statementLabel);
    $familyId = trim((string)dlbh_inbox_get_field_value_by_label($fields, 'Family ID'));
    if ($familyId === '') {
        $familyId = trim((string)dlbh_inbox_get_field_value($fields, 'Family ID'));
    }

    $statementDate = '';
    $periodStart = '';
    $periodEnd = '';
    $dueDate = '';
    $graceEnd = '';
    $delinquencyDate = '';
    if ($commencementTs) {
        $commencement = new DateTimeImmutable('@' . $commencementTs);
        $commencement = $commencement->setTimezone(new DateTimeZone('America/Chicago'));
        $anchorDay = (int)$commencement->format('j');
        $resolveCycleDate = function(DateTimeImmutable $seedMonth, $dayOfMonth) {
            $monthStart = $seedMonth->modify('first day of this month')->setTime(0, 0, 0);
            $daysInMonth = (int)$monthStart->format('t');
            $resolvedDay = min((int)$dayOfMonth, $daysInMonth);
            return $monthStart->setDate(
                (int)$monthStart->format('Y'),
                (int)$monthStart->format('n'),
                $resolvedDay
            );
        };

        if ($statementOffset === 0) {
            $statementDateObj = $commencement;
            $dueDateObj = $commencement;
            $periodStartObj = $commencement;
            $periodEndObj = $resolveCycleDate($commencement->modify('+1 month'), $anchorDay)->modify('-1 day');
        } else {
            $dueDateObj = $resolveCycleDate($commencement->modify('+' . $statementOffset . ' month'), $anchorDay);
            $statementDateObj = $dueDateObj->modify('-15 days');
            $periodStartObj = $dueDateObj;
            $periodEndObj = $resolveCycleDate($commencement->modify('+' . ($statementOffset + 1) . ' month'), $anchorDay)->modify('-1 day');
        }

        $statementDate = $statementDateObj->format('F j, Y');
        $periodStart = $periodStartObj->format('F j, Y');
        $periodEnd = $periodEndObj->format('F j, Y');
        $dueDate = $dueDateObj->format('F j, Y');
        $graceEndObj = $dueDateObj->modify('+15 days');
        $graceEnd = $graceEndObj->format('F j, Y');
        $delinquencyDate = $dueDateObj->modify('+30 days')->format('F j, Y');
        if ($statementLabel === '') {
            $statementLabel = $dueDateObj->format('F Y');
        }
    }

    if ($statementLabel === '') $statementLabel = 'Notional';

    return array(
        array('label' => 'Family ID', 'value' => $familyId),
        array('label' => 'Statement', 'value' => $statementLabel),
        array('label' => 'Status', 'value' => 'Current'),
        array('label' => 'Statement Date', 'value' => $statementDate),
        array('label' => 'Previous Balance', 'value' => '$0.00'),
        array('label' => 'Last Payment Received Amount', 'value' => '$0.00'),
        array('label' => 'Last Payment Received Date', 'value' => '-'),
        array('label' => 'Remaining Previous Balance', 'value' => '$0.00'),
        array('label' => 'Period Start', 'value' => $periodStart),
        array('label' => 'Period End', 'value' => $periodEnd),
        array('label' => 'Charges', 'value' => '$20.00'),
        array('label' => 'Total Due', 'value' => '$20.00'),
        array('label' => 'Due Date', 'value' => $dueDate),
        array('label' => 'Grace Period End Date', 'value' => $graceEnd),
        array('label' => 'Delinquency Date', 'value' => $delinquencyDate),
    );
}
}

if (!function_exists('dlbh_inbox_get_billing_status')) {
function dlbh_inbox_get_billing_status($fields, $receivedDate, $statementOffset, $statementLabel, $statementDateRaw, $dueDateRaw, $graceEndRaw, $delinquencyDateRaw, $previousBalanceAmount, $dueBalanceAmount, $graceBalanceAmount, $delinquencyBalanceAmount, $monthlyCharge) {
    $statementLabel = trim((string)$statementLabel);
    if (strcasecmp($statementLabel, 'Notional') === 0) return 'Notional';

    $statementTs = dlbh_inbox_parse_timestamp_flexible($statementDateRaw);
    $dueDateTs = dlbh_inbox_parse_timestamp_flexible($dueDateRaw);
    $graceEndTs = dlbh_inbox_parse_timestamp_flexible($graceEndRaw);
    $delinquencyTs = dlbh_inbox_parse_timestamp_flexible($delinquencyDateRaw);

    if ($delinquencyTs && $statementTs && $statementTs >= $delinquencyTs && (float)$delinquencyBalanceAmount >= 20.0) {
        return 'Delinquent';
    }
    if ($graceEndTs && $statementTs && $statementTs >= $graceEndTs && (float)$graceBalanceAmount >= 20.0) {
        return 'Past Due';
    }
    if ($dueDateTs && $statementTs && $statementTs >= $dueDateTs && (float)$dueBalanceAmount <= 20.0) {
        return 'Current';
    }

    $monthlyCharge = max(0.01, (float)$monthlyCharge);
    $previousBalanceAmount = (float)$previousBalanceAmount;
    if ($previousBalanceAmount >= 20.0 && $statementTs) {
        $priorCycleCount = (int)floor(($previousBalanceAmount + 0.0001) / $monthlyCharge);
        if ($priorCycleCount > 0) {
            $oldestOutstandingOffset = max(0, (int)$statementOffset - $priorCycleCount);
            $oldestCycleFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $oldestOutstandingOffset);
            $oldestGraceRaw = trim((string)dlbh_inbox_get_field_value_by_label($oldestCycleFields, 'Grace Period End Date'));
            $oldestDelinquencyRaw = trim((string)dlbh_inbox_get_field_value_by_label($oldestCycleFields, 'Delinquency Date'));
            $oldestGraceTs = dlbh_inbox_parse_timestamp_flexible($oldestGraceRaw);
            $oldestDelinquencyTs = dlbh_inbox_parse_timestamp_flexible($oldestDelinquencyRaw);
            if ($oldestDelinquencyTs && $statementTs >= $oldestDelinquencyTs) {
                return 'Delinquent';
            }
            if ($oldestGraceTs && $statementTs >= $oldestGraceTs) {
                return 'Past Due';
            }
        }
    }
    return 'Current';
}
}

if (!function_exists('dlbh_inbox_get_balance_as_of_date')) {
function dlbh_inbox_get_balance_as_of_date($fields, $receivedDate, $stripeRows, $targetDateRaw, $monthlyCharge = 20.0) {
    $targetTs = dlbh_inbox_parse_timestamp_flexible($targetDateRaw);
    if (!$targetTs) return 0.0;

    $monthlyCharge = (float)$monthlyCharge;
    $familyId = dlbh_inbox_normalize_family_id_lookup_key(dlbh_inbox_get_field_value_by_label($fields, 'Family ID'));
    $paymentsTotal = 0.0;
    if ($familyId !== '' && is_array($stripeRows)) {
        foreach ($stripeRows as $stripeRow) {
            if (!is_array($stripeRow)) continue;
            $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
            if ($stripeFamilyId === '' || $stripeFamilyId !== $familyId) continue;
            $paymentDateRaw = isset($stripeRow['Payment Received Date']) ? (string)$stripeRow['Payment Received Date'] : '';
            $paymentTs = dlbh_inbox_parse_timestamp_flexible($paymentDateRaw);
            if (!$paymentTs || $paymentTs > $targetTs) continue;
            $paymentsTotal += isset($stripeRow['_amount_numeric']) ? (float)$stripeRow['_amount_numeric'] : 0.0;
        }
    }

    $chargesTotal = 0.0;
    for ($i = 0; $i <= 240; $i++) {
        $cycleFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $i);
        $dueDateRaw = trim((string)dlbh_inbox_get_field_value_by_label($cycleFields, 'Due Date'));
        $dueDateTs = dlbh_inbox_parse_timestamp_flexible($dueDateRaw);
        if (!$dueDateTs) {
            break;
        }
        if ($dueDateTs > $targetTs) {
            break;
        }
        $chargesTotal += $monthlyCharge;
    }

    return $chargesTotal - $paymentsTotal;
}
}

if (!function_exists('dlbh_inbox_get_payment_statement_offset')) {
function dlbh_inbox_get_payment_statement_offset($fields, $receivedDate, $paymentDateRaw, $maxStatementWindow = 240) {
    $paymentTs = dlbh_inbox_parse_timestamp_flexible($paymentDateRaw);
    if (!$paymentTs) return 0;

    $maxStatementWindow = max(1, (int)$maxStatementWindow);
    $statementWindows = array();
    for ($i = 0; $i <= $maxStatementWindow; $i++) {
        $cycleFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $i);
        $cycleStatementDate = trim((string)dlbh_inbox_get_field_value_by_label($cycleFields, 'Statement Date'));
        $statementWindows[$i] = dlbh_inbox_parse_timestamp_flexible($cycleStatementDate);
    }

    $assignedOffset = null;
    $initialStatementTs = isset($statementWindows[0]) ? (int)$statementWindows[0] : 0;
    if ($initialStatementTs > 0 && $paymentTs < $initialStatementTs) {
        $assignedOffset = 1;
    }

    for ($i = 0; $assignedOffset === null && $i <= $maxStatementWindow; $i++) {
        $cycleStatementTs = isset($statementWindows[$i]) ? (int)$statementWindows[$i] : 0;
        if ($cycleStatementTs > 0 && $paymentTs <= $cycleStatementTs) {
            $assignedOffset = $i;
            break;
        }
    }

    if ($assignedOffset === null) {
        $assignedOffset = $maxStatementWindow;
    }

    return max(0, (int)$assignedOffset);
}
}

if (!function_exists('dlbh_inbox_build_account_summary_fields_with_payments')) {
function dlbh_inbox_build_account_summary_fields_with_payments($fields, $receivedDate, $statementOffset, $stripeRows, $statementLabel = '') {
    $summaryFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $statementOffset, $statementLabel);
    $familyId = dlbh_inbox_normalize_family_id_lookup_key(dlbh_inbox_get_field_value_by_label($fields, 'Family ID'));
    $monthlyCharge = 20.0;
    $statementOffset = max(0, (int)$statementOffset);
    $lastPaymentDate = '';
    $lastPaymentTs = 0;
    $statementPaymentTotal = 0.0;
    $previousBalance = 0.0;
    $remainingPreviousBalance = 0.0;
    $totalDue = $monthlyCharge;
    $currentCycleCharges = $monthlyCharge;
    $statusValue = 'Current';

    $maxStatementWindow = max(12, $statementOffset + 1);
    $statementWindows = array();
    for ($i = 0; $i <= $maxStatementWindow; $i++) {
        $cycleFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $i);
        $cycleStatementDate = trim((string)dlbh_inbox_get_field_value_by_label($cycleFields, 'Statement Date'));
        $statementWindows[$i] = dlbh_inbox_parse_timestamp_flexible($cycleStatementDate);
    }

    $familyPayments = array();
    if ($familyId !== '' && is_array($stripeRows) && !empty($stripeRows)) {
        foreach ($stripeRows as $stripeRow) {
            if (!is_array($stripeRow)) continue;
            $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
            if ($stripeFamilyId === '' || $stripeFamilyId !== $familyId) continue;
            $paymentDateRaw = isset($stripeRow['Payment Received Date']) ? (string)$stripeRow['Payment Received Date'] : '';
            $paymentTs = dlbh_inbox_parse_timestamp_flexible($paymentDateRaw);
            if (!$paymentTs) continue;
            $familyPayments[] = array(
                'ts' => $paymentTs,
                'amount' => isset($stripeRow['_amount_numeric']) ? (float)$stripeRow['_amount_numeric'] : 0.0,
                'date' => $paymentDateRaw,
            );
        }
    }
    usort($familyPayments, function($a, $b) {
        return (int)$a['ts'] - (int)$b['ts'];
    });

    $paymentsByStatement = array();
    foreach ($familyPayments as $familyPayment) {
        $paymentTs = isset($familyPayment['ts']) ? (int)$familyPayment['ts'] : 0;
        if ($paymentTs <= 0) continue;
        $assignedOffset = dlbh_inbox_get_payment_statement_offset(
            $fields,
            $receivedDate,
            isset($familyPayment['date']) ? (string)$familyPayment['date'] : '',
            $maxStatementWindow
        );
        if (!isset($paymentsByStatement[$assignedOffset])) {
            $paymentsByStatement[$assignedOffset] = array();
        }
        $paymentsByStatement[$assignedOffset][] = $familyPayment;
    }

    $runningPreviousBalance = 0.0;
    for ($i = 0; $i <= $statementOffset; $i++) {
        $paymentsThisCycle = 0.0;

        $cyclePayments = isset($paymentsByStatement[$i]) && is_array($paymentsByStatement[$i])
            ? $paymentsByStatement[$i]
            : array();
        foreach ($cyclePayments as $familyPayment) {
            $paymentTs = isset($familyPayment['ts']) ? (int)$familyPayment['ts'] : 0;
            $paymentAmount = isset($familyPayment['amount']) ? (float)$familyPayment['amount'] : 0.0;
            $paymentsThisCycle += $paymentAmount;
            if ($i === $statementOffset && $paymentTs >= $lastPaymentTs) {
                $lastPaymentTs = $paymentTs;
                $lastPaymentDate = isset($familyPayment['date']) ? (string)$familyPayment['date'] : '';
            }
        }

        $cyclePreviousBalance = $runningPreviousBalance;
        $cycleRemainingPreviousBalance = $cyclePreviousBalance - $paymentsThisCycle;
        $cycleCurrentChargesDue = $monthlyCharge;
        $cycleTotalDue = $cycleRemainingPreviousBalance + $cycleCurrentChargesDue;

        if ($i === $statementOffset) {
            $statementPaymentTotal = $paymentsThisCycle;
            $previousBalance = $cyclePreviousBalance;
            $remainingPreviousBalance = $cycleRemainingPreviousBalance;
            $totalDue = $cycleTotalDue;
        }

        $runningPreviousBalance = $cycleTotalDue;
    }

    foreach ($summaryFields as &$summaryField) {
        if (!is_array($summaryField)) continue;
        $label = isset($summaryField['label']) ? trim((string)$summaryField['label']) : '';
        if ($label === 'Previous Balance') {
            $summaryField['value'] = dlbh_inbox_format_currency_value((string)$previousBalance);
        } elseif ($label === 'Status') {
            $statementLabelRaw = trim((string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Statement'));
            if (strcasecmp($statementLabelRaw, 'Notional') === 0) {
                $statusValue = 'Notional';
            } else {
                $statusBucket = dlbh_inbox_get_aging_bucket_for_summary($summaryFields, date('F j, Y'), $totalDue);
                if ($statusBucket === 'delinquent') {
                    $statusValue = 'Delinquent';
                } elseif ($statusBucket === 'past_due_1_15' || $statusBucket === 'past_due_16_30') {
                    $statusValue = 'Past Due';
                } else {
                    $statusValue = 'Current';
                }
            }
            $summaryField['value'] = $statusValue;
        } elseif ($label === 'Last Payment Received Amount') {
            $summaryField['value'] = dlbh_inbox_format_currency_value((string)$statementPaymentTotal);
        } elseif ($label === 'Last Payment Received Date') {
            $summaryField['value'] = ($lastPaymentDate !== '' ? $lastPaymentDate : '-');
        } elseif ($label === 'Remaining Previous Balance') {
            $summaryField['value'] = dlbh_inbox_format_currency_value((string)$remainingPreviousBalance);
        } elseif ($label === 'Charges') {
            $summaryField['value'] = dlbh_inbox_format_currency_value((string)$currentCycleCharges);
        } elseif ($label === 'Total Due') {
            $summaryField['value'] = dlbh_inbox_format_currency_value((string)$totalDue);
        }
    }
    unset($summaryField);

    return $summaryFields;
}
}

if (!function_exists('dlbh_inbox_get_latest_family_payment')) {
function dlbh_inbox_get_latest_family_payment($fields, $stripeRows) {
    $latest = array(
        'amount' => '$0.00',
        'date_raw' => '-',
        'date_display' => 'N/A',
        'ts' => 0,
    );
    $familyId = dlbh_inbox_normalize_family_id_lookup_key(dlbh_inbox_get_field_value_by_label($fields, 'Family ID'));
    if ($familyId === '' || !is_array($stripeRows) || empty($stripeRows)) return $latest;

    foreach ($stripeRows as $stripeRow) {
        if (!is_array($stripeRow)) continue;
        $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
        if ($stripeFamilyId === '' || $stripeFamilyId !== $familyId) continue;
        $paymentDateRaw = isset($stripeRow['Payment Received Date']) ? (string)$stripeRow['Payment Received Date'] : '';
        $paymentTs = dlbh_inbox_parse_timestamp_flexible($paymentDateRaw);
        if (!$paymentTs || $paymentTs < (int)$latest['ts']) continue;
        $latest = array(
            'amount' => isset($stripeRow['Payment Received Amount']) ? (string)$stripeRow['Payment Received Amount'] : '$0.00',
            'date_raw' => $paymentDateRaw,
            'date_display' => ($paymentDateRaw !== '' ? dlbh_inbox_format_date_friendly($paymentDateRaw) : 'N/A'),
            'ts' => (int)$paymentTs,
        );
    }

    return $latest;
}
}

if (!function_exists('dlbh_inbox_get_family_payments_total')) {
function dlbh_inbox_get_family_payments_total($fields, $stripeRows) {
    $familyId = dlbh_inbox_normalize_family_id_lookup_key(dlbh_inbox_get_field_value_by_label($fields, 'Family ID'));
    if ($familyId === '' || !is_array($stripeRows) || empty($stripeRows)) return 0.0;

    $total = 0.0;
    foreach ($stripeRows as $stripeRow) {
        if (!is_array($stripeRow)) continue;
        $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
        if ($stripeFamilyId === '' || $stripeFamilyId !== $familyId) continue;
        $total += isset($stripeRow['_amount_numeric']) ? (float)$stripeRow['_amount_numeric'] : 0.0;
    }

    return $total;
}
}

if (!function_exists('dlbh_inbox_get_subscription_plan_label_for_amount')) {
function dlbh_inbox_get_subscription_plan_label_for_amount($amount) {
    $amount = (float)$amount;
    if (abs($amount - 20.0) < 0.01) return '$20.00/Monthly';
    if (abs($amount - 60.0) < 0.01) return '$60.00/Quarterly';
    if (abs($amount - 120.0) < 0.01) return '$120.00/Semi-Annually';
    if (abs($amount - 240.0) < 0.01) return '$240.00/Annually';
    return '';
}
}

if (!function_exists('dlbh_inbox_get_subscription_interval_months_for_amount')) {
function dlbh_inbox_get_subscription_interval_months_for_amount($amount) {
    $amount = (float)$amount;
    if (abs($amount - 20.0) < 0.01) return 1;
    if (abs($amount - 60.0) < 0.01) return 3;
    if (abs($amount - 120.0) < 0.01) return 6;
    if (abs($amount - 240.0) < 0.01) return 12;
    return 0;
}
}

if (!function_exists('dlbh_inbox_get_family_subscription_plan_details')) {
function dlbh_inbox_get_family_subscription_plan_details($fields, $stripeRows) {
    $details = array(
        'plan' => '',
        'next_withdrawal_date' => '',
    );

    $familyId = dlbh_inbox_normalize_family_id_lookup_key(dlbh_inbox_get_field_value_by_label($fields, 'Family ID'));
    if ($familyId === '') return $details;
    if (!is_array($stripeRows) || empty($stripeRows)) {
        $details['plan'] = 'One Time Payment';
        return $details;
    }

    $latestSubscriptionRow = null;
    foreach ($stripeRows as $stripeRow) {
        if (!is_array($stripeRow)) continue;
        $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
        if ($stripeFamilyId === '' || $stripeFamilyId !== $familyId) continue;
        $description = strtolower(trim((string)(isset($stripeRow['Description']) ? $stripeRow['Description'] : '')));
        if ($description !== 'subscription creation') continue;
        if ($latestSubscriptionRow === null) {
            $latestSubscriptionRow = $stripeRow;
            continue;
        }
        $existingTs = isset($latestSubscriptionRow['_sort_ts']) ? (int)$latestSubscriptionRow['_sort_ts'] : 0;
        $candidateTs = isset($stripeRow['_sort_ts']) ? (int)$stripeRow['_sort_ts'] : 0;
        if ($candidateTs >= $existingTs) {
            $latestSubscriptionRow = $stripeRow;
        }
    }

    if (!is_array($latestSubscriptionRow)) {
        $details['plan'] = 'One Time Payment';
        return $details;
    }

    $amount = isset($latestSubscriptionRow['_amount_numeric']) ? (float)$latestSubscriptionRow['_amount_numeric'] : 0.0;
    $details['plan'] = dlbh_inbox_get_subscription_plan_label_for_amount($amount);

    $intervalMonths = dlbh_inbox_get_subscription_interval_months_for_amount($amount);
    $createdTs = isset($latestSubscriptionRow['_sort_ts']) ? (int)$latestSubscriptionRow['_sort_ts'] : 0;
    if ($intervalMonths > 0 && $createdTs > 0) {
        $nextWithdrawalTs = strtotime('+' . $intervalMonths . ' months', $createdTs);
        if ($nextWithdrawalTs !== false && (int)$nextWithdrawalTs > 0) {
            $details['next_withdrawal_date'] = date('F j, Y', (int)$nextWithdrawalTs);
        }
    }

    return $details;
}
}

if (!function_exists('dlbh_inbox_get_aging_bucket_for_summary')) {
function dlbh_inbox_get_aging_bucket_for_summary($summaryFields, $asOfDateRaw = '', $balanceOverride = null) {
    $totalDue = 0.0;
    if ($balanceOverride !== null && $balanceOverride !== '') {
        $totalDue = (float)$balanceOverride;
    } else {
        $totalDueRaw = trim((string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Total Due'));
        $totalDue = (float)preg_replace('/[^0-9.\-]/', '', $totalDueRaw);
    }
    if ($totalDue <= 0.0) return 'current';

    $asOfTs = dlbh_inbox_parse_timestamp_flexible($asOfDateRaw);
    if (!$asOfTs) $asOfTs = dlbh_inbox_parse_timestamp_flexible(date('F j, Y'));
    $dueTs = dlbh_inbox_parse_timestamp_flexible(dlbh_inbox_get_field_value_by_label($summaryFields, 'Due Date'));
    // Specific exception: if today is the due date and balance is $20 (or less), keep as Current.
    if ($dueTs && $asOfTs && $asOfTs <= $dueTs && $totalDue <= 20.0) return 'current';

    if ($totalDue >= 60.0) return 'delinquent';
    if ($totalDue >= 40.0) return 'past_due_16_30';
    if ($totalDue >= 20.0) return 'past_due_1_15';
    if ($totalDue > 0.0) return 'past_due_1_15';

    $status = strtolower(trim((string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Status')));
    if ($status === 'current') return 'current';
    if ($status === 'delinquent') return 'delinquent';
    $graceTs = dlbh_inbox_parse_timestamp_flexible(dlbh_inbox_get_field_value_by_label($summaryFields, 'Grace Period End Date'));
    $delinquencyTs = dlbh_inbox_parse_timestamp_flexible(dlbh_inbox_get_field_value_by_label($summaryFields, 'Delinquency Date'));

    if ($delinquencyTs && $asOfTs >= $delinquencyTs) return 'delinquent';
    if ($status === 'past due') {
        if ($graceTs && $asOfTs <= $graceTs) return 'past_due_1_15';
        if ($delinquencyTs && $asOfTs < $delinquencyTs) return 'past_due_16_30';
        return 'past_due_1_15';
    }
    if ($dueTs && $graceTs && $asOfTs > $dueTs && $asOfTs <= $graceTs) return 'past_due_1_15';
    if ($graceTs && $delinquencyTs && $asOfTs > $graceTs && $asOfTs < $delinquencyTs) return 'past_due_16_30';
    if ($graceTs && !$delinquencyTs && $asOfTs > $graceTs) return 'past_due_16_30';
    if ($dueTs && !$graceTs && $asOfTs > $dueTs) return 'past_due_1_15';
    return 'current';
}
}

if (!function_exists('dlbh_inbox_build_aging_report_rows')) {
function dlbh_inbox_build_aging_report_rows($rosterRowsByFamilyId, $stripeRows) {
    $sections = array(
        'current' => array(),
        'past_due_1_15' => array(),
        'past_due_16_30' => array(),
        'delinquent' => array(),
    );
    if (!is_array($rosterRowsByFamilyId) || empty($rosterRowsByFamilyId)) return $sections;

    foreach ($rosterRowsByFamilyId as $familyId => $rosterRow) {
        if (!is_array($rosterRow)) continue;
        $profileFields = isset($rosterRow['Profile Fields']) && is_array($rosterRow['Profile Fields']) ? $rosterRow['Profile Fields'] : array();
        $receivedDate = (string)(isset($rosterRow['Commencement Date']) ? $rosterRow['Commencement Date'] : '');
        $currentOffset = dlbh_inbox_get_current_statement_offset($profileFields, $receivedDate);
        $statementLabel = dlbh_inbox_get_statement_label_for_offset($profileFields, $receivedDate, $currentOffset);
        $summaryFields = dlbh_inbox_build_account_summary_fields_with_payments(
            $profileFields,
            $receivedDate,
            $currentOffset,
            $stripeRows,
            $statementLabel
        );
        $currentStatementTotalDueRaw = trim((string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Total Due'));
        $currentStatementTotalDue = (float)preg_replace('/[^0-9.\-]/', '', $currentStatementTotalDueRaw);
        $allPaymentsTotal = dlbh_inbox_get_family_payments_total($profileFields, $stripeRows);
        $agingTotalDue = $currentStatementTotalDue - $allPaymentsTotal;
        if ($agingTotalDue < 0) $agingTotalDue = 0.0;

        $bucket = ($agingTotalDue <= 0.0)
            ? 'current'
            : dlbh_inbox_get_aging_bucket_for_summary($summaryFields, date('F j, Y'), $agingTotalDue);
        if (!isset($sections[$bucket])) $bucket = 'current';
        $bucketStatus = 'Current';
        if ($bucket === 'past_due_1_15' || $bucket === 'past_due_16_30') $bucketStatus = 'Past Due';
        if ($bucket === 'delinquent') $bucketStatus = 'Delinquent';
        $sections[$bucket][] = array(
            'primary_member' => (string)(isset($rosterRow['Name']) ? $rosterRow['Name'] : ''),
            'family_id' => (string)$familyId,
            'total_due' => dlbh_inbox_format_currency_value((string)$agingTotalDue),
            'statement_date' => (string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Statement Date'),
            'due_date' => (string)dlbh_inbox_get_field_value_by_label($summaryFields, 'Due Date'),
            'statement_offset' => (int)$currentOffset,
            'roster_member_key' => (string)(isset($rosterRow['Member Key']) ? $rosterRow['Member Key'] : ''),
            'source_folder_type' => (string)(isset($rosterRow['Source Folder Type']) ? $rosterRow['Source Folder Type'] : ''),
            'status' => $bucketStatus,
        );
    }

    foreach ($sections as &$bucketRows) {
        usort($bucketRows, function($a, $b) {
            return strcasecmp((string)(isset($a['primary_member']) ? $a['primary_member'] : ''), (string)(isset($b['primary_member']) ? $b['primary_member'] : ''));
        });
    }
    unset($bucketRows);

    return $sections;
}
}

if (!function_exists('dlbh_inbox_get_max_generated_statement_offset')) {
function dlbh_inbox_get_max_generated_statement_offset($fields, $receivedDate) {
    $tz = new DateTimeZone('America/Chicago');
    $today = new DateTimeImmutable('now', $tz);
    $todayTs = $today->setTime(0, 0, 0)->getTimestamp();
    $maxOffset = 0;
    $allowedNextFutureOffset = null;

    for ($i = 0; $i <= 240; $i++) {
        $cycleFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $i);
        $cycleStatementDate = trim((string)dlbh_inbox_get_field_value_by_label($cycleFields, 'Statement Date'));
        $cycleStatementTs = dlbh_inbox_parse_timestamp_flexible($cycleStatementDate);
        if (!$cycleStatementTs) {
            break;
        }
        if ($cycleStatementTs > $todayTs) {
            $allowedNextFutureOffset = $i;
            break;
        }
        $maxOffset = $i;
    }

    if ($allowedNextFutureOffset !== null) {
        $maxOffset = max($maxOffset, (int)$allowedNextFutureOffset);
    }

    return $maxOffset;
}
}

if (!function_exists('dlbh_inbox_get_current_statement_offset')) {
function dlbh_inbox_get_current_statement_offset($fields, $receivedDate) {
    $tz = new DateTimeZone('America/Chicago');
    $today = new DateTimeImmutable('now', $tz);
    $todayTs = $today->setTime(0, 0, 0)->getTimestamp();
    $currentOffset = 0;

    for ($i = 0; $i <= 240; $i++) {
        $cycleFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $i);
        $cycleStatementDate = trim((string)dlbh_inbox_get_field_value_by_label($cycleFields, 'Statement Date'));
        $cycleStatementTs = dlbh_inbox_parse_timestamp_flexible($cycleStatementDate);
        if (!$cycleStatementTs) {
            break;
        }
        if ($cycleStatementTs > $todayTs) {
            break;
        }
        $currentOffset = $i;
    }

    return $currentOffset;
}
}

if (!function_exists('dlbh_inbox_get_statement_label_for_offset')) {
function dlbh_inbox_get_statement_label_for_offset($fields, $receivedDate, $statementOffset) {
    $statementOffset = max(0, (int)$statementOffset);
    $tz = new DateTimeZone('America/Chicago');
    $today = new DateTimeImmutable('now', $tz);
    $todayTs = $today->setTime(0, 0, 0)->getTimestamp();
    $cycleFields = dlbh_inbox_build_account_summary_fields($fields, $receivedDate, $statementOffset);
    $cycleStatementDate = trim((string)dlbh_inbox_get_field_value_by_label($cycleFields, 'Statement Date'));
    $cycleStatementTs = dlbh_inbox_parse_timestamp_flexible($cycleStatementDate);
    if ($cycleStatementTs && $cycleStatementTs > $todayTs) {
        return 'Notional';
    }
    $dueDateRaw = trim((string)dlbh_inbox_get_field_value_by_label($cycleFields, 'Due Date'));
    $dueDateTs = dlbh_inbox_parse_timestamp_flexible($dueDateRaw);
    if ($dueDateTs) {
        $dueDate = new DateTimeImmutable('@' . $dueDateTs);
        $dueDate = $dueDate->setTimezone($tz);
        return $dueDate->format('F Y');
    }
    return '';
}
}

if (!function_exists('dlbh_inbox_format_membership_tenure')) {
function dlbh_inbox_format_membership_tenure($commencementRaw) {
    $commencementTs = dlbh_inbox_parse_timestamp_flexible($commencementRaw);
    if (!$commencementTs) return '';

    try {
        $tz = new DateTimeZone('America/Chicago');
        $start = new DateTimeImmutable('@' . $commencementTs);
        $start = $start->setTimezone($tz)->setTime(0, 0, 0);
        $today = new DateTimeImmutable('now', $tz);
        $today = $today->setTime(0, 0, 0);
        if ($today < $start) return '0 years, 0 months, 0 days';
        $diff = $start->diff($today);
        return (int)$diff->y . ' years, ' . (int)$diff->m . ' months, ' . (int)$diff->d . ' days';
    } catch (Exception $e) {
        return '';
    }
}
}

if (!function_exists('dlbh_inbox_format_membership_cohort')) {
function dlbh_inbox_format_membership_cohort($commencementRaw) {
    $commencementTs = dlbh_inbox_parse_timestamp_flexible($commencementRaw);
    if (!$commencementTs) return '';

    try {
        $tz = new DateTimeZone('America/Chicago');
        $start = new DateTimeImmutable('@' . $commencementTs);
        $start = $start->setTimezone($tz);
        return $start->format('F Y');
    } catch (Exception $e) {
        return '';
    }
}
}

if (!function_exists('dlbh_inbox_insert_membership_information')) {
function dlbh_inbox_insert_membership_information($fields, $primaryMember, $receivedDate) {
    if (!is_array($fields) || empty($fields)) return $fields;

    $existingCommencement = dlbh_inbox_get_field_value_by_label($fields, 'Commencement Date');
    $dateSeed = ($existingCommencement !== '') ? $existingCommencement : $receivedDate;

    // Remove any previously generated Membership/Account sections so they can be rebuilt deterministically.
    $cleanFields = array();
    $contactHeaderIdx = -1;
    $skipGeneratedSection = false;
    foreach ($fields as $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        $label = isset($row['label']) ? trim((string)$row['label']) : '';

        if ($type === 'header') {
            if (strcasecmp($label, 'Membership Information') === 0 || strcasecmp($label, 'Account Summary Information') === 0) {
                $skipGeneratedSection = true;
                continue;
            }
            $skipGeneratedSection = false;
            if ($contactHeaderIdx === -1 && strcasecmp($label, 'Contact Information') === 0) {
                $contactHeaderIdx = count($cleanFields);
            }
            $cleanFields[] = $row;
            continue;
        }

        if ($skipGeneratedSection) continue;
        $cleanFields[] = $row;
    }

    $insertRows = array();
    $membershipInfo = dlbh_inbox_build_membership_info_fields($cleanFields, $primaryMember, $dateSeed);
    if (!empty($membershipInfo)) {
        $insertRows[] = array('type' => 'header', 'label' => 'Membership Information', 'value' => '');
        foreach ($membershipInfo as $row) $insertRows[] = $row;
    }
    $accountSummary = dlbh_inbox_build_account_summary_fields($cleanFields, $dateSeed);
    if (!empty($accountSummary)) {
        $insertRows[] = array('type' => 'header', 'label' => 'Account Summary Information', 'value' => '');
        foreach ($accountSummary as $row) $insertRows[] = $row;
    }
    if (empty($insertRows)) return $cleanFields;

    if ($contactHeaderIdx >= 0) {
        $before = array_slice($cleanFields, 0, $contactHeaderIdx);
        $after = array_slice($cleanFields, $contactHeaderIdx);
        return array_merge($before, $insertRows, $after);
    }
    return array_merge($cleanFields, $insertRows);
}
}

if (!function_exists('dlbh_inbox_format_central_datetime')) {
function dlbh_inbox_format_central_datetime($rawDate) {
    $value = trim((string)$rawDate);
    if ($value === '') return '';

    try {
        $dt = new DateTime($value);
    } catch (Exception $e) {
        $ts = strtotime($value);
        if ($ts === false) return $value;
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone('UTC'));
    }

    $dt->setTimezone(new DateTimeZone('America/Chicago'));
    return $dt->format('Y-m-d g:i A T');
}
}

if (!function_exists('dlbh_inbox_format_date_friendly')) {
function dlbh_inbox_format_date_friendly($rawDate) {
    $value = trim((string)$rawDate);
    if ($value === '') return '';
    $ts = strtotime($value);
    if ($ts === false || $ts <= 0) return $value;
    return date('F j, Y', $ts);
}
}

if (!function_exists('dlbh_inbox_format_date_input_value')) {
function dlbh_inbox_format_date_input_value($rawDate) {
    $value = trim((string)$rawDate);
    if ($value === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;
    $ts = strtotime($value);
    if ($ts === false || $ts <= 0) return '';
    return date('Y-m-d', $ts);
}
}

if (!function_exists('dlbh_inbox_get_zip_city_state_lookup')) {
function dlbh_inbox_get_zip_city_state_lookup() {
    static $lookup = null;
    if (is_array($lookup)) return $lookup;

    $lookup = array();
    $csvUrl = 'https://gist.githubusercontent.com/Tucker-Eric/6a1a6b164726f21bb699623b06591389/raw/d87104248e4796f872412993a8b43d583c889176/us_zips.csv';
    $cacheKey = 'dlbh_inbox_zip_lookup_v1';
    $csv = '';

    if (function_exists('get_transient')) {
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') $csv = $cached;
    }

    if ($csv === '') {
        if (function_exists('wp_remote_get')) {
            $resp = wp_remote_get($csvUrl, array('timeout' => 8));
            if (!is_wp_error($resp)) {
                $body = wp_remote_retrieve_body($resp);
                if (is_string($body) && $body !== '') $csv = $body;
            }
        } else {
            $raw = @file_get_contents($csvUrl);
            if (is_string($raw) && $raw !== '') $csv = $raw;
        }

        if ($csv !== '' && function_exists('set_transient')) {
            set_transient($cacheKey, $csv, 12 * HOUR_IN_SECONDS);
        }
    }

    if ($csv === '') return $lookup;

    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (!is_array($lines) || count($lines) < 2) return $lookup;

    foreach ($lines as $idx => $line) {
        if ($idx === 0) continue; // header
        $line = trim((string)$line);
        if ($line === '') continue;

        $cols = str_getcsv($line);
        if (!is_array($cols) || count($cols) < 4) continue;

        $zip = trim((string)$cols[0]);
        $city = trim((string)$cols[1]);
        $stateFull = trim((string)$cols[2]);
        $stateAbbr = strtoupper(trim((string)$cols[3]));
        if ($zip === '' || $city === '' || ($stateFull === '' && $stateAbbr === '')) continue;

        if (!isset($lookup[$zip])) {
            $lookup[$zip] = array(
                'city' => $city,
                'state_full' => $stateFull,
                'state' => $stateAbbr,
            );
        }
    }

    return $lookup;
}
}

if (!function_exists('dlbh_inbox_calculate_age')) {
function dlbh_inbox_calculate_age($dobRaw) {
    $dobRaw = trim((string)$dobRaw);
    if ($dobRaw === '') return null;

    $formats = array('m/d/Y', 'n/j/Y', 'Y-m-d');
    $dob = null;
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $dobRaw);
        if ($dt instanceof DateTime) {
            $dob = $dt;
            break;
        }
    }
    if (!$dob) {
        $ts = strtotime($dobRaw);
        if ($ts === false) return null;
        $dob = new DateTime('@' . $ts);
        $dob->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    $today = new DateTime('today');
    return (int)$today->diff($dob)->y;
}
}

if (!function_exists('dlbh_inbox_evaluate_eligibility')) {
function dlbh_inbox_evaluate_eligibility($fields) {
    $result = array(
        'eligible' => true,
        'issues' => array(),
        'invalid_dob_indices' => array(),
        'dob_errors_by_index' => array(),
    );
    if (!is_array($fields) || empty($fields)) return $result;

    $records = array();
    $current = null;
    foreach ($fields as $fieldIdx => $f) {
        if (!is_array($f)) continue;
        $type = isset($f['type']) ? strtolower(trim((string)$f['type'])) : 'field';
        if ($type === 'header') continue;

        $label = isset($f['label']) ? trim((string)$f['label']) : '';
        $value = isset($f['value']) ? trim((string)$f['value']) : '';
        if ($label === '') continue;

        if (strcasecmp($label, 'Relationship') === 0) {
            if (is_array($current)) $records[] = $current;
            $current = array(
                'relationship' => $value,
                'name' => '',
                'dob' => '',
                'dob_field_index' => null,
                'military' => '',
            );
            continue;
        }
        if (!is_array($current)) continue;

        if (strcasecmp($label, 'Name') === 0) {
            $current['name'] = $value;
            continue;
        }
        if (strcasecmp($label, 'Date of Birth') === 0) {
            $current['dob'] = $value;
            $current['dob_field_index'] = (int)$fieldIdx;
            continue;
        }
        $labelLower = strtolower($label);
        if (strpos($labelLower, 'armed forces') !== false && strpos($labelLower, 'serv') !== false) {
            $current['military'] = strtolower($value);
            continue;
        }
    }
    if (is_array($current)) $records[] = $current;

    foreach ($records as $r) {
        $relationship = strtolower(trim((string)(isset($r['relationship']) ? $r['relationship'] : '')));
        $name = trim((string)(isset($r['name']) ? $r['name'] : ''));
        $dob = trim((string)(isset($r['dob']) ? $r['dob'] : ''));
        $military = strtolower(trim((string)(isset($r['military']) ? $r['military'] : '')));
        $displayName = $name !== '' ? $name : ucfirst($relationship !== '' ? $relationship : 'Member');

        $age = dlbh_inbox_calculate_age($dob);
        if ($age === null) {
            $result['eligible'] = false;
            $result['issues'][] = $displayName . ': Missing/invalid Date of Birth.';
            if (isset($r['dob_field_index']) && $r['dob_field_index'] !== null) {
                $idx = (int)$r['dob_field_index'];
                $result['invalid_dob_indices'][$idx] = true;
                $result['dob_errors_by_index'][$idx] = 'Invalid Date of Birth.';
            }
            continue;
        }

        if ($relationship === 'primary member' && $age < 19) {
            $result['eligible'] = false;
            $result['issues'][] = $displayName . ': Primary Member must be 19+.';
            if (isset($r['dob_field_index']) && $r['dob_field_index'] !== null) {
                $idx = (int)$r['dob_field_index'];
                $result['invalid_dob_indices'][$idx] = true;
                $result['dob_errors_by_index'][$idx] = 'Primary Member must be 19 years of age or older.';
            }
        }
        if (($relationship === 'spouse/partner' || $relationship === 'spouse' || $relationship === 'partner') && $age < 17) {
            $result['eligible'] = false;
            $result['issues'][] = $displayName . ': Spouse must be 17+.';
            if (isset($r['dob_field_index']) && $r['dob_field_index'] !== null) {
                $idx = (int)$r['dob_field_index'];
                $result['invalid_dob_indices'][$idx] = true;
                $result['dob_errors_by_index'][$idx] = 'Spouse must be 17 years of age or older.';
            }
        }
        if ($relationship === 'dependent' && $age > 18) {
            $result['eligible'] = false;
            $result['issues'][] = $displayName . ': Dependent must be 18 or younger.';
            if (isset($r['dob_field_index']) && $r['dob_field_index'] !== null) {
                $idx = (int)$r['dob_field_index'];
                $result['invalid_dob_indices'][$idx] = true;
                $result['dob_errors_by_index'][$idx] = 'Dependent must be 18 years of age or younger.';
            }
        }
        if ($military === 'yes' && $age < 17) {
            $result['eligible'] = false;
            $result['issues'][] = $displayName . ': Must be 17+ to serve in U.S. Armed Forces.';
            if (isset($r['dob_field_index']) && $r['dob_field_index'] !== null) {
                $idx = (int)$r['dob_field_index'];
                $result['invalid_dob_indices'][$idx] = true;
                $result['dob_errors_by_index'][$idx] = 'Must be 17 years of age or older to serve in United States Armed Forces.';
            }
        }
    }

    return $result;
}
}

if (!function_exists('dlbh_inbox_get_field_value')) {
function dlbh_inbox_get_field_value($fields, $labelWanted) {
    if (!is_array($fields) || $labelWanted === '') return '';
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $type = isset($f['type']) ? strtolower(trim((string)$f['type'])) : 'field';
        if ($type === 'header') continue;
        $label = isset($f['label']) ? trim((string)$f['label']) : '';
        if (strcasecmp($label, $labelWanted) === 0) {
            return isset($f['value']) ? trim((string)$f['value']) : '';
        }
    }
    return '';
}
}

if (!function_exists('dlbh_inbox_ensure_account_summary_family_id')) {
function dlbh_inbox_ensure_account_summary_family_id($fields) {
    if (!is_array($fields) || empty($fields)) return is_array($fields) ? $fields : array();
    $familyId = trim((string)dlbh_inbox_get_field_value_by_label($fields, 'Family ID'));
    if ($familyId === '') $familyId = trim((string)dlbh_inbox_get_field_value($fields, 'Family ID'));
    if ($familyId === '') return $fields;

    $inAccountSummary = false;
    $accountSummaryHeaderIndex = -1;
    $accountSummaryHasFamilyIdField = false;
    foreach ($fields as $idx => $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        $label = isset($row['label']) ? trim((string)$row['label']) : '';
        if ($type === 'header') {
            $inAccountSummary = (strcasecmp($label, 'Account Summary Information') === 0);
            if ($inAccountSummary && $accountSummaryHeaderIndex < 0) {
                $accountSummaryHeaderIndex = (int)$idx;
            }
            continue;
        }
        if (!$inAccountSummary) continue;
        if (strcasecmp($label, 'Family ID') !== 0) continue;
        $accountSummaryHasFamilyIdField = true;
        $value = isset($row['value']) ? trim((string)$row['value']) : '';
        if ($value !== '') continue;
        $fields[$idx]['value'] = $familyId;
        return $fields;
    }

    if ($accountSummaryHeaderIndex >= 0 && !$accountSummaryHasFamilyIdField) {
        array_splice($fields, $accountSummaryHeaderIndex + 1, 0, array(
            array('type' => 'field', 'label' => 'Family ID', 'value' => $familyId),
        ));
    }

    return $fields;
}
}

if (!function_exists('dlbh_inbox_get_primary_member_name_from_fields')) {
function dlbh_inbox_get_primary_member_name_from_fields($fields) {
    if (!is_array($fields)) return '';
    $currentRelationship = '';
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $type = isset($f['type']) ? strtolower(trim((string)$f['type'])) : 'field';
        if ($type === 'header') continue;
        $label = isset($f['label']) ? trim((string)$f['label']) : '';
        $value = isset($f['value']) ? trim((string)$f['value']) : '';
        if (strcasecmp($label, 'Relationship') === 0) {
            $currentRelationship = $value;
            continue;
        }
        if (strcasecmp($label, 'Name') === 0 && strcasecmp($currentRelationship, 'Primary Member') === 0) {
            return $value;
        }
    }
    $fallback = dlbh_inbox_get_field_value($fields, 'Primary Member');
    return trim((string)$fallback);
}
}

if (!function_exists('dlbh_inbox_get_reply_message_value')) {
function dlbh_inbox_get_reply_message_value($fields) {
    $inReplySection = false;
    $fallbackMessage = '';
    foreach ((array)$fields as $f) {
        if (!is_array($f)) continue;
        $type = isset($f['type']) ? strtolower(trim((string)$f['type'])) : 'field';
        $label = isset($f['label']) ? trim((string)$f['label']) : '';
        $value = isset($f['value']) ? trim((string)$f['value']) : '';
        if ($type === 'header') {
            $inReplySection = (strcasecmp($label, 'Reply Message') === 0);
            continue;
        }
        if ($inReplySection && strcasecmp($label, 'Message') === 0 && $value !== '' && dlbh_inbox_is_real_reply_message($value)) {
            return dlbh_inbox_clean_reply_message_text($value);
        }
        if (strcasecmp($label, 'Message') === 0 && $value !== '' && $fallbackMessage === '' && dlbh_inbox_is_real_reply_message($value)) {
            $fallbackMessage = dlbh_inbox_clean_reply_message_text($value);
        }
    }
    return $fallbackMessage;
}
}

if (!function_exists('dlbh_inbox_extract_best_reply_message')) {
function dlbh_inbox_extract_best_reply_message($bodyText) {
    $bodyText = trim((string)$bodyText);
    if ($bodyText === '') return '';
    $best = '';
    $bodyTextForHeuristics = $bodyText;
    if (preg_match('/__DLBH_REPLY_START__\s*(.*?)\s*__DLBH_REPLY_END__/is', $bodyText, $m)) {
        $replyMessage = dlbh_inbox_clean_reply_message_text((string)$m[1]);
        $best = dlbh_inbox_choose_better_reply_message($best, $replyMessage);
        $bodyTextForHeuristics = preg_replace('/__DLBH_REPLY_START__\s*.*?\s*__DLBH_REPLY_END__\s*/is', '', $bodyText);
        if (!is_string($bodyTextForHeuristics)) $bodyTextForHeuristics = $bodyText;
    }
    $best = dlbh_inbox_choose_better_reply_message($best, dlbh_inbox_extract_reply_message_from_plain($bodyTextForHeuristics));
    $best = dlbh_inbox_choose_better_reply_message($best, dlbh_inbox_extract_reply_message($bodyTextForHeuristics));

    $text = dlbh_inbox_normalize_body_text($bodyTextForHeuristics);
    $formMarkers = array(
        'Pre-Enrollment Questionnaire',
        'Membership Information',
        'Contact Information',
        'Primary Member Information',
        'Dependent Information',
        'Do you or anyone your enrolling today have any allergies or food restrictions?',
    );
    $bestPos = false;
    foreach ($formMarkers as $marker) {
        $pos = stripos($text, $marker);
        if ($pos !== false && ($bestPos === false || $pos < $bestPos)) {
            $bestPos = $pos;
        }
    }
    if ($bestPos !== false && $bestPos > 0) {
        $replyPrefix = dlbh_inbox_clean_reply_message_text((string)substr($text, 0, (int)$bestPos));
        $looksLikeCss = (
            stripos($replyPrefix, 'wpforms') !== false ||
            stripos($replyPrefix, '@media') !== false ||
            stripos($replyPrefix, '.wpforms') !== false ||
            preg_match('/\{[^}]+\}/', $replyPrefix)
        );
        if (!$looksLikeCss && stripos($replyPrefix, 'membership committee') === false) {
            $best = dlbh_inbox_choose_better_reply_message($best, $replyPrefix);
        }
    }

    return dlbh_inbox_is_real_reply_message($best) ? $best : '';
}
}

if (!function_exists('dlbh_inbox_choose_better_reply_message')) {
function dlbh_inbox_choose_better_reply_message($current, $candidate) {
    $current = dlbh_inbox_clean_reply_message_text($current);
    $candidate = dlbh_inbox_clean_reply_message_text($candidate);
    if (!dlbh_inbox_is_real_reply_message($candidate)) return $current;
    if (!dlbh_inbox_is_real_reply_message($current)) return $candidate;
    return (strlen($candidate) > strlen($current)) ? $candidate : $current;
}
}

if (!function_exists('dlbh_inbox_expand_reply_message_from_body')) {
function dlbh_inbox_expand_reply_message_from_body($replyMessage, $bodyText) {
    $replyMessage = dlbh_inbox_clean_reply_message_text($replyMessage);
    $bodyText = trim((string)$bodyText);
    if ($replyMessage === '' || $bodyText === '') return $replyMessage;

    $normalizedBody = dlbh_inbox_normalize_body_text($bodyText);
    if ($normalizedBody === '') return $replyMessage;
    $normalizedBody = preg_replace('/__DLBH_REPLY_START__\s*.*?\s*__DLBH_REPLY_END__\s*/is', '', $normalizedBody);
    $normalizedBody = trim((string)$normalizedBody);
    if ($normalizedBody === '') return $replyMessage;

    $needle = substr($replyMessage, 0, min(strlen($replyMessage), 24));
    $needle = trim((string)$needle);
    if ($needle === '') return $replyMessage;

    $startPos = stripos($normalizedBody, $needle);
    if ($startPos === false) return $replyMessage;

    $remaining = (string)substr($normalizedBody, (int)$startPos);
    $candidate = $remaining;
    if (preg_match('/^(.*?)(?:\n\s*On .+? wrote:|\n\s*From:\s.+|\n\s*Pre-Enrollment Questionnaire\b|\n\s*Membership Information\b|\n\s*Contact Information\b|\n\s*Primary Member Information\b|\n\s*Dependent Information\b)/is', $remaining, $m)) {
        $candidate = isset($m[1]) ? (string)$m[1] : $remaining;
    }
    $candidate = dlbh_inbox_clean_reply_message_text($candidate);

    if (!dlbh_inbox_is_real_reply_message($candidate)) return $replyMessage;
    return (strlen($candidate) > strlen($replyMessage)) ? $candidate : $replyMessage;
}
}

if (!function_exists('dlbh_inbox_fields_are_equal')) {
function dlbh_inbox_fields_are_equal($left, $right) {
    $normalizeForCompare = function($label, $value) {
        $labelNorm = strtolower(trim((string)$label));
        $valueNorm = trim((string)$value);
        $valueNorm = preg_replace('/\s+/', ' ', $valueNorm);
        if (!is_string($valueNorm)) $valueNorm = trim((string)$value);

        if (strpos($labelNorm, 'date') !== false) {
            $datePlaceholder = strtolower(trim($valueNorm));
            if ($datePlaceholder === '' || $datePlaceholder === '-' || $datePlaceholder === 'n/a' || $datePlaceholder === 'na') {
                return '';
            }
            $ts = strtotime($valueNorm);
            if ($ts !== false && $ts > 0) return date('Y-m-d', $ts);
        }
        if (strpos($labelNorm, 'phone') !== false) {
            return preg_replace('/[^0-9]/', '', $valueNorm);
        }
        if (strpos($labelNorm, 'email') !== false) {
            return strtolower($valueNorm);
        }
        if (strpos($labelNorm, 'balance') !== false || strpos($labelNorm, 'charges') !== false || strpos($labelNorm, 'due') !== false || strpos($labelNorm, 'payment') !== false) {
            $money = preg_replace('/[^0-9.\-]/', '', $valueNorm);
            return (string)$money;
        }
        return strtolower($valueNorm);
    };

    if (!is_array($left) || !is_array($right)) return false;
    if (count($left) !== count($right)) return false;
    $count = count($left);
    for ($i = 0; $i < $count; $i++) {
        $a = isset($left[$i]) && is_array($left[$i]) ? $left[$i] : array();
        $b = isset($right[$i]) && is_array($right[$i]) ? $right[$i] : array();
        $aType = isset($a['type']) ? strtolower(trim((string)$a['type'])) : 'field';
        $bType = isset($b['type']) ? strtolower(trim((string)$b['type'])) : 'field';
        $aLabel = isset($a['label']) ? trim((string)$a['label']) : '';
        $bLabel = isset($b['label']) ? trim((string)$b['label']) : '';
        $aValueRaw = isset($a['value']) ? (string)$a['value'] : '';
        $bValueRaw = isset($b['value']) ? (string)$b['value'] : '';
        $aValue = $normalizeForCompare($aLabel, $aValueRaw);
        $bValue = $normalizeForCompare($bLabel, $bValueRaw);
        if ($aType !== $bType || $aLabel !== $bLabel || $aValue !== $bValue) return false;
    }
    return true;
}
}

if (!function_exists('dlbh_inbox_fields_semantically_equal')) {
function dlbh_inbox_fields_semantically_equal($left, $right) {
    $canon = function($rows) {
        $out = array();
        if (!is_array($rows)) return $out;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
            if ($type === 'header') continue;
            $label = isset($row['label']) ? strtolower(trim((string)$row['label'])) : '';
            $value = isset($row['value']) ? trim((string)$row['value']) : '';
            if ($label === '') continue;
            if (strpos($label, 'date') !== false) {
                $vLower = strtolower($value);
                if ($vLower === '' || $vLower === '-' || $vLower === 'n/a' || $vLower === 'na') {
                    $value = '';
                } else {
                    $ts = strtotime($value);
                    if ($ts !== false && $ts > 0) $value = date('Y-m-d', $ts);
                }
            } elseif (strpos($label, 'phone') !== false) {
                $value = preg_replace('/[^0-9]/', '', $value);
            } elseif (strpos($label, 'email') !== false) {
                $value = strtolower($value);
            } elseif (strpos($label, 'balance') !== false || strpos($label, 'charges') !== false || strpos($label, 'due') !== false || strpos($label, 'payment') !== false) {
                $value = preg_replace('/[^0-9.\-]/', '', $value);
            } else {
                $value = preg_replace('/\s+/', ' ', $value);
                if (!is_string($value)) $value = trim((string)(isset($row['value']) ? $row['value'] : ''));
                $value = strtolower(trim($value));
            }
            if (!isset($out[$label])) $out[$label] = array();
            $out[$label][] = (string)$value;
        }
        ksort($out);
        return $out;
    };

    return $canon($left) == $canon($right);
}
}

if (!function_exists('dlbh_inbox_filter_user_editable_fields')) {
function dlbh_inbox_filter_user_editable_fields($rows) {
    $out = array();
    $skipSection = false;
    $skipSectionLabel = '';
    if (!is_array($rows)) return $out;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        $label = isset($row['label']) ? trim((string)$row['label']) : '';
        $value = isset($row['value']) ? (string)$row['value'] : '';
        if ($type === 'header') {
            $headerKey = strtolower($label);
            $skipSection = ($headerKey === 'membership information' || $headerKey === 'account summary information' || $headerKey === 'reply message');
            $skipSectionLabel = $label;
            $out[] = array('type' => 'header', 'label' => $label, 'value' => '');
            continue;
        }
        if ($skipSection) {
            $allowMembershipCommencement =
                strcasecmp($skipSectionLabel, 'Membership Information') === 0 &&
                strcasecmp($label, 'Commencement Date') === 0;
            if (!$allowMembershipCommencement) continue;
        }
        if (strcasecmp($label, 'Message') === 0) continue;
        $out[] = array('type' => 'field', 'label' => $label, 'value' => $value);
    }
    return $out;
}
}

if (!function_exists('dlbh_inbox_get_record_kind_from_header')) {
function dlbh_inbox_get_record_kind_from_header($label) {
    $label = trim((string)$label);
    if (strcasecmp($label, 'Spouse Information') === 0) return 'spouse';
    if (preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $label)) return 'dependent';
    return '';
}
}

if (!function_exists('dlbh_inbox_renumber_dependent_headers')) {
function dlbh_inbox_renumber_dependent_headers($rows) {
    $rows = is_array($rows) ? array_values($rows) : array();
    $depCount = 0;
    foreach ($rows as &$row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        $label = isset($row['label']) ? trim((string)$row['label']) : '';
        if ($type === 'header' && preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $label)) {
            $depCount++;
            $row['label'] = ($depCount === 1) ? 'Dependent Information' : ('Dependent Information #' . $depCount);
            $row['value'] = '';
        }
    }
    unset($row);
    return $rows;
}
}

if (!function_exists('dlbh_inbox_build_blank_record_block')) {
function dlbh_inbox_build_blank_record_block($kind, $headerLabel = '') {
    $kind = strtolower(trim((string)$kind));
    if ($kind === 'spouse') {
        return array(
            array('type' => 'header', 'label' => ($headerLabel !== '' ? $headerLabel : 'Spouse Information'), 'value' => ''),
            array('type' => 'field', 'label' => 'Relationship', 'value' => 'Spouse'),
            array('type' => 'field', 'label' => 'Name', 'value' => ''),
            array('type' => 'field', 'label' => 'Date of Birth', 'value' => ''),
            array('type' => 'field', 'label' => 'Do you or anyone your enrolling today have any allergies or food restrictions?', 'value' => 'No'),
            array('type' => 'field', 'label' => 'Have you or anyone your enrolling today served or are serving in the United States Armed Forces?', 'value' => 'No'),
            array('type' => 'field', 'label' => 'T-Shirt Size', 'value' => ''),
        );
    }
    if ($kind === 'dependent') {
        return array(
            array('type' => 'header', 'label' => ($headerLabel !== '' ? $headerLabel : 'Dependent Information'), 'value' => ''),
            array('type' => 'field', 'label' => 'Relationship', 'value' => 'Dependent'),
            array('type' => 'field', 'label' => 'Name', 'value' => ''),
            array('type' => 'field', 'label' => 'Date of Birth', 'value' => ''),
            array('type' => 'field', 'label' => 'Do you have any allergies or food restrictions?', 'value' => 'No'),
            array('type' => 'field', 'label' => 'Have you or are you serving in the United States Armed Forces?', 'value' => 'No'),
            array('type' => 'field', 'label' => 'T-Shirt Size', 'value' => ''),
        );
    }
    return array();
}
}

if (!function_exists('dlbh_inbox_apply_record_action')) {
function dlbh_inbox_apply_record_action($rows, $action, $targetLabel) {
    $rows = is_array($rows) ? array_values($rows) : array();
    $action = strtolower(trim((string)$action));
    $targetLabel = trim((string)$targetLabel);
    if ($action === '' || $targetLabel === '') return $rows;

    $kind = dlbh_inbox_get_record_kind_from_header($targetLabel);
    if ($kind === '') return $rows;

    $headerIndex = -1;
    foreach ($rows as $idx => $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        $label = isset($row['label']) ? trim((string)$row['label']) : '';
        if ($type === 'header' && strcasecmp($label, $targetLabel) === 0) {
            $headerIndex = (int)$idx;
            break;
        }
    }

    if ($headerIndex === -1) {
        if ($action === 'add') {
            $rows = array_merge($rows, dlbh_inbox_build_blank_record_block($kind, $kind === 'spouse' ? 'Spouse Information' : 'Dependent Information'));
            return dlbh_inbox_renumber_dependent_headers($rows);
        }
        return $rows;
    }

    $endIndex = count($rows);
    for ($i = $headerIndex + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        if ($type === 'header') {
            $endIndex = $i;
            break;
        }
    }

    if ($action === 'remove') {
        array_splice($rows, $headerIndex, $endIndex - $headerIndex);
        $remainingKindCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
            $label = isset($row['label']) ? trim((string)$row['label']) : '';
            if ($type === 'header' && dlbh_inbox_get_record_kind_from_header($label) === $kind) {
                $remainingKindCount++;
            }
        }
        if ($remainingKindCount === 0) {
            $placeholderLabel = ($kind === 'spouse') ? 'Spouse Information' : 'Dependent Information';
            $rows[] = array('type' => 'header', 'label' => $placeholderLabel, 'value' => '');
        }
        return dlbh_inbox_renumber_dependent_headers($rows);
    }

    if ($action === 'add') {
        $existingKindCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
            $label = isset($row['label']) ? trim((string)$row['label']) : '';
            if ($type === 'header' && dlbh_inbox_get_record_kind_from_header($label) === $kind) {
                $existingKindCount++;
            }
        }
        if ($kind === 'spouse' && $existingKindCount >= 1 && $endIndex > ($headerIndex + 1)) {
            return dlbh_inbox_renumber_dependent_headers($rows);
        }

        $blankBlock = dlbh_inbox_build_blank_record_block($kind, $targetLabel);
        $isPlaceholderOnly = ($endIndex === ($headerIndex + 1));
        if ($isPlaceholderOnly) {
            array_splice($rows, $headerIndex, 1, $blankBlock);
        } else {
            array_splice($rows, $endIndex, 0, $blankBlock);
        }
        return dlbh_inbox_renumber_dependent_headers($rows);
    }

    return dlbh_inbox_renumber_dependent_headers($rows);
}
}

if (!function_exists('dlbh_inbox_find_last_record_header_label')) {
function dlbh_inbox_find_last_record_header_label($rows, $kind) {
    $rows = is_array($rows) ? $rows : array();
    $kind = strtolower(trim((string)$kind));
    $found = '';
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $type = isset($row['type']) ? strtolower(trim((string)$row['type'])) : 'field';
        $label = isset($row['label']) ? trim((string)$row['label']) : '';
        if ($type === 'header' && dlbh_inbox_get_record_kind_from_header($label) === $kind) {
            $found = $label;
        }
    }
    return $found;
}
}

if (!function_exists('dlbh_inbox_build_compose_template_body')) {
function dlbh_inbox_build_compose_template_body($firstName) {
    $firstName = trim((string)$firstName);
    if ($firstName === '') $firstName = 'Member';
    return "Dear " . $firstName . ",\n\n"
        . "This email acknowledges receipt of your Membership Enrollment for the Damie Lee Burton Howard Family Reunion.\n\n"
        . "During our review, we identified one or more discrepancies related to the Date(s) of Birth entered on your enrollment form. Based on the information submitted, the age(s) do not align with our membership eligibility guidelines.\n\n"
        . "For reference, eligibility requirements are as follows:\n\n"
        . "Primary Member: Must be 19 years of age or older\n\n"
        . "Spouse: Must be 17 years of age or older\n\n"
        . "Dependent: Must be 18 years of age or younger\n\n"
        . "Military Service: Must be 17 years of age or older to serve in the United States Armed Forces\n\n"
        . "At this time, we are unable to finalize your enrollment until the Date(s) of Birth have been verified or corrected.\n\n"
        . "Please reply to this email and confirm the correct Date(s) of Birth so we may continue processing your enrollment.\n\n"
        . "If you have any questions, feel free to reply for assistance.\n\n"
        . "Sincerely,\n"
        . "Membership Committee\n"
        . "Damie Lee Burton Howard Family Reunion";
}
}

if (!function_exists('dlbh_inbox_build_eligible_compose_template_body')) {
function dlbh_inbox_build_eligible_compose_template_body($fields, $firstName) {
    $firstName = trim((string)$firstName);
    if ($firstName === '') $firstName = 'Family';
    $familyId = dlbh_inbox_get_field_value($fields, 'Family ID');
    if ($familyId === '') $familyId = 'DLBHF-00000';
    $email = dlbh_inbox_get_field_value($fields, 'Email');
    $dueDate = dlbh_inbox_get_field_value($fields, 'Due Date');
    $graceDate = dlbh_inbox_get_field_value($fields, 'Grace Period End Date');
    $delinquencyDate = dlbh_inbox_get_field_value($fields, 'Delinquency Date');
    if ($email === '') $email = 'the same email you used to complete your Family Reunion Enrollment';
    if ($dueDate === '') $dueDate = 'your posted due date';
    if ($graceDate === '') $graceDate = 'your posted grace period end date';
    if ($delinquencyDate === '') $delinquencyDate = 'your posted delinquency date';

    return "Welcome\n\n"
        . "Hi " . $firstName . ",\n\n"
        . "We can't wait to see you.\n\n"
        . "You're all set — we've received your Membership Enrollment for the Damie Lee Burton Howard Family Reunion (July 23–25, 2027). Completing your Membership Enrollment lets us know you'll be there — and we truly hope you will be. This weekend is about reconnecting, celebrating our roots, and continuing the legacy we share as one family, with plenty of connection, history, laughter, and memories in between.\n\n"
        . "Family ID & Account Access\n\n"
        . "First things first: your Family ID is " . $familyId . ". Please save it. You'll use this each time you submit a payment and anytime you need support.\n\n"
        . "Your Family Access account has also been successfully set up.\n\n"
        . "Please allow 15–30 minutes for your account to fully activate before attempting to sign in.\n\n"
        . "Sign In Instructions\n\n"
        . "When signing in, you will be prompted to enter:\n\n"
        . "Email Address: " . $email . "\n\n"
        . "Password: Your Family ID (" . $familyId . ")\n\n"
        . "Family Access\n\n"
        . "Family Access allows you to:\n\n"
        . "View your household membership record\n\n"
        . "Confirm your membership status\n\n"
        . "Review billing statements\n\n"
        . "View payment history\n\n"
        . "Make dues payments securely\n\n"
        . "If you experience any issues accessing your account after the activation window, please reply to this email for assistance.\n\n"
        . "Article XI — Family Reunion Dues\n\n"
        . "Now let's talk about Family Dues — what they are and why they matter.\n\n"
        . "Family Dues are the household contribution that helps fund and support the Family Reunion's financial needs and requirements. That includes securing spaces and deposits, food and hospitality, activities and programming, supplies and on-site logistics, communication and planning tools, and the administrative and financial operations that keep everything organized and accountable.\n\n"
        . "Here's the official structure, straight from Article XI — Family Reunion Dues:\n\n"
        . "Dues are $20.00 per month per household.\n\n"
        . "A \"household\" includes:\n\n"
        . "A Primary Member who is 19 or older\n\n"
        . "A Spouse/Partner who is 17 or older\n\n"
        . "Any Dependents who are 18 or younger\n\n"
        . "Everyone in your household is covered under one membership — dues are paid once per household, not per person.\n\n"
        . "Account Summary\n\n"
        . "Your dues payment is due on " . $dueDate . ".\n\n"
        . "When you click Choose Your Plan, you can set up auto-renew at:\n\n"
        . "$20 monthly\n\n"
        . "$60 quarterly\n\n"
        . "$120 semi-annually\n\n"
        . "$240 annually\n\n"
        . "Or you can make a one-time payment of $20, $60, $120, or $240 — whichever works best for your household.\n\n"
        . "If payment isn't received by " . $graceDate . ", your account will be marked Late.\n"
        . "If it still isn't received by " . $delinquencyDate . ", your account will be marked Delinquent.\n\n"
        . "When your dues are current, your membership is Active, and your household is fully included in reunion events and meetings. If dues are not current, membership becomes Inactive, and we'll need to bring things up to date before the reunion so you can fully participate with the family.\n\n"
        . "Help Wanted\n\n"
        . "Before you go — we're also recruiting volunteers for our Standing Committees:\n\n"
        . "Membership\n\n"
        . "Finance\n\n"
        . "Reunion\n\n"
        . "Newsroom\n\n"
        . "We're looking for family members in good standing who can show up consistently, follow guidelines, communicate respectfully, and help move the work forward.\n\n"
        . "If you're ready to serve and support the reunion behind the scenes, click I'll Help to learn more.\n\n"
        . "We're glad to have your household officially enrolled and connected through Family Access.\n\n"
        . "Sincerely,\n"
        . "Membership Committee\n"
        . "Damie Lee Burton Howard Family Reunion";
}
}

if (!function_exists('dlbh_inbox_membership_past_due_subject')) {
function dlbh_inbox_membership_past_due_subject() {
    return 'Damie Lee Burton Howard Family Reunion | Family Dues Past Due';
}
}

if (!function_exists('dlbh_inbox_membership_delinquent_subject')) {
function dlbh_inbox_membership_delinquent_subject() {
    return 'Damie Lee Burton Howard Family Reunion | Family Dues Delinquent';
}
}

if (!function_exists('dlbh_inbox_build_past_due_compose_template_body')) {
function dlbh_inbox_build_past_due_compose_template_body($primaryMemberName, $dueDate, $amountDue) {
    $primaryMemberName = trim((string)$primaryMemberName);
    if ($primaryMemberName === '') $primaryMemberName = 'Member';
    $dueDate = trim((string)$dueDate);
    if ($dueDate === '' || $dueDate === '-') $dueDate = 'your due date';
    $amountDue = trim((string)$amountDue);
    if ($amountDue === '' || $amountDue === '-') $amountDue = '$0.00';
    return "Dear " . $primaryMemberName . ",\n\n"
        . "This is a courtesy notice that your Family Dues are currently past due.\n\n"
        . "Our records indicate that payment was due on " . $dueDate . ", and we have not yet received the required monthly dues of $20 per household. As outlined in our Family Dues policy, a 15-day grace period is provided following the due date. That grace period has now expired.\n\n"
        . "Your total outstanding balance is " . $amountDue . ".\n\n"
        . "We kindly ask that you submit your payment as soon as possible to bring your account current and avoid further delinquency status.\n\n"
        . "Family Dues directly support the planning and execution of our upcoming reunion, including venue commitments, event planning, and operational expenses. Your continued participation and timely support are greatly appreciated.\n\n"
        . "If you have already submitted payment, please disregard this notice. If you have any questions regarding your account, please reply to this email for assistance.\n\n"
        . "Thank you for your prompt attention to this matter.\n\n"
        . "Sincerely,\n"
        . "Membership Committee\n"
        . "Damie Lee Burton Howard Family Reunion";
}
}

if (!function_exists('dlbh_inbox_build_delinquent_compose_template_body')) {
function dlbh_inbox_build_delinquent_compose_template_body($primaryMemberName, $dueDate, $amountDue) {
    $primaryMemberName = trim((string)$primaryMemberName);
    if ($primaryMemberName === '') $primaryMemberName = 'Member';
    $dueDate = trim((string)$dueDate);
    if ($dueDate === '' || $dueDate === '-') $dueDate = 'your due date';
    $amountDue = trim((string)$amountDue);
    if ($amountDue === '' || $amountDue === '-') $amountDue = '$0.00';
    return "Dear " . $primaryMemberName . ",\n\n"
        . "This notice is to inform you that your Family Dues account is now classified as Delinquent.\n\n"
        . "Our records show that payment was originally due on " . $dueDate . ". The 15-day grace period has expired, and an additional 15 days have passed without receipt of payment. As a result, the account is now 30 days past the original due date.\n\n"
        . "Your total outstanding balance is " . $amountDue . ".\n\n"
        . "Family Dues support all planning, deposits, and operational commitments related to the Damie Lee Burton Howard Family Reunion. Maintaining accounts in good standing allows us to plan responsibly and equitably for all participating households.\n\n"
        . "Please submit payment promptly to restore your account to Current status. If payment has already been submitted, please disregard this notice. If you are experiencing hardship or believe this notice was sent in error, reply to this email so we can review your account.\n\n"
        . "We appreciate your immediate attention to this matter.\n\n"
        . "Sincerely,\n"
        . "Membership Committee\n"
        . "Damie Lee Burton Howard Family Reunion";
}
}

if (!function_exists('dlbh_inbox_build_email_action_button_html')) {
function dlbh_inbox_build_email_action_button_html($label, $url) {
    $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars((string)$url, ENT_QUOTES, 'UTF-8');
    return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" contenteditable="false" style="border-collapse:separate;border-spacing:0;">'
        . '<tr>'
        . '<td align="center" bgcolor="#FFE24A" style="background:#FFE24A;border:1px solid #131313;border-radius:6px;">'
        . '<a href="' . $safeUrl . '" style="display:inline-block;padding:10px 16px;font-size:14px;font-weight:600;line-height:1.2;color:#131313;text-decoration:none;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
        . $safeLabel
        . '</a>'
        . '</td>'
        . '</tr>'
        . '</table>';
}
}

if (!function_exists('dlbh_inbox_build_email_section_header_html')) {
function dlbh_inbox_build_email_section_header_html($label) {
    return '<div contenteditable="false" style="margin:0 0 12px 0;padding:8px 10px;background:#F7F9FA;border:1px solid #d9dde1;border-radius:4px;font-size:18px;font-weight:600;line-height:1.3;color:#131313;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
        . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8')
        . '</div>';
}
}

if (!function_exists('dlbh_inbox_build_eligible_compose_intro_html')) {
function dlbh_inbox_build_eligible_compose_intro_html($selectedFields, $primaryMemberName) {
    $primaryMemberName = trim((string)$primaryMemberName);
    if ($primaryMemberName === '') $primaryMemberName = 'Family';
    $firstNameParts = preg_split('/\s+/', $primaryMemberName);
    $firstName = (is_array($firstNameParts) && !empty($firstNameParts)) ? trim((string)$firstNameParts[0]) : $primaryMemberName;
    if ($firstName === '') $firstName = 'Family';

    $familyId = dlbh_inbox_get_field_value($selectedFields, 'Family ID');
    $contactEmail = dlbh_inbox_get_field_value($selectedFields, 'Email');
    $periodStart = dlbh_inbox_get_field_value($selectedFields, 'Period Start');
    $periodEnd = dlbh_inbox_get_field_value($selectedFields, 'Period End');
    $dueDate = dlbh_inbox_get_field_value($selectedFields, 'Due Date');
    $graceDate = dlbh_inbox_get_field_value($selectedFields, 'Grace Period End Date');
    $delinquencyDate = dlbh_inbox_get_field_value($selectedFields, 'Delinquency Date');

    if ($familyId === '') $familyId = 'DLBHF-00000';
    if ($contactEmail === '') $contactEmail = 'The same email you used to complete your Family Reunion Enrollment';
    $passwordHint = preg_replace('/\D+/', '', $familyId);
    if ($passwordHint === '') $passwordHint = $familyId;

    $periodStartSlash = dlbh_inbox_format_date_input_value($periodStart);
    $periodEndSlash = dlbh_inbox_format_date_input_value($periodEnd);
    $dueDateFriendly = dlbh_inbox_format_date_friendly($dueDate);
    $dueDateSlash = dlbh_inbox_format_date_input_value($dueDate);
    $graceDateFriendly = dlbh_inbox_format_date_friendly($graceDate);
    $graceDateSlash = dlbh_inbox_format_date_input_value($graceDate);
    $delinquencyDateFriendly = dlbh_inbox_format_date_friendly($delinquencyDate);
    $delinquencyDateSlash = dlbh_inbox_format_date_input_value($delinquencyDate);

    if ($dueDateFriendly === '') $dueDateFriendly = 'your posted due date';
    if ($graceDateFriendly === '') $graceDateFriendly = 'your posted grace period end date';
    if ($delinquencyDateFriendly === '') $delinquencyDateFriendly = 'your posted delinquency date';

    return ''
        . '<div style="display:block;">'
        . '<div style="margin:0 0 18px 0;padding:0;">'
            . dlbh_inbox_build_email_section_header_html('Welcome')
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Hi <strong>' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ',</strong></p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">We can\'t wait to see you.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">You\'re all set — we\'ve received your Membership Enrollment for the <strong>Damie Lee Burton Howard Family Reunion (July 23–25, 2027)</strong>. Completing your Membership Enrollment lets us know you\'ll be there — and we truly hope you will be. This weekend is about reconnecting, celebrating our roots, and continuing the legacy we share as one family, with plenty of connection, history, laughter, and memories in between.</p>'
            . '<p style="margin:0 0 14px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Your enrollment details are included below for your records. Please retain a copy of this email for future reference.</p>'
            . '<div style="margin:0 0 18px 0;">' . dlbh_inbox_build_email_action_button_html('Details', 'https://dlbhfamily.com/details/') . '</div>'
            . dlbh_inbox_build_email_section_header_html('Family ID & Account Access')
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">First things first: your Family ID is <strong>' . htmlspecialchars($familyId, ENT_QUOTES, 'UTF-8') . '</strong>. Please save it. You\'ll use this each time you submit a payment and anytime you need support.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Your Family Access account has also been successfully set up.</p>'
            . '<p style="margin:0 0 16px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Please allow 15–30 minutes for your account to fully activate before attempting to sign in.</p>'
            . dlbh_inbox_build_email_section_header_html('Sign In Instructions')
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">When signing in, use the same email address submitted with your Family Reunion Enrollment.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;"><strong>Email Address:</strong> ' . htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 16px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;"><strong>Password:</strong> ' . htmlspecialchars($passwordHint, ENT_QUOTES, 'UTF-8') . '</p>'
            . dlbh_inbox_build_email_section_header_html('Family Access')
            . '<p style="margin:0 0 10px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Family Access allows you to:</p>'
            . '<ul style="margin:0 0 14px 22px;padding:0;color:#595959;font-size:14px;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                . '<li style="margin:0 0 6px 0;">View your household membership record</li>'
                . '<li style="margin:0 0 6px 0;">Confirm your membership status</li>'
                . '<li style="margin:0 0 6px 0;">Review billing statements</li>'
                . '<li style="margin:0 0 6px 0;">View payment history</li>'
                . '<li style="margin:0;">Make dues payments securely</li>'
            . '</ul>'
            . '<p style="margin:0 0 14px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">If you experience any issues accessing your account after the activation window, please reply to this email for assistance.</p>'
            . '<div style="margin:0 0 18px 0;">' . dlbh_inbox_build_email_action_button_html('Sign In', 'https://dlbhfamily.com/portal/') . '</div>'
            . dlbh_inbox_build_email_section_header_html('Article XI - Family Reunion Dues')
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Now let\'s talk about Family Dues — what they are and why they matter.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Family Dues are the household contribution that helps fund and support the Family Reunion\'s financial needs and requirements. That includes securing spaces and deposits, food and hospitality, activities and programming, supplies and on-site logistics, communication and planning tools, and the administrative and financial operations that keep everything organized and accountable.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Here\'s the official structure, straight from <strong>Article XI - Family Reunion Dues:</strong></p>'
            . '<p style="margin:0 0 10px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;"><strong>Dues are $20.00 per month per household.</strong></p>'
            . '<p style="margin:0 0 10px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">A household includes:</p>'
            . '<ul style="margin:0 0 14px 22px;padding:0;color:#595959;font-size:14px;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                . '<li style="margin:0 0 6px 0;">A Primary Member who is 19 or older</li>'
                . '<li style="margin:0 0 6px 0;">A Spouse/Partner who is 17 or older</li>'
                . '<li style="margin:0;">Any Dependents who are 18 or younger</li>'
            . '</ul>'
            . '<p style="margin:0 0 16px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Everyone in your household is covered under one membership — dues are paid once per household, not per person.</p>'
            . dlbh_inbox_build_email_section_header_html('Account Summary')
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Your dues payment is due on <strong>' . htmlspecialchars($dueDateFriendly, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">When you click <strong>Choose Your Plan</strong>, you can set up auto-renew at:</p>'
            . '<ul style="margin:0 0 14px 22px;padding:0;color:#595959;font-size:14px;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                . '<li style="margin:0 0 6px 0;">$20 monthly</li>'
                . '<li style="margin:0 0 6px 0;">$60 quarterly</li>'
                . '<li style="margin:0 0 6px 0;">$120 semi-annually</li>'
                . '<li style="margin:0;">$240 annually</li>'
            . '</ul>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Or you can make a one-time payment of <strong>$20, $60, $120, or $240</strong> — whichever works best for your household.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">If payment isn\'t received by <strong>' . htmlspecialchars($graceDateFriendly, ENT_QUOTES, 'UTF-8') . '</strong>, your account will be marked <strong>Late</strong>.<br>If it still isn\'t received by <strong>' . htmlspecialchars($delinquencyDateFriendly, ENT_QUOTES, 'UTF-8') . '</strong>, your account will be marked <strong>Delinquent</strong>.</p>'
            . '<p style="margin:0 0 14px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">When your dues are current, your membership is <strong>Active</strong>, and your household is fully included in reunion events and meetings. If dues are not current, membership becomes <strong>Inactive</strong>, and we\'ll need to bring things up to date before the reunion so you can fully participate with the family.</p>'
            . '<div style="margin:0 0 18px 0;">' . dlbh_inbox_build_email_action_button_html('Choose Your Plan', 'https://dlbhfamily.com/dues/') . '</div>'
            . dlbh_inbox_build_email_section_header_html('Help Wanted')
            . '<p style="margin:0 0 10px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Before you go — we\'re also recruiting volunteers for our Standing Committees:</p>'
            . '<ul style="margin:0 0 14px 22px;padding:0;color:#595959;font-size:14px;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                . '<li style="margin:0 0 6px 0;">Membership Committee</li>'
                . '<li style="margin:0 0 6px 0;">Finance Committee</li>'
                . '<li style="margin:0 0 6px 0;">Reunion Committee</li>'
                . '<li style="margin:0;">Newsroom Committee</li>'
            . '</ul>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">We\'re looking for family members in good standing who can show up consistently, follow guidelines, communicate respectfully, and help move the work forward.</p>'
            . '<p style="margin:0 0 14px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">If you\'re ready to serve and support the reunion behind the scenes, click <strong>I\'ll Help</strong> to learn more.</p>'
            . '<div style="margin:0 0 18px 0;">' . dlbh_inbox_build_email_action_button_html('I\'ll Help', 'https://dlbhfamily.com/committees/') . '</div>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">We\'re glad to have your household officially enrolled and connected through Family Access.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;"><strong>Sincerely,</strong><br><strong>Membership Committee</strong><br><strong>Damie Lee Burton Howard Family Reunion</strong></p>'
        . '</div>'
        . '</div>';
}
}

if (!function_exists('dlbh_inbox_build_aging_compose_intro_html')) {
function dlbh_inbox_build_aging_compose_intro_html($selectedFields, $primaryMemberName, $composeMode) {
    $primaryMemberName = trim((string)$primaryMemberName);
    if ($primaryMemberName === '') $primaryMemberName = 'Member';
    $familyId = trim((string)dlbh_inbox_get_field_value_by_label($selectedFields, 'Family ID'));
    $dueDate = dlbh_inbox_format_date_friendly(dlbh_inbox_get_field_value_by_label($selectedFields, 'Due Date'));
    $amountDue = trim((string)dlbh_inbox_get_field_value_by_label($selectedFields, 'Remaining Previous Balance'));
    if ($dueDate === '') $dueDate = 'your due date';
    if ($amountDue === '') $amountDue = '$0.00';

    if ($composeMode === 'aging_delinquent') {
        return ''
            . '<div style="display:block;">'
            . '<div style="margin:0 0 18px 0;padding:0;">'
                . dlbh_inbox_build_email_section_header_html('Delinquent Notice')
                . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Dear <strong>' . htmlspecialchars($primaryMemberName, ENT_QUOTES, 'UTF-8') . ',</strong></p>'
                . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">This notice is to inform you that your Family Dues account is now classified as <strong>Delinquent</strong>.</p>'
                . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Our records show that payment was originally due on <strong>' . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '</strong>. The 15-day grace period has expired, and an additional 15 days have passed without receipt of payment. As a result, the account is now 30 days past the original due date.</p>'
                . '<p style="margin:0 0 16px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Your total outstanding balance is <strong>' . htmlspecialchars($amountDue, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . dlbh_inbox_build_email_section_header_html('Make a Payment')
                . '<p style="margin:0 0 14px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Please submit payment promptly to restore your account to Current status.</p>'
                . '<div style="margin:0 0 18px 0;">' . dlbh_inbox_build_email_action_button_html('Make a Payment', 'https://dlbhfamily.com/dues/') . '</div>'
                . dlbh_inbox_build_email_section_header_html('Account Support')
                . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Family Dues support all planning, deposits, and operational commitments related to the Damie Lee Burton Howard Family Reunion. Maintaining accounts in good standing allows us to plan responsibly and equitably for all participating households.</p>'
                . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">If payment has already been submitted, please disregard this notice. If you are experiencing hardship or believe this notice was sent in error, reply to this email so we can review your account.</p>'
                . '<p style="margin:0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;"><strong>Sincerely,</strong><br><strong>Membership Committee</strong><br><strong>Damie Lee Burton Howard Family Reunion</strong></p>'
            . '</div>'
            . '</div>';
    }

    return ''
        . '<div style="display:block;">'
        . '<div style="margin:0 0 18px 0;padding:0;">'
            . dlbh_inbox_build_email_section_header_html('Past Due Notice')
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Dear <strong>' . htmlspecialchars($primaryMemberName, ENT_QUOTES, 'UTF-8') . ',</strong></p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">This is a courtesy notice that your Family Dues are currently <strong>Past Due</strong>.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Our records indicate that payment was due on <strong>' . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '</strong>, and we have not yet received the required monthly dues of $20 per household. As outlined in our Family Dues policy, a 15-day grace period is provided following the due date. That grace period has now expired.</p>'
            . '<p style="margin:0 0 16px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Your total outstanding balance is <strong>' . htmlspecialchars($amountDue, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . dlbh_inbox_build_email_section_header_html('Make a Payment')
            . '<p style="margin:0 0 14px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">We kindly ask that you submit your payment as soon as possible to bring your account current and avoid further delinquency status.</p>'
            . '<div style="margin:0 0 18px 0;">' . dlbh_inbox_build_email_action_button_html('Make a Payment', 'https://dlbhfamily.com/dues/') . '</div>'
            . dlbh_inbox_build_email_section_header_html('Account Support')
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">Family Dues directly support the planning and execution of our upcoming reunion, including venue commitments, event planning, and operational expenses. Your continued participation and timely support are greatly appreciated.</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">If you have already submitted payment, please disregard this notice. If you have any questions regarding your account, please reply to this email for assistance.</p>'
            . '<p style="margin:0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;"><strong>Sincerely,</strong><br><strong>Membership Committee</strong><br><strong>Damie Lee Burton Howard Family Reunion</strong></p>'
        . '</div>'
        . '</div>';
}
}

if (!function_exists('dlbh_inbox_force_html_mail_content_type')) {
function dlbh_inbox_force_html_mail_content_type() {
    return 'text/html; charset=UTF-8';
}
}

if (!function_exists('dlbh_inbox_membership_subject')) {
function dlbh_inbox_membership_subject() {
    return 'Damie Lee Burton Howard Family Reunion | New Membership';
}
}

if (!function_exists('dlbh_inbox_membership_eligible_subject')) {
function dlbh_inbox_membership_eligible_subject() {
    return "Damie Lee Burton Howard Family Reunion | Your all set!";
}
}

if (!function_exists('dlbh_inbox_membership_action_required_subject')) {
function dlbh_inbox_membership_action_required_subject() {
    return 'Damie Lee Burton Howard Family Reunion | New Membership - Action Required';
}
}

if (!function_exists('dlbh_inbox_extract_reply_message')) {
function dlbh_inbox_extract_reply_message($text) {
    $text = trim((string)$text);
    if ($text === '') return '';
    $hasReplyMarker = (
        preg_match('/\nOn .+? wrote:/is', $text) ||
        preg_match('/\nFrom:\s.+/is', $text)
    );
    if (!$hasReplyMarker) return '';
    if (preg_match('/^(.*?)(?:\nOn .+? wrote:|\nFrom:\s.+)/is', $text, $replyMatch)) {
        $candidateReply = dlbh_inbox_clean_reply_message_text((string)$replyMatch[1]);
        $looksLikeCss = (
            stripos($candidateReply, 'wpforms') !== false ||
            stripos($candidateReply, '@media') !== false ||
            stripos($candidateReply, '.wpforms') !== false ||
            preg_match('/\{[^}]+\}/', $candidateReply)
        );
        if ($candidateReply !== '' && !$looksLikeCss && stripos($candidateReply, 'membership committee') === false) {
            return $candidateReply;
        }
    }
    return '';
}
}

if (!function_exists('dlbh_inbox_post_value')) {
function dlbh_inbox_post_value($key, $default = '') {
    if (!isset($_POST[$key])) return $default;
    $value = $_POST[$key];
    if (function_exists('wp_unslash')) {
        $value = wp_unslash($value);
    } elseif (is_string($value)) {
        $value = stripslashes($value);
    }
    return is_string($value) ? $value : $default;
}
}

if (!function_exists('dlbh_inbox_build_composed_email_html')) {
function dlbh_inbox_build_composed_email_html($composeBody, $issues, $selectedFields, $verification = null, $options = array()) {
    $showTopHeader = true;
    $showIntroBody = true;
    $showStatusCard = true;
    $showFieldErrors = true;
    $showDetails = true;
    $composeMode = '';
    $excludeReplyMessage = false;
    $detailsCardTitle = 'Membership Enrollment Form';
    if (is_array($options)) {
        if (isset($options['show_top_header'])) $showTopHeader = (bool)$options['show_top_header'];
        if (isset($options['show_intro_body'])) $showIntroBody = (bool)$options['show_intro_body'];
        if (isset($options['show_status_card'])) $showStatusCard = (bool)$options['show_status_card'];
        if (isset($options['show_field_errors'])) $showFieldErrors = (bool)$options['show_field_errors'];
        if (isset($options['show_details'])) $showDetails = (bool)$options['show_details'];
        if (isset($options['compose_mode'])) $composeMode = trim((string)$options['compose_mode']);
        if (isset($options['exclude_reply_message'])) $excludeReplyMessage = (bool)$options['exclude_reply_message'];
        if (isset($options['details_card_title'])) $detailsCardTitle = trim((string)$options['details_card_title']);
    }
    $primaryMemberName = '';
    $currentRelationship = '';
    if (is_array($selectedFields)) {
        foreach ($selectedFields as $f) {
            if (!is_array($f)) continue;
            $ft = isset($f['type']) ? strtolower(trim((string)$f['type'])) : 'field';
            if ($ft === 'header') continue;
            $fl = isset($f['label']) ? trim((string)$f['label']) : '';
            $fv = isset($f['value']) ? trim((string)$f['value']) : '';
            if (strcasecmp($fl, 'Relationship') === 0) {
                $currentRelationship = $fv;
                continue;
            }
            if (strcasecmp($fl, 'Primary Member') === 0 && $primaryMemberName === '') {
                $primaryMemberName = $fv;
                continue;
            }
            if (strcasecmp($fl, 'Name') === 0 && strcasecmp($currentRelationship, 'Primary Member') === 0) {
                $primaryMemberName = $fv;
                break;
            }
        }
    }
    if ($primaryMemberName === '') $primaryMemberName = 'Member';
    $familyIdValue = trim((string)dlbh_inbox_get_field_value_by_label($selectedFields, 'Family ID'));
    $eligibilityStatus = (!is_array($issues) || empty($issues)) ? 'Eligible' : 'Not Eligible';

    $invalidDobIndices = array();
    $dobErrorsByIndex = array();
    if (is_array($verification)) {
        $invalidDobIndices = isset($verification['invalid_dob_indices']) && is_array($verification['invalid_dob_indices'])
            ? $verification['invalid_dob_indices']
            : array();
        $dobErrorsByIndex = isset($verification['dob_errors_by_index']) && is_array($verification['dob_errors_by_index'])
            ? $verification['dob_errors_by_index']
            : array();
    }

    $detailsHtml = '<div style="display:block;">'
        . '<div contenteditable="false" style="margin:0 0 14px 0;padding:14px 16px;background:#131313;color:#FFFFFF;border-radius:8px 8px 0 0;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
        . '<div style="font-size:12px;opacity:.9;letter-spacing:.03em;text-transform:uppercase;">Damie Lee Burton Howard Family Reunion</div>'
        . '<div style="font-weight:600;font-size:18px;margin-top:3px;">' . htmlspecialchars(($detailsCardTitle !== '' ? $detailsCardTitle : 'Details'), ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div>';
    $isNotEligibleEmail = (is_array($issues) && !empty($issues));
    $excludeEmailLabels = $isNotEligibleEmail ? array(
        'group' => true,
        'family id' => true,
        'class' => true,
        'bill group' => true,
        'location code' => true,
        'previous balance' => true,
        'last payment received amount' => true,
        'last payment received date' => true,
        'remaining previous balance' => true,
        'period start' => true,
        'period end' => true,
        'charges' => true,
        'total due' => true,
        'due date' => true,
        'grace period end date' => true,
        'delinquency date' => true,
    ) : array();
    $headerHasVisibleContent = array();
    if (is_array($selectedFields)) {
        $totalFields = count($selectedFields);
        for ($scanIdx = 0; $scanIdx < $totalFields; $scanIdx++) {
            $scanRow = $selectedFields[$scanIdx];
            if (!is_array($scanRow)) continue;
            $scanType = isset($scanRow['type']) ? strtolower(trim((string)$scanRow['type'])) : 'field';
            if ($scanType !== 'header') continue;
            $scanHeaderLabel = isset($scanRow['label']) ? trim((string)$scanRow['label']) : '';
            $isRecordHeader = (
                strcasecmp($scanHeaderLabel, 'Spouse Information') === 0 ||
                preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $scanHeaderLabel)
            );
            $hasVisible = false;
            $hasRealRecordContent = false;
            for ($nextIdx = $scanIdx + 1; $nextIdx < $totalFields; $nextIdx++) {
                $nextRow = $selectedFields[$nextIdx];
                if (!is_array($nextRow)) continue;
                $nextType = isset($nextRow['type']) ? strtolower(trim((string)$nextRow['type'])) : 'field';
                if ($nextType === 'header') break;
                $nextLabel = isset($nextRow['label']) ? trim((string)$nextRow['label']) : '';
                $nextValue = isset($nextRow['value']) ? trim((string)$nextRow['value']) : '';
                if ($excludeReplyMessage && (strcasecmp($nextLabel, 'Reply Message') === 0 || strcasecmp($nextLabel, 'Message') === 0)) continue;
                $nextKey = strtolower($nextLabel);
                if ($isNotEligibleEmail && isset($excludeEmailLabels[$nextKey])) continue;
                $hasVisible = true;
                    if ($isRecordHeader) {
                        $defaultValue = '';
                        if (strcasecmp($scanHeaderLabel, 'Spouse Information') === 0) {
                        if (strcasecmp($nextLabel, 'Relationship') === 0) $defaultValue = 'Spouse';
                        elseif (strcasecmp($nextLabel, 'Do you or anyone your enrolling today have any allergies or food restrictions?') === 0) $defaultValue = 'No';
                        elseif (strcasecmp($nextLabel, 'Have you or anyone your enrolling today served or are serving in the United States Armed Forces?') === 0) $defaultValue = 'No';
                    } else {
                        if (strcasecmp($nextLabel, 'Relationship') === 0) $defaultValue = 'Dependent';
                        elseif (strcasecmp($nextLabel, 'Do you have any allergies or food restrictions?') === 0) $defaultValue = 'No';
                        elseif (strcasecmp($nextLabel, 'Have you or are you serving in the United States Armed Forces?') === 0) $defaultValue = 'No';
                    }
                    if ($nextValue !== '' && strcasecmp($nextValue, $defaultValue) !== 0) {
                        $hasRealRecordContent = true;
                    }
                } else {
                    break;
                }
            }
            $headerHasVisibleContent[$scanIdx] = $isRecordHeader ? $hasRealRecordContent : $hasVisible;
        }
    }
    $skipAccountSummarySection = false;
    $skipHiddenSection = false;
    if (is_array($selectedFields)) {
        foreach ($selectedFields as $fieldIdx => $f) {
            if (!is_array($f)) continue;
            $ft = isset($f['type']) ? strtolower(trim((string)$f['type'])) : 'field';
            $fl = isset($f['label']) ? (string)$f['label'] : '';
            $fv = isset($f['value']) ? (string)$f['value'] : '';
            if ($ft === 'header') {
                $headerKey = strtolower(trim($fl));
                $skipHiddenSection = false;
                if ($isNotEligibleEmail && $headerKey === 'account summary information') {
                    $skipAccountSummarySection = true;
                    continue;
                }
                if (isset($headerHasVisibleContent[$fieldIdx]) && !$headerHasVisibleContent[$fieldIdx]) {
                    $skipHiddenSection = true;
                    continue;
                }
                if ($headerKey !== '') {
                    $skipAccountSummarySection = false;
                }
                $detailsHtml .= '<div contenteditable="false" style="margin:12px 0 6px 0;padding:6px 8px;background:#F7F9FA;border:1px solid #d9dde1;border-radius:4px;font-size:14px;font-weight:600;color:#131313;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                    . htmlspecialchars($fl, ENT_QUOTES, 'UTF-8')
                    . '</div>';
                continue;
            }
            if ($isNotEligibleEmail && $skipAccountSummarySection) continue;
            if ($skipHiddenSection) continue;
            $fieldKey = strtolower(trim($fl));
            if ($excludeReplyMessage && (strcasecmp(trim($fl), 'Reply Message') === 0 || strcasecmp(trim($fl), 'Message') === 0)) continue;
            if ($isNotEligibleEmail && isset($excludeEmailLabels[$fieldKey])) continue;
            $fieldValue = $fv;
            if (strcasecmp(trim($fl), 'Address') === 0) {
                $fieldValue = ucwords(strtolower($fieldValue));
            }
            $isDateField = (stripos($fl, 'date') !== false || strcasecmp(trim($fl), 'Date of Birth') === 0);
            if ($isDateField) {
                $fieldValue = dlbh_inbox_format_date_friendly($fieldValue);
            }
            $isYesNo = in_array(strtolower(trim($fieldValue)), array('yes', 'no'), true);
            $isShirt = (strcasecmp(trim($fl), 'T-Shirt Size') === 0);
            $useTextarea = (strlen($fieldValue) > 85 || strpos($fieldValue, "\n") !== false);
            $isDob = (strcasecmp(trim($fl), 'Date of Birth') === 0);
            $hasDobError = ($isDob && isset($invalidDobIndices[(int)$fieldIdx]));
            $dobErrorText = $hasDobError && isset($dobErrorsByIndex[(int)$fieldIdx])
                ? (string)$dobErrorsByIndex[(int)$fieldIdx]
                : 'Date of Birth does not meet eligibility requirements.';
            $controlBorder = ($showFieldErrors && $hasDobError) ? '2px solid #cc0000' : '1px solid #d9dde1';
            $controlBg = ($showFieldErrors && $hasDobError) ? '#fff5f5' : '#F7F9FA';

            $controlHtml = '';
            if (strcasecmp(trim($fl), 'Relationship') === 0) {
                $relationshipVal = trim((string)$fieldValue);
                $controlHtml = '<select disabled style="width:100%;box-sizing:border-box;border:' . $controlBorder . ';border-radius:4px;background:' . $controlBg . ';color:#595959;font-size:14px;line-height:1.5;padding:8px 10px;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                    . '<option value="Primary Member"' . (strcasecmp($relationshipVal, 'Primary Member') === 0 ? ' selected' : '') . '>Primary Member</option>'
                    . '<option value="Spouse"' . ((strcasecmp($relationshipVal, 'Spouse') === 0 || strcasecmp($relationshipVal, 'Spouse/Partner') === 0) ? ' selected' : '') . '>Spouse</option>'
                    . '<option value="Dependent"' . (strcasecmp($relationshipVal, 'Dependent') === 0 ? ' selected' : '') . '>Dependent</option>'
                    . '</select>';
            } elseif ($isYesNo) {
                $yesSelected = (strtolower(trim($fieldValue)) === 'yes') ? ' selected' : '';
                $noSelected = (strtolower(trim($fieldValue)) === 'no') ? ' selected' : '';
                $controlHtml = '<select disabled style="width:100%;box-sizing:border-box;border:' . $controlBorder . ';border-radius:4px;background:' . $controlBg . ';color:#595959;font-size:14px;line-height:1.5;padding:8px 10px;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                    . '<option value="Yes"' . $yesSelected . '>Yes</option>'
                    . '<option value="No"' . $noSelected . '>No</option>'
                    . '</select>';
            } elseif ($isShirt) {
                $shirtVal = strtoupper(trim($fieldValue));
                $sizeOptions = array('S', 'M', 'L', 'XL', '2XL', '3XL');
                $optionsHtml = '';
                foreach ($sizeOptions as $sizeOption) {
                    $selectedAttr = ($shirtVal === $sizeOption) ? ' selected' : '';
                    $optionsHtml .= '<option value="' . htmlspecialchars($sizeOption, ENT_QUOTES, 'UTF-8') . '"' . $selectedAttr . '>' . htmlspecialchars($sizeOption, ENT_QUOTES, 'UTF-8') . '</option>';
                }
                $controlHtml = '<select disabled style="width:100%;box-sizing:border-box;border:' . $controlBorder . ';border-radius:4px;background:' . $controlBg . ';color:#595959;font-size:14px;line-height:1.5;padding:8px 10px;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                    . $optionsHtml
                    . '</select>';
            } elseif ($useTextarea) {
                $controlHtml = '<textarea readonly style="width:100%;box-sizing:border-box;border:' . $controlBorder . ';border-radius:4px;background:' . $controlBg . ';color:#595959;font-size:14px;line-height:1.5;padding:8px 10px;min-height:74px;resize:vertical;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                    . htmlspecialchars($fieldValue, ENT_QUOTES, 'UTF-8')
                    . '</textarea>';
            } else {
                $controlHtml = '<input readonly type="text" value="' . htmlspecialchars($fieldValue, ENT_QUOTES, 'UTF-8') . '" style="width:100%;box-sizing:border-box;border:' . $controlBorder . ';border-radius:4px;background:' . $controlBg . ';color:#595959;font-size:14px;line-height:1.5;padding:8px 10px;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">';
            }

            $detailsHtml .= '<div style="margin:0 0 10px 0;">'
                . '<div style="display:block;margin-bottom:5px;font-size:14px;font-weight:500;color:#595959;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">' . htmlspecialchars($fl, ENT_QUOTES, 'UTF-8') . '</div>'
                . $controlHtml
                . (($showFieldErrors && $hasDobError)
                    ? '<div style="margin-top:6px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:6px;padding:7px 8px;display:flex;gap:8px;align-items:flex-start;">'
                        . '<span style="width:16px;height:16px;min-width:16px;border-radius:50%;border:1px solid #fca5a5;background:#fee2e2;color:#b42318;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;line-height:1;margin-top:1px;">!</span>'
                        . '<span><span style="display:block;font-size:11px;font-weight:700;margin-bottom:2px;">Age Verification Required</span>'
                        . '<span style="display:block;font-size:11px;font-weight:700;line-height:1.3;color:#991b1b;">' . htmlspecialchars($dobErrorText, ENT_QUOTES, 'UTF-8') . '</span></span>'
                      . '</div>'
                    : '')
                . '</div>';
        }
    }
    $detailsHtml .= '</div>';

    $composeBodyHtml = '';
    if ($showIntroBody && $composeMode === 'eligible') {
        $composeBodyHtml = dlbh_inbox_build_eligible_compose_intro_html($selectedFields, $primaryMemberName);
    } elseif ($showIntroBody && ($composeMode === 'aging_past_due' || $composeMode === 'aging_delinquent')) {
        $composeBodyHtml = dlbh_inbox_build_aging_compose_intro_html($selectedFields, $primaryMemberName, $composeMode);
    } elseif ($showIntroBody) {
        $bodyBlocks = preg_split('/\n\s*\n/', trim((string)$composeBody));
        if (is_array($bodyBlocks) && !empty($bodyBlocks)) {
            foreach ($bodyBlocks as $block) {
                $block = trim((string)$block);
                if ($block === '') continue;
                $lines = preg_split('/\r\n|\r|\n/', $block);
                $lineHtml = array();
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $line = trim((string)$line);
                        if ($line === '') continue;
                        $safeLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

                        if (preg_match('/^Dear\s+(.+),$/i', $line, $mDear)) {
                            $safeName = htmlspecialchars((string)$mDear[1], ENT_QUOTES, 'UTF-8');
                            $safeLine = 'Dear <strong>' . $safeName . '</strong>,';
                        } elseif (
                            $line === 'Primary Member: Must be 19 years of age or older' ||
                            $line === 'Spouse: Must be 17 years of age or older' ||
                            $line === 'Dependent: Must be 18 years of age or younger' ||
                            $line === 'Military Service: Must be 17 years of age or older to serve in the United States Armed Forces' ||
                            $line === 'At this time, we are unable to finalize your enrollment until the Date(s) of Birth have been verified or corrected.' ||
                            $line === 'Sincerely,' ||
                            $line === 'Membership Committee' ||
                            $line === 'Damie Lee Burton Howard Family Reunion'
                        ) {
                            $safeLine = '<strong>' . $safeLine . '</strong>';
                        }
                        if ($composeMode === 'aging_past_due' || $composeMode === 'aging_delinquent') {
                            if (preg_match('/payment was (?:due|originally due) on (.+?)(?:(?:, and)|\.)/i', $line, $mDue)) {
                                $dueText = trim((string)$mDue[1]);
                                $safeDueText = htmlspecialchars($dueText, ENT_QUOTES, 'UTF-8');
                                $safeLine = preg_replace('/' . preg_quote($safeDueText, '/') . '/', '<strong>' . $safeDueText . '</strong>', $safeLine, 1);
                            }
                            if (preg_match('/Your total outstanding balance is\s*(\$[0-9,]+(?:\.[0-9]{2})?)\./i', $line, $mAmount)) {
                                $amountText = trim((string)$mAmount[1]);
                                $safeAmountText = htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8');
                                $safeLine = str_replace($safeAmountText, '<strong>' . $safeAmountText . '</strong>', $safeLine);
                            }
                        }
                        if (stripos($line, 'identified one or more discrepancies related to the Date(s) of Birth entered on your enrollment form') !== false) {
                            $boldPhrase = htmlspecialchars('identified one or more discrepancies related to the Date(s) of Birth entered on your enrollment form', ENT_QUOTES, 'UTF-8');
                            $safeLine = str_ireplace($boldPhrase, '<strong>' . $boldPhrase . '</strong>', $safeLine);
                        }
                        if (stripos($line, 'Damie Lee Burton Howard Family Reunion') !== false && stripos($safeLine, '<strong>') === false) {
                            $brandPhrase = htmlspecialchars('Damie Lee Burton Howard Family Reunion', ENT_QUOTES, 'UTF-8');
                            $safeLine = str_ireplace($brandPhrase, '<strong>' . $brandPhrase . '</strong>', $safeLine);
                        }

                        $lineHtml[] = $safeLine;
                    }
                }
                $composeBodyHtml .= '<p style="margin:0 0 12px 0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                    . implode('<br>', $lineHtml)
                    . '</p>';
            }
        }
        if ($composeBodyHtml === '') {
            $composeBodyHtml = '<p style="margin:0;font-size:14px;color:#595959;line-height:1.5;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">'
                . nl2br(htmlspecialchars($composeBody, ENT_QUOTES, 'UTF-8'))
                . '</p>';
        }
    }

    $html = '<div style="margin:0;padding:0;background:#F7F9FA;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#595959;line-height:1.5;">'
        . '<div style="width:100%;max-width:none;margin:0;background:#FFFFFF;border:1px solid #d9dde1;border-radius:0;overflow:hidden;box-sizing:border-box;">';
    if ($showTopHeader) {
        $html .= '<div contenteditable="false" style="padding:14px 16px;background:#131313;color:#FFFFFF;">'
            . '<div style="font-size:12px;opacity:.9;letter-spacing:.03em;text-transform:uppercase;">Damie Lee Burton Howard Family Reunion</div>'
            . '<div style="font-weight:600;font-size:18px;margin-top:3px;">Membership Committee</div>'
            . '</div>';
    }
    $html .= '<div style="padding:16px 18px;">';
    if ($composeBodyHtml !== '') {
        $html .= $composeBodyHtml;
    }
    if ($showDetails) {
        if ($composeBodyHtml !== '') {
            $html .= '<div style="height:1px;background:#d9dde1;margin:18px 0;"></div>';
        }
    }
    if ($showStatusCard) {
        if (!$showDetails && $composeBodyHtml !== '') {
            $html .= '<div style="height:1px;background:#d9dde1;margin:18px 0;"></div>';
        }
        $html .= '<div contenteditable="false" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 12px 0;padding:8px 10px;border:1px solid #d9dde1;border-radius:8px;background:#F7F9FA;">'
            . '<div>'
                . '<div style="font-size:14px;font-weight:600;color:#131313;">' . htmlspecialchars($primaryMemberName, ENT_QUOTES, 'UTF-8') . '</div>'
                . ($familyIdValue !== ''
                    ? '<div style="margin-top:2px;font-size:12px;font-weight:500;color:#595959;">' . htmlspecialchars($familyIdValue, ENT_QUOTES, 'UTF-8') . '</div>'
                    : '')
                . '</div>'
            . '<span contenteditable="false" style="box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-width:140px;min-height:34px;border:1px solid ' . (!empty($issues) ? '#131313;background:#FFE24A;color:#131313' : '#131313;background:#FFE24A;color:#131313') . ';border-radius:6px;padding:8px 12px;font-size:12px;font-weight:600;line-height:1;margin-left:12px;cursor:default;pointer-events:none;">' . htmlspecialchars($eligibilityStatus, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>';
    }
    if ($showDetails) {
        $html .= $detailsHtml;
    }
    $html .= '</div>'
        . '</div>'
        . '</div>';
    return $html;
}
}

if (!function_exists('dlbh_inbox_extract_body_inner_html')) {
function dlbh_inbox_extract_body_inner_html($html) {
    $html = (string)$html;
    if ($html === '') return '';
    if (preg_match('/<body[^>]*>([\s\S]*)<\/body>/i', $html, $m)) {
        return (string)$m[1];
    }
    return $html;
}
}

if (!function_exists('dlbh_bingo_portal_shortcode')) {
function dlbh_bingo_portal_shortcode($atts = array()) {
    $defaults = array(
        'server' => 'imap.titan.email',
        'port' => '993',
        'folder' => 'INBOX',
        'subject_filter' => 'Damie Lee Burton Howard Family Reunion | New Membership',
        'access_email' => '',
        'access_password' => '',
    );
    $a = function_exists('shortcode_atts') ? shortcode_atts($defaults, $atts, 'bingo') : array_merge($defaults, (array)$atts);

    $server = trim((string)$a['server']);
    $port = (int)$a['port'];
    $folder = trim((string)$a['folder']);
    $subjectFilter = (string)$a['subject_filter'];
    $accessEmail = trim((string)$a['access_email']);
    $accessPassword = (string)$a['access_password'];
    if ($accessEmail === '' && defined('DLBH_PORTAL_ACCESS_EMAIL')) {
        $accessEmail = trim((string)constant('DLBH_PORTAL_ACCESS_EMAIL'));
    }
    if ($accessPassword === '' && defined('DLBH_PORTAL_ACCESS_PASSWORD')) {
        $accessPassword = (string)constant('DLBH_PORTAL_ACCESS_PASSWORD');
    }
    if ($accessEmail === '') {
        $envAccessEmail = getenv('DLBH_PORTAL_ACCESS_EMAIL');
        if (is_string($envAccessEmail)) $accessEmail = trim($envAccessEmail);
    }
    if ($accessPassword === '') {
        $envAccessPassword = getenv('DLBH_PORTAL_ACCESS_PASSWORD');
        if (is_string($envAccessPassword)) $accessPassword = $envAccessPassword;
    }

    $error = '';
    $signedIn = false;
    $rows = array();
    $postedEmail = '';
    $memberSession = array(
        'enabled' => false,
        'family_id' => '',
        'member_key' => '',
    );
    $sessionScope = md5((string)$server . '|' . (string)$port . '|' . (string)$folder . '|' . (string)$subjectFilter);
    $authSessionKey = 'dlbh_inbox_auth_' . $sessionScope;
    $memberSessionKey = 'dlbh_inbox_member_' . $sessionScope;
    $flashSessionKey = 'dlbh_inbox_flash_' . $sessionScope;
    $statusFlashSessionKey = 'dlbh_inbox_status_' . $sessionScope;
    $submitFlashSessionKey = 'dlbh_inbox_submit_' . $sessionScope;
    $fieldsOverrideSessionKey = 'dlbh_inbox_fields_override_' . $sessionScope;
    $stateFlashSessionKey = 'dlbh_inbox_state_flash_' . $sessionScope;
    $sessionEnabled = (session_status() === PHP_SESSION_ACTIVE);

    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    $formAction = isset($_POST['dlbh_inbox_action']) ? (string)$_POST['dlbh_inbox_action'] : '';
    $statusRefreshRequested = isset($_GET['dlbh_status_refresh']) && (string)$_GET['dlbh_status_refresh'] === '1';
    $nonceOk = true;
    if ($requestMethod === 'POST' && function_exists('wp_verify_nonce')) {
        $nonce = isset($_POST['dlbh_inbox_nonce']) ? (string)$_POST['dlbh_inbox_nonce'] : '';
        $nonceOk = wp_verify_nonce($nonce, 'dlbh_inbox_signin_submit');
    }

    if ($requestMethod === 'POST' && $formAction === 'logout') {
        if ($sessionEnabled) {
            unset($_SESSION[$authSessionKey], $_SESSION[$memberSessionKey], $_SESSION[$flashSessionKey], $_SESSION[$statusFlashSessionKey], $_SESSION[$submitFlashSessionKey]);
        }
        if (!headers_sent()) {
            $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect($redirectUrl);
            } else {
                header('Location: ' . $redirectUrl);
            }
            exit;
        }
    }

    if ($requestMethod === 'POST' && $formAction === 'trash_email') {
        if (!$nonceOk) {
            $error = 'Security check failed. Please try again.';
            if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
        } elseif ($sessionEnabled && isset($_SESSION[$authSessionKey]) && is_array($_SESSION[$authSessionKey])) {
            $postedEmail = isset($_SESSION[$authSessionKey]['email']) ? trim((string)$_SESSION[$authSessionKey]['email']) : '';
            $postedPassword = isset($_SESSION[$authSessionKey]['password']) ? (string)$_SESSION[$authSessionKey]['password'] : '';
            $postedIdx = (int)dlbh_inbox_post_value('dlbh_email_idx', '-1');
            $targetRow = ($postedIdx >= 0 && isset($rows[$postedIdx]) && is_array($rows[$postedIdx]))
                ? $rows[$postedIdx]
                : null;
            if ($targetRow === null) {
                $msgNum = (int)dlbh_inbox_post_value('dlbh_msg_num', '0');
                if ($msgNum > 0) $targetRow = array('msg_num' => $msgNum);
            }
            if ($postedEmail !== '' && $postedPassword !== '' && $targetRow !== null) {
                $rw = dlbh_inbox_open_connection_rw($postedEmail, $postedPassword, $server, $port, $folder);
                if ($rw !== false) {
                    $moved = dlbh_inbox_move_row_messages($rw, $targetRow, 'Trash', 'INBOX.Trash');
                    if ($moved) {
                        if ($sessionEnabled) $_SESSION[$statusFlashSessionKey] = 'Email moved to Trash.';
                    } else {
                        $error = 'Unable to move email to Trash.';
                        if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
                    }
                    @imap_close($rw);
                } else {
                    $error = 'Unable to open mailbox for trash action.';
                    if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
                }
            } else {
                $error = 'Unable to open mailbox for trash action.';
                if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
            }
        }
        if (!headers_sent()) {
            $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            if ($redirectUrl !== '') {
                $parts = parse_url($redirectUrl);
                $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
                $query = array();
                if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                unset($query['dlbh_edit'], $query['dlbh_compose'], $query['dlbh_compose_mode'], $query['dlbh_verify'], $query['dlbh_message'], $query['dlbh_trash_confirm'], $query['dlbh_eligible_confirm']);
                $query['dlbh_status_refresh'] = '1';
                $redirectUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
            }
            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect($redirectUrl);
            } else {
                header('Location: ' . $redirectUrl);
            }
            exit;
        }
    }

    if ($requestMethod === 'POST' && $formAction === 'eligible_roster_only') {
        if (!$nonceOk) {
            $error = 'Security check failed. Please try again.';
            if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
        } elseif ($sessionEnabled && isset($_SESSION[$authSessionKey]) && is_array($_SESSION[$authSessionKey])) {
            $postedEmail = isset($_SESSION[$authSessionKey]['email']) ? trim((string)$_SESSION[$authSessionKey]['email']) : '';
            $postedPassword = isset($_SESSION[$authSessionKey]['password']) ? (string)$_SESSION[$authSessionKey]['password'] : '';
            $msgNum = (int)dlbh_inbox_post_value('dlbh_msg_num', '0');
            if ($postedEmail !== '' && $postedPassword !== '' && $msgNum > 0) {
                $rw = dlbh_inbox_open_connection_rw($postedEmail, $postedPassword, $server, $port, $folder);
                if ($rw !== false) {
                    @imap_setflag_full($rw, (string)$msgNum, "\\Seen");
                    $moved = @imap_mail_move($rw, (string)$msgNum, 'Roster');
                    if (!$moved) $moved = @imap_mail_move($rw, (string)$msgNum, 'INBOX.Roster');
                    if ($moved) {
                        @imap_expunge($rw);
                    } else {
                        $error = 'Unable to move email to Roster.';
                        if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
                    }
                    @imap_close($rw);
                } else {
                    $error = 'Unable to open mailbox for roster action.';
                    if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
                }
            }
        }
        if (!headers_sent()) {
            $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            if ($redirectUrl !== '') {
                $parts = parse_url($redirectUrl);
                $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
                $redirectUrl = $path;
            }
            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect($redirectUrl);
            } else {
                header('Location: ' . $redirectUrl);
            }
            exit;
        }
    }

    if ($requestMethod === 'POST' && $formAction === 'login' && $nonceOk) {
        $postedEmail = isset($_POST['dlbh_inbox_email']) ? trim((string)$_POST['dlbh_inbox_email']) : '';
        $postedPassword = isset($_POST['dlbh_inbox_password']) ? (string)$_POST['dlbh_inbox_password'] : '';
        $familyIdLoginKey = dlbh_inbox_normalize_family_id_lookup_key($postedPassword);
        $isFamilyIdLogin = ($familyIdLoginKey !== '' && preg_match('/^\d{5}$/', trim($postedPassword)));

        if ($postedEmail === '' || $postedPassword === '') {
            $error = 'Email and password are required.';
        } elseif ($isFamilyIdLogin) {
            $lookupEmail = ($accessEmail !== '' ? $accessEmail : $postedEmail);
            $lookupPassword = ($accessPassword !== '' ? $accessPassword : $postedPassword);
            $memberRosterRows = dlbh_inbox_collect_roster_rows($lookupEmail, $lookupPassword, $server, $port);
            $matchedRosterRow = dlbh_inbox_find_member_roster_row_by_login($memberRosterRows, $postedEmail, $familyIdLoginKey);
            if (!$matchedRosterRow) {
                $error = 'Sign-in failed. Check your email/Family ID.';
            } else {
                if ($sessionEnabled) {
                    $_SESSION[$authSessionKey] = array(
                        'email' => $lookupEmail,
                        'password' => $lookupPassword,
                    );
                    $_SESSION[$memberSessionKey] = array(
                        'email' => strtolower($postedEmail),
                        'family_id' => $familyIdLoginKey,
                        'member_key' => (string)(isset($matchedRosterRow['Member Key']) ? $matchedRosterRow['Member Key'] : ''),
                    );
                }
            }
        } else {
            $connection = dlbh_inbox_open_connection($postedEmail, $postedPassword, $server, $port, $folder);
            if ($connection === false) {
                $error = 'Sign-in failed. Check your email/password.';
            } else {
                if ($sessionEnabled) {
                    $_SESSION[$authSessionKey] = array(
                        'email' => $postedEmail,
                        'password' => $postedPassword,
                    );
                    unset($_SESSION[$memberSessionKey]);
                }
                imap_close($connection);
            }
        }

        if ($sessionEnabled) {
            $_SESSION[$flashSessionKey] = $error;
        }
        if (!headers_sent()) {
            $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            if ($redirectUrl !== '') {
                $parts = parse_url($redirectUrl);
                $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
                $query = array();
                if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                unset($query['dlbh_logout_confirm']);
                $redirectUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
            }
            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect($redirectUrl);
            } else {
                header('Location: ' . $redirectUrl);
            }
            exit;
        }
    } elseif ($requestMethod === 'POST' && $formAction === 'login' && !$nonceOk) {
        $error = 'Security check failed. Please try again.';
        if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
        if (!headers_sent()) {
            $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect($redirectUrl);
            } else {
                header('Location: ' . $redirectUrl);
            }
            exit;
        }
    }

    if ($sessionEnabled && isset($_SESSION[$flashSessionKey])) {
        $error = (string)$_SESSION[$flashSessionKey];
        unset($_SESSION[$flashSessionKey]);
    }
    $statusMessage = '';
    if ($sessionEnabled && isset($_SESSION[$statusFlashSessionKey])) {
        $statusMessage = (string)$_SESSION[$statusFlashSessionKey];
        unset($_SESSION[$statusFlashSessionKey]);
    }
    $screen = isset($_GET['dlbh_screen']) ? strtolower(trim((string)$_GET['dlbh_screen'])) : 'inbox';
    if ($screen === 'stripe') $screen = 'financials';
    if ($screen !== 'financials' && $screen !== 'roster' && $screen !== 'allergies' && $screen !== 'military' && $screen !== 'apparel' && $screen !== 'birthdays' && $screen !== 'fundraising_bingo') $screen = 'inbox';
    $financialView = isset($_GET['dlbh_financial_view']) ? strtolower(trim((string)$_GET['dlbh_financial_view'])) : 'cash_receipts_journal';
    if ($financialView !== 'statement_of_activity' && $financialView !== 'aging_report' && $financialView !== 'budget_calculator') $financialView = 'cash_receipts_journal';
    $rosterMemberKey = isset($_GET['dlbh_roster_member']) ? trim((string)$_GET['dlbh_roster_member']) : '';
    $rosterSearch = isset($_GET['dlbh_roster_search']) ? trim((string)$_GET['dlbh_roster_search']) : '';
    $statementOffset = isset($_GET['dlbh_statement_offset']) ? max(0, (int)$_GET['dlbh_statement_offset']) : 0;
    $profileSection = isset($_GET['dlbh_profile_section']) ? trim((string)$_GET['dlbh_profile_section']) : '';
    $householdMemberOffset = isset($_GET['dlbh_household_offset']) ? max(0, (int)$_GET['dlbh_household_offset']) : 0;
    $stripeRows = array();
    $rosterRows = array();
    $allergyRows = array();
    $militaryRows = array();
    $apparelRows = array();
    $birthdayRows = array();
    $rosterFamilyIds = array();
    if ($sessionEnabled && isset($_SESSION[$memberSessionKey]) && is_array($_SESSION[$memberSessionKey])) {
        $memberSession['enabled'] = true;
        $memberSession['family_id'] = dlbh_inbox_normalize_family_id_lookup_key(isset($_SESSION[$memberSessionKey]['family_id']) ? $_SESSION[$memberSessionKey]['family_id'] : '');
        $memberSession['member_key'] = trim((string)(isset($_SESSION[$memberSessionKey]['member_key']) ? $_SESSION[$memberSessionKey]['member_key'] : ''));
    }
    if ($memberSession['enabled']) {
        if ($screen !== 'roster' && $screen !== 'fundraising_bingo') $screen = 'roster';
        if ($screen === 'roster') $rosterMemberKey = $memberSession['member_key'];
    }

    if ($sessionEnabled && isset($_SESSION[$authSessionKey]) && is_array($_SESSION[$authSessionKey])) {
        $postedEmail = isset($_SESSION[$authSessionKey]['email']) ? trim((string)$_SESSION[$authSessionKey]['email']) : '';
        $postedPassword = isset($_SESSION[$authSessionKey]['password']) ? (string)$_SESSION[$authSessionKey]['password'] : '';
        if ($postedEmail !== '' && $postedPassword !== '') {
            $connection = dlbh_inbox_open_connection($postedEmail, $postedPassword, $server, $port, $folder);
            if ($connection === false) {
                unset($_SESSION[$authSessionKey]);
                if ($error === '') $error = 'Session expired or credentials are no longer valid. Please sign in again.';
            } else {
                $signedIn = true;
                if ($screen === 'financials') {
                    dlbh_inbox_process_inbox_stripe_messages($postedEmail, $postedPassword, $server, $port);
                }
                if ($screen === 'financials' || $screen === 'roster') {
                    if ($screen === 'financials') {
                        $rosterFamilyIds = dlbh_inbox_get_roster_family_ids($postedEmail, $postedPassword, $server, $port);
                    }
                    $stripeOpen = dlbh_inbox_open_connection_with_fallbacks($postedEmail, $postedPassword, $server, $port, array('Stripe', 'INBOX.Stripe'));
                    $stripeConnection = isset($stripeOpen['connection']) ? $stripeOpen['connection'] : false;
                    if ($stripeConnection !== false) {
                        $stripeRows = dlbh_inbox_collect_stripe_rows_from_connection($stripeConnection);
                        @imap_close($stripeConnection);
                    }
                }
                if ($screen === 'financials' || $screen === 'roster' || $screen === 'allergies' || $screen === 'military' || $screen === 'apparel' || $screen === 'birthdays' || $screen === 'fundraising_bingo') {
                    $rosterRows = dlbh_inbox_collect_roster_rows($postedEmail, $postedPassword, $server, $port, $stripeRows);
                    if ($memberSession['enabled']) {
                        $matchedMemberRow = dlbh_inbox_find_member_roster_row_by_login(
                            $rosterRows,
                            isset($_SESSION[$memberSessionKey]['email']) ? (string)$_SESSION[$memberSessionKey]['email'] : '',
                            $memberSession['family_id']
                        );
                        if ($matchedMemberRow) {
                            $memberSession['member_key'] = (string)(isset($matchedMemberRow['Member Key']) ? $matchedMemberRow['Member Key'] : $memberSession['member_key']);
                            if ($sessionEnabled) {
                                $_SESSION[$memberSessionKey]['member_key'] = $memberSession['member_key'];
                            }
                            $rosterRows = array($matchedMemberRow);
                        } else {
                            unset($_SESSION[$authSessionKey], $_SESSION[$memberSessionKey]);
                            $signedIn = false;
                            $error = 'Session expired or member record is no longer available. Please sign in again.';
                            @imap_close($connection);
                            $connection = false;
                        }
                    }
                    if ($connection === false) {
                        $allergyRows = array();
                        $militaryRows = array();
                        $apparelRows = array();
                        $birthdayRows = array();
                    } else {
                    $allergyRows = array_values(array_filter($rosterRows, function($row) {
                        return (
                            strcasecmp((string)(isset($row['Allergies & Food Restrictions']) ? $row['Allergies & Food Restrictions'] : ''), 'Yes') === 0 ||
                            strcasecmp((string)(isset($row['Household Allergies & Food Restrictions']) ? $row['Household Allergies & Food Restrictions'] : ''), 'Yes') === 0
                        );
                    }));
                    $militaryRows = array_values(array_filter($rosterRows, function($row) {
                        return (
                            strcasecmp((string)(isset($row['Military Status']) ? $row['Military Status'] : ''), 'Yes') === 0 ||
                            strcasecmp((string)(isset($row['Household Military Status']) ? $row['Household Military Status'] : ''), 'Yes') === 0
                        );
                    }));
                    $apparelRows = array_values(array_filter($rosterRows, function($row) {
                        return trim((string)(isset($row['T-Shirt Size']) ? $row['T-Shirt Size'] : '')) !== '';
                    }));
                    $birthdayRows = array_values(array_filter($rosterRows, function($row) {
                        return trim((string)(isset($row['Date of Birth']) ? $row['Date of Birth'] : '')) !== '';
                    }));
                    }
                }
                $emailNumbers = ($memberSession['enabled'] || $connection === false) ? false : imap_search($connection, 'ALL');
                if (is_array($emailNumbers) && !empty($emailNumbers)) {
                    rsort($emailNumbers, SORT_NUMERIC);
                    foreach ($emailNumbers as $num) {
                        $overview = imap_fetch_overview($connection, (string)$num, 0);
                        $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
                        if (!$item) continue;

                        $subject = dlbh_inbox_decode_header_text(isset($item->subject) ? (string)$item->subject : '');
                        if ($subjectFilter !== '' && stripos($subject, $subjectFilter) === false) continue;

                        $plainBody = dlbh_inbox_get_plain_text_body($connection, (int)$num);
                        $receivedRaw = isset($item->date) ? trim((string)$item->date) : '';
                        if ($receivedRaw === '' && isset($item->udate) && (int)$item->udate > 0) {
                            $receivedRaw = gmdate('D, d M Y H:i:s O', (int)$item->udate);
                        }
                        if ($receivedRaw === '' && function_exists('imap_headerinfo')) {
                            $headerInfo = @imap_headerinfo($connection, (int)$num);
                            if ($headerInfo && isset($headerInfo->MailDate)) {
                                $receivedRaw = trim((string)$headerInfo->MailDate);
                            }
                        }
                        $parsedBody = dlbh_inbox_parse_body_fields($plainBody, array('received_date' => $receivedRaw));
                        $primaryMemberName = isset($parsedBody['primary_member']) ? (string)$parsedBody['primary_member'] : '';
                        $received = dlbh_inbox_format_central_datetime($receivedRaw);
                        $receivedTs = strtotime($receivedRaw);
                        if ($receivedTs === false) $receivedTs = 0;

                        $rows[] = array(
                            'msg_num' => (int)$num,
                            'from' => $primaryMemberName,
                            'subject' => dlbh_inbox_membership_subject(),
                            'received' => $received,
                            'received_raw' => $receivedRaw,
                            'received_ts' => (int)$receivedTs,
                            'body_text' => (string)$plainBody,
                            'fields' => (isset($parsedBody['fields']) && is_array($parsedBody['fields'])) ? $parsedBody['fields'] : array(),
                        );
                    }
                }
                if ($connection !== false) {
                    imap_close($connection);
                }
            }
        }
    }

    if (!empty($rows)) {
        usort($rows, function($a, $b) {
            $aTs = isset($a['received_ts']) ? (int)$a['received_ts'] : 0;
            $bTs = isset($b['received_ts']) ? (int)$b['received_ts'] : 0;
            if ($aTs === $bTs) return 0;
            return ($aTs > $bTs) ? -1 : 1;
        });
        $rows = dlbh_inbox_group_membership_rows($rows);
    }
    if (!empty($stripeRows)) {
        usort($stripeRows, function($a, $b) {
            $aTs = isset($a['_sort_ts']) ? (int)$a['_sort_ts'] : 0;
            $bTs = isset($b['_sort_ts']) ? (int)$b['_sort_ts'] : 0;
            if ($aTs === $bTs) return 0;
            return ($aTs > $bTs) ? -1 : 1;
        });
    }
    $stripeSummary = array(
        'as_of_email_date' => '',
        'last_payment_received_date' => '',
        'revenue' => '$0.00',
        'fees' => '$0.00',
        'net_income' => '$0.00',
        'money_in_suspense' => '$0.00',
        'cash_receipts' => '$0.00',
        'total_gross_income' => '$0.00',
    );
    if (!empty($stripeRows)) {
        $revenueTotal = 0.0;
        $feesTotal = 0.0;
        $suspenseTotal = 0.0;
        $latestEmailTs = 0;
        $latestEmailDisplay = '';
        foreach ($stripeRows as $stripeRow) {
            $amountNumeric = isset($stripeRow['_amount_numeric']) ? (float)$stripeRow['_amount_numeric'] : 0.0;
            $feeNumeric = isset($stripeRow['_fee_numeric']) ? (float)$stripeRow['_fee_numeric'] : 0.0;
            $feesTotal += $feeNumeric;
            $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
            if ($stripeFamilyId === '' || !isset($rosterFamilyIds[$stripeFamilyId])) {
                $suspenseTotal += $amountNumeric;
            } else {
                $revenueTotal += $amountNumeric;
            }
            $sourceTs = isset($stripeRow['_source_email_ts']) ? (int)$stripeRow['_source_email_ts'] : 0;
            if ($sourceTs >= $latestEmailTs) {
                $latestEmailTs = $sourceTs;
                $latestEmailDisplay = isset($stripeRow['_source_email_received']) ? (string)$stripeRow['_source_email_received'] : '';
            }
        }
        $stripeSummary['as_of_email_date'] = $latestEmailDisplay;
        $stripeSummary['last_payment_received_date'] = isset($stripeRows[0]['Payment Received Date']) ? (string)$stripeRows[0]['Payment Received Date'] : '';
        $stripeSummary['revenue'] = dlbh_inbox_format_currency_value((string)$revenueTotal);
        $stripeSummary['fees'] = dlbh_inbox_format_currency_value((string)$feesTotal);
        $stripeSummary['net_income'] = dlbh_inbox_format_currency_value((string)($revenueTotal - $feesTotal));
        $stripeSummary['money_in_suspense'] = dlbh_inbox_format_currency_value((string)$suspenseTotal);
        $stripeSummary['cash_receipts'] = dlbh_inbox_format_currency_value((string)($revenueTotal + $suspenseTotal));
        $stripeSummary['total_gross_income'] = dlbh_inbox_format_currency_value((string)(($revenueTotal - $feesTotal) + $feesTotal));
    }
    $selectedRosterRow = null;
    if ($screen === 'roster' && $rosterMemberKey !== '' && !empty($rosterRows)) {
        foreach ($rosterRows as $candidateRosterRow) {
            $candidateKey = isset($candidateRosterRow['Member Key']) ? (string)$candidateRosterRow['Member Key'] : '';
            if ($candidateKey !== '' && hash_equals($candidateKey, $rosterMemberKey)) {
                $selectedRosterRow = $candidateRosterRow;
                break;
            }
        }
    }
    if ($memberSession['enabled'] && !$selectedRosterRow && !empty($rosterRows)) {
        $selectedRosterRow = $rosterRows[0];
        $rosterMemberKey = isset($selectedRosterRow['Member Key']) ? (string)$selectedRosterRow['Member Key'] : '';
    }
    $filteredRosterRows = $rosterRows;
    if (!$memberSession['enabled'] && !empty($filteredRosterRows) && $rosterSearch !== '') {
        $filteredRosterRows = array_values(array_filter($filteredRosterRows, function($rosterRow) use ($rosterSearch) {
            if (!is_array($rosterRow)) return false;
            $name = trim((string)(isset($rosterRow['Name']) ? $rosterRow['Name'] : ''));
            $familyId = trim((string)(isset($rosterRow['Family ID']) ? $rosterRow['Family ID'] : ''));
            return !(stripos($name, $rosterSearch) === false && stripos($familyId, $rosterSearch) === false);
        }));
    }
    $rosterRowsByFamilyId = array();
    if (!empty($rosterRows)) {
        foreach ($rosterRows as $rosterRow) {
            if (!is_array($rosterRow)) continue;
            $familyIdKey = dlbh_inbox_normalize_family_id_lookup_key(isset($rosterRow['Family ID']) ? $rosterRow['Family ID'] : '');
            if ($familyIdKey === '' || isset($rosterRowsByFamilyId[$familyIdKey])) continue;
            $rosterRowsByFamilyId[$familyIdKey] = $rosterRow;
        }
    }
    $rowsOriginal = $rows;

    $selectedEmailIdx = isset($_GET['dlbh_email_idx']) ? (int)$_GET['dlbh_email_idx'] : 0;
    $trashConfirmIdx = isset($_GET['dlbh_trash_confirm']) ? (int)$_GET['dlbh_trash_confirm'] : -1;
    $eligibleConfirmIdx = isset($_GET['dlbh_eligible_confirm']) ? (int)$_GET['dlbh_eligible_confirm'] : -1;
    $editModeRequested = isset($_GET['dlbh_edit']) && (string)$_GET['dlbh_edit'] === '1';
    $editFlashSessionKey = 'dlbh_inbox_edit_flash_' . $sessionScope;
    $recordFocusSessionKey = 'dlbh_inbox_record_focus_' . $sessionScope;
    if ($requestMethod === 'POST' && $formAction === 'send_compose_email' && isset($_POST['dlbh_email_idx'])) {
        $selectedEmailIdx = (int)$_POST['dlbh_email_idx'];
    }
    if ($sessionEnabled && $requestMethod === 'GET' && $editModeRequested && !headers_sent()) {
        $_SESSION[$editFlashSessionKey] = (int)$selectedEmailIdx;
        $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($redirectUrl !== '') {
            $parts = parse_url($redirectUrl);
            $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
            $query = array();
            if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
            unset($query['dlbh_edit']);
            $query['dlbh_email_idx'] = (int)$selectedEmailIdx;
            $redirectUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
        }
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($redirectUrl);
        } else {
            header('Location: ' . $redirectUrl);
        }
        exit;
    }
    if ($sessionEnabled && isset($_SESSION[$editFlashSessionKey]) && (int)$_SESSION[$editFlashSessionKey] === (int)$selectedEmailIdx) {
        $editModeRequested = true;
        unset($_SESSION[$editFlashSessionKey]);
    } else {
        $editModeRequested = false;
    }
    $recordFocusLabel = '';
    if ($sessionEnabled && isset($_SESSION[$recordFocusSessionKey]) && is_array($_SESSION[$recordFocusSessionKey])) {
        $focusData = $_SESSION[$recordFocusSessionKey];
        if ((int)(isset($focusData['idx']) ? $focusData['idx'] : -1) === (int)$selectedEmailIdx) {
            $recordFocusLabel = trim((string)(isset($focusData['label']) ? $focusData['label'] : ''));
        }
        unset($_SESSION[$recordFocusSessionKey]);
    }
    $verifyRequested = isset($_GET['dlbh_verify']) && (string)$_GET['dlbh_verify'] === '1';
    $composeRequested = isset($_GET['dlbh_compose']) && (string)$_GET['dlbh_compose'] === '1';
    $composeMode = isset($_GET['dlbh_compose_mode']) ? trim((string)$_GET['dlbh_compose_mode']) : '';
    $messageRequested = isset($_GET['dlbh_message']) && (string)$_GET['dlbh_message'] === '1';
    $verificationFlashKey = 'dlbh_inbox_verify_flash_' . $sessionScope;
    $composeFlashKey = 'dlbh_inbox_compose_flash_' . $sessionScope;
    $rosterComposeStatusFlashKey = 'dlbh_roster_compose_status_' . $sessionScope;
    $hasStateFlash = ($sessionEnabled && !empty($_SESSION[$stateFlashSessionKey]));
    if (
        $sessionEnabled &&
        $requestMethod === 'GET' &&
        !$editModeRequested &&
        !$composeRequested &&
        !$hasStateFlash
    ) {
        unset($_SESSION[$verificationFlashKey], $_SESSION[$composeFlashKey], $_SESSION[$fieldsOverrideSessionKey]);
    }
    if ($hasStateFlash) unset($_SESSION[$stateFlashSessionKey]);
    if ($selectedEmailIdx < 0 || $selectedEmailIdx >= count($rows)) {
        $selectedEmailIdx = (!empty($rows) ? 0 : -1);
    }
    $selectedEmail = ($selectedEmailIdx >= 0 && isset($rows[$selectedEmailIdx])) ? $rows[$selectedEmailIdx] : null;
    $selectedEmailOriginal = ($selectedEmailIdx >= 0 && isset($rowsOriginal[$selectedEmailIdx])) ? $rowsOriginal[$selectedEmailIdx] : null;
    $fieldsOverrides = array();
    if ($sessionEnabled && isset($_SESSION[$fieldsOverrideSessionKey]) && is_array($_SESSION[$fieldsOverrideSessionKey])) {
        $fieldsOverrides = $_SESSION[$fieldsOverrideSessionKey];
    }
    if ($selectedEmail && isset($fieldsOverrides[(int)$selectedEmailIdx]) && is_array($fieldsOverrides[(int)$selectedEmailIdx]) && $selectedEmailOriginal) {
        $originalForSelected = isset($selectedEmailOriginal['fields']) && is_array($selectedEmailOriginal['fields'])
            ? $selectedEmailOriginal['fields']
            : array();
        $overrideComparable = dlbh_inbox_filter_user_editable_fields($fieldsOverrides[(int)$selectedEmailIdx]);
        $originalComparable = dlbh_inbox_filter_user_editable_fields($originalForSelected);
        if (dlbh_inbox_fields_are_equal($overrideComparable, $originalComparable)) {
            unset($fieldsOverrides[(int)$selectedEmailIdx]);
            if ($sessionEnabled) $_SESSION[$fieldsOverrideSessionKey] = $fieldsOverrides;
        }
    }
    if ($selectedEmail && isset($fieldsOverrides[(int)$selectedEmailIdx]) && is_array($fieldsOverrides[(int)$selectedEmailIdx])) {
        $selectedEmail['fields'] = $fieldsOverrides[(int)$selectedEmailIdx];
        if (isset($rows[(int)$selectedEmailIdx])) {
            $rows[(int)$selectedEmailIdx]['fields'] = $fieldsOverrides[(int)$selectedEmailIdx];
        }
    }
    if ($selectedEmail && isset($selectedEmail['fields']) && is_array($selectedEmail['fields'])) {
        $selectedPrimaryMember = dlbh_inbox_get_primary_member_name_from_fields($selectedEmail['fields']);
        $selectedReceivedSeed = isset($selectedEmail['received_raw']) ? (string)$selectedEmail['received_raw'] : '';
        $selectedEmail['fields'] = dlbh_inbox_insert_membership_information($selectedEmail['fields'], $selectedPrimaryMember, $selectedReceivedSeed);
        $selectedEmail['fields'] = dlbh_inbox_ensure_account_summary_family_id($selectedEmail['fields']);
        if (isset($rows[(int)$selectedEmailIdx])) {
            $rows[(int)$selectedEmailIdx]['fields'] = $selectedEmail['fields'];
        }
    }
    if ($selectedEmailOriginal && isset($selectedEmailOriginal['fields']) && is_array($selectedEmailOriginal['fields'])) {
        $selectedOriginalPrimaryMember = dlbh_inbox_get_primary_member_name_from_fields($selectedEmailOriginal['fields']);
        $selectedOriginalReceivedSeed = isset($selectedEmailOriginal['received_raw']) ? (string)$selectedEmailOriginal['received_raw'] : '';
        $selectedEmailOriginal['fields'] = dlbh_inbox_insert_membership_information($selectedEmailOriginal['fields'], $selectedOriginalPrimaryMember, $selectedOriginalReceivedSeed);
        $selectedEmailOriginal['fields'] = dlbh_inbox_ensure_account_summary_family_id($selectedEmailOriginal['fields']);
    }
    $selectedReplyMessages = array();
    if ($selectedEmail) {
        $selectedReplyMessages = dlbh_inbox_merge_reply_message_entries(
            $selectedReplyMessages,
            dlbh_inbox_collect_reply_message_entries_from_row($selectedEmail)
        );
    }
    if ($selectedEmailOriginal) {
        $selectedReplyMessages = dlbh_inbox_merge_reply_message_entries(
            $selectedReplyMessages,
            dlbh_inbox_collect_reply_message_entries_from_row($selectedEmailOriginal)
        );
    }
    $selectedReplyMessage = '';
    if (!empty($selectedReplyMessages) && isset($selectedReplyMessages[0]['message'])) {
        $selectedReplyMessage = (string)$selectedReplyMessages[0]['message'];
    }
    $showMessage = (!empty($selectedReplyMessages) && $messageRequested && !$composeRequested);
    $selectedHasEdits = ($selectedEmail && isset($fieldsOverrides[(int)$selectedEmailIdx]) && is_array($fieldsOverrides[(int)$selectedEmailIdx]));
    $submitCompleted = false;
    if ($sessionEnabled && isset($_SESSION[$submitFlashSessionKey])) {
        unset($_SESSION[$submitFlashSessionKey]);
    }
    $editOnUrl = '';
    $editOffUrl = '';
    $messageOnUrl = '';
    $messageOffUrl = '';
    if ($selectedEmail) {
        $requestUriForEdit = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($requestUriForEdit !== '') {
            $parts = parse_url($requestUriForEdit);
            $path = isset($parts['path']) ? (string)$parts['path'] : $requestUriForEdit;
            $query = array();
            if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
            unset($query['dlbh_compose'], $query['dlbh_compose_mode'], $query['dlbh_verify'], $query['dlbh_message'], $query['dlbh_trash_confirm']);
            $query['dlbh_email_idx'] = (int)$selectedEmailIdx;
            $query['dlbh_edit'] = '1';
            $editOnUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
            unset($query['dlbh_edit']);
            $editOffUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
            if ($selectedReplyMessage !== '') {
                $query['dlbh_message'] = '1';
                $messageOnUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                unset($query['dlbh_message']);
                $messageOffUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
            }
        }
    }
    if ($requestMethod === 'POST' && ($formAction === 'save_detail_fields' || $formAction === 'save_and_verify') && isset($_POST['dlbh_email_idx'])) {
        $postedIdx = (int)$_POST['dlbh_email_idx'];
        $detailSubmitMode = trim((string)dlbh_inbox_post_value('detail_submit_mode', ''));
        $detailRecordAction = trim((string)dlbh_inbox_post_value('detail_record_action', ''));
        $detailRecordTarget = trim((string)dlbh_inbox_post_value('detail_record_target', ''));
        $redirectToInboxOnly = false;
        if ($formAction === 'save_detail_fields' && $detailRecordAction !== '' && $detailRecordTarget === '') {
            $detailRecordAction = '';
        }
        if (!$nonceOk) {
            $error = 'Security check failed. Please try again.';
            if ($sessionEnabled) $_SESSION[$flashSessionKey] = $error;
        } elseif (isset($rows[$postedIdx]) && is_array($rows[$postedIdx])) {
            $types = isset($_POST['detail_field_type']) && is_array($_POST['detail_field_type']) ? $_POST['detail_field_type'] : array();
            $labels = isset($_POST['detail_field_label']) && is_array($_POST['detail_field_label']) ? $_POST['detail_field_label'] : array();
            $values = isset($_POST['detail_field_value']) && is_array($_POST['detail_field_value']) ? $_POST['detail_field_value'] : array();
            if (function_exists('wp_unslash')) {
                $types = wp_unslash($types);
                $labels = wp_unslash($labels);
                $values = wp_unslash($values);
            } else {
                $types = array_map('stripslashes', $types);
                $labels = array_map('stripslashes', $labels);
                $values = array_map('stripslashes', $values);
            }
            $max = max(count($types), count($labels), count($values));
            $rebuilt = array();
            for ($i = 0; $i < $max; $i++) {
                $rowType = isset($types[$i]) ? strtolower(trim((string)$types[$i])) : 'field';
                $rowType = ($rowType === 'header') ? 'header' : 'field';
                $rowLabel = isset($labels[$i]) ? trim((string)$labels[$i]) : '';
                if ($rowLabel === '') continue;
                $rowValue = isset($values[$i]) ? trim((string)$values[$i]) : '';
                if ($rowType === 'header') $rowValue = '';
                $rebuilt[] = array(
                    'type' => $rowType,
                    'label' => $rowLabel,
                    'value' => $rowValue,
                );
            }
            if ($detailRecordAction !== '' && !empty($rebuilt)) {
                $rebuilt = dlbh_inbox_apply_record_action($rebuilt, $detailRecordAction, $detailRecordTarget);
                $formAction = 'save_detail_fields';
            }
            if (!empty($rebuilt)) {
                $originalPostedFields = (isset($rowsOriginal[(int)$postedIdx]['fields']) && is_array($rowsOriginal[(int)$postedIdx]['fields']))
                    ? $rowsOriginal[(int)$postedIdx]['fields']
                    : array();
                $preservedRows = array();
                foreach ($originalPostedFields as $origRow) {
                    if (!is_array($origRow)) continue;
                    $origType = isset($origRow['type']) ? strtolower(trim((string)$origRow['type'])) : 'field';
                    $origLabel = isset($origRow['label']) ? trim((string)$origRow['label']) : '';
                    if (($origType === 'header' && strcasecmp($origLabel, 'Reply Message') === 0) || strcasecmp($origLabel, 'Message') === 0) {
                        $preservedRows[] = $origRow;
                    }
                }
                if (!empty($preservedRows)) {
                    $rebuilt = array_merge($preservedRows, $rebuilt);
                }
                $receivedSeed = isset($rows[$postedIdx]['received_raw']) ? (string)$rows[$postedIdx]['received_raw'] : '';
                $rebuiltPrimaryMember = dlbh_inbox_get_primary_member_name_from_fields($rebuilt);
                $rebuilt = dlbh_inbox_insert_membership_information($rebuilt, $rebuiltPrimaryMember, $receivedSeed);
                $originalPrimaryMember = dlbh_inbox_get_primary_member_name_from_fields($originalPostedFields);
                $originalPostedFields = dlbh_inbox_insert_membership_information($originalPostedFields, $originalPrimaryMember, $receivedSeed);
                // Always compare saved values to original parsed email values.
                $rebuiltComparable = dlbh_inbox_filter_user_editable_fields($rebuilt);
                $originalComparable = dlbh_inbox_filter_user_editable_fields($originalPostedFields);
                $hasTrueChanges = !dlbh_inbox_fields_are_equal($rebuiltComparable, $originalComparable);
                if ($hasTrueChanges) {
                    $fieldsOverrides[(int)$postedIdx] = $rebuilt;
                } else {
                    unset($fieldsOverrides[(int)$postedIdx]);
                }
                if ($sessionEnabled) $_SESSION[$fieldsOverrideSessionKey] = $fieldsOverrides;
                if ($selectedEmailIdx === $postedIdx) {
                    $selectedEmail['fields'] = $hasTrueChanges ? $rebuilt : $originalPostedFields;
                    $rows[(int)$postedIdx]['fields'] = $hasTrueChanges ? $rebuilt : $originalPostedFields;
                }
                if ($detailRecordAction !== '' && $sessionEnabled) {
                    $_SESSION[$editFlashSessionKey] = (int)$postedIdx;
                    $focusKind = dlbh_inbox_get_record_kind_from_header($detailRecordTarget);
                    $focusLabel = '';
                    if ($detailRecordAction === 'add' && $focusKind !== '') {
                        $focusLabel = dlbh_inbox_find_last_record_header_label($rebuilt, $focusKind);
                    } elseif ($detailRecordAction === 'remove') {
                        $focusLabel = $detailRecordTarget;
                    }
                    if ($focusLabel !== '') {
                        $_SESSION[$recordFocusSessionKey] = array(
                            'idx' => (int)$postedIdx,
                            'label' => $focusLabel,
                        );
                    }
                }
            }
                if ($formAction === 'save_and_verify' && !empty($rebuilt)) {
                $verificationComputed = dlbh_inbox_evaluate_eligibility($rebuilt);
                if ($sessionEnabled) {
                    $_SESSION[$verificationFlashKey] = $verificationComputed;
                }
                if ($hasTrueChanges && $detailSubmitMode === 'submit') {
                    $submitFields = array_values(array_filter($rebuilt, function($row) {
                        if (!is_array($row)) return false;
                        $label = isset($row['label']) ? trim((string)$row['label']) : '';
                        return !(strcasecmp($label, 'Reply Message') === 0 || strcasecmp($label, 'Message') === 0);
                    }));
                    $submitTo = 'office@dlbhfamily.com';
                    $submitSubject = dlbh_inbox_membership_subject();
                    $issues = isset($verificationComputed['issues']) && is_array($verificationComputed['issues']) ? $verificationComputed['issues'] : array();
                    $submitHtml = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;">'
                        . dlbh_inbox_build_composed_email_html(
                            '',
                            $issues,
                            $submitFields,
                            $verificationComputed,
                            array(
                                'show_top_header' => false,
                                'show_intro_body' => false,
                                'show_status_card' => false,
                                'show_field_errors' => false,
                                'exclude_reply_message' => true,
                            )
                        )
                        . '</body></html>';

                    $submitHeaders = array('Content-Type: text/html; charset=UTF-8');
                    if ($postedEmail !== '' && filter_var($postedEmail, FILTER_VALIDATE_EMAIL)) {
                        $submitHeaders[] = 'From: Membership Committee <' . $postedEmail . '>';
                        $submitHeaders[] = 'Reply-To: ' . $postedEmail;
                    }

                    $submitSent = false;
                    if (function_exists('wp_mail')) {
                        if (function_exists('add_filter')) add_filter('wp_mail_content_type', 'dlbh_inbox_force_html_mail_content_type');
                        $submitSent = (bool)wp_mail($submitTo, $submitSubject, $submitHtml, $submitHeaders);
                        if (function_exists('remove_filter')) remove_filter('wp_mail_content_type', 'dlbh_inbox_force_html_mail_content_type');
                    } elseif (function_exists('mail')) {
                        $submitSent = (bool)mail($submitTo, $submitSubject, $submitHtml, implode("\r\n", $submitHeaders));
                    }
                    if ($submitSent) {
                        $redirectToInboxOnly = true;
                        $targetRow = isset($rows[$postedIdx]) && is_array($rows[$postedIdx]) ? $rows[$postedIdx] : null;
                        if ($targetRow !== null && $postedEmail !== '' && $postedPassword !== '') {
                            $rw = dlbh_inbox_open_connection_rw($postedEmail, $postedPassword, $server, $port, $folder);
                            if ($rw !== false) {
                                $moved = dlbh_inbox_move_row_messages($rw, $targetRow, 'Trash', 'INBOX.Trash');
                                if ($moved) {
                                } elseif ($sessionEnabled) {
                                    $_SESSION[$flashSessionKey] = 'Email sent, but it could not be moved to Trash.';
                                }
                                @imap_close($rw);
                            }
                        }
                        unset($fieldsOverrides[(int)$postedIdx]);
                        if ($sessionEnabled) {
                            $_SESSION[$fieldsOverrideSessionKey] = $fieldsOverrides;
                        }
                    }
                }
            }
        }

        if (!headers_sent()) {
            if ($sessionEnabled) $_SESSION[$stateFlashSessionKey] = 1;
            $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            if ($redirectUrl !== '') {
                $parts = parse_url($redirectUrl);
                $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
                if ($redirectToInboxOnly) {
                    $redirectUrl = $path;
                } else {
                    $query = array();
                    if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                    unset($query['dlbh_compose'], $query['dlbh_verify'], $query['dlbh_eligible_confirm']);
                    $query['dlbh_email_idx'] = $postedIdx;
                    $redirectUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                }
            }
            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect($redirectUrl);
            } else {
                header('Location: ' . $redirectUrl);
            }
            exit;
        }
    }
    if ($composeRequested && $sessionEnabled && !headers_sent()) {
        $_SESSION[$stateFlashSessionKey] = 1;
        $_SESSION[$composeFlashKey] = array('idx' => (int)$selectedEmailIdx, 'mode' => $composeMode);
        if ($selectedEmail) {
            $selectedFieldsForComposeFlash = isset($selectedEmail['fields']) && is_array($selectedEmail['fields']) ? $selectedEmail['fields'] : array();
            $_SESSION[$verificationFlashKey] = dlbh_inbox_evaluate_eligibility($selectedFieldsForComposeFlash);
        }
        $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($redirectUrl !== '') {
            $parts = parse_url($redirectUrl);
            $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
            $query = array();
            if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
            unset($query['dlbh_compose'], $query['dlbh_compose_mode']);
            $redirectUrl = $path;
            if (!empty($query)) $redirectUrl .= '?' . http_build_query($query);
        }
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($redirectUrl);
        } else {
            header('Location: ' . $redirectUrl);
        }
        exit;
    }

    $composeFlashActive = false;
    $composeFlashMode = '';
    if ($sessionEnabled && isset($_SESSION[$composeFlashKey])) {
        $composeFlashData = $_SESSION[$composeFlashKey];
        $composeFlashIdx = is_array($composeFlashData) ? (int)(isset($composeFlashData['idx']) ? $composeFlashData['idx'] : -1) : (int)$composeFlashData;
        $composeFlashMode = is_array($composeFlashData) ? trim((string)(isset($composeFlashData['mode']) ? $composeFlashData['mode'] : '')) : '';
        if ($composeFlashIdx === (int)$selectedEmailIdx) {
            $composeFlashActive = true;
        }
        unset($_SESSION[$composeFlashKey]);
    }
    $verification = null;
    if ($selectedEmail) {
        $selectedFields = isset($selectedEmail['fields']) && is_array($selectedEmail['fields']) ? $selectedEmail['fields'] : array();
        $verificationComputed = dlbh_inbox_evaluate_eligibility($selectedFields);
        $verification = $verificationComputed;
        if ($sessionEnabled) $_SESSION[$verificationFlashKey] = $verificationComputed;
    }
    if ($verification === null && $sessionEnabled && isset($_SESSION[$verificationFlashKey])) {
        $verification = $_SESSION[$verificationFlashKey];
        unset($_SESSION[$verificationFlashKey]);
    }

    if ($selectedEmail && $composeRequested && $verification === null) {
        $selectedFields = isset($selectedEmail['fields']) && is_array($selectedEmail['fields']) ? $selectedEmail['fields'] : array();
        $verification = dlbh_inbox_evaluate_eligibility($selectedFields);
    }

    $composeStatus = array('type' => '', 'message' => '');
    $composeTo = '';
    $composeFrom = $postedEmail !== '' ? $postedEmail : 'office@dlbhfamily.com';
    $composeSubject = dlbh_inbox_membership_action_required_subject();
    $composeBody = '';
    $composeHtml = '';
    $composeCancelUrl = '';
    $showCompose = false;
    $effectiveComposeMode = ($composeFlashMode !== '' ? $composeFlashMode : $composeMode);
    if ($selectedEmail) {
        $detailFieldsForCompose = isset($selectedEmail['fields']) && is_array($selectedEmail['fields']) ? $selectedEmail['fields'] : array();
        $detailFieldsForCompose = dlbh_inbox_ensure_account_summary_family_id($detailFieldsForCompose);
        if ($composeFlashActive && $verification === null) {
            $verification = dlbh_inbox_evaluate_eligibility($detailFieldsForCompose);
        }
        $composeTo = dlbh_inbox_get_field_value($detailFieldsForCompose, 'Email');
        $firstName = '';
        $pmName = isset($selectedEmail['from']) ? trim((string)$selectedEmail['from']) : '';
        if ($pmName !== '') {
            $pmParts = preg_split('/\s+/', $pmName);
            if (is_array($pmParts) && !empty($pmParts)) $firstName = (string)$pmParts[0];
        }
        if ($effectiveComposeMode === 'eligible') {
            $composeSubject = dlbh_inbox_membership_eligible_subject();
            $composeBody = dlbh_inbox_build_eligible_compose_template_body($detailFieldsForCompose, $firstName);
        } else {
            $composeSubject = dlbh_inbox_membership_action_required_subject();
            $composeBody = dlbh_inbox_build_compose_template_body($firstName);
        }
        $previewIssuesInit = (is_array($verification) && isset($verification['issues']) && is_array($verification['issues'])) ? $verification['issues'] : array();
        $composeHtml = dlbh_inbox_build_composed_email_html($composeBody, $previewIssuesInit, $detailFieldsForCompose, $verification, array(
            'show_status_card' => true,
            'show_details' => true,
            'compose_mode' => $effectiveComposeMode,
        ));
        $showCompose = ($composeFlashActive || (!$sessionEnabled && $composeRequested));
        if ($showCompose) {
            $showCompose = ($effectiveComposeMode === 'eligible')
                ? (is_array($verification) && !empty($verification['eligible']))
                : (is_array($verification) && empty($verification['eligible']));
        }
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($requestUri !== '') {
            $parts = parse_url($requestUri);
            $path = isset($parts['path']) ? (string)$parts['path'] : $requestUri;
            $query = array();
            if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
            unset($query['dlbh_compose'], $query['dlbh_compose_mode'], $query['dlbh_verify']);
            $query['dlbh_email_idx'] = (int)$selectedEmailIdx;
            $composeCancelUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
        }
    }

    $rosterComposeStatus = array('type' => '', 'message' => '');
    if ($sessionEnabled && isset($_SESSION[$rosterComposeStatusFlashKey]) && is_array($_SESSION[$rosterComposeStatusFlashKey])) {
        $rosterComposeStatus = $_SESSION[$rosterComposeStatusFlashKey];
        unset($_SESSION[$rosterComposeStatusFlashKey]);
    }

    if ($requestMethod === 'POST' && $formAction === 'send_roster_compose_email') {
        $profileComposeModePosted = trim((string)dlbh_inbox_post_value('profile_compose_mode', ''));
        if ($profileComposeModePosted === '') {
            $profileComposeModePosted = trim((string)dlbh_inbox_post_value('compose_mode', ''));
        }
        $profileComposeMemberKeyPosted = trim((string)dlbh_inbox_post_value('dlbh_roster_member', ''));
        $profileComposeFamilyIdPosted = strtoupper(trim((string)dlbh_inbox_post_value('family_id', '')));
        $profileComposeStatementOffsetPosted = (int)dlbh_inbox_post_value('dlbh_statement_offset', '0');
        $profileComposeReturnPosted = trim((string)dlbh_inbox_post_value('dlbh_return', ''));
        $profileComposeSourceFolderPosted = trim((string)dlbh_inbox_post_value('source_folder', ''));
        $profileComposeSourceMsgNumPosted = (int)dlbh_inbox_post_value('source_msg_num', '0');
        $profileComposeTo = trim((string)dlbh_inbox_post_value('compose_to', ''));
        $profileComposeSubject = trim((string)dlbh_inbox_post_value('compose_subject', ''));
        $profileComposeBody = trim((string)dlbh_inbox_post_value('compose_body', ''));
        $profileComposeHtml = trim((string)dlbh_inbox_post_value('compose_html', ''));
        $profileComposeFrom = trim((string)dlbh_inbox_post_value('compose_from', ($postedEmail !== '' ? $postedEmail : 'office@dlbhfamily.com')));
        $profileComposeStatus = array('type' => '', 'message' => '');

        if ($profileComposeTo === '' || !filter_var($profileComposeTo, FILTER_VALIDATE_EMAIL)) {
            $profileComposeStatus = array('type' => 'error', 'message' => 'A valid recipient email is required.');
        } elseif ($profileComposeSubject === '') {
            $profileComposeStatus = array('type' => 'error', 'message' => 'A subject is required.');
        } elseif ($profileComposeBody === '' && $profileComposeHtml === '') {
            $profileComposeStatus = array('type' => 'error', 'message' => 'Email body cannot be empty.');
        } else {
            $profileComposeSent = false;
            $profileComposeHeaders = array('Content-Type: text/html; charset=UTF-8');
            if ($profileComposeFrom !== '' && filter_var($profileComposeFrom, FILTER_VALIDATE_EMAIL)) {
                $profileComposeHeaders[] = 'From: Membership Committee <' . $profileComposeFrom . '>';
                $profileComposeHeaders[] = 'Reply-To: ' . $profileComposeFrom;
            }
            $profileComposeFields = array();
            if (
                $postedEmail !== '' &&
                $postedPassword !== '' &&
                $profileComposeMemberKeyPosted !== ''
            ) {
                $profileComposeStripeRows = array();
                $profileComposeStripeOpen = dlbh_inbox_open_connection_with_fallbacks($postedEmail, $postedPassword, $server, $port, array('Stripe', 'INBOX.Stripe'));
                $profileComposeStripeConnection = isset($profileComposeStripeOpen['connection']) ? $profileComposeStripeOpen['connection'] : false;
                if ($profileComposeStripeConnection !== false) {
                    $profileComposeStripeRows = dlbh_inbox_collect_stripe_rows_from_connection($profileComposeStripeConnection);
                    @imap_close($profileComposeStripeConnection);
                }
                $profileComposeRosterRows = dlbh_inbox_collect_roster_rows($postedEmail, $postedPassword, $server, $port, $profileComposeStripeRows);
                foreach ($profileComposeRosterRows as $profileComposeRosterRow) {
                    if (!is_array($profileComposeRosterRow)) continue;
                    $profileComposeMemberKey = isset($profileComposeRosterRow['Member Key']) ? trim((string)$profileComposeRosterRow['Member Key']) : '';
                    if ($profileComposeMemberKey === '' || !hash_equals($profileComposeMemberKey, $profileComposeMemberKeyPosted)) continue;
                    $profileComposeProfileFields = isset($profileComposeRosterRow['Profile Fields']) && is_array($profileComposeRosterRow['Profile Fields'])
                        ? $profileComposeRosterRow['Profile Fields']
                        : array();
                    $profileComposeReceivedDate = isset($profileComposeRosterRow['Commencement Date']) ? (string)$profileComposeRosterRow['Commencement Date'] : '';
                    $profileComposeMaxOffset = dlbh_inbox_get_max_generated_statement_offset($profileComposeProfileFields, $profileComposeReceivedDate);
                    $profileComposeEffectiveOffset = max(0, min($profileComposeStatementOffsetPosted, $profileComposeMaxOffset));
                    $profileComposeStatementLabel = dlbh_inbox_get_statement_label_for_offset(
                        $profileComposeProfileFields,
                        $profileComposeReceivedDate,
                        $profileComposeEffectiveOffset
                    );
                    $profileComposeSummaryFields = dlbh_inbox_build_account_summary_fields_with_payments(
                        $profileComposeProfileFields,
                        $profileComposeReceivedDate,
                        $profileComposeEffectiveOffset,
                        $profileComposeStripeRows,
                        $profileComposeStatementLabel
                    );
                    $profileComposePrimaryMember = trim((string)dlbh_inbox_get_field_value_by_label($profileComposeProfileFields, 'Primary Member'));
                    if ($profileComposePrimaryMember === '') {
                        $profileComposePrimaryMember = trim((string)(isset($profileComposeRosterRow['Name']) ? $profileComposeRosterRow['Name'] : 'Member'));
                    }
                    $profileComposeFields = array(
                        array('type' => 'field', 'label' => 'Primary Member', 'value' => $profileComposePrimaryMember),
                    );
                    $profileComposeFamilyIdValue = trim((string)dlbh_inbox_get_field_value_by_label($profileComposeProfileFields, 'Family ID'));
                    if ($profileComposeFamilyIdValue === '') {
                        $profileComposeFamilyIdValue = trim((string)(isset($profileComposeRosterRow['Family ID']) ? $profileComposeRosterRow['Family ID'] : ''));
                    }
                    if ($profileComposeFamilyIdValue !== '') {
                        $profileComposeFields[] = array('type' => 'field', 'label' => 'Family ID', 'value' => $profileComposeFamilyIdValue);
                    }
                    if (!empty($profileComposeSummaryFields)) {
                        $profileComposeFields[] = array('type' => 'header', 'label' => 'Account Summary Information', 'value' => '');
                        foreach ($profileComposeSummaryFields as $profileComposeSummaryField) {
                            if (!is_array($profileComposeSummaryField)) continue;
                            $profileComposeSummaryLabel = isset($profileComposeSummaryField['label']) ? (string)$profileComposeSummaryField['label'] : '';
                            $profileComposeSummaryValue = isset($profileComposeSummaryField['value']) ? (string)$profileComposeSummaryField['value'] : '';
                            if (
                                strcasecmp(trim($profileComposeSummaryLabel), 'Family ID') === 0 &&
                                trim($profileComposeSummaryValue) === '' &&
                                $profileComposeFamilyIdValue !== ''
                            ) {
                                $profileComposeSummaryValue = $profileComposeFamilyIdValue;
                            }
                            $profileComposeFields[] = array(
                                'type' => 'field',
                                'label' => $profileComposeSummaryLabel,
                                'value' => $profileComposeSummaryValue,
                            );
                        }
                    }
                    break;
                }
            }

            $profileComposeHtmlBody = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#F7F9FA;">'
                . dlbh_inbox_build_composed_email_html($profileComposeBody, array(), $profileComposeFields, null, array(
                    'show_status_card' => false,
                    'show_details' => true,
                    'show_intro_body' => true,
                    'compose_mode' => $profileComposeModePosted,
                    'details_card_title' => 'Account Summary',
                ))
                . '</body></html>';
            if ($profileComposeHtml !== '') {
                $profileComposeHtmlBody = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#F7F9FA;">' . $profileComposeHtml . '</body></html>';
            }
            if (function_exists('wp_mail')) {
                if (function_exists('add_filter')) add_filter('wp_mail_content_type', 'dlbh_inbox_force_html_mail_content_type');
                $profileComposeSent = (bool)wp_mail($profileComposeTo, $profileComposeSubject, $profileComposeHtmlBody, $profileComposeHeaders);
                if (function_exists('remove_filter')) remove_filter('wp_mail_content_type', 'dlbh_inbox_force_html_mail_content_type');
            } elseif (function_exists('mail')) {
                $profileComposeSent = (bool)mail($profileComposeTo, $profileComposeSubject, $profileComposeHtmlBody, implode("\r\n", $profileComposeHeaders));
            }
            $profileComposeStatus = $profileComposeSent
                ? array('type' => 'success', 'message' => 'Email sent successfully.')
                : array('type' => 'error', 'message' => 'Unable to send the email.');
            if (
                $profileComposeSent &&
                in_array($profileComposeModePosted, array('aging_past_due', 'aging_delinquent'), true) &&
                $postedEmail !== '' &&
                $postedPassword !== '' &&
                $profileComposeSourceFolderPosted !== '' &&
                $profileComposeSourceMsgNumPosted > 0
            ) {
                $sourceRw = dlbh_inbox_open_connection_rw($postedEmail, $postedPassword, $server, $port, $profileComposeSourceFolderPosted);
                if ($sourceRw !== false) {
                    $movedToAging = dlbh_inbox_move_message_to_folder_candidates($sourceRw, $profileComposeSourceMsgNumPosted, dlbh_inbox_get_roster_folder_candidates('aging'));
                    if ($movedToAging && $profileComposeFamilyIdPosted !== '') {
                        dlbh_inbox_set_aging_notice_status_map_value($profileComposeFamilyIdPosted, $profileComposeModePosted);
                    }
                    @imap_close($sourceRw);
                }
            }
        }

        if ($sessionEnabled) $_SESSION[$rosterComposeStatusFlashKey] = $profileComposeStatus;
        if (!headers_sent()) {
            $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            if ($redirectUrl !== '') {
                $parts = parse_url($redirectUrl);
                $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
                $query = array();
                if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                if (
                    $profileComposeStatus['type'] === 'success' &&
                    $profileComposeReturnPosted === 'aging_report' &&
                    in_array($profileComposeModePosted, array('aging_past_due', 'aging_delinquent'), true)
                ) {
                    unset(
                        $query['dlbh_roster_member'],
                        $query['dlbh_profile_section'],
                        $query['dlbh_statement_offset'],
                        $query['dlbh_return'],
                        $query['dlbh_profile_compose'],
                        $query['dlbh_household_offset']
                    );
                    $query['dlbh_screen'] = 'financials';
                    $query['dlbh_financial_view'] = 'aging_report';
                } else {
                    $query['dlbh_screen'] = 'roster';
                    if ($profileComposeMemberKeyPosted !== '') $query['dlbh_roster_member'] = $profileComposeMemberKeyPosted;
                    $query['dlbh_profile_section'] = 'Billing';
                    if ($profileComposeStatementOffsetPosted > 0) $query['dlbh_statement_offset'] = $profileComposeStatementOffsetPosted;
                    else unset($query['dlbh_statement_offset']);
                    if ($profileComposeReturnPosted !== '') $query['dlbh_return'] = $profileComposeReturnPosted;
                    if ($profileComposeModePosted !== '') $query['dlbh_profile_compose'] = $profileComposeModePosted;
                }
                $redirectUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
            }
            if (function_exists('wp_safe_redirect')) wp_safe_redirect($redirectUrl);
            else header('Location: ' . $redirectUrl);
            exit;
        }
    }

    if ($requestMethod === 'POST' && $formAction === 'send_compose_email' && $selectedEmail) {
        $composeFrom = trim((string)dlbh_inbox_post_value('compose_from', $composeFrom));
        $composeTo = trim((string)dlbh_inbox_post_value('compose_to', $composeTo));
        $effectiveComposeMode = trim((string)dlbh_inbox_post_value('compose_mode', $effectiveComposeMode));
        $composeSubject = ($effectiveComposeMode === 'eligible') ? dlbh_inbox_membership_eligible_subject() : dlbh_inbox_membership_action_required_subject();
        $composeBody = trim((string)dlbh_inbox_post_value('compose_body', $composeBody));
        $composeHtml = trim((string)dlbh_inbox_post_value('compose_html', $composeHtml));

        $selectedFields = isset($selectedEmail['fields']) && is_array($selectedEmail['fields']) ? $selectedEmail['fields'] : array();
        $selectedFields = dlbh_inbox_ensure_account_summary_family_id($selectedFields);
        $verificationNow = dlbh_inbox_evaluate_eligibility($selectedFields);
        $verification = $verificationNow;
        $showCompose = ($effectiveComposeMode === 'eligible')
            ? (!empty($verificationNow) && !empty($verificationNow['eligible']))
            : (!empty($verificationNow) && empty($verificationNow['eligible']));

        if ($composeTo === '' || !filter_var($composeTo, FILTER_VALIDATE_EMAIL)) {
            $composeStatus = array('type' => 'error', 'message' => 'Invalid To email address.');
        } elseif ($composeSubject === '') {
            $composeStatus = array('type' => 'error', 'message' => 'Email subject is required.');
        } elseif ($composeBody === '' && $composeHtml === '') {
            $composeStatus = array('type' => 'error', 'message' => 'Email body is required.');
        } else {
            $issues = isset($verificationNow['issues']) && is_array($verificationNow['issues']) ? $verificationNow['issues'] : array();
            $htmlBody = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;">'
                . dlbh_inbox_build_composed_email_html($composeBody, $issues, $selectedFields, $verificationNow, array(
                    'compose_mode' => $effectiveComposeMode,
                ))
                . '</body></html>';
            if ($composeHtml !== '') {
                $htmlBody = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;">' . $composeHtml . '</body></html>';
            }

            $headers = array('Content-Type: text/html; charset=UTF-8');
            if ($composeFrom !== '' && filter_var($composeFrom, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'From: Membership Committee <' . $composeFrom . '>';
                $headers[] = 'Reply-To: ' . $composeFrom;
            }

            $sent = false;
            if (function_exists('wp_mail')) {
                if (function_exists('add_filter')) add_filter('wp_mail_content_type', 'dlbh_inbox_force_html_mail_content_type');
                $sent = (bool)wp_mail($composeTo, $composeSubject, $htmlBody, $headers);
                if (function_exists('remove_filter')) remove_filter('wp_mail_content_type', 'dlbh_inbox_force_html_mail_content_type');
            } elseif (function_exists('mail')) {
                $sent = (bool)mail($composeTo, $composeSubject, $htmlBody, implode("\r\n", $headers));
            }
            $composeStatus = $sent
                ? array('type' => '', 'message' => '')
                : array('type' => 'error', 'message' => 'Failed to send email.');
            if ($sent && !headers_sent()) {
                if ($selectedEmail && $postedEmail !== '' && $postedPassword !== '') {
                    $rw = dlbh_inbox_open_connection_rw($postedEmail, $postedPassword, $server, $port, $folder);
                    if ($rw !== false) {
                        $targetFolder = ($effectiveComposeMode === 'eligible') ? 'Roster' : 'Trash';
                        $fallbackFolder = ($effectiveComposeMode === 'eligible') ? 'INBOX.Roster' : 'INBOX.Trash';
                        $moved = dlbh_inbox_move_row_messages($rw, $selectedEmail, $targetFolder, $fallbackFolder);
                        if ($moved) {
                        } else {
                            $moveError = ($effectiveComposeMode === 'eligible')
                                ? 'Email sent, but it could not be moved to Roster.'
                                : 'Email sent, but it could not be moved to Trash.';
                            if ($sessionEnabled) $_SESSION[$flashSessionKey] = $moveError;
                        }
                        @imap_close($rw);
                    }
                }
                unset($fieldsOverrides[(int)$selectedEmailIdx]);
                if ($sessionEnabled) {
                    $_SESSION[$fieldsOverrideSessionKey] = $fieldsOverrides;
                }
                $redirectUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
                if ($redirectUrl !== '') {
                    $parts = parse_url($redirectUrl);
                    $path = isset($parts['path']) ? (string)$parts['path'] : $redirectUrl;
                    $redirectUrl = $path;
                }
                if (function_exists('wp_safe_redirect')) {
                    wp_safe_redirect($redirectUrl);
                } else {
                    header('Location: ' . $redirectUrl);
                }
                exit;
            }
        }
    }

    // Final authoritative edit-state check after all actions.
    $selectedHasEdits = false;
    if ($selectedEmail && $selectedEmailOriginal && isset($fieldsOverrides[(int)$selectedEmailIdx]) && is_array($fieldsOverrides[(int)$selectedEmailIdx])) {
        $originalNow = isset($selectedEmailOriginal['fields']) && is_array($selectedEmailOriginal['fields'])
            ? $selectedEmailOriginal['fields']
            : array();
        $overrideComparable = dlbh_inbox_filter_user_editable_fields($fieldsOverrides[(int)$selectedEmailIdx]);
        $originalComparable = dlbh_inbox_filter_user_editable_fields($originalNow);
        if (dlbh_inbox_fields_are_equal($overrideComparable, $originalComparable)) {
            unset($fieldsOverrides[(int)$selectedEmailIdx]);
            if ($sessionEnabled) $_SESSION[$fieldsOverrideSessionKey] = $fieldsOverrides;
            $selectedEmail['fields'] = $originalNow;
            if (isset($rows[(int)$selectedEmailIdx])) $rows[(int)$selectedEmailIdx]['fields'] = $originalNow;
            $selectedHasEdits = false;
        } else {
            $selectedHasEdits = true;
        }
    }

    ob_start();
    ?>
    <?php if ($statusRefreshRequested): ?>
        <?php
        $refreshUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($refreshUrl !== '') {
            $refreshParts = parse_url($refreshUrl);
            $refreshPath = isset($refreshParts['path']) ? (string)$refreshParts['path'] : $refreshUrl;
            $refreshQuery = array();
            if (isset($refreshParts['query'])) parse_str((string)$refreshParts['query'], $refreshQuery);
            unset($refreshQuery['dlbh_status_refresh']);
            $refreshUrl = $refreshPath . (!empty($refreshQuery) ? ('?' . http_build_query($refreshQuery)) : '');
        }
        ?>
        <meta http-equiv="refresh" content="0.5;url=<?php echo htmlspecialchars($refreshUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&display=swap');
        :root {
            --dlbh-base: #ffffff;
            --dlbh-contrast: #595959;
            --dlbh-primary: #131313;
            --dlbh-secondary: #ffe24a;
            --dlbh-tertiary: #f7f9fa;
            --dlbh-text: var(--dlbh-contrast);
            --dlbh-background: var(--dlbh-base);
            --dlbh-link: var(--dlbh-primary);
            --dlbh-button-text: var(--dlbh-primary);
            --dlbh-button-bg: var(--dlbh-secondary);
            --dlbh-heading: var(--dlbh-primary);
            --dlbh-border: #d9dde1;
            --dlbh-font-sans: "Instrument Sans", "Instrumental Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            --dlbh-font-size: 1.2rem;
            --dlbh-line-height: 1.5;
            --dlbh-heading-size: 1.25rem;
        }
        .dlbh-inbox-card, .dlbh-inbox-card * {
            font-family: var(--dlbh-font-sans);
        }
        .dlbh-inbox-card {
            width: 100%;
            max-width: none;
            min-height: 100vh;
            margin-top: -24px;
            border: 1px solid var(--dlbh-border);
            border-radius: 8px;
            overflow: visible;
            background: var(--dlbh-background);
            color: var(--dlbh-text);
            display: flex;
            flex-direction: column;
            font-size: var(--dlbh-font-size);
            line-height: var(--dlbh-line-height);
        }
        .dlbh-inbox-head { position: relative; z-index: 5; background: var(--dlbh-primary); color: var(--dlbh-base); padding: 10px 12px; font-size: var(--dlbh-heading-size); font-weight: 600; display: flex; align-items: center; justify-content: space-between; }
        .dlbh-inbox-signout { margin-left: auto; display: inline-flex; align-items: center; }
        .dlbh-inbox-head-link { color: inherit; text-decoration: none; }
        .dlbh-inbox-head-link:hover { text-decoration: underline; }
        .dlbh-inbox-brand { display: inline-flex; align-items: center; gap: 10px; color: inherit; text-decoration: none; }
        .dlbh-inbox-brand:hover { text-decoration: none; }
        .dlbh-inbox-brand-mark {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--dlbh-base);
            border-radius: 6px;
            color: var(--dlbh-base);
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1;
            box-sizing: border-box;
        }
        .dlbh-inbox-brand-text {
            display: inline-flex;
            align-items: center;
            font-size: var(--dlbh-heading-size);
            font-weight: 600;
            line-height: 1.2;
            color: var(--dlbh-base);
        }
        .dlbh-inbox-body { padding: 0 12px 12px 12px; flex: 1; display: flex; flex-direction: column; }
        .dlbh-inbox-error { margin-bottom: 10px; padding: 8px 10px; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; border-radius: 6px; font-size: 13px; }
        .dlbh-inbox-status { margin-bottom: 10px; padding: 8px 10px; border: 1px solid #86efac; background: #ecfdf3; color: #166534; border-radius: 6px; font-size: 13px; }
        .dlbh-inbox-grid { display: grid; gap: 10px; max-width: 360px; }
        .dlbh-inbox-label { display: block; margin-bottom: 4px; font-size: 1rem; color: var(--dlbh-text); font-weight: 500; }
        .dlbh-inbox-input { width: 100%; box-sizing: border-box; border: 1px solid var(--dlbh-border); border-radius: 6px; padding: 7px 9px; font-size: 1rem; background: var(--dlbh-base); color: var(--dlbh-text); }
        .dlbh-inbox-btn, .dlbh-inbox-signout-btn {
            border-radius: 6px;
            padding: 8px 12px;
            min-width: 140px;
            min-height: 34px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            box-sizing: border-box;
        }
        .dlbh-inbox-btn { border: 1px solid var(--dlbh-primary); background: var(--dlbh-button-bg); color: var(--dlbh-button-text); }
        .dlbh-inbox-btn:hover { background: #f4d52e; }
        .dlbh-inbox-note { font-size: 1rem; color: var(--dlbh-text); margin-bottom: 12px; }
        .dlbh-inbox-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .dlbh-inbox-table th, .dlbh-inbox-table td { text-align: left; vertical-align: top; padding: 7px 9px; border-bottom: 1px solid var(--dlbh-border); font-size: 1rem; line-height: var(--dlbh-line-height); word-break: break-word; color: var(--dlbh-text); }
        .dlbh-inbox-table th { background: var(--dlbh-tertiary); text-transform: uppercase; letter-spacing: .02em; font-size: 1rem; color: var(--dlbh-heading); }
        .dlbh-inbox-table .is-selected td { background: #fff9d6; }
        .dlbh-row-btn { width: 100%; text-align: left; background: transparent; border: 0; padding: 0; margin: 0; color: var(--dlbh-link); cursor: pointer; font: inherit; }
        .dlbh-row-btn:hover { text-decoration: underline; }
        .dlbh-inbox-row-actions { white-space: nowrap; width: 1%; }
        .dlbh-trash-btn {
            border: 0;
            background: transparent;
            color: #991b1b;
            padding: 0;
            margin: 0;
            min-width: 0;
            min-height: 0;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
        }
        .dlbh-trash-btn:hover { color: #b91c1c; background: transparent; }
        .dlbh-trash-confirm {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #7f1d1d;
        }
        .dlbh-trash-confirm-btn {
            border: 0;
            background: transparent;
            padding: 0;
            margin: 0;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
        }
        .dlbh-trash-confirm-btn.confirm { color: #991b1b; }
        .dlbh-trash-confirm-btn.cancel { color: #475569; }
        .dlbh-inbox-empty { padding: 10px; font-size: 13px; }
        .dlbh-details-shell { margin-top: 12px; border: 1px solid var(--dlbh-border); border-radius: 6px; background: var(--dlbh-background); }
        .dlbh-details-head { padding: 8px 10px; border-bottom: 1px solid var(--dlbh-border); background: var(--dlbh-tertiary); font-size: var(--dlbh-heading-size); font-weight: 600; color: var(--dlbh-heading); display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .dlbh-inbox-field-value { font-size: 1rem; color: var(--dlbh-text); }
        .dlbh-details-head.is-eligible { background: #ecfdf3; border-bottom-color: #86efac; }
        .dlbh-details-head.is-ineligible { background: #fef2f2; border-bottom-color: #fecaca; }
        .dlbh-details-head-title.is-eligible { color: #166534; }
        .dlbh-details-head-title.is-ineligible { color: #991b1b; }
        .dlbh-details-head-actions { display: inline-flex; align-items: center; gap: 8px; }
        .dlbh-details-head-actions .dlbh-inbox-signout-btn { margin-left: 0; }
        .dlbh-inbox-signout-btn.dlbh-edit-toggle-btn {
            min-width: 0;
            width: auto;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            line-height: 1;
            border: 0 !important;
            background: transparent !important;
            color: #334155 !important;
            margin-left: 0;
        }
        .dlbh-inbox-signout-btn.dlbh-edit-toggle-btn:hover,
        .dlbh-inbox-signout-btn.dlbh-edit-toggle-btn:focus,
        .dlbh-inbox-signout-btn.dlbh-edit-toggle-btn:active,
        .dlbh-inbox-signout-btn.dlbh-edit-toggle-btn.is-editing {
            border-color: transparent !important;
            background: transparent !important;
            color: #334155 !important;
        }
        .dlbh-edit-cancel-btn {
            min-width: 0;
            width: auto;
            padding: 0;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            line-height: 1;
            border: 0 !important;
            background: transparent !important;
            color: #334155 !important;
        }
        .dlbh-edit-cancel-btn:hover,
        .dlbh-edit-cancel-btn:focus,
        .dlbh-edit-cancel-btn:active {
            border-color: transparent !important;
            background: transparent !important;
            color: #334155 !important;
        }
        .dlbh-edit-cancel-btn.is-visible { display: inline-flex; }
        .dlbh-message-view {
            padding: 14px;
            border: 1px solid var(--dlbh-border);
            border-radius: 8px;
            background: var(--dlbh-background);
        }
        .dlbh-message-title {
            margin: 0 0 10px 0;
            font-size: var(--dlbh-heading-size);
            font-weight: 600;
            color: var(--dlbh-heading);
        }
        .dlbh-message-copy {
            margin: 0;
            white-space: pre-wrap;
            font-size: 1rem;
            line-height: var(--dlbh-line-height);
            color: var(--dlbh-text);
        }
        .dlbh-verify-result { margin: 8px 0 0 0; padding: 8px 10px; border-radius: 6px; font-size: 12px; line-height: 1.35; }
        .dlbh-verify-result.ok { background: #ecfdf3; border: 1px solid #86efac; color: #166534; }
        .dlbh-verify-result.fail { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .dlbh-compose-wrap { margin: 10px 0 0 0; border: 1px solid var(--dlbh-border); border-radius: 8px; background: var(--dlbh-background); padding: 10px; display: flex; flex-direction: column; min-height: calc(100vh - 260px); }
        .dlbh-compose-title { margin: 0 0 8px 0; font-size: var(--dlbh-heading-size); font-weight: 600; color: var(--dlbh-heading); }
        .dlbh-compose-status { margin: 0 0 8px 0; padding: 8px 10px; border-radius: 6px; font-size: 12px; }
        .dlbh-compose-status.success { border: 1px solid #86efac; background: #ecfdf3; color: #166534; }
        .dlbh-compose-status.error { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; }
        .dlbh-compose-actions { display: inline-flex; align-items: center; gap: 8px; margin-top: 6px; }
        .dlbh-compose-preview-wrap { margin-top: 10px; border: 1px solid var(--dlbh-border); border-radius: 8px; overflow: hidden; background: var(--dlbh-background); flex: 1; min-height: 520px; display: flex; flex-direction: column; }
        .dlbh-compose-preview-head { padding: 8px 10px; background: var(--dlbh-tertiary); border-bottom: 1px solid var(--dlbh-border); font-size: 1rem; font-weight: 600; color: var(--dlbh-heading); }
        .dlbh-compose-preview-body { padding: 0; flex: 1; min-height: 0; }
        .dlbh-compose-editor {
            height: 100%;
            width: 100%;
            box-sizing: border-box;
            padding: 0;
            outline: none;
            background: var(--dlbh-background);
            overflow: auto;
        }
        .dlbh-details-body { padding: 8px 10px; }
        .dlbh-inbox-fields { display: block; }
        .dlbh-inbox-field { display: block; }
        .dlbh-inbox-section-header {
            margin: 12px 0 6px 0;
            padding: 6px 8px;
            background: var(--dlbh-tertiary);
            border: 1px solid var(--dlbh-border);
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--dlbh-heading);
        }
        .dlbh-inbox-section-header.dlbh-record-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .dlbh-record-header-text { flex: 1; min-width: 0; }
        .dlbh-record-header-actions {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            margin-left: auto;
        }
        .dlbh-record-header-btn {
            border: 0;
            background: transparent;
            color: var(--dlbh-link);
            padding: 0;
            margin: 0;
            min-width: 0;
            min-height: 0;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
        }
        .dlbh-record-header-btn[disabled] {
            opacity: .35;
            cursor: default;
        }
        .dlbh-record-block + .dlbh-record-block { margin-top: 6px; }
        .dlbh-record-placeholder { margin-top: 6px; }
        .dlbh-inbox-field-label { display: block; margin-bottom: 5px; font-size: 1rem; font-weight: 500; color: var(--dlbh-text); }
        .dlbh-wpf-input, .dlbh-wpf-textarea, .dlbh-wpf-select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--dlbh-border);
            border-radius: 4px;
            background: var(--dlbh-background);
            color: var(--dlbh-text);
            font-size: 1rem;
            line-height: var(--dlbh-line-height);
            padding: 8px 10px;
        }
        .dlbh-wpf-input[readonly], .dlbh-wpf-textarea[readonly], .dlbh-wpf-select[disabled] { background: var(--dlbh-tertiary); }
        .dlbh-wpf-textarea { min-height: 74px; resize: vertical; }
        .dlbh-field-invalid { border: 2px solid #cc0000 !important; background: #fff5f5 !important; box-shadow: 0 0 0 2px rgba(204,0,0,0.12); }
        .dlbh-field-error {
            margin-top: 6px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 6px;
            padding: 7px 8px;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .dlbh-field-error-icon {
            width: 16px;
            height: 16px;
            min-width: 16px;
            border-radius: 50%;
            border: 1px solid #fca5a5;
            background: #fee2e2;
            color: #b42318;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            margin-top: 1px;
        }
        .dlbh-field-error-title { display: block; font-size: 11px; font-weight: 700; margin-bottom: 2px; }
        .dlbh-field-error-message { display: block; font-size: 11px; font-weight: 600; line-height: 1.3; }
        .dlbh-inbox-field + .dlbh-inbox-field { margin-top: 10px; }
        .dlbh-inbox-signout-btn { border: 1px solid var(--dlbh-primary); background: var(--dlbh-button-bg); color: var(--dlbh-button-text); margin-left: 12px; }
        .dlbh-inbox-signout-btn:hover { background: #f4d52e; }
        .dlbh-head-menu { position: relative; margin-left: 12px; }
        .dlbh-head-menu-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 0;
            padding: 0;
            margin: 0;
            cursor: pointer;
            font: inherit;
            color: inherit;
            line-height: inherit;
            -webkit-appearance: none;
            appearance: none;
        }
        .dlbh-head-menu-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 320px;
            background: var(--dlbh-background);
            border: 1px solid var(--dlbh-border);
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
            padding: 6px;
            z-index: 40;
        }
        .dlbh-head-menu-panel[hidden] { display: none; }
        .dlbh-head-menu-overlay {
            position: fixed;
            inset: 0;
            background: transparent;
            z-index: 35;
        }
        .dlbh-head-menu-overlay[hidden] { display: none; }
        .dlbh-head-menu-item {
            display: block;
            width: 100%;
            text-align: left;
            background: transparent;
            border: 0;
            padding: 8px 10px;
            margin: 0;
            font: inherit;
            color: var(--dlbh-link);
            border-radius: 4px;
            cursor: pointer;
            white-space: nowrap;
        }
        .dlbh-head-menu-item:hover { background: var(--dlbh-tertiary); }
        .dlbh-head-menu-label {
            display: block;
            padding: 8px 10px 4px 10px;
            color: var(--dlbh-heading);
            font-size: 0.95rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .dlbh-head-menu-item-sub {
            padding-left: 20px;
            font-size: 1rem;
            white-space: nowrap;
        }
        .dlbh-head-menu-confirm {
            padding: 8px 10px;
            color: var(--dlbh-text);
            font-size: 1rem;
            line-height: 1.35;
        }
        .dlbh-head-menu-confirm-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 10px 8px 10px;
        }
        .dlbh-head-menu-confirm-btn {
            background: transparent;
            border: 0;
            padding: 0;
            margin: 0;
            font: inherit;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .dlbh-head-menu-confirm-btn.confirm { color: var(--dlbh-link); }
        .dlbh-head-menu-confirm-btn.cancel { color: var(--dlbh-text); }
        .dlbh-bingo-card-shell {
            --dlbh-bingo-cell-size: 55px;
            text-align: center;
            display: inline-block;
            width: 275px;
            min-width: 275px;
            max-width: 275px;
            flex: 0 0 275px;
            margin: 0;
            padding: 0;
            border: 0;
            background: transparent;
            box-sizing: border-box;
        }
        .dlbh-bingo-card-title {
            text-align: center;
            color: #444444;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: .06em;
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .dlbh-bingo-card-wrap {
            width: 100%;
            overflow-x: visible;
        }
        .dlbh-bingo-deck-actions {
            margin-top: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .dlbh-bingo-cart {
            margin-top: 14px;
            border: 1px solid var(--dlbh-border);
            border-radius: 8px;
            background: #ffffff;
            padding: 12px;
        }
        .dlbh-bingo-cart-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .dlbh-bingo-cart-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1f2937;
        }
        .dlbh-bingo-cart-total {
            font-size: 0.95rem;
            font-weight: 700;
            color: #111827;
        }
        .dlbh-bingo-cart-head-actions {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }
        .dlbh-bingo-cart-head-actions .dlbh-bingo-cart-total {
            width: 100%;
            text-align: center;
        }
        .dlbh-bingo-head-qty {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .dlbh-bingo-cart-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0;
        }
        .dlbh-bingo-cart-item {
            border: 0;
            border-radius: 0;
            padding: 8px 0;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .dlbh-bingo-cart-list .dlbh-bingo-cart-item + .dlbh-bingo-cart-item {
            border-top: 1px solid #e5e7eb;
            margin-top: 8px;
            padding-top: 12px;
        }
        .dlbh-bingo-cart-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            width: 100%;
        }
        .dlbh-bingo-cart-field {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .dlbh-bingo-cart-field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: #6b7280;
        }
        .dlbh-bingo-cart-field-value {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            line-height: 1.25;
        }
        .dlbh-bingo-qty-controls {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .dlbh-bingo-qty-btn {
            min-width: 36px;
            min-height: 32px;
            height: 32px;
            padding: 4px 10px;
            font-size: 0.95rem;
            line-height: 1;
        }
        .dlbh-bingo-qty-count {
            min-width: 24px;
            text-align: center;
            font-weight: 700;
            color: #111827;
        }
        .dlbh-bingo-cart-empty {
            font-size: 0.92rem;
            color: #4b5563;
        }
        .dlbh-bingo-card-table {
            width: calc(var(--dlbh-bingo-cell-size) * 5);
            border-collapse: collapse;
            border-spacing: 0;
            table-layout: fixed;
            background: transparent;
            margin: 0 auto;
        }
        .dlbh-bingo-card-table th,
        .dlbh-bingo-card-cell {
            width: var(--dlbh-bingo-cell-size);
            min-width: var(--dlbh-bingo-cell-size);
            max-width: var(--dlbh-bingo-cell-size);
            height: var(--dlbh-bingo-cell-size);
            min-height: var(--dlbh-bingo-cell-size);
            max-height: var(--dlbh-bingo-cell-size);
            padding: 0;
            font-size: 1.125rem;
            line-height: var(--dlbh-bingo-cell-size);
        }
        .dlbh-bingo-card-table th {
            border: 1px solid var(--dlbh-bingo-accent, #e53935);
            text-align: center;
            color: #ffffff;
            background: var(--dlbh-bingo-accent, #e53935);
            font-weight: 700;
            letter-spacing: 0;
        }
        .dlbh-bingo-card-cell {
            text-align: center;
            vertical-align: middle;
            border: 1px solid #ffffff;
            border-radius: 0;
            background: #f3f3f3;
            color: #444444;
            font-weight: 400;
            box-sizing: border-box;
        }
        .dlbh-bingo-card-cell.is-free {
            background: var(--dlbh-bingo-accent, #e53935);
            border-color: var(--dlbh-bingo-accent, #e53935);
            color: #ffffff;
            font-size: 0.8rem;
            letter-spacing: 0;
            line-height: var(--dlbh-bingo-cell-size);
            font-weight: 700;
            white-space: nowrap;
        }
        .dlbh-bingo-carousel {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        .dlbh-bingo-carousel-viewport {
            flex: 0 0 auto;
            width: 1130px; /* 4 cards (275) + 3 gaps (10) */
            max-width: 1130px;
            overflow-x: hidden;
            scroll-behavior: smooth;
            scrollbar-width: thin;
            border: 1px solid var(--dlbh-border);
            border-radius: 8px;
            background: #fafafa;
            padding: 0;
            margin: 0 auto;
            box-sizing: content-box;
        }
        .dlbh-bingo-carousel-rail {
            display: flex;
            align-items: stretch;
            gap: 0;
            width: 100%;
        }
        .dlbh-bingo-page {
            flex: 0 0 100%;
            min-width: 100%;
            max-width: 100%;
            display: flex;
            align-items: start;
            justify-content: flex-start;
            gap: 10px;
            box-sizing: border-box;
        }
        .dlbh-bingo-arrow {
            min-width: 34px;
            height: 34px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #1f2937;
            border-radius: 6px;
            font-size: 18px;
            line-height: 1;
            padding: 0;
            cursor: pointer;
        }
        .dlbh-bingo-arrow:hover { background: #f3f4f6; }
        .dlbh-bingo-pricing {
            margin: 0 0 14px 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            padding: 0;
        }
        .dlbh-bingo-pricing-list { display: block; }
        .dlbh-bingo-pricing-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f9fafb;
            padding: 10px;
            min-height: 126px;
            display: flex;
            flex-direction: column;
        }
        .dlbh-bingo-pricing-item h4 {
            margin: 0 0 6px 0;
            font-size: 0.95rem;
            font-weight: 700;
            color: #111827;
        }
        .dlbh-bingo-pricing-item p {
            margin: 0;
            font-size: 0.9rem;
            line-height: 1.4;
            color: #374151;
        }
        .dlbh-bingo-pricing-price {
            font-weight: 700;
            margin: 0 0 6px 0;
        }
        .dlbh-bingo-pricing-minimum {
            font-weight: 700;
            margin: auto 0 0 0;
        }
        .dlbh-bingo-upgrades {
            margin: 10px 0 14px 0;
            border: 1px solid var(--dlbh-border);
            border-radius: 8px;
            background: #ffffff;
            padding: 12px;
        }
        .dlbh-bingo-upgrades-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 8px 0;
        }
        .dlbh-bingo-upgrade-option {
            margin: 0 0 8px 0;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            cursor: pointer;
        }
        .dlbh-bingo-upgrade-option:last-child { margin-bottom: 0; }
        .dlbh-bingo-upgrade-option strong { display: block; margin-bottom: 4px; color: #111827; }
        .dlbh-bingo-upgrade-option input[type="radio"] {
            margin: 2px 0 0 0;
            flex: 0 0 auto;
            width: auto;
            height: auto;
            accent-color: #1f2937;
        }
        .dlbh-bingo-upgrade-copy {
            display: block;
            min-width: 0;
            width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            background: #f9fafb;
            color: #111827;
            transition: background-color .2s ease, border-color .2s ease, color .2s ease;
        }
        .dlbh-bingo-upgrade-option.is-bronze input[type="radio"]:checked + .dlbh-bingo-upgrade-copy { background: #b87333; border-color: #b87333; color: #ffffff; }
        .dlbh-bingo-upgrade-option.is-silver input[type="radio"]:checked + .dlbh-bingo-upgrade-copy { background: #9ca3af; border-color: #9ca3af; color: #ffffff; }
        .dlbh-bingo-upgrade-option.is-gold input[type="radio"]:checked + .dlbh-bingo-upgrade-copy { background: #c79a00; border-color: #c79a00; color: #ffffff; }
        .dlbh-bingo-upgrade-option.is-platinum input[type="radio"]:checked + .dlbh-bingo-upgrade-copy { background: #4f8ef7; border-color: #4f8ef7; color: #ffffff; }
        .dlbh-bingo-upgrade-option input[type="radio"]:checked + .dlbh-bingo-upgrade-copy strong,
        .dlbh-bingo-upgrade-option input[type="radio"]:checked + .dlbh-bingo-upgrade-copy span { color: #ffffff; }
        .dlbh-bingo-pricing-item.is-bronze { border-color: #b87333; background: #b87333; }
        .dlbh-bingo-pricing-item.is-silver { border-color: #9ca3af; background: #9ca3af; }
        .dlbh-bingo-pricing-item.is-gold { border-color: #c79a00; background: #c79a00; }
        .dlbh-bingo-pricing-item.is-platinum { border-color: #4f8ef7; background: #4f8ef7; }
        .dlbh-bingo-pricing-item.is-bronze h4,
        .dlbh-bingo-pricing-item.is-silver h4,
        .dlbh-bingo-pricing-item.is-gold h4,
        .dlbh-bingo-pricing-item.is-platinum h4 { color: #ffffff; }
        .dlbh-bingo-pricing-item.is-bronze p,
        .dlbh-bingo-pricing-item.is-silver p,
        .dlbh-bingo-pricing-item.is-gold p,
        .dlbh-bingo-pricing-item.is-platinum p { color: #ffffff; }
        @media (max-width: 680px) {
            .dlbh-bingo-card-shell {
                --dlbh-bingo-cell-size: 45px;
            }
            .dlbh-bingo-card-table th,
            .dlbh-bingo-card-cell {
                font-size: 0.95rem;
            }
            .dlbh-bingo-arrow {
                min-width: 30px;
                height: 30px;
            }
            .dlbh-bingo-pricing-list {
                grid-template-columns: 1fr;
            }
            .dlbh-bingo-pricing-item {
                min-height: auto;
            }
            .dlbh-bingo-cart-list {
                grid-template-columns: 1fr;
            }
            .dlbh-bingo-cart-fields {
                grid-template-columns: 1fr;
            }
        }
        .dlbh-eligible-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 140px;
            min-height: 34px;
            padding: 8px 12px;
            border: 1px solid #16a34a;
            background: #ecfdf3;
            color: #166534;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            box-sizing: border-box;
        }
        .dlbh-billing-hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 14px 0 18px 0;
            padding: 20px 16px;
            border: 1px solid #dbe3ee;
            border-radius: 10px;
            background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
            text-align: center;
        }
        .dlbh-billing-hero-label {
            font-size: 14px;
            font-weight: 700;
            color: #1e3557;
            letter-spacing: .02em;
        }
        .dlbh-billing-hero-amount {
            font-size: 36px;
            line-height: 1;
            font-weight: 800;
            color: #0f172a;
        }
        .dlbh-billing-hero-meta {
            font-size: 14px;
            color: #334155;
        }
        .dlbh-billing-hero-divider {
            width: 100%;
            max-width: 240px;
            height: 1px;
            background: #dbe3ee;
            margin: 4px 0;
        }
        .dlbh-billing-hero-pay-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 180px;
            text-decoration: none;
        }
        .dlbh-budget-table-input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--dlbh-border);
            border-radius: 4px;
            background: var(--dlbh-background);
            color: var(--dlbh-text);
            font-size: 1rem;
            line-height: var(--dlbh-line-height);
            padding: 8px 10px;
        }
        .dlbh-budget-table-input[readonly] {
            background: var(--dlbh-tertiary);
        }
    </style>
    <div class="dlbh-inbox-card">
        <div class="dlbh-inbox-head">
            <?php
            $inboxRefreshUrl = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            $cashReceiptsJournalUrl = $inboxRefreshUrl;
            $statementOfActivityUrl = $inboxRefreshUrl;
            $agingReportUrl = $inboxRefreshUrl;
            $budgetCalculatorUrl = $inboxRefreshUrl;
            $rosterScreenUrl = $inboxRefreshUrl;
            $allergiesScreenUrl = $inboxRefreshUrl;
            $militaryScreenUrl = $inboxRefreshUrl;
            $apparelScreenUrl = $inboxRefreshUrl;
            $birthdaysScreenUrl = $inboxRefreshUrl;
            $fundraisingBingoUrl = $inboxRefreshUrl;
            $fundraisingBingoPlayUrl = $inboxRefreshUrl;
            if ($inboxRefreshUrl !== '') {
                $parts = parse_url($inboxRefreshUrl);
                $path = isset($parts['path']) ? (string)$parts['path'] : $inboxRefreshUrl;
                $query = array();
                if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                unset($query['dlbh_logout_confirm'], $query['dlbh_screen'], $query['dlbh_financial_view'], $query['dlbh_roster_member'], $query['dlbh_statement_offset'], $query['dlbh_profile_section'], $query['dlbh_household_offset'], $query['dlbh_return'], $query['dlbh_roster_search'], $query['dlbh_roster_name'], $query['dlbh_roster_family_id'], $query['dlbh_bingo_mode'], $query['dlbh_bingo_view']);
                $inboxRefreshUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                $cashReceiptsQuery = $query;
                $cashReceiptsQuery['dlbh_screen'] = 'financials';
                $cashReceiptsQuery['dlbh_financial_view'] = 'cash_receipts_journal';
                $cashReceiptsJournalUrl = $path . (!empty($cashReceiptsQuery) ? ('?' . http_build_query($cashReceiptsQuery)) : '');
                $statementQuery = $query;
                $statementQuery['dlbh_screen'] = 'financials';
                $statementQuery['dlbh_financial_view'] = 'statement_of_activity';
                $statementOfActivityUrl = $path . (!empty($statementQuery) ? ('?' . http_build_query($statementQuery)) : '');
                $agingReportQuery = $query;
                $agingReportQuery['dlbh_screen'] = 'financials';
                $agingReportQuery['dlbh_financial_view'] = 'aging_report';
                $agingReportUrl = $path . (!empty($agingReportQuery) ? ('?' . http_build_query($agingReportQuery)) : '');
                $budgetCalculatorQuery = $query;
                $budgetCalculatorQuery['dlbh_screen'] = 'financials';
                $budgetCalculatorQuery['dlbh_financial_view'] = 'budget_calculator';
                $budgetCalculatorUrl = $path . (!empty($budgetCalculatorQuery) ? ('?' . http_build_query($budgetCalculatorQuery)) : '');
                $rosterQuery = $query;
                $rosterQuery['dlbh_screen'] = 'roster';
                $rosterScreenUrl = $path . (!empty($rosterQuery) ? ('?' . http_build_query($rosterQuery)) : '');
                $allergiesQuery = $query;
                $allergiesQuery['dlbh_screen'] = 'allergies';
                $allergiesScreenUrl = $path . (!empty($allergiesQuery) ? ('?' . http_build_query($allergiesQuery)) : '');
                $militaryQuery = $query;
                $militaryQuery['dlbh_screen'] = 'military';
                $militaryScreenUrl = $path . (!empty($militaryQuery) ? ('?' . http_build_query($militaryQuery)) : '');
                $apparelQuery = $query;
                $apparelQuery['dlbh_screen'] = 'apparel';
                $apparelScreenUrl = $path . (!empty($apparelQuery) ? ('?' . http_build_query($apparelQuery)) : '');
                $birthdaysQuery = $query;
                $birthdaysQuery['dlbh_screen'] = 'birthdays';
                $birthdaysScreenUrl = $path . (!empty($birthdaysQuery) ? ('?' . http_build_query($birthdaysQuery)) : '');
                $fundraisingBingoQuery = $query;
                $fundraisingBingoQuery['dlbh_screen'] = 'fundraising_bingo';
                unset($fundraisingBingoQuery['dlbh_bingo_mode'], $fundraisingBingoQuery['dlbh_bingo_view']);
                $fundraisingBingoUrl = $path . (!empty($fundraisingBingoQuery) ? ('?' . http_build_query($fundraisingBingoQuery)) : '');
                $fundraisingBingoPlayQuery = $fundraisingBingoQuery;
                $fundraisingBingoPlayQuery['dlbh_bingo_mode'] = 'play';
                $fundraisingBingoPlayUrl = $path . (!empty($fundraisingBingoPlayQuery) ? ('?' . http_build_query($fundraisingBingoPlayQuery)) : '');
            }
            ?>
            <?php if ($memberSession['enabled'] || $screen === 'fundraising_bingo'): ?>
                <a href="<?php echo htmlspecialchars($rosterScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-head-link">Profile</a>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($inboxRefreshUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-brand">
                    <span class="dlbh-inbox-brand-mark">FA</span>
                    <span class="dlbh-inbox-brand-text">Family Access</span>
                </a>
            <?php endif; ?>
            <?php if ($signedIn): ?>
                <?php $logoutConfirmRequested = isset($_GET['dlbh_logout_confirm']) && (string)$_GET['dlbh_logout_confirm'] === '1'; ?>
                <div class="dlbh-head-menu">
                    <?php if (!$memberSession['enabled']): ?>
                    <button type="button" class="dlbh-inbox-head-link dlbh-head-menu-toggle" id="dlbh-head-menu-toggle" aria-haspopup="true" aria-expanded="<?php echo ($logoutConfirmRequested ? 'true' : 'false'); ?>" onclick="(function(btn){var panel=document.getElementById('dlbh-head-menu-panel');var overlay=document.getElementById('dlbh-head-menu-overlay');if(!panel||!overlay)return false;var isHidden=panel.hasAttribute('hidden');if(isHidden){panel.removeAttribute('hidden');overlay.removeAttribute('hidden');btn.setAttribute('aria-expanded','true');}else{panel.setAttribute('hidden','hidden');overlay.setAttribute('hidden','hidden');btn.setAttribute('aria-expanded','false');}return false;})(this); return false;">Menu</button>
                    <div class="dlbh-head-menu-overlay" id="dlbh-head-menu-overlay"<?php echo ($logoutConfirmRequested ? '' : ' hidden'); ?> onclick="(function(){var panel=document.getElementById('dlbh-head-menu-panel');var overlay=document.getElementById('dlbh-head-menu-overlay');var btn=document.getElementById('dlbh-head-menu-toggle');if(panel)panel.setAttribute('hidden','hidden');if(overlay)overlay.setAttribute('hidden','hidden');if(btn)btn.setAttribute('aria-expanded','false');})(); return false;"></div>
                    <div class="dlbh-head-menu-panel" id="dlbh-head-menu-panel"<?php echo ($logoutConfirmRequested ? '' : ' hidden'); ?>>
                        <a href="<?php echo htmlspecialchars($inboxRefreshUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item" style="text-decoration:none;box-sizing:border-box;">Inbox</a>
                        <span class="dlbh-head-menu-label">Membership Committee</span>
                        <a href="<?php echo htmlspecialchars($rosterScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Roster</a>
                        <a href="<?php echo htmlspecialchars($allergiesScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Allergies &amp; Food Restrictions</a>
                        <a href="<?php echo htmlspecialchars($militaryScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Military Status</a>
                        <a href="<?php echo htmlspecialchars($birthdaysScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Birthdays</a>
                        <a href="<?php echo htmlspecialchars($apparelScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Apparel</a>
                        <span class="dlbh-head-menu-label">Finance Committee</span>
                        <a href="<?php echo htmlspecialchars($cashReceiptsJournalUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Cash Receipts Journal</a>
                        <a href="<?php echo htmlspecialchars($statementOfActivityUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Statement of Activity</a>
                        <a href="<?php echo htmlspecialchars($agingReportUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Aging Report</a>
                        <a href="<?php echo htmlspecialchars($budgetCalculatorUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Budget Calculator</a>
                        <?php if ($logoutConfirmRequested): ?>
                            <div class="dlbh-head-menu-confirm">Are you sure?</div>
                            <div class="dlbh-head-menu-confirm-actions">
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="dlbh_inbox_action" value="logout">
                                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                                    <button class="dlbh-head-menu-confirm-btn confirm" type="submit">Yes</button>
                                </form>
                                <a href="<?php echo htmlspecialchars($inboxRefreshUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-confirm-btn cancel" style="text-decoration:none;">No</a>
                            </div>
                        <?php else: ?>
                            <?php
                            $logoutConfirmUrl = $inboxRefreshUrl;
                            if ($logoutConfirmUrl !== '') {
                                $parts = parse_url($logoutConfirmUrl);
                                $path = isset($parts['path']) ? (string)$parts['path'] : $logoutConfirmUrl;
                                $query = array();
                                if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                $query['dlbh_logout_confirm'] = '1';
                                $logoutConfirmUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                            }
                            ?>
                            <a href="<?php echo htmlspecialchars($logoutConfirmUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item" style="text-decoration:none;box-sizing:border-box;">Sign Out</a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <?php
                    $logoutConfirmUrl = $rosterScreenUrl;
                    if ($logoutConfirmUrl !== '') {
                        $parts = parse_url($logoutConfirmUrl);
                        $path = isset($parts['path']) ? (string)$parts['path'] : $logoutConfirmUrl;
                        $query = array();
                        if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                        $query['dlbh_logout_confirm'] = '1';
                        $logoutConfirmUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                    }
                    ?>
                    <?php if ($logoutConfirmRequested): ?>
                        <span class="dlbh-head-menu-confirm">Are you sure?</span>
                        <span class="dlbh-head-menu-confirm-actions">
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="dlbh_inbox_action" value="logout">
                                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                                <button class="dlbh-head-menu-confirm-btn confirm" type="submit">Yes</button>
                            </form>
                            <a href="<?php echo htmlspecialchars($rosterScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-confirm-btn cancel" style="text-decoration:none;">No</a>
                        </span>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($logoutConfirmUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-head-link" style="text-decoration:none;">Sign Out</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="dlbh-inbox-body">
            <?php if (!function_exists('imap_open')): ?>
                <div class="dlbh-inbox-error">PHP IMAP extension is not enabled on this server.</div>
            <?php elseif ($error !== ''): ?>
                <div class="dlbh-inbox-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($statusMessage !== ''): ?>
                <div class="dlbh-inbox-status" id="dlbh-inbox-status"><?php echo htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$signedIn): ?>
                <form method="post" class="dlbh-inbox-grid">
                    <input type="hidden" name="dlbh_inbox_action" value="login">
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                    <div>
                        <label class="dlbh-inbox-label" for="dlbh-inbox-email">Email address</label>
                        <input class="dlbh-inbox-input" id="dlbh-inbox-email" name="dlbh_inbox_email" type="email" required autocomplete="username" value="<?php echo htmlspecialchars($postedEmail, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="dlbh-inbox-label" for="dlbh-inbox-password">Password</label>
                        <input class="dlbh-inbox-input" id="dlbh-inbox-password" name="dlbh_inbox_password" type="password" required autocomplete="current-password">
                    </div>
                    <div>
                        <button class="dlbh-inbox-btn" type="submit">Sign In</button>
                    </div>
                </form>
            <?php elseif ($screen === 'financials'): ?>
                <?php $agingReportSections = dlbh_inbox_build_aging_report_rows($rosterRowsByFamilyId, $stripeRows); ?>
                <?php
                $budgetCalculatorSections = array(
                    'Venue and Setup' => array(
                        array('item' => 'Venue', 'per_item' => 'Flat Fee'),
                        array('item' => 'Tables', 'per_item' => 'Each'),
                        array('item' => 'Chairs', 'per_item' => 'Each'),
                        array('item' => 'Linens', 'per_item' => 'Each'),
                    ),
                    'Food and Refreshments' => array(
                        array('item' => 'Food', 'per_item' => 'Per Person'),
                        array('item' => 'Beverages', 'per_item' => 'Per Person'),
                        array('item' => 'Serving supplies', 'per_item' => 'Each'),
                    ),
                    'Activities and Entertainment' => array(
                        array('item' => 'Entertainment', 'per_item' => 'Flat Fee'),
                        array('item' => 'Activities', 'per_item' => 'Each'),
                        array('item' => 'Prizes', 'per_item' => 'Each'),
                    ),
                    'Decorations and Materials' => array(
                        array('item' => 'Decorations', 'per_item' => 'Each'),
                        array('item' => 'T-Shirts', 'per_item' => 'Per Person'),
                        array('item' => 'Name tags', 'per_item' => 'Per Person'),
                        array('item' => 'Printing', 'per_item' => 'Each'),
                    ),
                    'Administrative and Miscellaneous' => array(
                        array('item' => 'Fees and permits', 'per_item' => 'Flat Fee'),
                        array('item' => 'Supplies', 'per_item' => 'Each'),
                        array('item' => 'Miscellaneous', 'per_item' => 'Each'),
                    ),
                );
                $totalPrimaryMembers = 0;
                foreach ($rosterRows as $rosterSummaryRow) {
                    if (!is_array($rosterSummaryRow)) continue;
                    if (strcasecmp((string)(isset($rosterSummaryRow['Relationship']) ? $rosterSummaryRow['Relationship'] : ''), 'Primary Member') !== 0) continue;
                    $totalPrimaryMembers++;
                }
                $currentMonthNumber = (int)date('n');
                $monthsRemainingInYear = max(0, 12 - $currentMonthNumber + 1);
                $projectedDuesRevenue = dlbh_inbox_format_currency_value((string)($totalPrimaryMembers * 20 * $monthsRemainingInYear));
                $financialsTodayLabel = date('F j, Y');
                ?>
                <div class="dlbh-details-shell">
                    <div class="dlbh-details-head">
                        <span class="dlbh-details-head-title">Financials</span>
                        <span class="dlbh-inbox-field-value"><?php echo htmlspecialchars($financialsTodayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if ($financialView === 'budget_calculator'): ?>
                            <?php foreach ($budgetCalculatorSections as $budgetSectionLabel => $budgetSectionRows): ?>
                                <?php $budgetSectionKey = sanitize_title($budgetSectionLabel); ?>
                                <div class="dlbh-inbox-section-header" style="margin-top:12px;"><?php echo htmlspecialchars($budgetSectionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <table class="dlbh-inbox-table" data-budget-section="<?php echo htmlspecialchars($budgetSectionKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <tr>
                                        <th>Item</th>
                                        <th>Unit Cost</th>
                                        <th>Per Item</th>
                                        <th>Total Items</th>
                                        <th>Estimated Cost</th>
                                    </tr>
                                    <?php foreach ($budgetSectionRows as $budgetRow): ?>
                                        <?php $budgetRowIsFlatFee = ((string)$budgetRow['per_item'] === 'Flat Fee'); ?>
                                        <tr data-budget-mode="<?php echo htmlspecialchars((string)$budgetRow['per_item'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($budgetRowIsFlatFee ? ' data-budget-flat-fee="1"' : ''); ?>>
                                            <td><?php echo htmlspecialchars((string)$budgetRow['item'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    class="dlbh-budget-table-input"
                                                    data-budget-unit-cost="1"
                                                    oninput="(function(el){var row=el.closest('tr');if(!row)return;var table=row.closest('table');var unit=row.querySelector('[data-budget-unit-cost=&quot;1&quot;]');var total=row.querySelector('[data-budget-total-items=&quot;1&quot;]');var estimate=row.querySelector('[data-budget-estimated-cost=&quot;1&quot;]');var mode=String(row.getAttribute('data-budget-mode')||'').trim();var unitVal=parseFloat(String(unit&&unit.value||'').trim());var totalVal=parseFloat(String(total&&total.value||'').trim());var parseMoney=function(v){var n=parseFloat(String(v||'').replace(/[^0-9.\-]/g,''));return isFinite(n)?n:0;};var money=function(v){var n=Number(v);if(!isFinite(n)||n<0)n=0;return '$'+n.toFixed(2);};if(!isFinite(unitVal)||unitVal<0)unitVal=0;if(!isFinite(totalVal)||totalVal<0)totalVal=0;if(mode==='Flat Fee'&&total){totalVal=1;total.value='1';total.readOnly=true;}else if(total){total.readOnly=false;}if(estimate){estimate.value=money((mode==='Flat Fee')?unitVal:(unitVal*totalVal));}if(table){var sectionTotal=0;Array.prototype.forEach.call(table.querySelectorAll('tr[data-budget-mode] [data-budget-estimated-cost=&quot;1&quot;]'),function(i){sectionTotal+=parseMoney(i.value);});var sectionSubtotal=table.querySelector('[data-budget-section-subtotal]');if(sectionSubtotal)sectionSubtotal.value=money(sectionTotal);var sectionKey=String(table.getAttribute('data-budget-section')||'').trim();var summary=document.querySelector('[data-budget-summary-subtotal=&quot;'+sectionKey+'&quot;]');if(summary)summary.value=money(sectionTotal);}var grand=0;Array.prototype.forEach.call(document.querySelectorAll('[data-budget-summary-subtotal]'),function(i){grand+=parseMoney(i.value);});var grandField=document.getElementById('dlbh-budget-grand-total');if(grandField)grandField.value=money(grand);})(this)"
                                                    onchange="(function(el){var row=el.closest('tr');if(!row)return;var table=row.closest('table');var unit=row.querySelector('[data-budget-unit-cost=&quot;1&quot;]');var total=row.querySelector('[data-budget-total-items=&quot;1&quot;]');var estimate=row.querySelector('[data-budget-estimated-cost=&quot;1&quot;]');var mode=String(row.getAttribute('data-budget-mode')||'').trim();var unitVal=parseFloat(String(unit&&unit.value||'').trim());var totalVal=parseFloat(String(total&&total.value||'').trim());var parseMoney=function(v){var n=parseFloat(String(v||'').replace(/[^0-9.\-]/g,''));return isFinite(n)?n:0;};var money=function(v){var n=Number(v);if(!isFinite(n)||n<0)n=0;return '$'+n.toFixed(2);};if(!isFinite(unitVal)||unitVal<0)unitVal=0;if(!isFinite(totalVal)||totalVal<0)totalVal=0;if(mode==='Flat Fee'&&total){totalVal=1;total.value='1';total.readOnly=true;}else if(total){total.readOnly=false;}if(estimate){estimate.value=money((mode==='Flat Fee')?unitVal:(unitVal*totalVal));}if(table){var sectionTotal=0;Array.prototype.forEach.call(table.querySelectorAll('tr[data-budget-mode] [data-budget-estimated-cost=&quot;1&quot;]'),function(i){sectionTotal+=parseMoney(i.value);});var sectionSubtotal=table.querySelector('[data-budget-section-subtotal]');if(sectionSubtotal)sectionSubtotal.value=money(sectionTotal);var sectionKey=String(table.getAttribute('data-budget-section')||'').trim();var summary=document.querySelector('[data-budget-summary-subtotal=&quot;'+sectionKey+'&quot;]');if(summary)summary.value=money(sectionTotal);}var grand=0;Array.prototype.forEach.call(document.querySelectorAll('[data-budget-summary-subtotal]'),function(i){grand+=parseMoney(i.value);});var grandField=document.getElementById('dlbh-budget-grand-total');if(grandField)grandField.value=money(grand);})(this)"
                                                    inputmode="decimal"
                                                >
                                            </td>
                                            <td>
                                                <input
                                                    type="text"
                                                    readonly
                                                    value="<?php echo htmlspecialchars((string)$budgetRow['per_item'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    class="dlbh-budget-table-input"
                                                    data-budget-per-item-label="<?php echo htmlspecialchars((string)$budgetRow['per_item'], ENT_QUOTES, 'UTF-8'); ?>"
                                                >
                                            </td>
                                            <td>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    class="dlbh-budget-table-input"
                                                    data-budget-total-items="1"
                                                    value="<?php echo ($budgetRowIsFlatFee ? '1' : ''); ?>"
                                                    <?php echo ($budgetRowIsFlatFee ? 'readonly' : ''); ?>
                                                    oninput="(function(el){var row=el.closest('tr');if(!row)return;var table=row.closest('table');var unit=row.querySelector('[data-budget-unit-cost=&quot;1&quot;]');var total=row.querySelector('[data-budget-total-items=&quot;1&quot;]');var estimate=row.querySelector('[data-budget-estimated-cost=&quot;1&quot;]');var mode=String(row.getAttribute('data-budget-mode')||'').trim();var unitVal=parseFloat(String(unit&&unit.value||'').trim());var totalVal=parseFloat(String(total&&total.value||'').trim());var parseMoney=function(v){var n=parseFloat(String(v||'').replace(/[^0-9.\-]/g,''));return isFinite(n)?n:0;};var money=function(v){var n=Number(v);if(!isFinite(n)||n<0)n=0;return '$'+n.toFixed(2);};if(!isFinite(unitVal)||unitVal<0)unitVal=0;if(!isFinite(totalVal)||totalVal<0)totalVal=0;if(mode==='Flat Fee'&&total){totalVal=1;total.value='1';total.readOnly=true;}else if(total){total.readOnly=false;}if(estimate){estimate.value=money((mode==='Flat Fee')?unitVal:(unitVal*totalVal));}if(table){var sectionTotal=0;Array.prototype.forEach.call(table.querySelectorAll('tr[data-budget-mode] [data-budget-estimated-cost=&quot;1&quot;]'),function(i){sectionTotal+=parseMoney(i.value);});var sectionSubtotal=table.querySelector('[data-budget-section-subtotal]');if(sectionSubtotal)sectionSubtotal.value=money(sectionTotal);var sectionKey=String(table.getAttribute('data-budget-section')||'').trim();var summary=document.querySelector('[data-budget-summary-subtotal=&quot;'+sectionKey+'&quot;]');if(summary)summary.value=money(sectionTotal);}var grand=0;Array.prototype.forEach.call(document.querySelectorAll('[data-budget-summary-subtotal]'),function(i){grand+=parseMoney(i.value);});var grandField=document.getElementById('dlbh-budget-grand-total');if(grandField)grandField.value=money(grand);})(this)"
                                                    onchange="(function(el){var row=el.closest('tr');if(!row)return;var table=row.closest('table');var unit=row.querySelector('[data-budget-unit-cost=&quot;1&quot;]');var total=row.querySelector('[data-budget-total-items=&quot;1&quot;]');var estimate=row.querySelector('[data-budget-estimated-cost=&quot;1&quot;]');var mode=String(row.getAttribute('data-budget-mode')||'').trim();var unitVal=parseFloat(String(unit&&unit.value||'').trim());var totalVal=parseFloat(String(total&&total.value||'').trim());var parseMoney=function(v){var n=parseFloat(String(v||'').replace(/[^0-9.\-]/g,''));return isFinite(n)?n:0;};var money=function(v){var n=Number(v);if(!isFinite(n)||n<0)n=0;return '$'+n.toFixed(2);};if(!isFinite(unitVal)||unitVal<0)unitVal=0;if(!isFinite(totalVal)||totalVal<0)totalVal=0;if(mode==='Flat Fee'&&total){totalVal=1;total.value='1';total.readOnly=true;}else if(total){total.readOnly=false;}if(estimate){estimate.value=money((mode==='Flat Fee')?unitVal:(unitVal*totalVal));}if(table){var sectionTotal=0;Array.prototype.forEach.call(table.querySelectorAll('tr[data-budget-mode] [data-budget-estimated-cost=&quot;1&quot;]'),function(i){sectionTotal+=parseMoney(i.value);});var sectionSubtotal=table.querySelector('[data-budget-section-subtotal]');if(sectionSubtotal)sectionSubtotal.value=money(sectionTotal);var sectionKey=String(table.getAttribute('data-budget-section')||'').trim();var summary=document.querySelector('[data-budget-summary-subtotal=&quot;'+sectionKey+'&quot;]');if(summary)summary.value=money(sectionTotal);}var grand=0;Array.prototype.forEach.call(document.querySelectorAll('[data-budget-summary-subtotal]'),function(i){grand+=parseMoney(i.value);});var grandField=document.getElementById('dlbh-budget-grand-total');if(grandField)grandField.value=money(grand);})(this)"
                                                    inputmode="numeric"
                                                >
                                            </td>
                                            <td>
                                                <input
                                                    type="text"
                                                    readonly
                                                    value="$0.00"
                                                    class="dlbh-budget-table-input"
                                                    data-budget-estimated-cost="1"
                                                >
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr>
                                        <td colspan="4" style="text-align:right;font-weight:700;">Subtotal</td>
                                        <td>
                                            <input
                                                type="text"
                                                readonly
                                                value="$0.00"
                                                class="dlbh-budget-table-input"
                                                data-budget-section-subtotal="<?php echo htmlspecialchars($budgetSectionKey, ENT_QUOTES, 'UTF-8'); ?>"
                                            >
                                        </td>
                                    </tr>
                                </table>
                            <?php endforeach; ?>
                            <div class="dlbh-inbox-section-header" style="margin-top:12px;">Budget Summary</div>
                            <table class="dlbh-inbox-table" id="dlbh-budget-summary-table">
                                <tr>
                                    <th>Section</th>
                                    <th>Subtotal</th>
                                </tr>
                                <?php foreach ($budgetCalculatorSections as $budgetSectionLabel => $budgetSectionRows): ?>
                                    <?php $budgetSectionKey = sanitize_title($budgetSectionLabel); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($budgetSectionLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <input
                                                type="text"
                                                readonly
                                                value="$0.00"
                                                class="dlbh-budget-table-input"
                                                data-budget-summary-subtotal="<?php echo htmlspecialchars($budgetSectionKey, ENT_QUOTES, 'UTF-8'); ?>"
                                            >
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td style="font-weight:700;">Grand Total</td>
                                    <td>
                                        <input
                                            type="text"
                                            readonly
                                            value="$0.00"
                                            class="dlbh-budget-table-input"
                                            id="dlbh-budget-grand-total"
                                        >
                                    </td>
                                </tr>
                            </table>
                        <?php elseif (empty($stripeRows)): ?>
                            <div class="dlbh-inbox-field-value">No CSV attachments found.</div>
                        <?php elseif ($financialView === 'cash_receipts_journal'): ?>
                            <table class="dlbh-inbox-table">
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Payment Received Date</th>
                                    <th>Payment Received Amount</th>
                                    <th>Payment Fee</th>
                                    <th>Family ID</th>
                                </tr>
                                <?php foreach ($stripeRows as $stripeRow): ?>
                                    <?php
                                    $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
                                    $cashReceiptsFamilyIdDisplay = (string)(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
                                    if ($stripeFamilyId !== '') {
                                        $cashReceiptsFamilyIdDisplay = 'DLBHF-' . $stripeFamilyId;
                                    }
                                    $billingProfileUrl = '';
                                    if ($stripeFamilyId !== '' && isset($rosterRowsByFamilyId[$stripeFamilyId]) && $rosterScreenUrl !== '') {
                                        $matchedRosterRow = $rosterRowsByFamilyId[$stripeFamilyId];
                                        $matchedRosterKey = isset($matchedRosterRow['Member Key']) ? (string)$matchedRosterRow['Member Key'] : '';
                                        $matchedRosterFields = isset($matchedRosterRow['Profile Fields']) && is_array($matchedRosterRow['Profile Fields']) ? $matchedRosterRow['Profile Fields'] : array();
                                        $matchedCommencementDate = (string)(isset($matchedRosterRow['Commencement Date']) ? $matchedRosterRow['Commencement Date'] : '');
                                        $matchedPaymentDate = (string)(isset($stripeRow['Payment Received Date']) ? $stripeRow['Payment Received Date'] : '');
                                        if ($matchedRosterKey !== '') {
                                            $matchedStatementOffset = dlbh_inbox_get_payment_statement_offset(
                                                $matchedRosterFields,
                                                $matchedCommencementDate,
                                                $matchedPaymentDate
                                            );
                                            $parts = parse_url($rosterScreenUrl);
                                            $path = isset($parts['path']) ? (string)$parts['path'] : $rosterScreenUrl;
                                            $query = array();
                                            if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                            $query['dlbh_screen'] = 'roster';
                                            $query['dlbh_roster_member'] = $matchedRosterKey;
                                            $query['dlbh_profile_section'] = 'Billing';
                                            if ($matchedStatementOffset > 0) {
                                                $query['dlbh_statement_offset'] = $matchedStatementOffset;
                                            } else {
                                                unset($query['dlbh_statement_offset']);
                                            }
                                            $billingProfileUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                                        }
                                    } elseif ($stripeFamilyId === '' || !isset($rosterRowsByFamilyId[$stripeFamilyId])) {
                                        $cashReceiptsFamilyIdDisplay = 'Suspense';
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)(isset($stripeRow['Transaction ID']) ? $stripeRow['Transaction ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($stripeRow['Payment Received Date']) ? $stripeRow['Payment Received Date'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($stripeRow['Payment Received Amount']) ? $stripeRow['Payment Received Amount'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($stripeRow['Payment Fee']) ? $stripeRow['Payment Fee'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($billingProfileUrl !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($billingProfileUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-row-btn" style="display:block;text-decoration:none;"><?php echo htmlspecialchars($cashReceiptsFamilyIdDisplay, ENT_QUOTES, 'UTF-8'); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($cashReceiptsFamilyIdDisplay, ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php elseif ($financialView === 'aging_report'): ?>
                            <?php
                            $agingLabels = array(
                                'current' => 'Current',
                                'past_due_1_15' => '1-15 Days Past Due',
                                'past_due_16_30' => '16-30 Days Past Due',
                                'delinquent' => '31+ Days (Delinquent)',
                            );
                            ?>
                            <?php foreach ($agingLabels as $agingKey => $agingLabel): ?>
                                <?php $agingRows = isset($agingReportSections[$agingKey]) && is_array($agingReportSections[$agingKey]) ? $agingReportSections[$agingKey] : array(); ?>
                                <div class="dlbh-inbox-section-header" style="margin-top:12px;"><?php echo htmlspecialchars($agingLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if (empty($agingRows)): ?>
                                    <div class="dlbh-inbox-field-value">No accounts found.</div>
                                <?php else: ?>
                                    <table class="dlbh-inbox-table" style="margin-top:8px;">
                                        <tr>
                                            <th>Primary Member</th>
                                            <th>Family ID</th>
                                            <th>Total Due</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                        <?php foreach ($agingRows as $agingRow): ?>
                                            <?php
                                            $agingBillingUrl = '';
                                            $agingComposeUrl = '';
                                            $agingRosterKey = isset($agingRow['roster_member_key']) ? trim((string)$agingRow['roster_member_key']) : '';
                                            $agingStatementOffset = isset($agingRow['statement_offset']) ? (int)$agingRow['statement_offset'] : 0;
                                            $agingStatusRaw = strtolower(trim((string)(isset($agingRow['status']) ? $agingRow['status'] : '')));
                                            $agingSourceFolderType = strtolower(trim((string)(isset($agingRow['source_folder_type']) ? $agingRow['source_folder_type'] : '')));
                                            $agingComposeMode = ($agingStatusRaw === 'delinquent') ? 'aging_delinquent' : (($agingStatusRaw === 'past due') ? 'aging_past_due' : '');
                                            if ($rosterScreenUrl !== '' && $agingRosterKey !== '') {
                                                $parts = parse_url($rosterScreenUrl);
                                                $path = isset($parts['path']) ? (string)$parts['path'] : $rosterScreenUrl;
                                                $query = array();
                                                if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                                $query['dlbh_screen'] = 'roster';
                                                $query['dlbh_roster_member'] = $agingRosterKey;
                                                $query['dlbh_profile_section'] = 'Billing';
                                                $query['dlbh_return'] = 'aging_report';
                                                if ($agingStatementOffset > 0) {
                                                    $query['dlbh_statement_offset'] = $agingStatementOffset;
                                                } else {
                                                    unset($query['dlbh_statement_offset']);
                                                }
                                                $agingBillingUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                                                if ($agingComposeMode !== '') {
                                                    $composeQuery = $query;
                                                    $composeQuery['dlbh_profile_compose'] = $agingComposeMode;
                                                    $agingComposeUrl = $path . (!empty($composeQuery) ? ('?' . http_build_query($composeQuery)) : '');
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)(isset($agingRow['primary_member']) ? $agingRow['primary_member'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)(isset($agingRow['family_id']) ? $agingRow['family_id'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)(isset($agingRow['total_due']) ? $agingRow['total_due'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <?php if ($agingBillingUrl !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($agingBillingUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-row-btn" style="display:block;text-decoration:none;"><?php echo htmlspecialchars((string)(isset($agingRow['due_date']) ? $agingRow['due_date'] : ''), ENT_QUOTES, 'UTF-8'); ?></a>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars((string)(isset($agingRow['due_date']) ? $agingRow['due_date'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars((string)(isset($agingRow['status']) ? $agingRow['status'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="dlbh-inbox-row-actions" style="text-align:right;">
                                                    <?php if ($agingComposeUrl !== '' && $agingSourceFolderType !== 'aging'): ?>
                                                        <a href="<?php echo htmlspecialchars($agingComposeUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-record-header-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;" aria-label="Send Notice" title="Send Notice">&#128276;</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="dlbh-inbox-fields" style="margin-top:12px;">
                                <div class="dlbh-inbox-section-header">Account Summary</div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Account Summary</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['as_of_email_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Last Payment Received Date</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['last_payment_received_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Cash Receipts</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['cash_receipts'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Revenue</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['revenue'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Suspense</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['money_in_suspense'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Total Gross Income</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['total_gross_income'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Fees</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['fees'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Net Income</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$stripeSummary['net_income'], ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="dlbh-inbox-field">
                                    <label class="dlbh-inbox-field-label">Projected Dues Revenue</label>
                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((string)$projectedDuesRevenue, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($screen === 'roster'): ?>
                <div class="dlbh-details-shell">
                    <div class="dlbh-details-head">
                        <span class="dlbh-details-head-title"><?php echo htmlspecialchars($selectedRosterRow ? (string)(isset($selectedRosterRow['Name']) ? $selectedRosterRow['Name'] : 'Roster') : 'Roster', ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($selectedRosterRow): ?>
                            <?php
                            $rosterDetailFields = isset($selectedRosterRow['Profile Fields']) && is_array($selectedRosterRow['Profile Fields']) ? $selectedRosterRow['Profile Fields'] : array();
                            $profileSections = array();
                            $profileSectionOrder = array(
                                'Membership',
                                'Account',
                                'Billing',
                                'Payment History',
                                'Household',
                                'Contact',
                            );
                            $selectedRosterFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($selectedRosterRow['Family ID']) ? $selectedRosterRow['Family ID'] : '');
                            $selectedRosterPaymentHistory = array();
                            if ($selectedRosterFamilyId !== '' && !empty($stripeRows)) {
                                foreach ($stripeRows as $stripeRow) {
                                    if (!is_array($stripeRow)) continue;
                                    $stripeFamilyId = dlbh_inbox_normalize_family_id_lookup_key(isset($stripeRow['Family ID']) ? $stripeRow['Family ID'] : '');
                                    if ($stripeFamilyId !== $selectedRosterFamilyId) continue;
                                    $selectedRosterPaymentHistory[] = $stripeRow;
                                }
                            }
                            $householdSections = array();
                            foreach ($rosterDetailFields as $detailFieldForMenu) {
                                if (!is_array($detailFieldForMenu)) continue;
                                $detailTypeForMenu = isset($detailFieldForMenu['type']) ? strtolower(trim((string)$detailFieldForMenu['type'])) : 'field';
                                if ($detailTypeForMenu !== 'header') continue;
                                $detailLabelForMenu = trim((string)(isset($detailFieldForMenu['label']) ? $detailFieldForMenu['label'] : ''));
                                if ($detailLabelForMenu === '') continue;
                                if (
                                    strcasecmp($detailLabelForMenu, 'Primary Member Information') === 0 ||
                                    strcasecmp($detailLabelForMenu, 'Spouse Information') === 0 ||
                                    preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $detailLabelForMenu)
                                ) {
                                    $profileSections['Household'] = 'Household';
                                    $householdSections[] = $detailLabelForMenu;
                                    continue;
                                }
                                if (preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $detailLabelForMenu)) {
                                    continue;
                                }
                                if (strcasecmp($detailLabelForMenu, 'Account Summary Information') === 0) {
                                    $profileSections['Billing'] = 'Billing';
                                    if (!empty($selectedRosterPaymentHistory)) {
                                        $profileSections['Payment History'] = 'Payment History';
                                    }
                                    continue;
                                }
                                if (strcasecmp($detailLabelForMenu, 'Membership Information') === 0) {
                                    $profileSections['Membership'] = 'Membership';
                                    $profileSections['Account'] = 'Account';
                                    continue;
                                }
                                if (strcasecmp($detailLabelForMenu, 'Contact Information') === 0) {
                                    $profileSections['Contact'] = 'Contact';
                                    continue;
                                }
                                foreach ($profileSectionOrder as $orderedSectionLabel) {
                                    if (strcasecmp($detailLabelForMenu, $orderedSectionLabel) === 0) {
                                        $profileSections[$orderedSectionLabel] = $orderedSectionLabel;
                                        break;
                                    }
                                }
                            }
                            $selectedProfileSection = '';
                            if ($profileSection !== '') {
                                foreach ($profileSections as $profileSectionLabel) {
                                    if (strcasecmp($profileSection, $profileSectionLabel) === 0) {
                                        $selectedProfileSection = $profileSectionLabel;
                                        break;
                                    }
                                }
                            }
                            if ($selectedProfileSection === '' && !empty($profileSections)) {
                                foreach ($profileSectionOrder as $orderedSectionLabel) {
                                    if (isset($profileSections[$orderedSectionLabel])) {
                                        $selectedProfileSection = $orderedSectionLabel;
                                        break;
                                    }
                                }
                                if ($selectedProfileSection === '') {
                                    $sectionValues = array_values($profileSections);
                                    $selectedProfileSection = isset($sectionValues[0]) ? (string)$sectionValues[0] : '';
                                }
                            }
                            $householdSections = array_values($householdSections);
                            $effectiveHouseholdMemberOffset = $householdMemberOffset;
                            if ($effectiveHouseholdMemberOffset >= count($householdSections)) {
                                $effectiveHouseholdMemberOffset = max(0, count($householdSections) - 1);
                            }
                            $currentStatementOffset = dlbh_inbox_get_current_statement_offset(
                                $rosterDetailFields,
                                (string)(isset($selectedRosterRow['Commencement Date']) ? $selectedRosterRow['Commencement Date'] : '')
                            );
                            ?>
                            <div class="dlbh-head-menu" style="margin-left:0;">
                                <button type="button" class="dlbh-inbox-head-link dlbh-head-menu-toggle" id="dlbh-profile-menu-toggle" aria-haspopup="true" aria-expanded="false" onclick="(function(btn){var panel=document.getElementById('dlbh-profile-menu-panel');var overlay=document.getElementById('dlbh-profile-menu-overlay');if(!panel||!overlay)return false;var isHidden=panel.hasAttribute('hidden');if(isHidden){panel.removeAttribute('hidden');overlay.removeAttribute('hidden');btn.setAttribute('aria-expanded','true');}else{panel.setAttribute('hidden','hidden');overlay.setAttribute('hidden','hidden');btn.setAttribute('aria-expanded','false');}return false;})(this); return false;">Profile</button>
                                <div class="dlbh-head-menu-overlay" id="dlbh-profile-menu-overlay" hidden onclick="(function(){var panel=document.getElementById('dlbh-profile-menu-panel');var overlay=document.getElementById('dlbh-profile-menu-overlay');var btn=document.getElementById('dlbh-profile-menu-toggle');if(panel)panel.setAttribute('hidden','hidden');if(overlay)overlay.setAttribute('hidden','hidden');if(btn)btn.setAttribute('aria-expanded','false');})(); return false;"></div>
                                <div class="dlbh-head-menu-panel" id="dlbh-profile-menu-panel" hidden>
                                    <?php foreach ($profileSectionOrder as $orderedSectionLabel): ?>
                                        <?php if (!isset($profileSections[$orderedSectionLabel])) continue; ?>
                                        <?php
                                        $profileSectionUrl = '';
                                        $selectedRosterKeyForMenu = isset($selectedRosterRow['Member Key']) ? (string)$selectedRosterRow['Member Key'] : '';
                                        if ($rosterScreenUrl !== '' && $selectedRosterKeyForMenu !== '') {
                                            $parts = parse_url($rosterScreenUrl);
                                            $path = isset($parts['path']) ? (string)$parts['path'] : $rosterScreenUrl;
                                            $query = array();
                                            if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                            $query['dlbh_screen'] = 'roster';
                                            $query['dlbh_roster_member'] = $selectedRosterKeyForMenu;
                                            $query['dlbh_profile_section'] = $orderedSectionLabel;
                                            unset($query['dlbh_statement_offset']);
                                            if ($orderedSectionLabel === 'Billing' && $currentStatementOffset > 0) {
                                                $query['dlbh_statement_offset'] = $currentStatementOffset;
                                            } elseif ($orderedSectionLabel !== 'Billing' && $statementOffset > 0) {
                                                $query['dlbh_statement_offset'] = $statementOffset;
                                            }
                                            if ($orderedSectionLabel === 'Household' && $effectiveHouseholdMemberOffset > 0) {
                                                $query['dlbh_household_offset'] = $effectiveHouseholdMemberOffset;
                                            }
                                            unset($query['dlbh_profile_compose']);
                                            $profileSectionUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                                        }
                                        ?>
                                        <a href="<?php echo htmlspecialchars($profileSectionUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item" style="text-decoration:none;box-sizing:border-box;"><?php echo htmlspecialchars($orderedSectionLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                                        <?php if ($orderedSectionLabel === 'Billing'): ?>
                                            <a href="https://billing.stripe.com/p/login/7sY4gz9i555GeKO8N15Vu00" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;" target="_blank" rel="noopener noreferrer">Customer Portal</a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <span class="dlbh-head-menu-label">Fundraising</span>
                                    <a href="<?php echo htmlspecialchars($fundraisingBingoUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Bingo</a>
                                    <a href="<?php echo htmlspecialchars($fundraisingBingoPlayUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Play</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="dlbh-inbox-field-value"><?php echo (int)count($rosterRows); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if (empty($rosterRows)): ?>
                            <div class="dlbh-inbox-field-value">No roster records found.</div>
                        <?php elseif ($selectedRosterRow): ?>
                            <?php if (empty($rosterDetailFields)): ?>
                                <div class="dlbh-inbox-field-value">No roster details found.</div>
                            <?php else: ?>
                                <?php
                                $selectedRosterKey = isset($selectedRosterRow['Member Key']) ? (string)$selectedRosterRow['Member Key'] : '';
                                $effectiveStatementOffset = $statementOffset;
                                $maxGeneratedStatementOffset = dlbh_inbox_get_max_generated_statement_offset(
                                    $rosterDetailFields,
                                    (string)(isset($selectedRosterRow['Commencement Date']) ? $selectedRosterRow['Commencement Date'] : '')
                                );
                                if ($effectiveStatementOffset > $maxGeneratedStatementOffset) {
                                    $effectiveStatementOffset = $maxGeneratedStatementOffset;
                                }
                                $selectedRosterProfileUrl = $rosterScreenUrl;
                                if ($selectedRosterKey !== '') {
                                    $selectedRosterQuery = array(
                                        'dlbh_screen' => 'roster',
                                        'dlbh_roster_member' => $selectedRosterKey,
                                    );
                                    if ($selectedProfileSection !== '') {
                                        $selectedRosterQuery['dlbh_profile_section'] = $selectedProfileSection;
                                    }
                                    if ($effectiveStatementOffset > 0) {
                                        $selectedRosterQuery['dlbh_statement_offset'] = $effectiveStatementOffset;
                                    }
                                    if ($selectedProfileSection === 'Household' && $effectiveHouseholdMemberOffset > 0) {
                                        $selectedRosterQuery['dlbh_household_offset'] = $effectiveHouseholdMemberOffset;
                                    }
                                    $parts = parse_url($rosterScreenUrl);
                                    $path = isset($parts['path']) ? (string)$parts['path'] : $rosterScreenUrl;
                                    $query = array();
                                    if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                    $query = array_merge($query, $selectedRosterQuery);
                                    $selectedRosterProfileUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                                }
                                $prevStatementUrl = '';
                                if ($effectiveStatementOffset > 0) {
                                    $prevParts = parse_url($selectedRosterProfileUrl);
                                    $prevPath = isset($prevParts['path']) ? (string)$prevParts['path'] : $selectedRosterProfileUrl;
                                    $prevQuery = array();
                                    if (isset($prevParts['query'])) parse_str((string)$prevParts['query'], $prevQuery);
                                    $prevQuery['dlbh_statement_offset'] = $effectiveStatementOffset - 1;
                                    $prevStatementUrl = $prevPath . (!empty($prevQuery) ? ('?' . http_build_query($prevQuery)) : '');
                                }
                                $nextStatementUrl = '';
                                if ($effectiveStatementOffset < $maxGeneratedStatementOffset) {
                                    $nextParts = parse_url($selectedRosterProfileUrl);
                                    $nextPath = isset($nextParts['path']) ? (string)$nextParts['path'] : $selectedRosterProfileUrl;
                                    $nextQuery = array();
                                    if (isset($nextParts['query'])) parse_str((string)$nextParts['query'], $nextQuery);
                                    $nextQuery['dlbh_statement_offset'] = $effectiveStatementOffset + 1;
                                    $nextStatementUrl = $nextPath . (!empty($nextQuery) ? ('?' . http_build_query($nextQuery)) : '');
                                }
                                $statementLabel = dlbh_inbox_get_statement_label_for_offset(
                                    $rosterDetailFields,
                                    (string)(isset($selectedRosterRow['Commencement Date']) ? $selectedRosterRow['Commencement Date'] : ''),
                                    $effectiveStatementOffset
                                );
                                $statementAccountSummaryFields = dlbh_inbox_build_account_summary_fields_with_payments(
                                    $rosterDetailFields,
                                    (string)(isset($selectedRosterRow['Commencement Date']) ? $selectedRosterRow['Commencement Date'] : ''),
                                    $effectiveStatementOffset,
                                    $stripeRows,
                                    $statementLabel
                                );
                                $rosterReturnTarget = isset($_GET['dlbh_return']) ? strtolower(trim((string)$_GET['dlbh_return'])) : '';
                                $profileComposeMode = isset($_GET['dlbh_profile_compose']) ? trim((string)$_GET['dlbh_profile_compose']) : '';
                                $backToRosterUrl = $rosterScreenUrl;
                                $backToRosterLabel = 'Back to Roster';
                                if ($rosterReturnTarget === 'aging_report') {
                                    $backToRosterUrl = $agingReportUrl;
                                    $backToRosterLabel = 'Back to Aging Report';
                                }
                                $showRosterBackButton = true;
                                if (in_array($profileComposeMode, array('aging_past_due', 'aging_delinquent'), true) && $rosterReturnTarget === 'aging_report') {
                                    $showRosterBackButton = false;
                                }
                                $billingCurrentStatementLabel = dlbh_inbox_get_statement_label_for_offset(
                                    $rosterDetailFields,
                                    (string)(isset($selectedRosterRow['Commencement Date']) ? $selectedRosterRow['Commencement Date'] : ''),
                                    $currentStatementOffset
                                );
                                $billingCurrentSummaryFields = dlbh_inbox_build_account_summary_fields_with_payments(
                                    $rosterDetailFields,
                                    (string)(isset($selectedRosterRow['Commencement Date']) ? $selectedRosterRow['Commencement Date'] : ''),
                                    $currentStatementOffset,
                                    $stripeRows,
                                    $billingCurrentStatementLabel
                                );
                                $billingLatestPayment = dlbh_inbox_get_latest_family_payment($rosterDetailFields, $stripeRows);
                                $billingCurrentStatementTotalDueRaw = trim((string)dlbh_inbox_get_field_value_by_label($billingCurrentSummaryFields, 'Total Due'));
                                $billingCurrentStatementTotalDue = (float)preg_replace('/[^0-9.\-]/', '', $billingCurrentStatementTotalDueRaw);
                                $billingAllPaymentsTotal = dlbh_inbox_get_family_payments_total($rosterDetailFields, $stripeRows);
                                $billingHeroTotalDue = $billingCurrentStatementTotalDue - $billingAllPaymentsTotal;
                                if ($billingHeroTotalDue < 0) $billingHeroTotalDue = 0.0;
                                $billingProfileComposeTo = trim((string)dlbh_inbox_get_field_value($rosterDetailFields, 'Email'));
                                $billingProfilePrimaryMember = trim((string)dlbh_inbox_get_field_value_by_label($rosterDetailFields, 'Primary Member'));
                                if ($billingProfilePrimaryMember === '') $billingProfilePrimaryMember = trim((string)(isset($selectedRosterRow['Name']) ? $selectedRosterRow['Name'] : 'Member'));
                                $billingProfileDueDate = trim((string)dlbh_inbox_get_field_value_by_label($statementAccountSummaryFields, 'Due Date'));
                                if ($billingProfileDueDate !== '') $billingProfileDueDate = dlbh_inbox_format_date_friendly($billingProfileDueDate);
                                $billingProfileOutstandingBalanceRaw = trim((string)dlbh_inbox_get_field_value_by_label($statementAccountSummaryFields, 'Remaining Previous Balance'));
                                $billingProfileOutstandingBalanceNumeric = (float)preg_replace('/[^0-9.\-]/', '', $billingProfileOutstandingBalanceRaw);
                                $billingProfileOutstandingBalance = dlbh_inbox_format_currency_value((string)$billingProfileOutstandingBalanceNumeric);
                                if ($billingProfileOutstandingBalance === '') $billingProfileOutstandingBalance = '$0.00';
                                $showBillingProfileCompose = ($selectedProfileSection === 'Billing' && in_array($profileComposeMode, array('aging_past_due', 'aging_delinquent'), true));
                                $billingProfileComposeSubject = '';
                                $billingProfileComposeBody = '';
                                if ($profileComposeMode === 'aging_delinquent') {
                                    $billingProfileComposeSubject = dlbh_inbox_membership_delinquent_subject();
                                    $billingProfileComposeBody = dlbh_inbox_build_delinquent_compose_template_body($billingProfilePrimaryMember, $billingProfileDueDate, $billingProfileOutstandingBalance);
                                } elseif ($profileComposeMode === 'aging_past_due') {
                                    $billingProfileComposeSubject = dlbh_inbox_membership_past_due_subject();
                                    $billingProfileComposeBody = dlbh_inbox_build_past_due_compose_template_body($billingProfilePrimaryMember, $billingProfileDueDate, $billingProfileOutstandingBalance);
                                }
                                $billingProfileComposeHtml = '';
                                $billingProfileComposeFields = array(
                                    array('type' => 'field', 'label' => 'Primary Member', 'value' => $billingProfilePrimaryMember),
                                );
                                $billingProfileFamilyId = trim((string)dlbh_inbox_get_field_value_by_label($rosterDetailFields, 'Family ID'));
                                if ($billingProfileFamilyId === '') {
                                    $billingProfileFamilyId = trim((string)(isset($selectedRosterRow['Family ID']) ? $selectedRosterRow['Family ID'] : ''));
                                }
                                if ($billingProfileFamilyId !== '') {
                                    $billingProfileComposeFields[] = array('type' => 'field', 'label' => 'Family ID', 'value' => $billingProfileFamilyId);
                                }
                                if (!empty($statementAccountSummaryFields)) {
                                    $billingProfileComposeFields[] = array('type' => 'header', 'label' => 'Account Summary Information', 'value' => '');
                                    foreach ($statementAccountSummaryFields as $statementComposeField) {
                                        if (!is_array($statementComposeField)) continue;
                                        $statementComposeLabel = isset($statementComposeField['label']) ? (string)$statementComposeField['label'] : '';
                                        $statementComposeValue = isset($statementComposeField['value']) ? (string)$statementComposeField['value'] : '';
                                        if (
                                            strcasecmp(trim($statementComposeLabel), 'Family ID') === 0 &&
                                            trim($statementComposeValue) === '' &&
                                            $billingProfileFamilyId !== ''
                                        ) {
                                            $statementComposeValue = $billingProfileFamilyId;
                                        }
                                        $billingProfileComposeFields[] = array(
                                            'type' => 'field',
                                            'label' => $statementComposeLabel,
                                            'value' => $statementComposeValue,
                                        );
                                    }
                                }
                                if ($showBillingProfileCompose && $billingProfileComposeBody !== '') {
                                    $billingProfileComposeHtml = dlbh_inbox_build_composed_email_html(
                                        $billingProfileComposeBody,
                                        array(),
                                        $billingProfileComposeFields,
                                        null,
                                        array(
                                            'show_status_card' => false,
                                            'show_details' => true,
                                            'show_intro_body' => true,
                                            'compose_mode' => $profileComposeMode,
                                            'details_card_title' => 'Account Summary',
                                        )
                                    );
                                }
                                $billingProfileComposeCancelUrl = $selectedRosterProfileUrl;
                                if (in_array($profileComposeMode, array('aging_past_due', 'aging_delinquent'), true)) {
                                    $billingProfileComposeCancelUrl = $agingReportUrl;
                                } elseif ($rosterReturnTarget === 'aging_report') {
                                    $billingProfileComposeCancelUrl = $agingReportUrl;
                                } elseif ($billingProfileComposeCancelUrl !== '') {
                                    $parts = parse_url($billingProfileComposeCancelUrl);
                                    $path = isset($parts['path']) ? (string)$parts['path'] : $billingProfileComposeCancelUrl;
                                    $query = array();
                                    if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                    unset($query['dlbh_profile_compose']);
                                    $billingProfileComposeCancelUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                                }
                                ?>
                                <?php if (!$memberSession['enabled'] && $showRosterBackButton): ?>
                                    <div style="margin-bottom:12px;">
                                        <a href="<?php echo htmlspecialchars($backToRosterUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"><?php echo htmlspecialchars($backToRosterLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($selectedProfileSection === 'Payment History'): ?>
                                    <div class="dlbh-inbox-section-header">Payment History</div>
                                    <?php if (empty($selectedRosterPaymentHistory)): ?>
                                        <div class="dlbh-inbox-field-value">No payments found.</div>
                                    <?php else: ?>
                                        <table class="dlbh-inbox-table" style="margin-top:12px;">
                                            <tr>
                                                <th>Payment Received Date</th>
                                                <th>Payment Received Amount</th>
                                                <th>Payment Fee</th>
                                                <th>Transaction ID</th>
                                                <th>Family ID</th>
                                            </tr>
                                            <?php foreach ($selectedRosterPaymentHistory as $paymentRow): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars((string)(isset($paymentRow['Payment Received Date']) ? $paymentRow['Payment Received Date'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)(isset($paymentRow['Payment Received Amount']) ? $paymentRow['Payment Received Amount'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)(isset($paymentRow['Payment Fee']) ? $paymentRow['Payment Fee'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)(isset($paymentRow['Transaction ID']) ? $paymentRow['Transaction ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)(isset($paymentRow['Family ID']) ? $paymentRow['Family ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    <?php endif; ?>
                                <?php else: ?>
                                <div class="dlbh-inbox-fields">
                                    <?php if ($showBillingProfileCompose): ?>
                                        <div class="dlbh-compose-wrap" style="margin-bottom:14px;">
                                            <h4 class="dlbh-compose-title">Email Compose</h4>
                                            <?php if ($rosterComposeStatus['message'] !== ''): ?>
                                                <div class="dlbh-compose-status <?php echo ($rosterComposeStatus['type'] === 'success' ? 'success' : 'error'); ?>">
                                                    <?php echo htmlspecialchars((string)$rosterComposeStatus['message'], ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                            <?php endif; ?>
                                            <form method="post">
                                                <input type="hidden" name="dlbh_inbox_action" value="send_roster_compose_email">
                                                <input type="hidden" name="dlbh_roster_member" value="<?php echo htmlspecialchars($selectedRosterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="family_id" value="<?php echo htmlspecialchars((string)(isset($selectedRosterRow['Family ID']) ? $selectedRosterRow['Family ID'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="dlbh_statement_offset" value="<?php echo (int)$effectiveStatementOffset; ?>">
                                                <input type="hidden" name="dlbh_return" value="<?php echo htmlspecialchars($rosterReturnTarget, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="source_folder" value="<?php echo htmlspecialchars((string)(isset($selectedRosterRow['Source Folder']) ? $selectedRosterRow['Source Folder'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="source_msg_num" value="<?php echo (int)(isset($selectedRosterRow['Source Msg Num']) ? $selectedRosterRow['Source Msg Num'] : 0); ?>">
                                                <input type="hidden" name="profile_compose_mode" value="<?php echo htmlspecialchars($profileComposeMode, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="compose_mode" value="<?php echo htmlspecialchars($profileComposeMode, ENT_QUOTES, 'UTF-8'); ?>">
                                                <textarea name="compose_html" id="dlbh-compose-html" style="display:none;"><?php echo htmlspecialchars($billingProfileComposeHtml, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                <input type="hidden" name="compose_body" value="<?php echo htmlspecialchars($billingProfileComposeBody, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                                                <div class="dlbh-inbox-field">
                                                    <label class="dlbh-inbox-field-label">From</label>
                                                    <input class="dlbh-wpf-input" type="email" name="compose_from" value="<?php echo htmlspecialchars(($postedEmail !== '' ? $postedEmail : 'office@dlbhfamily.com'), ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="dlbh-inbox-field">
                                                    <label class="dlbh-inbox-field-label">To</label>
                                                    <input class="dlbh-wpf-input" type="email" name="compose_to" value="<?php echo htmlspecialchars($billingProfileComposeTo, ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="dlbh-inbox-field">
                                                    <label class="dlbh-inbox-field-label">Subject</label>
                                                    <input class="dlbh-wpf-input" type="text" name="compose_subject" value="<?php echo htmlspecialchars($billingProfileComposeSubject, ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="dlbh-compose-actions">
                                                    <button type="submit" class="dlbh-inbox-btn">Send</button>
                                                    <?php if ($billingProfileComposeCancelUrl !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($billingProfileComposeCancelUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">Cancel</a>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="dlbh-compose-preview-wrap">
                                                    <div class="dlbh-compose-preview-head"><?php echo htmlspecialchars($billingProfileComposeSubject, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <div class="dlbh-compose-preview-body">
                                                        <div id="dlbh-compose-editor" class="dlbh-compose-editor" contenteditable="true"><?php echo $billingProfileComposeHtml; ?></div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$showBillingProfileCompose): ?>
                                    <?php $skipRosterAccountSummaryFields = false; ?>
                                    <?php $activeRosterHeader = ''; ?>
                                    <?php $activeHouseholdBlockLabel = ''; ?>
                                    <?php $renderCurrentHouseholdBlock = false; ?>
                                    <?php $currentHouseholdHeader = isset($householdSections[$effectiveHouseholdMemberOffset]) ? (string)$householdSections[$effectiveHouseholdMemberOffset] : ''; ?>
                                    <?php
                                    $prevHouseholdUrl = '';
                                    if ($selectedProfileSection === 'Household' && $effectiveHouseholdMemberOffset > 0) {
                                        $prevParts = parse_url($selectedRosterProfileUrl);
                                        $prevPath = isset($prevParts['path']) ? (string)$prevParts['path'] : $selectedRosterProfileUrl;
                                        $prevQuery = array();
                                        if (isset($prevParts['query'])) parse_str((string)$prevParts['query'], $prevQuery);
                                        $prevQuery['dlbh_household_offset'] = $effectiveHouseholdMemberOffset - 1;
                                        $prevHouseholdUrl = $prevPath . (!empty($prevQuery) ? ('?' . http_build_query($prevQuery)) : '');
                                    }
                                    $nextHouseholdUrl = '';
                                    if ($selectedProfileSection === 'Household' && $effectiveHouseholdMemberOffset < (count($householdSections) - 1)) {
                                        $nextParts = parse_url($selectedRosterProfileUrl);
                                        $nextPath = isset($nextParts['path']) ? (string)$nextParts['path'] : $selectedRosterProfileUrl;
                                        $nextQuery = array();
                                        if (isset($nextParts['query'])) parse_str((string)$nextParts['query'], $nextQuery);
                                        $nextQuery['dlbh_household_offset'] = $effectiveHouseholdMemberOffset + 1;
                                        $nextHouseholdUrl = $nextPath . (!empty($nextQuery) ? ('?' . http_build_query($nextQuery)) : '');
                                    }
                                    ?>
                                    <?php foreach ($rosterDetailFields as $detailField): ?>
                                        <?php
                                        $detailType = isset($detailField['type']) ? strtolower(trim((string)$detailField['type'])) : 'field';
                                        $detailLabel = (string)(isset($detailField['label']) ? $detailField['label'] : '');
                                        $detailValue = (string)(isset($detailField['value']) ? $detailField['value'] : '');
                                        $normalizedDetailSectionLabel = $detailLabel;
                                        if ($detailType === 'header' && strcasecmp($detailLabel, 'Membership Information') === 0) {
                                            $normalizedDetailSectionLabel = 'Membership';
                                        }
                                        if ($detailType === 'header' && strcasecmp($detailLabel, 'Account Summary Information') === 0) {
                                            $normalizedDetailSectionLabel = 'Billing';
                                        }
                                        if ($detailType === 'header' && strcasecmp($detailLabel, 'Contact Information') === 0) {
                                            $normalizedDetailSectionLabel = 'Contact';
                                        }
                                        if (
                                            $detailType === 'header' && (
                                                strcasecmp($detailLabel, 'Primary Member Information') === 0 ||
                                                strcasecmp($detailLabel, 'Spouse Information') === 0 ||
                                                preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $detailLabel)
                                            )
                                        ) {
                                            $normalizedDetailSectionLabel = 'Household';
                                        }
                                        if ($detailType === 'header') {
                                            $activeRosterHeader = $normalizedDetailSectionLabel;
                                            if (strcasecmp($normalizedDetailSectionLabel, 'Household') === 0) {
                                                $activeHouseholdBlockLabel = $detailLabel;
                                                $renderCurrentHouseholdBlock = ($currentHouseholdHeader !== '' && strcasecmp($detailLabel, $currentHouseholdHeader) === 0);
                                            } else {
                                                $activeHouseholdBlockLabel = '';
                                                $renderCurrentHouseholdBlock = false;
                                            }
                                        }
                                        if ($detailType === 'header' && strcasecmp($detailLabel, 'Account Summary Information') === 0) {
                                            $skipRosterAccountSummaryFields = false;
                                        } elseif ($detailType === 'header' && $skipRosterAccountSummaryFields) {
                                            $skipRosterAccountSummaryFields = false;
                                        }
                                        if ($selectedProfileSection !== '' && $detailType === 'header' && strcasecmp($normalizedDetailSectionLabel, $selectedProfileSection) !== 0) {
                                            if (strcasecmp($detailLabel, 'Account Summary Information') === 0) {
                                                $skipRosterAccountSummaryFields = true;
                                            }
                                            continue;
                                        }
                                        if (
                                            $detailType !== 'header' &&
                                            strcasecmp($activeRosterHeader, 'Membership') === 0
                                        ) {
                                            $membershipFieldLabel = trim((string)$detailLabel);
                                            $isMembershipField = (
                                                strcasecmp($membershipFieldLabel, 'Primary Member') === 0 ||
                                                strcasecmp($membershipFieldLabel, 'Commencement Date') === 0
                                            );
                                            if ($selectedProfileSection === 'Membership' && !$isMembershipField) {
                                                continue;
                                            }
                                            if ($selectedProfileSection === 'Account' && $isMembershipField) {
                                                continue;
                                            }
                                        }
                                        if ($selectedProfileSection === 'Household' && $detailType === 'header' && $currentHouseholdHeader !== '' && strcasecmp($detailLabel, $currentHouseholdHeader) !== 0 && strcasecmp($normalizedDetailSectionLabel, 'Household') === 0) {
                                            continue;
                                        }
                                        if ($selectedProfileSection === 'Household' && $detailType !== 'header' && strcasecmp($activeRosterHeader, 'Household') === 0 && !$renderCurrentHouseholdBlock) {
                                            continue;
                                        }
                                        if (
                                            $selectedProfileSection !== '' &&
                                            $detailType !== 'header' &&
                                            strcasecmp($activeRosterHeader, $selectedProfileSection) !== 0 &&
                                            !(
                                                strcasecmp($activeRosterHeader, 'Membership') === 0 &&
                                                strcasecmp($selectedProfileSection, 'Account') === 0
                                            )
                                        ) {
                                            continue;
                                        }
                                        if ($skipRosterAccountSummaryFields && $detailType !== 'header') {
                                            continue;
                                        }
                                        if ($detailType === 'header' && strcasecmp($detailLabel, 'Account Summary Information') === 0):
                                        ?>
                                            <div class="dlbh-inbox-section-header dlbh-record-header">
                                                <span class="dlbh-record-header-text">Billing</span>
                                                <span class="dlbh-record-header-actions">
                                                    <?php if ($prevStatementUrl !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($prevStatementUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-record-header-btn" style="text-decoration:none;">&#8592;</a>
                                                    <?php else: ?>
                                                        <span class="dlbh-record-header-btn" style="opacity:.35;cursor:default;">&#8592;</span>
                                                    <?php endif; ?>
                                                    <?php if ($nextStatementUrl !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($nextStatementUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-record-header-btn" style="text-decoration:none;">&#8594;</a>
                                                    <?php else: ?>
                                                        <span class="dlbh-record-header-btn" style="opacity:.35;cursor:default;">&#8594;</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <?php
                                            $billingTotalDue = dlbh_inbox_format_currency_value((string)$billingHeroTotalDue);
                                            if ($billingTotalDue === '') $billingTotalDue = '$0.00';
                                            $billingLastPaymentAmount = trim((string)(isset($billingLatestPayment['amount']) ? $billingLatestPayment['amount'] : '$0.00'));
                                            if ($billingLastPaymentAmount === '') $billingLastPaymentAmount = '$0.00';
                                            $billingLastPaymentDate = trim((string)(isset($billingLatestPayment['date_display']) ? $billingLatestPayment['date_display'] : 'N/A'));
                                            if ($billingLastPaymentDate === '' || $billingLastPaymentDate === '-') $billingLastPaymentDate = 'N/A';
                                            $showBillingLastPayment = !(
                                                $billingLastPaymentAmount === '$0.00' &&
                                                $billingLastPaymentDate === 'N/A'
                                            );
                                            $billingTotalContributionsValue = dlbh_inbox_get_family_payments_total($rosterDetailFields, $stripeRows);
                                            $billingContributionGoalValue = 20 * max(0, 12 - (int)date('n') + 1);
                                            $billingRemainingContributionValue = max(0.0, $billingContributionGoalValue - $billingTotalContributionsValue);
                                            $billingTotalContributions = dlbh_inbox_format_currency_value((string)$billingTotalContributionsValue);
                                            if ($billingTotalContributions === '') $billingTotalContributions = '$0.00';
                                            $billingContributionGoal = dlbh_inbox_format_currency_value((string)$billingRemainingContributionValue);
                                            if ($billingContributionGoal === '') $billingContributionGoal = '$0.00';
                                            $billingSubscriptionPlanDetails = dlbh_inbox_get_family_subscription_plan_details($rosterDetailFields, $stripeRows);
                                            $billingSubscriptionPlan = trim((string)(isset($billingSubscriptionPlanDetails['plan']) ? $billingSubscriptionPlanDetails['plan'] : ''));
                                            $billingNextWithdrawalDate = trim((string)(isset($billingSubscriptionPlanDetails['next_withdrawal_date']) ? $billingSubscriptionPlanDetails['next_withdrawal_date'] : ''));
                                            ?>
                                            <div class="dlbh-billing-hero">
                                                <div class="dlbh-billing-hero-label">Contribution Goal</div>
                                                <div class="dlbh-billing-hero-amount"><?php echo htmlspecialchars($billingContributionGoal, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="dlbh-billing-hero-label">Total Contributions</div>
                                                <div class="dlbh-billing-hero-amount"><?php echo htmlspecialchars($billingTotalContributions, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="dlbh-billing-hero-divider" aria-hidden="true"></div>
                                                <div class="dlbh-billing-hero-label">Total Amount Due</div>
                                                <div class="dlbh-billing-hero-amount"><?php echo htmlspecialchars($billingTotalDue, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <script async src="https://js.stripe.com/v3/buy-button.js"></script>
                                                <stripe-buy-button
                                                  buy-button-id="buy_btn_1SlM97GY8uYlMaCJjn5zcMr8"
                                                  publishable-key="pk_live_51Sb4MWGY8uYlMaCJCw2VeaXWzNQyYejiT4e2h1ClhPFhU7faCVkHwN8YN0kTmLMyfFDCZvdmPBNIF6U4OFW0qo7T00ZcD6lvMz"
                                                >
                                                </stripe-buy-button>
                                                <?php if ($showBillingLastPayment): ?>
                                                    <div class="dlbh-billing-hero-meta">Last payment of <?php echo htmlspecialchars($billingLastPaymentAmount, ENT_QUOTES, 'UTF-8'); ?> made on <?php echo htmlspecialchars($billingLastPaymentDate, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                            $billingPrimaryMember = trim((string)dlbh_inbox_get_field_value_by_label($rosterDetailFields, 'Primary Member'));
                                            if ($billingPrimaryMember === '') $billingPrimaryMember = '-';
                                            $billingStatementValueMap = array();
                                            foreach ($statementAccountSummaryFields as $statementField) {
                                                if (!is_array($statementField)) continue;
                                                $statementFieldLabel = trim((string)(isset($statementField['label']) ? $statementField['label'] : ''));
                                                if ($statementFieldLabel === '') continue;
                                                $billingStatementValueMap[$statementFieldLabel] = (string)(isset($statementField['value']) ? $statementField['value'] : '');
                                            }
                                            $billingFamilyIdDisplay = trim((string)(isset($billingStatementValueMap['Family ID']) ? $billingStatementValueMap['Family ID'] : ''));
                                            if ($billingFamilyIdDisplay === '') {
                                                $billingFamilyIdDisplay = trim((string)dlbh_inbox_get_field_value_by_label($rosterDetailFields, 'Family ID'));
                                            }
                                            if ($billingFamilyIdDisplay === '') $billingFamilyIdDisplay = '-';
                                            $billingPlanDisplay = ($billingSubscriptionPlan !== '' ? $billingSubscriptionPlan : 'One Time Payment');
                                            $getBillingStatementValue = function($label) use ($billingStatementValueMap) {
                                                $value = isset($billingStatementValueMap[$label]) ? trim((string)$billingStatementValueMap[$label]) : '';
                                                return ($value !== '' ? $value : '-');
                                            };
                                            ?>

                                            <div class="dlbh-inbox-section-header" style="margin:0 0 12px 0;padding:8px 10px;background:#131313;border:1px solid #131313;border-radius:4px;font-size:18px;font-weight:600;line-height:1.3;color:#FFFFFF;">Account information</div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Primary Member</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($billingPrimaryMember, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Plan</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($billingPlanDisplay, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Family ID</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($billingFamilyIdDisplay, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>

                                            <div class="dlbh-inbox-section-header" style="margin:12px 0 12px 0;padding:8px 10px;background:#131313;border:1px solid #131313;border-radius:4px;font-size:18px;font-weight:600;line-height:1.3;color:#FFFFFF;">Statement summary</div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Statement</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Statement'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Status</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Status'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Statement Date</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Statement Date'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>

                                            <div class="dlbh-inbox-section-header" style="margin:12px 0 12px 0;padding:8px 10px;background:#131313;border:1px solid #131313;border-radius:4px;font-size:18px;font-weight:600;line-height:1.3;color:#FFFFFF;">Balance &amp; payments</div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Previous Balance</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Previous Balance'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Last Payment Received Amount</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Last Payment Received Amount'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Last Payment Received Date</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Last Payment Received Date'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <?php if ($billingNextWithdrawalDate !== ''): ?>
                                                <div class="dlbh-inbox-field">
                                                    <label class="dlbh-inbox-field-label">Next Withdrawal Date</label>
                                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($billingNextWithdrawalDate, ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                            <?php endif; ?>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Remaining Previous Balance</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Remaining Previous Balance'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>

                                            <div class="dlbh-inbox-section-header" style="margin:12px 0 12px 0;padding:8px 10px;background:#131313;border:1px solid #131313;border-radius:4px;font-size:18px;font-weight:600;line-height:1.3;color:#FFFFFF;">Billing period</div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Period Start</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Period Start'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Period End</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Period End'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>

                                            <div class="dlbh-inbox-section-header" style="margin:12px 0 12px 0;padding:8px 10px;background:#131313;border:1px solid #131313;border-radius:4px;font-size:18px;font-weight:600;line-height:1.3;color:#FFFFFF;">Current charges</div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Charges</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Charges'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Total Due</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Total Due'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Due Date</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Due Date'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>

                                            <div class="dlbh-inbox-section-header" style="margin:12px 0 12px 0;padding:8px 10px;background:#131313;border:1px solid #131313;border-radius:4px;font-size:18px;font-weight:600;line-height:1.3;color:#FFFFFF;">Important dates</div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Grace Period End Date</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Grace Period End Date'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Delinquency Date</label>
                                                <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($getBillingStatementValue('Delinquency Date'), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <?php $skipRosterAccountSummaryFields = true; ?>
                                        <?php else: ?>
                                            <?php if ($detailType === 'header'): ?>
                                                <?php if ($selectedProfileSection === 'Household' && strcasecmp($normalizedDetailSectionLabel, 'Household') === 0): ?>
                                                    <div class="dlbh-inbox-section-header dlbh-record-header">
                                                        <span class="dlbh-record-header-text">Household</span>
                                                        <span class="dlbh-record-header-actions">
                                                            <span style="font-size:12px;font-weight:700;color:#1e3557;margin-right:6px;">Record <?php echo (int)($effectiveHouseholdMemberOffset + 1); ?> of <?php echo (int)count($householdSections); ?></span>
                                                            <?php if ($prevHouseholdUrl !== ''): ?>
                                                                <a href="<?php echo htmlspecialchars($prevHouseholdUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-record-header-btn" style="text-decoration:none;">&#8592;</a>
                                                            <?php else: ?>
                                                                <span class="dlbh-record-header-btn" style="opacity:.35;cursor:default;">&#8592;</span>
                                                            <?php endif; ?>
                                                            <?php if ($nextHouseholdUrl !== ''): ?>
                                                                <a href="<?php echo htmlspecialchars($nextHouseholdUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-record-header-btn" style="text-decoration:none;">&#8594;</a>
                                                            <?php else: ?>
                                                                <span class="dlbh-record-header-btn" style="opacity:.35;cursor:default;">&#8594;</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                    <div class="dlbh-inbox-section-header" style="margin-top:6px;"><?php echo htmlspecialchars($detailLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php else: ?>
                                                    <div class="dlbh-inbox-section-header"><?php echo htmlspecialchars($normalizedDetailSectionLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                                <?php if ($selectedProfileSection === 'Contact' && strcasecmp($normalizedDetailSectionLabel, 'Contact') === 0): ?>
                                                    <?php $contactPrimaryMember = trim((string)dlbh_inbox_get_field_value_by_label($rosterDetailFields, 'Primary Member')); ?>
                                                    <?php if ($contactPrimaryMember !== ''): ?>
                                                        <div class="dlbh-inbox-field">
                                                            <label class="dlbh-inbox-field-label">Primary Member</label>
                                                            <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($contactPrimaryMember, ENT_QUOTES, 'UTF-8'); ?>">
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="dlbh-inbox-field">
                                                    <label class="dlbh-inbox-field-label"><?php echo htmlspecialchars($detailLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                                                    <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars((stripos($detailLabel, 'date') !== false || strcasecmp($detailLabel, 'Date of Birth') === 0) ? dlbh_inbox_format_date_friendly($detailValue) : $detailValue, ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <?php if ($selectedProfileSection === 'Membership' && strcasecmp(trim($detailLabel), 'Commencement Date') === 0): ?>
                                                    <?php $membershipCohort = dlbh_inbox_format_membership_cohort($detailValue); ?>
                                                    <?php if ($membershipCohort !== ''): ?>
                                                        <div class="dlbh-inbox-field">
                                                            <label class="dlbh-inbox-field-label">Cohort</label>
                                                            <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($membershipCohort, ENT_QUOTES, 'UTF-8'); ?>">
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php $membershipTenure = dlbh_inbox_format_membership_tenure($detailValue); ?>
                                                    <?php if ($membershipTenure !== ''): ?>
                                                        <div class="dlbh-inbox-field">
                                                            <label class="dlbh-inbox-field-label">Membership Tenure</label>
                                                            <input class="dlbh-wpf-input" type="text" readonly value="<?php echo htmlspecialchars($membershipTenure, ENT_QUOTES, 'UTF-8'); ?>">
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="get" class="dlbh-inbox-grid" style="margin-bottom:12px;">
                                <input type="hidden" name="dlbh_screen" value="roster">
                                <div>
                                    <label class="dlbh-inbox-label" for="dlbh-roster-search">Search Name or Family ID</label>
                                    <input class="dlbh-inbox-input" id="dlbh-roster-search" name="dlbh_roster_search" type="text" value="<?php echo htmlspecialchars($rosterSearch, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div style="display:flex;align-items:flex-end;gap:10px;">
                                    <button class="dlbh-inbox-btn" type="submit">Search</button>
                                    <?php if ($rosterSearch !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($rosterScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;margin-left:0;">Clear</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <?php if (empty($filteredRosterRows)): ?>
                                <div class="dlbh-inbox-field-value">No roster records found.</div>
                            <?php else: ?>
                            <table class="dlbh-inbox-table">
                                <tr>
                                    <th>Relationship</th>
                                    <th>Name</th>
                                    <th>Commencement Date</th>
                                    <th>Family ID</th>
                                </tr>
                                <?php foreach ($filteredRosterRows as $rosterRow): ?>
                                    <?php
                                    $rosterRowKey = isset($rosterRow['Member Key']) ? (string)$rosterRow['Member Key'] : '';
                                    $rosterRowQuery = array('dlbh_screen' => 'roster');
                                    if ($rosterRowKey !== '') $rosterRowQuery['dlbh_roster_member'] = $rosterRowKey;
                                    if ($rosterSearch !== '') $rosterRowQuery['dlbh_roster_search'] = $rosterSearch;
                                    $rosterRowUrl = $rosterScreenUrl;
                                    if ($rosterScreenUrl !== '') {
                                        $parts = parse_url($rosterScreenUrl);
                                        $path = isset($parts['path']) ? (string)$parts['path'] : $rosterScreenUrl;
                                        $query = array();
                                        if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                        $query = array_merge($query, $rosterRowQuery);
                                        $rosterRowUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                                    }
                                    ?>
                                    <tr class="<?php echo ($selectedRosterRow && isset($selectedRosterRow['Member Key']) && (string)$selectedRosterRow['Member Key'] === $rosterRowKey ? 'is-selected' : ''); ?>">
                                        <td><?php echo htmlspecialchars((string)(isset($rosterRow['Relationship']) ? $rosterRow['Relationship'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><a href="<?php echo htmlspecialchars($rosterRowUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-row-btn" style="display:block;text-decoration:none;"><?php echo htmlspecialchars((string)(isset($rosterRow['Name']) ? $rosterRow['Name'] : ''), ENT_QUOTES, 'UTF-8'); ?></a></td>
                                        <td><?php echo htmlspecialchars((string)(isset($rosterRow['Commencement Date']) ? $rosterRow['Commencement Date'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($rosterRow['Family ID']) ? $rosterRow['Family ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($screen === 'allergies'): ?>
                <div class="dlbh-details-shell">
                    <div class="dlbh-details-head">
                        <span class="dlbh-details-head-title">Allergies &amp; Food Restrictions</span>
                        <span class="dlbh-inbox-field-value"><?php echo (int)count($allergyRows); ?></span>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if (empty($allergyRows)): ?>
                            <div class="dlbh-inbox-field-value">No allergy records found.</div>
                        <?php else: ?>
                            <table class="dlbh-inbox-table">
                                <tr>
                                    <th>Relationship</th>
                                    <th>Name</th>
                                    <th>Family ID</th>
                                </tr>
                                <?php foreach ($allergyRows as $allergyRow): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)(isset($allergyRow['Relationship']) ? $allergyRow['Relationship'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($allergyRow['Name']) ? $allergyRow['Name'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($allergyRow['Family ID']) ? $allergyRow['Family ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($screen === 'military'): ?>
                <div class="dlbh-details-shell">
                    <div class="dlbh-details-head">
                        <span class="dlbh-details-head-title">Military Status</span>
                        <span class="dlbh-inbox-field-value"><?php echo (int)count($militaryRows); ?></span>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if (empty($militaryRows)): ?>
                            <div class="dlbh-inbox-field-value">No military records found.</div>
                        <?php else: ?>
                            <table class="dlbh-inbox-table">
                                <tr>
                                    <th>Relationship</th>
                                    <th>Name</th>
                                    <th>Family ID</th>
                                </tr>
                                <?php foreach ($militaryRows as $militaryRow): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)(isset($militaryRow['Relationship']) ? $militaryRow['Relationship'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($militaryRow['Name']) ? $militaryRow['Name'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($militaryRow['Family ID']) ? $militaryRow['Family ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($screen === 'apparel'): ?>
                <?php
                $apparelTotals = array();
                foreach ($apparelRows as $apparelRow) {
                    $size = strtoupper(trim((string)(isset($apparelRow['T-Shirt Size']) ? $apparelRow['T-Shirt Size'] : '')));
                    if ($size === '') continue;
                    if (!isset($apparelTotals[$size])) $apparelTotals[$size] = 0;
                    $apparelTotals[$size]++;
                }
                $apparelOrder = array('S', 'M', 'L', 'XL', '2XL', '3XL');
                $apparelSummaryParts = array();
                foreach ($apparelOrder as $sizeKey) {
                    if (!isset($apparelTotals[$sizeKey])) continue;
                    $apparelSummaryParts[] = $sizeKey . ': ' . (int)$apparelTotals[$sizeKey];
                }
                foreach ($apparelTotals as $sizeKey => $sizeCount) {
                    if (in_array($sizeKey, $apparelOrder, true)) continue;
                    $apparelSummaryParts[] = $sizeKey . ': ' . (int)$sizeCount;
                }
                ?>
                <div class="dlbh-details-shell">
                    <div class="dlbh-details-head">
                        <span class="dlbh-details-head-title">Apparel</span>
                        <span class="dlbh-inbox-field-value"><?php echo htmlspecialchars(!empty($apparelSummaryParts) ? implode(' | ', $apparelSummaryParts) : '0', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if (empty($apparelRows)): ?>
                            <div class="dlbh-inbox-field-value">No apparel records found.</div>
                        <?php else: ?>
                            <table class="dlbh-inbox-table">
                                <tr>
                                    <th>Relationship</th>
                                    <th>Name</th>
                                    <th>T-Shirt Size</th>
                                    <th>Family ID</th>
                                </tr>
                                <?php foreach ($apparelRows as $apparelRow): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)(isset($apparelRow['Relationship']) ? $apparelRow['Relationship'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($apparelRow['Name']) ? $apparelRow['Name'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($apparelRow['T-Shirt Size']) ? $apparelRow['T-Shirt Size'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($apparelRow['Family ID']) ? $apparelRow['Family ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($screen === 'birthdays'): ?>
                <div class="dlbh-details-shell">
                    <div class="dlbh-details-head">
                        <span class="dlbh-details-head-title">Birthdays</span>
                        <span class="dlbh-inbox-field-value"><?php echo (int)count($birthdayRows); ?></span>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if (empty($birthdayRows)): ?>
                            <div class="dlbh-inbox-field-value">No birthday records found.</div>
                        <?php else: ?>
                            <table class="dlbh-inbox-table">
                                <tr>
                                    <th>Relationship</th>
                                    <th>Name</th>
                                    <th>Birthday</th>
                                    <th>Age</th>
                                    <th>Family ID</th>
                                </tr>
                                <?php foreach ($birthdayRows as $birthdayRow): ?>
                                    <?php $birthdayAge = dlbh_inbox_calculate_age(isset($birthdayRow['Date of Birth']) ? $birthdayRow['Date of Birth'] : ''); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)(isset($birthdayRow['Relationship']) ? $birthdayRow['Relationship'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($birthdayRow['Name']) ? $birthdayRow['Name'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($birthdayRow['Date of Birth']) ? $birthdayRow['Date of Birth'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($birthdayAge !== null ? (string)$birthdayAge : '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)(isset($birthdayRow['Family ID']) ? $birthdayRow['Family ID'] : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($screen === 'fundraising_bingo'): ?>
                <?php
                $dlbh_tier_options = array(
                    6 => 'Bronze',
                    12 => 'Silver',
                    18 => 'Gold',
                    24 => 'Platinum',
                );
                $dlbh_tier_colors = array(
                    6 => '#b87333',
                    12 => '#9ca3af',
                    18 => '#c79a00',
                    24 => '#4f8ef7',
                );
                $dlbh_selected_tier = isset($_GET['dlbh_bingo_tier']) ? (int)$_GET['dlbh_bingo_tier'] : 6;
                if (!isset($dlbh_tier_options[$dlbh_selected_tier])) $dlbh_selected_tier = 6;
                $dlbh_bingo_mode = isset($_GET['dlbh_bingo_mode']) ? strtolower(trim((string)$_GET['dlbh_bingo_mode'])) : 'shop';
                if ($dlbh_bingo_mode !== 'play') $dlbh_bingo_mode = 'shop';
                $dlbh_auto_upgrade = isset($_REQUEST['dlbh_auto_upgrade']) && (string)$_REQUEST['dlbh_auto_upgrade'] === '1';
                $dlbh_bingo_view = isset($_GET['dlbh_bingo_view']) ? strtolower(trim((string)$_GET['dlbh_bingo_view'])) : 'main';
                if ($dlbh_bingo_view !== 'checkout') $dlbh_bingo_view = 'main';
                $dlbh_checkout_view = ($dlbh_bingo_view === 'checkout');
                $dlbh_order_notice = isset($_GET['dlbh_order_notice']) ? strtolower(trim((string)$_GET['dlbh_order_notice'])) : '';
                $dlbh_cards_ui_enabled = false; // temporary: hide card display/generate controls
                $dlbh_bingo_generate = $dlbh_cards_ui_enabled && !empty($_GET['dlbh_bingo_generate']);
                $dlbh_bingo_storage_key = 'dlbh_fundraising_bingo_cards';
                $dlbh_bingo_cart_key = 'dlbh_fundraising_bingo_cart';
                $dlbh_bingo_checkout_email_key = 'dlbh_fundraising_bingo_checkout_email';
                $dlbh_user_id = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
                $dlbh_saved_cards_json = '';
                if ($dlbh_user_id > 0 && function_exists('get_user_meta')) {
                    $dlbh_saved_cards_json = (string)get_user_meta($dlbh_user_id, $dlbh_bingo_storage_key, true);
                }
                $dlbh_saved_cards = json_decode($dlbh_saved_cards_json, true);
                if (!is_array($dlbh_saved_cards)) $dlbh_saved_cards = array();
                if (!isset($_GET['dlbh_bingo_tier'])) {
                    $savedCount = count($dlbh_saved_cards);
                    if (isset($dlbh_tier_options[$savedCount])) $dlbh_selected_tier = (int)$savedCount;
                }
                $dlbh_bingo_accent = isset($dlbh_tier_colors[$dlbh_selected_tier]) ? (string)$dlbh_tier_colors[$dlbh_selected_tier] : '#e53935';

                $dlbh_pick_unique_numbers = function($min, $max, $count) {
                    $pool = range((int)$min, (int)$max);
                    shuffle($pool);
                    return array_slice($pool, 0, (int)$count);
                };
                $dlbh_generate_card_id = function($usedIds = array()) {
                    $guard = 0;
                    do {
                        $guard++;
                        $candidate = (string)random_int(10000, 99999);
                        if (!isset($usedIds[$candidate])) return $candidate;
                    } while ($guard < 10000);
                    return (string)random_int(10000, 99999);
                };
                $dlbh_generate_card = function($usedIds = array()) use ($dlbh_pick_unique_numbers, $dlbh_generate_card_id) {
                    $B = $dlbh_pick_unique_numbers(1, 15, 5);
                    $I = $dlbh_pick_unique_numbers(16, 30, 5);
                    $Nbase = $dlbh_pick_unique_numbers(31, 45, 4);
                    $N = array($Nbase[0], $Nbase[1], 'FREE', $Nbase[2], $Nbase[3]);
                    $G = $dlbh_pick_unique_numbers(46, 60, 5);
                    $O = $dlbh_pick_unique_numbers(61, 75, 5);
                    return array('card_id' => $dlbh_generate_card_id($usedIds), 'B' => $B, 'I' => $I, 'N' => $N, 'G' => $G, 'O' => $O);
                };
                $dlbh_card_signature = function($card) {
                    $cols = array(
                        isset($card['B']) && is_array($card['B']) ? $card['B'] : array(),
                        isset($card['I']) && is_array($card['I']) ? $card['I'] : array(),
                        isset($card['N']) && is_array($card['N']) ? $card['N'] : array(),
                        isset($card['G']) && is_array($card['G']) ? $card['G'] : array(),
                        isset($card['O']) && is_array($card['O']) ? $card['O'] : array(),
                    );
                    $grid = array();
                    for ($r = 0; $r < 5; $r++) {
                        $row = array();
                        for ($c = 0; $c < 5; $c++) {
                            $value = isset($cols[$c][$r]) ? $cols[$c][$r] : '';
                            if ($r === 2 && $c === 2) $value = 'FREE';
                            $row[] = $value;
                        }
                        $grid[] = $row;
                    }
                    return json_encode($grid);
                };

                $dlbh_cards = array();
                $dlbh_seen_signatures = array();
                $dlbh_seen_card_ids = array();
                foreach ($dlbh_saved_cards as $dlbh_saved_card) {
                    if (!is_array($dlbh_saved_card)) continue;
                    $savedCardId = '';
                    if (isset($dlbh_saved_card['card_id'])) {
                        $savedCardId = preg_replace('/[^0-9]/', '', (string)$dlbh_saved_card['card_id']);
                        if (!is_string($savedCardId)) $savedCardId = '';
                    }
                    if (strlen($savedCardId) !== 5 || isset($dlbh_seen_card_ids[$savedCardId])) {
                        $savedCardId = $dlbh_generate_card_id($dlbh_seen_card_ids);
                    }
                    $dlbh_saved_card['card_id'] = $savedCardId;
                    $dlbh_seen_card_ids[$savedCardId] = true;
                    $sig = $dlbh_card_signature($dlbh_saved_card);
                    if (!is_string($sig) || isset($dlbh_seen_signatures[$sig])) continue;
                    $dlbh_seen_signatures[$sig] = true;
                    $dlbh_cards[] = $dlbh_saved_card;
                }

                if ($dlbh_bingo_generate || empty($dlbh_cards)) {
                    $dlbh_cards = array();
                    $dlbh_seen_signatures = array();
                    $dlbh_seen_card_ids = array();
                }
                $dlbh_attempts = 0;
                while (count($dlbh_cards) < $dlbh_selected_tier && $dlbh_attempts < 50000) {
                    $dlbh_attempts++;
                    $new_card = $dlbh_generate_card($dlbh_seen_card_ids);
                    $sig = $dlbh_card_signature($new_card);
                    if (!is_string($sig) || isset($dlbh_seen_signatures[$sig])) continue;
                    $dlbh_seen_signatures[$sig] = true;
                    $newId = isset($new_card['card_id']) ? (string)$new_card['card_id'] : '';
                    if ($newId !== '') $dlbh_seen_card_ids[$newId] = true;
                    $dlbh_cards[] = $new_card;
                }
                if ($dlbh_user_id > 0 && function_exists('update_user_meta')) {
                    update_user_meta($dlbh_user_id, $dlbh_bingo_storage_key, wp_json_encode($dlbh_cards));
                }
                $dlbh_pricing_packages = array(
                    6 => array(
                        'class' => 'is-bronze',
                        'title' => 'Lucky Starter',
                        'price' => '$10',
                        'amount' => 10,
                        'minimum' => 'Minimum: 1 Card Per Game',
                        'desc' => "Step onto the floor with 6 total cards for the 6-game event. That's a minimum of 1 card in each game when played evenly across the session. A clean entry with just enough action to keep every round in reach.",
                    ),
                    12 => array(
                        'class' => 'is-silver',
                        'title' => 'Double Luck',
                        'price' => '$15',
                        'amount' => 15,
                        'minimum' => 'Minimum: 2 Cards Per Game',
                        'desc' => "Bring more heat to the floor with 12 total cards for the 6-game event. That's a minimum of 2 cards in each game when distributed evenly. A stronger play with more coverage, more suspense, and more chances to stay in the hunt all night long.",
                    ),
                    18 => array(
                        'class' => 'is-gold',
                        'title' => 'Triple Chance',
                        'price' => '$20',
                        'amount' => 20,
                        'minimum' => 'Minimum: 3 Cards Per Game',
                        'desc' => "Turn up the excitement with 18 total cards across the 6-game event. That's a minimum of 3 cards in each game when spread evenly. Built for players who want a fuller hand, bigger energy, and more action every time the next number drops.",
                    ),
                    24 => array(
                        'class' => 'is-platinum',
                        'title' => 'Full Play',
                        'price' => '$25',
                        'amount' => 25,
                        'minimum' => 'Minimum: 4 Cards Per Game',
                        'desc' => "Go in at the top level with 24 total cards for the 6-game event. That's a minimum of 4 cards in each game when distributed evenly. This is premium play-more coverage, more momentum, and a bigger presence from opening call to final win.",
                    ),
                );
                $dlbh_active_package = isset($dlbh_pricing_packages[$dlbh_selected_tier]) ? $dlbh_pricing_packages[$dlbh_selected_tier] : $dlbh_pricing_packages[6];
                $dlbh_cart_items = array();
                if ($dlbh_user_id > 0 && function_exists('get_user_meta')) {
                    $dlbh_cart_json = (string)get_user_meta($dlbh_user_id, $dlbh_bingo_cart_key, true);
                    $decoded_cart = json_decode($dlbh_cart_json, true);
                    if (is_array($decoded_cart)) $dlbh_cart_items = $decoded_cart;
                }
                $dlbh_tier_key_by_label = array();
                foreach ($dlbh_tier_options as $k => $label) {
                    $dlbh_tier_key_by_label[(string)$label] = (string)$k;
                }
                $dlbh_tier_steps = array(6, 12, 18, 24);
                $dlbh_upgrade_map = array();
                foreach (array_keys($dlbh_tier_options) as $tierCount) {
                    $tierCount = (int)$tierCount;
                    $doubleCount = $tierCount * 2;
                    if (isset($dlbh_tier_options[$doubleCount])) {
                        $dlbh_upgrade_map[$tierCount] = $doubleCount;
                    }
                }
                $dlbh_last5_int = function($value) {
                    $digits = preg_replace('/[^0-9]/', '', (string)$value);
                    if (!is_string($digits) || $digits === '') return 0;
                    if (strlen($digits) > 5) $digits = substr($digits, -5);
                    return (int)$digits;
                };
                $dlbh_compute_access_code_from_card_codes = function($cardCodes = array()) {
                    $window = 1000000000000; // keep last 12 digits while multiplying
                    $result = 1;
                    $hasAny = false;
                    foreach ($cardCodes as $code) {
                        $n = preg_replace('/[^0-9]/', '', (string)$code);
                        if (!is_string($n) || strlen($n) !== 5) continue;
                        $v = (int)$n;
                        if ($v <= 0) continue;
                        $hasAny = true;
                        $result *= $v;
                        while ($result % 10 === 0) $result = (int)($result / 10);
                        $result %= $window;
                    }
                    if (!$hasAny) return '00000';
                    $last5 = (int)($result % 100000);
                    return str_pad((string)$last5, 5, '0', STR_PAD_LEFT);
                };
                $dlbh_generate_book_codes = function($count, $existingCodes = array()) use ($dlbh_generate_card_id) {
                    $count = (int)$count;
                    if ($count < 1) return array();
                    $used = array();
                    foreach ($existingCodes as $ec) {
                        $clean = preg_replace('/[^0-9]/', '', (string)$ec);
                        if (is_string($clean) && strlen($clean) === 5) $used[$clean] = true;
                    }
                    $book = array();
                    $guard = 0;
                    while (count($book) < $count && $guard < 100000) {
                        $guard++;
                        $next = (string)$dlbh_generate_card_id($used);
                        if (!is_string($next) || strlen($next) !== 5 || isset($used[$next])) continue;
                        $used[$next] = true;
                        $book[] = $next;
                    }
                    return $book;
                };
                $dlbh_sync_tier_cart_entry = function(&$entry, $tierInt) use ($dlbh_pricing_packages, $dlbh_generate_book_codes, $dlbh_compute_access_code_from_card_codes) {
                    $tierInt = (int)$tierInt;
                    if ($tierInt < 1) return;
                    $books = isset($entry['books']) ? (int)$entry['books'] : 0;
                    if ($books < 0) $books = 0;
                    $entry['tier_key'] = (string)$tierInt;
                    $entry['tier'] = isset($dlbh_pricing_packages[$tierInt]['title']) ? (string)$entry['tier'] : (isset($entry['tier']) ? (string)$entry['tier'] : '');
                    $entry['cards_per_book'] = $tierInt;
                    $entry['books'] = $books;
                    $entry['total_cards'] = $books * $tierInt;
                    $entry['price'] = isset($dlbh_pricing_packages[$tierInt]['price']) ? (string)$dlbh_pricing_packages[$tierInt]['price'] : '$0';
                    $unitAmount = isset($dlbh_pricing_packages[$tierInt]['amount']) ? (int)$dlbh_pricing_packages[$tierInt]['amount'] : 0;
                    $entry['amount'] = $books * $unitAmount;
                    $codes = isset($entry['card_codes']) && is_array($entry['card_codes']) ? array_values($entry['card_codes']) : array();
                    if (count($codes) > $entry['total_cards']) {
                        $codes = array_slice($codes, 0, $entry['total_cards']);
                    } elseif (count($codes) < $entry['total_cards']) {
                        $more = $dlbh_generate_book_codes($entry['total_cards'] - count($codes), $codes);
                        foreach ($more as $m) $codes[] = $m;
                    }
                    $entry['card_codes'] = $codes;
                    $entry['access_code'] = $dlbh_compute_access_code_from_card_codes($codes);
                };
                $dlbh_rebuild_cart_from_total_cards = function($totalCards, $maxTier) use ($dlbh_tier_steps, $dlbh_tier_options, $dlbh_pricing_packages, $dlbh_sync_tier_cart_entry) {
                    $totalCards = max(0, (int)$totalCards);
                    $maxTier = (int)$maxTier;
                    $eligibleTiers = array();
                    foreach ($dlbh_tier_steps as $t) {
                        $ti = (int)$t;
                        if ($ti >= 6 && $ti <= $maxTier) $eligibleTiers[] = $ti;
                    }
                    if (empty($eligibleTiers)) $eligibleTiers = array(6);
                    rsort($eligibleTiers, SORT_NUMERIC);

                    $rebuilt = array();
                    $remaining = $totalCards;
                    foreach ($eligibleTiers as $tier) {
                        if ($tier <= 0) continue;
                        $books = intdiv($remaining, $tier);
                        if ($books <= 0) continue;
                        $key = (string)$tier;
                        $rebuilt[$key] = array(
                            'tier_key' => $key,
                            'tier' => isset($dlbh_tier_options[$tier]) ? (string)$dlbh_tier_options[$tier] : '',
                            'price' => isset($dlbh_pricing_packages[$tier]['price']) ? (string)$dlbh_pricing_packages[$tier]['price'] : '$0',
                            'amount' => 0,
                            'books' => $books,
                            'cards_per_book' => $tier,
                            'total_cards' => 0,
                            'card_codes' => array(),
                            'access_code' => '00000',
                        );
                        $dlbh_sync_tier_cart_entry($rebuilt[$key], $tier);
                        $remaining -= ($books * $tier);
                    }
                    if ($remaining > 0) {
                        $key = '6';
                        if (!isset($rebuilt[$key])) {
                            $rebuilt[$key] = array(
                                'tier_key' => $key,
                                'tier' => isset($dlbh_tier_options[6]) ? (string)$dlbh_tier_options[6] : '',
                                'price' => isset($dlbh_pricing_packages[6]['price']) ? (string)$dlbh_pricing_packages[6]['price'] : '$0',
                                'amount' => 0,
                                'books' => 0,
                                'cards_per_book' => 6,
                                'total_cards' => 0,
                                'card_codes' => array(),
                                'access_code' => '00000',
                            );
                        }
                        $extraBooks = (int)ceil(((float)$remaining) / 6.0);
                        $rebuilt[$key]['books'] = (int)$rebuilt[$key]['books'] + $extraBooks;
                        $dlbh_sync_tier_cart_entry($rebuilt[$key], 6);
                    }
                    return $rebuilt;
                };
                $dlbh_cart_total_cards = function($cartById) {
                    $sum = 0;
                    if (!is_array($cartById)) return 0;
                    foreach ($cartById as $entry) {
                        if (!is_array($entry)) continue;
                        $sum += (int)(isset($entry['total_cards']) ? $entry['total_cards'] : 0);
                    }
                    return max(0, (int)$sum);
                };
                $dlbh_cart_total_amount = function($cartById) {
                    $sum = 0;
                    if (!is_array($cartById)) return 0;
                    foreach ($cartById as $entry) {
                        if (!is_array($entry)) continue;
                        $sum += (int)(isset($entry['amount']) ? $entry['amount'] : 0);
                    }
                    return max(0, (int)$sum);
                };
                $dlbh_cart_mix_label = function($cartById) use ($dlbh_tier_options) {
                    if (!is_array($cartById) || empty($cartById)) return '';
                    $pieces = array();
                    $keys = array_keys($cartById);
                    $keys = array_map('intval', $keys);
                    rsort($keys, SORT_NUMERIC);
                    foreach ($keys as $k) {
                        $entryKey = (string)$k;
                        if (!isset($cartById[$entryKey])) continue;
                        $books = (int)(isset($cartById[$entryKey]['books']) ? $cartById[$entryKey]['books'] : 0);
                        if ($books <= 0) continue;
                        $tierLabel = isset($dlbh_tier_options[$k]) ? (string)$dlbh_tier_options[$k] : ((string)$k . '-Card');
                        $pieces[] = $books . ' ' . $tierLabel;
                    }
                    return implode(' + ', $pieces);
                };
                $dlbh_cart_by_id = array();
                foreach ($dlbh_cart_items as $ci) {
                    if (!is_array($ci)) continue;
                    $tier_label = isset($ci['tier']) ? (string)$ci['tier'] : '';
                    $tier_key = isset($ci['tier_key']) ? preg_replace('/[^0-9]/', '', (string)$ci['tier_key']) : '';
                    if (!is_string($tier_key) || $tier_key === '') {
                        $tier_key = isset($dlbh_tier_key_by_label[$tier_label]) ? (string)$dlbh_tier_key_by_label[$tier_label] : '';
                    }
                    if ($tier_key === '' || !isset($dlbh_tier_options[(int)$tier_key])) continue;
                    if (!isset($dlbh_cart_by_id[$tier_key])) {
                        $dlbh_cart_by_id[$tier_key] = array(
                            'tier_key' => (string)$tier_key,
                            'tier' => (string)$dlbh_tier_options[(int)$tier_key],
                            'price' => isset($ci['price']) ? (string)$ci['price'] : '$0',
                            'amount' => 0,
                            'books' => 0,
                            'cards_per_book' => (int)$tier_key,
                            'total_cards' => 0,
                            'card_codes' => array(),
                            'access_code' => '00000',
                        );
                    }
                    $entry_books = isset($ci['books']) ? max(1, (int)$ci['books']) : 1;
                    $min_cards_for_books = (int)$tier_key * $entry_books;
                    $entry_cards = isset($ci['total_cards']) ? max($min_cards_for_books, (int)$ci['total_cards']) : $min_cards_for_books;
                    $entry_amount = isset($ci['amount']) ? (int)$ci['amount'] : 0;
                    if ($entry_amount <= 0 && isset($ci['price'])) {
                        $entry_amount = (int)preg_replace('/[^0-9]/', '', (string)$ci['price']);
                        if ($entry_amount > 0) $entry_amount *= $entry_books;
                    }
                    $dlbh_cart_by_id[$tier_key]['books'] += $entry_books;
                    $dlbh_cart_by_id[$tier_key]['total_cards'] += $entry_cards;
                    $dlbh_cart_by_id[$tier_key]['amount'] += $entry_amount;
                    if (isset($ci['card_codes']) && is_array($ci['card_codes'])) {
                        foreach ($ci['card_codes'] as $cardCode) {
                            $cc = preg_replace('/[^0-9]/', '', (string)$cardCode);
                            if (is_string($cc) && strlen($cc) === 5) $dlbh_cart_by_id[$tier_key]['card_codes'][] = $cc;
                        }
                    }
                    $dlbh_cart_by_id[$tier_key]['access_code'] = $dlbh_compute_access_code_from_card_codes($dlbh_cart_by_id[$tier_key]['card_codes']);
                }
                $dlbh_current_card_ids = array();
                foreach ($dlbh_cards as $cc) {
                    $id = isset($cc['card_id']) ? preg_replace('/[^0-9]/', '', (string)$cc['card_id']) : '';
                    if (is_string($id) && strlen($id) === 5) $dlbh_current_card_ids[$id] = true;
                }
                $dlbh_post_action_tier = 0;
                $dlbh_should_prg_redirect = false;
                $dlbh_order_notice_redirect = '';
                if (isset($_REQUEST['dlbh_bingo_action'])) {
                    $dlbh_should_prg_redirect = true;
                    $action = (string)$_REQUEST['dlbh_bingo_action'];
                    if ($action === 'add_deck') {
                        $requested_tier = isset($_REQUEST['dlbh_tier_key']) ? (int)$_REQUEST['dlbh_tier_key'] : (int)$dlbh_selected_tier;
                        if (!isset($dlbh_tier_options[$requested_tier])) $requested_tier = (int)$dlbh_selected_tier;
                        $dlbh_post_action_tier = $requested_tier;
                        $tier_key = (string)$requested_tier;
                        if (!isset($dlbh_cart_by_id[$tier_key])) {
                            $dlbh_cart_by_id[$tier_key] = array(
                                'tier_key' => $tier_key,
                                'tier' => isset($dlbh_tier_options[$requested_tier]) ? (string)$dlbh_tier_options[$requested_tier] : '',
                                'price' => isset($dlbh_pricing_packages[$requested_tier]['price']) ? (string)$dlbh_pricing_packages[$requested_tier]['price'] : '$0',
                                'amount' => 0,
                                'books' => 0,
                                'cards_per_book' => (int)$requested_tier,
                                'total_cards' => 0,
                                'card_codes' => array(),
                                'access_code' => '00000',
                            );
                        }
                        $dlbh_cart_by_id[$tier_key]['books'] = (int)$dlbh_cart_by_id[$tier_key]['books'] + 1;
                        $dlbh_sync_tier_cart_entry($dlbh_cart_by_id[$tier_key], $requested_tier);

                        if ($dlbh_auto_upgrade) {
                            // Auto-upgrade only when enabled via checkbox.
                            $carry = true;
                            while ($carry) {
                                $carry = false;
                                foreach ($dlbh_upgrade_map as $fromTier => $toTier) {
                                    $fromKey = (string)$fromTier;
                                    if (!isset($dlbh_cart_by_id[$fromKey])) continue;
                                    $fromBooks = (int)(isset($dlbh_cart_by_id[$fromKey]['books']) ? $dlbh_cart_by_id[$fromKey]['books'] : 0);
                                    if ($fromBooks < 2) continue;
                                    $dlbh_cart_by_id[$fromKey]['books'] = $fromBooks - 2;
                                    $dlbh_sync_tier_cart_entry($dlbh_cart_by_id[$fromKey], $fromTier);
                                    if ((int)$dlbh_cart_by_id[$fromKey]['books'] <= 0) unset($dlbh_cart_by_id[$fromKey]);

                                    $toKey = (string)$toTier;
                                    if (!isset($dlbh_cart_by_id[$toKey])) {
                                        $dlbh_cart_by_id[$toKey] = array(
                                            'tier_key' => $toKey,
                                            'tier' => isset($dlbh_tier_options[$toTier]) ? (string)$dlbh_tier_options[$toTier] : '',
                                            'price' => isset($dlbh_pricing_packages[$toTier]['price']) ? (string)$dlbh_pricing_packages[$toTier]['price'] : '$0',
                                            'amount' => 0,
                                            'books' => 0,
                                            'cards_per_book' => $toTier,
                                            'total_cards' => 0,
                                            'card_codes' => array(),
                                            'access_code' => '00000',
                                        );
                                    }
                                    $dlbh_cart_by_id[$toKey]['books'] = (int)$dlbh_cart_by_id[$toKey]['books'] + 1;
                                    $dlbh_sync_tier_cart_entry($dlbh_cart_by_id[$toKey], $toTier);
                                    $dlbh_post_action_tier = $toTier;
                                    $carry = true;
                                }
                            }
                        }
                    } elseif ($action === 'decrement_deck') {
                        $tier_key = isset($_REQUEST['dlbh_tier_key']) ? preg_replace('/[^0-9]/', '', (string)$_REQUEST['dlbh_tier_key']) : '';
                        if (is_string($tier_key) && $tier_key !== '' && isset($dlbh_cart_by_id[$tier_key])) {
                            $tk = (int)$tier_key;
                            $books = (int)(isset($dlbh_cart_by_id[$tier_key]['books']) ? $dlbh_cart_by_id[$tier_key]['books'] : 0);
                            if ($books > 1) {
                                $dlbh_cart_by_id[$tier_key]['books'] = $books - 1;
                                $dlbh_sync_tier_cart_entry($dlbh_cart_by_id[$tier_key], $tk);
                                $dlbh_post_action_tier = $tk;
                            } else {
                                $totalBooksBefore = 0;
                                foreach ($dlbh_cart_by_id as $countEntry) {
                                    if (!is_array($countEntry)) continue;
                                    $totalBooksBefore += (int)(isset($countEntry['books']) ? $countEntry['books'] : 0);
                                }
                                $lowerTier = 0;
                                $idx = array_search($tk, $dlbh_tier_steps, true);
                                if ($idx !== false && $idx > 0) {
                                    $lowerTier = (int)$dlbh_tier_steps[$idx - 1];
                                }

                                // Step down through tiers only when this is the only book in cart.
                                if ($totalBooksBefore === 1 && $lowerTier > 0) {
                                    unset($dlbh_cart_by_id[$tier_key]);
                                    $lowerKey = (string)$lowerTier;
                                    if (!isset($dlbh_cart_by_id[$lowerKey])) {
                                        $dlbh_cart_by_id[$lowerKey] = array(
                                            'tier_key' => $lowerKey,
                                            'tier' => isset($dlbh_tier_options[$lowerTier]) ? (string)$dlbh_tier_options[$lowerTier] : '',
                                            'price' => isset($dlbh_pricing_packages[$lowerTier]['price']) ? (string)$dlbh_pricing_packages[$lowerTier]['price'] : '$0',
                                            'amount' => 0,
                                            'books' => 0,
                                            'cards_per_book' => $lowerTier,
                                            'total_cards' => 0,
                                            'card_codes' => array(),
                                            'access_code' => '00000',
                                        );
                                    }
                                    $dlbh_cart_by_id[$lowerKey]['books'] = 1;
                                    $dlbh_sync_tier_cart_entry($dlbh_cart_by_id[$lowerKey], $lowerTier);
                                    $dlbh_post_action_tier = $lowerTier;
                                } else {
                                    unset($dlbh_cart_by_id[$tier_key]);
                                    $dlbh_post_action_tier = 0;
                                }
                            }
                        }
                    } elseif ($action === 'upgrade_offer') {
                        $target_raw = isset($_REQUEST['dlbh_upgrade_target']) ? $_REQUEST['dlbh_upgrade_target'] : 0;
                        if (is_array($target_raw)) {
                            $target_raw = reset($target_raw);
                        }
                        $target_tier = (int)$target_raw;
                        $current_total_cards = $dlbh_cart_total_cards($dlbh_cart_by_id);
                        if (isset($dlbh_tier_options[$target_tier]) && $target_tier > 6 && $current_total_cards > 0) {
                            $target_total_cards = max($current_total_cards, $target_tier);
                            $dlbh_cart_by_id = $dlbh_rebuild_cart_from_total_cards($target_total_cards, $target_tier);
                            $dlbh_post_action_tier = $target_tier;
                        }
                    } elseif ($action === 'set_checkout_email') {
                        $newEmailRaw = isset($_REQUEST['dlbh_checkout_email']) ? trim((string)$_REQUEST['dlbh_checkout_email']) : '';
                        $newEmail = function_exists('sanitize_email') ? (string)sanitize_email($newEmailRaw) : (string)filter_var($newEmailRaw, FILTER_SANITIZE_EMAIL);
                        if ($dlbh_user_id > 0 && function_exists('update_user_meta')) {
                            update_user_meta($dlbh_user_id, $dlbh_bingo_checkout_email_key, $newEmail);
                        }
                    } elseif ($action === 'place_order') {
                        $orderPrimary = '-';
                        $orderFamily = '-';
                        $orderEmail = '-';
                        $orderPhone = '-';
                        if (isset($selectedRosterRow) && is_array($selectedRosterRow)) {
                            $orderPrimary = trim((string)(isset($selectedRosterRow['Name']) ? $selectedRosterRow['Name'] : ''));
                            $orderFamily = trim((string)(isset($selectedRosterRow['Family ID']) ? $selectedRosterRow['Family ID'] : ''));
                            $orderEmail = trim((string)(isset($selectedRosterRow['Email']) ? $selectedRosterRow['Email'] : ''));
                            $orderPhone = trim((string)(isset($selectedRosterRow['Phone']) ? $selectedRosterRow['Phone'] : ''));
                            $orderProfileFields = isset($selectedRosterRow['Profile Fields']) && is_array($selectedRosterRow['Profile Fields']) ? $selectedRosterRow['Profile Fields'] : array();
                            if (!empty($orderProfileFields)) {
                                $tmpPrimary = trim((string)dlbh_inbox_get_field_value_by_label($orderProfileFields, 'Primary Member'));
                                $tmpFamily = trim((string)dlbh_inbox_get_field_value_by_label($orderProfileFields, 'Family ID'));
                                $tmpEmail = trim((string)dlbh_inbox_get_field_value_by_label($orderProfileFields, 'Email'));
                                if ($tmpEmail === '') $tmpEmail = trim((string)dlbh_inbox_get_field_value($orderProfileFields, 'Email'));
                                $tmpPhone = trim((string)dlbh_inbox_get_field_value_by_label($orderProfileFields, 'Phone'));
                                if ($tmpPhone === '') $tmpPhone = trim((string)dlbh_inbox_get_field_value($orderProfileFields, 'Phone'));
                                if ($tmpPrimary !== '') $orderPrimary = $tmpPrimary;
                                if ($tmpFamily !== '') $orderFamily = $tmpFamily;
                                if ($tmpEmail !== '') $orderEmail = $tmpEmail;
                                if ($tmpPhone !== '') $orderPhone = $tmpPhone;
                            }
                        }
                        if ($dlbh_user_id > 0 && function_exists('get_user_meta')) {
                            $savedCheckoutEmail = trim((string)get_user_meta($dlbh_user_id, $dlbh_bingo_checkout_email_key, true));
                            if ($savedCheckoutEmail !== '') $orderEmail = $savedCheckoutEmail;
                        }
                        if ($orderPrimary === '') $orderPrimary = '-';
                        if ($orderFamily === '') $orderFamily = '-';
                        if ($orderEmail === '') $orderEmail = '-';
                        if ($orderPhone === '') $orderPhone = '-';

                        $orderItemsCount = 0;
                        foreach ($dlbh_cart_by_id as $orderEntry) {
                            if (!is_array($orderEntry)) continue;
                            $orderItemsCount += (int)(isset($orderEntry['books']) ? $orderEntry['books'] : 0);
                        }
                        $orderTotalCardsAll = 0;
                        foreach ($dlbh_cart_by_id as $orderEntry) {
                            if (!is_array($orderEntry)) continue;
                            $orderTotalCardsAll += (int)(isset($orderEntry['total_cards']) ? $orderEntry['total_cards'] : 0);
                        }
                        $orderCards = array();
                        $orderUsedIds = array();
                        $orderSeenSigs = array();
                        $orderGuard = 0;
                        while (count($orderCards) < $orderTotalCardsAll && $orderGuard < 100000) {
                            $orderGuard++;
                            $card = $dlbh_generate_card($orderUsedIds);
                            $sig = $dlbh_card_signature($card);
                            if (!is_string($sig) || isset($orderSeenSigs[$sig])) continue;
                            $orderSeenSigs[$sig] = true;
                            $cid = isset($card['card_id']) ? preg_replace('/[^0-9]/', '', (string)$card['card_id']) : '';
                            if (!is_string($cid) || strlen($cid) !== 5) continue;
                            $orderUsedIds[$cid] = true;
                            $orderCards[] = $card;
                        }
                        $orderCardCodes = array();
                        foreach ($orderCards as $oc) {
                            $cid = isset($oc['card_id']) ? preg_replace('/[^0-9]/', '', (string)$oc['card_id']) : '';
                            if (is_string($cid) && strlen($cid) === 5) $orderCardCodes[] = $cid;
                        }
                        $orderAccessCode = $dlbh_compute_access_code_from_card_codes($orderCardCodes);

                        $cardsHtml = '';
                        foreach ($orderCards as $idx => $card) {
                            $cardId = isset($card['card_id']) ? preg_replace('/[^0-9]/', '', (string)$card['card_id']) : '';
                            if (!is_string($cardId) || strlen($cardId) !== 5) $cardId = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                            $cols = array($card['B'], $card['I'], $card['N'], $card['G'], $card['O']);
                            $cardsHtml .= '<h4 style="margin:16px 0 6px 0;font-family:Arial,sans-serif;font-size:18px;letter-spacing:.04em;">Bingo Card ' . ($idx + 1) . ' - ' . htmlspecialchars($cardId, ENT_QUOTES, 'UTF-8') . '</h4>';
                            $cardsHtml .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:14px;font-family:Arial,sans-serif;">';
                            $cardsHtml .= '<tr>'
                                . '<th style="background:#e53935;color:#ffffff;width:44px;height:44px;text-align:center;font-size:30px;font-weight:700;border:1px solid #ffffff;">B</th>'
                                . '<th style="background:#e53935;color:#ffffff;width:44px;height:44px;text-align:center;font-size:30px;font-weight:700;border:1px solid #ffffff;">I</th>'
                                . '<th style="background:#e53935;color:#ffffff;width:44px;height:44px;text-align:center;font-size:30px;font-weight:700;border:1px solid #ffffff;">N</th>'
                                . '<th style="background:#e53935;color:#ffffff;width:44px;height:44px;text-align:center;font-size:30px;font-weight:700;border:1px solid #ffffff;">G</th>'
                                . '<th style="background:#e53935;color:#ffffff;width:44px;height:44px;text-align:center;font-size:30px;font-weight:700;border:1px solid #ffffff;">O</th>'
                                . '</tr>';
                            for ($r = 0; $r < 5; $r++) {
                                $cardsHtml .= '<tr>';
                                for ($c = 0; $c < 5; $c++) {
                                    if ($r === 2 && $c === 2) {
                                        $cardsHtml .= '<td style="width:44px;height:44px;text-align:center;background:#e53935;color:#ffffff;font-size:14px;font-weight:700;border:1px solid #e5e7eb;">FREE</td>';
                                    } else {
                                        $cardsHtml .= '<td style="width:44px;height:44px;text-align:center;background:#efefef;color:#4b5563;font-size:15px;font-weight:600;border:1px solid #e5e7eb;">' . (int)$cols[$c][$r] . '</td>';
                                    }
                                }
                                $cardsHtml .= '</tr>';
                            }
                            $cardsHtml .= '</table>';
                        }

                        $emailBody = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#F7F9FA;">'
                            . '<div style="margin:0;padding:20px 0;background:#F7F9FA;font-family:Instrument Sans,Instrumental Sans,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#1f2937;line-height:1.45;">'
                            . '<div style="width:100%;max-width:920px;margin:0 auto;background:#FFFFFF;border:1px solid #d9dde1;border-radius:10px;overflow:hidden;">'
                            . '<div style="padding:14px 16px;background:#131313;color:#FFFFFF;">'
                            . '<div style="font-size:12px;opacity:.9;letter-spacing:.03em;text-transform:uppercase;">Damie Lee Burton Howard Family Reunion</div>'
                            . '<div style="font-weight:700;font-size:20px;margin-top:3px;">Let\'s Play Bingo!</div>'
                            . '</div>'
                            . '<div style="padding:16px 18px;">'
                            . '<div style="margin:0 0 12px 0;padding:12px;border:1px solid #d9dde1;border-radius:8px;background:#F7F9FA;">'
                            . '<div style="font-size:18px;font-weight:700;color:#111827;margin-bottom:8px;">Order Request Received</div>'
                            . '<div style="display:flex;flex-wrap:wrap;gap:8px 24px;">'
                            . '<div style="min-width:180px;"><div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;">Primary Member</div><div style="font-size:16px;font-weight:600;color:#111827;">' . htmlspecialchars($orderPrimary, ENT_QUOTES, 'UTF-8') . '</div></div>'
                            . '<div style="min-width:160px;"><div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;">Family ID</div><div style="font-size:16px;font-weight:600;color:#111827;">' . htmlspecialchars($orderFamily, ENT_QUOTES, 'UTF-8') . '</div></div>'
                            . '<div style="min-width:130px;"><div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;">Total Cards</div><div style="font-size:16px;font-weight:700;color:#111827;">' . (int)$orderTotalCardsAll . '</div></div>'
                            . '<div style="min-width:160px;"><div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;">Access Code</div><div style="font-size:16px;font-weight:700;color:#111827;letter-spacing:.04em;">' . htmlspecialchars((string)$orderAccessCode, ENT_QUOTES, 'UTF-8') . '</div></div>'
                            . '</div>'
                            . '</div>'
                            . '<div style="font-size:18px;font-weight:700;color:#111827;margin:14px 0 8px 0;">Bingo Cards</div>'
                            . $cardsHtml
                            . '</div>'
                            . '</div>'
                            . '</div>'
                            . '</body></html>';

                        $mailSubject = 'Let\'s Play Bingo! Order Request';
                        $mailHeaders = array(
                            'Content-Type: text/html; charset=UTF-8',
                            'From: Let\'s Play Bingo <office@dlbhfamily.com>',
                        );
                        $inventoryFolders = dlbh_inbox_get_bingo_inventory_folder_candidates();
                        $mailSent = dlbh_inbox_append_html_message_to_folder_candidates(
                            $postedEmail,
                            $postedPassword,
                            $server,
                            $port,
                            $inventoryFolders,
                            "Let's Play Bingo <office@dlbhfamily.com>",
                            'office@dlbhfamily.com',
                            $mailSubject,
                            $emailBody
                        );
                        if ($mailSent) {
                            $dlbh_cart_by_id = array();
                            $dlbh_selected_tier = 6;
                            $dlbh_post_action_tier = 6;
                            $dlbh_bingo_mode = 'shop';
                            $dlbh_bingo_view = 'main';
                            $dlbh_checkout_view = false;
                        }
                        $dlbh_order_notice_redirect = $mailSent ? 'sent' : 'failed';
                    } elseif ($action === 'clear_cart') {
                        $dlbh_cart_by_id = array();
                    }
                    if ($dlbh_user_id > 0 && function_exists('update_user_meta')) {
                        update_user_meta($dlbh_user_id, $dlbh_bingo_cart_key, wp_json_encode(array_values($dlbh_cart_by_id)));
                    }
                }
                if ($dlbh_bingo_generate) {
                    $dlbh_should_prg_redirect = true;
                }
                if ($dlbh_post_action_tier > 0 && isset($dlbh_tier_options[(int)$dlbh_post_action_tier])) {
                    $dlbh_selected_tier = (int)$dlbh_post_action_tier;
                }
                $dlbh_active_package = isset($dlbh_pricing_packages[$dlbh_selected_tier]) ? $dlbh_pricing_packages[$dlbh_selected_tier] : $dlbh_pricing_packages[6];
                $dlbh_bingo_accent = isset($dlbh_tier_colors[$dlbh_selected_tier]) ? (string)$dlbh_tier_colors[$dlbh_selected_tier] : '#e53935';
                $dlbh_cart_items = array_values($dlbh_cart_by_id);
                $dlbh_cart_count = 0;
                foreach ($dlbh_cart_items as $ci) $dlbh_cart_count += (int)(isset($ci['books']) ? $ci['books'] : 0);
                if ((int)$dlbh_cart_count === 0) {
                    $dlbh_selected_tier = 6;
                    $dlbh_active_package = $dlbh_pricing_packages[6];
                    $dlbh_bingo_accent = isset($dlbh_tier_colors[6]) ? (string)$dlbh_tier_colors[6] : '#e53935';
                } elseif ((int)$dlbh_post_action_tier <= 0) {
                    $dlbh_cart_tiers = array_keys($dlbh_cart_by_id);
                    $dlbh_cart_tiers = array_map('intval', $dlbh_cart_tiers);
                    sort($dlbh_cart_tiers);
                    $dlbh_selected_tier = (int)end($dlbh_cart_tiers);
                    if (!isset($dlbh_tier_options[$dlbh_selected_tier])) $dlbh_selected_tier = 6;
                    $dlbh_active_package = isset($dlbh_pricing_packages[$dlbh_selected_tier]) ? $dlbh_pricing_packages[$dlbh_selected_tier] : $dlbh_pricing_packages[6];
                    $dlbh_bingo_accent = isset($dlbh_tier_colors[$dlbh_selected_tier]) ? (string)$dlbh_tier_colors[$dlbh_selected_tier] : '#e53935';
                }
                $dlbh_selected_tier_key = (string)$dlbh_selected_tier;
                $dlbh_books_for_selected_tier = isset($dlbh_cart_by_id[$dlbh_selected_tier_key]['books']) ? max(0, (int)$dlbh_cart_by_id[$dlbh_selected_tier_key]['books']) : 0;
                $dlbh_visible_cards_target = (int)$dlbh_selected_tier * (int)$dlbh_books_for_selected_tier;
                if (count($dlbh_cards) > $dlbh_visible_cards_target) {
                    $dlbh_cards = array_slice($dlbh_cards, 0, $dlbh_visible_cards_target);
                    $dlbh_seen_signatures = array();
                    $dlbh_seen_card_ids = array();
                    foreach ($dlbh_cards as $dlbh_existing_card) {
                        $sig = $dlbh_card_signature($dlbh_existing_card);
                        if (is_string($sig)) $dlbh_seen_signatures[$sig] = true;
                        $existingId = isset($dlbh_existing_card['card_id']) ? preg_replace('/[^0-9]/', '', (string)$dlbh_existing_card['card_id']) : '';
                        if (is_string($existingId) && strlen($existingId) === 5) $dlbh_seen_card_ids[$existingId] = true;
                    }
                }
                $dlbh_expand_attempts = 0;
                while (count($dlbh_cards) < $dlbh_visible_cards_target && $dlbh_expand_attempts < 100000) {
                    $dlbh_expand_attempts++;
                    $next_card = $dlbh_generate_card($dlbh_seen_card_ids);
                    $next_sig = $dlbh_card_signature($next_card);
                    if (!is_string($next_sig) || isset($dlbh_seen_signatures[$next_sig])) continue;
                    $dlbh_seen_signatures[$next_sig] = true;
                    $next_id = isset($next_card['card_id']) ? (string)$next_card['card_id'] : '';
                    if ($next_id !== '') $dlbh_seen_card_ids[$next_id] = true;
                    $dlbh_cards[] = $next_card;
                }
                if ($dlbh_user_id > 0 && function_exists('update_user_meta')) {
                    update_user_meta($dlbh_user_id, $dlbh_bingo_storage_key, wp_json_encode($dlbh_cards));
                }
                $dlbh_checkout_primary_member = '-';
                $dlbh_checkout_family_id = '-';
                $dlbh_checkout_email = '-';
                $dlbh_checkout_phone = '-';
                $dlbh_checkout_email_override = '';
                if ($dlbh_user_id > 0 && function_exists('get_user_meta')) {
                    $dlbh_checkout_email_override = trim((string)get_user_meta($dlbh_user_id, $dlbh_bingo_checkout_email_key, true));
                }
                if (isset($selectedRosterRow) && is_array($selectedRosterRow)) {
                    $dlbh_checkout_primary_member = trim((string)(isset($selectedRosterRow['Name']) ? $selectedRosterRow['Name'] : ''));
                    $dlbh_checkout_family_id = trim((string)(isset($selectedRosterRow['Family ID']) ? $selectedRosterRow['Family ID'] : ''));
                    $dlbh_checkout_email = trim((string)(isset($selectedRosterRow['Email']) ? $selectedRosterRow['Email'] : ''));
                    $dlbh_checkout_phone = trim((string)(isset($selectedRosterRow['Phone']) ? $selectedRosterRow['Phone'] : ''));
                    $dlbh_profile_fields = isset($selectedRosterRow['Profile Fields']) && is_array($selectedRosterRow['Profile Fields']) ? $selectedRosterRow['Profile Fields'] : array();
                    if (!empty($dlbh_profile_fields)) {
                        $pm = trim((string)dlbh_inbox_get_field_value_by_label($dlbh_profile_fields, 'Primary Member'));
                        if ($pm !== '') $dlbh_checkout_primary_member = $pm;
                        $fid = trim((string)dlbh_inbox_get_field_value_by_label($dlbh_profile_fields, 'Family ID'));
                        if ($fid !== '') $dlbh_checkout_family_id = $fid;
                        $em = trim((string)dlbh_inbox_get_field_value_by_label($dlbh_profile_fields, 'Email'));
                        if ($em === '') $em = trim((string)dlbh_inbox_get_field_value($dlbh_profile_fields, 'Email'));
                        if ($em !== '') $dlbh_checkout_email = $em;
                        $ph = trim((string)dlbh_inbox_get_field_value_by_label($dlbh_profile_fields, 'Phone'));
                        if ($ph === '') $ph = trim((string)dlbh_inbox_get_field_value($dlbh_profile_fields, 'Phone'));
                        if ($ph !== '') $dlbh_checkout_phone = $ph;
                    }
                }
                if ($dlbh_checkout_primary_member === '') $dlbh_checkout_primary_member = '-';
                if ($dlbh_checkout_family_id === '' && isset($memberSession) && is_array($memberSession) && !empty($memberSession['family_id'])) {
                    $dlbh_checkout_family_id = trim((string)$memberSession['family_id']);
                }
                if ($dlbh_checkout_family_id === '') $dlbh_checkout_family_id = '-';
                if ($dlbh_checkout_email_override !== '') $dlbh_checkout_email = $dlbh_checkout_email_override;
                if ($dlbh_checkout_email === '') $dlbh_checkout_email = '-';
                if ($dlbh_checkout_phone === '') $dlbh_checkout_phone = '-';
                $dlbh_play_cards = array();
                $dlbh_play_moved_count = 0;
                $dlbh_play_family_key = dlbh_inbox_normalize_family_id_lookup_key($dlbh_checkout_family_id);
                if ($dlbh_play_family_key === '' && isset($memberSession) && is_array($memberSession) && !empty($memberSession['family_id'])) {
                    $dlbh_play_family_key = dlbh_inbox_normalize_family_id_lookup_key((string)$memberSession['family_id']);
                }
                if ($dlbh_bingo_mode === 'play' && $postedEmail !== '' && $postedPassword !== '') {
                    $inventoryFolders = dlbh_inbox_get_bingo_inventory_folder_candidates();
                    $inboxRw = dlbh_inbox_open_connection_rw($postedEmail, $postedPassword, $server, $port, 'INBOX');
                    if ($inboxRw !== false) {
                        $inboxNumbers = @imap_search($inboxRw, 'ALL');
                        if (is_array($inboxNumbers) && !empty($inboxNumbers)) {
                            foreach ($inboxNumbers as $inboxNum) {
                                $overview = @imap_fetch_overview($inboxRw, (string)$inboxNum, 0);
                                $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
                                $subject = dlbh_inbox_decode_header_text($item && isset($item->subject) ? (string)$item->subject : '');
                                if (stripos($subject, "Let's Play Bingo! Order Request") === false) continue;
                                if (dlbh_inbox_move_message_to_folder_candidates($inboxRw, (int)$inboxNum, $inventoryFolders)) {
                                    $dlbh_play_moved_count++;
                                }
                            }
                        }
                        @imap_close($inboxRw);
                    }

                    $inventoryOpen = dlbh_inbox_open_connection_rw_with_fallbacks($postedEmail, $postedPassword, $server, $port, $inventoryFolders);
                    $inventoryRw = isset($inventoryOpen['connection']) ? $inventoryOpen['connection'] : false;
                    if ($inventoryRw !== false) {
                        $inventoryNumbers = @imap_search($inventoryRw, 'ALL');
                        if (is_array($inventoryNumbers) && !empty($inventoryNumbers)) {
                            rsort($inventoryNumbers, SORT_NUMERIC);
                            $playSeenSignatures = array();
                            $playSeenCardIds = array();
                            foreach ($inventoryNumbers as $inventoryNum) {
                                $overview = @imap_fetch_overview($inventoryRw, (string)$inventoryNum, 0);
                                $item = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
                                $subject = dlbh_inbox_decode_header_text($item && isset($item->subject) ? (string)$item->subject : '');
                                if (stripos($subject, "Let's Play Bingo! Order Request") === false) continue;
                                $plainBody = dlbh_inbox_get_plain_text_body($inventoryRw, (int)$inventoryNum);
                                $messageFamilyId = dlbh_bingo_extract_family_id_from_text($plainBody);
                                if ($dlbh_play_family_key !== '' && $messageFamilyId !== '' && $messageFamilyId !== $dlbh_play_family_key) continue;
                                if ($dlbh_play_family_key !== '' && $messageFamilyId === '') continue;
                                $htmlBody = dlbh_inbox_get_html_body($inventoryRw, (int)$inventoryNum);
                                if ($htmlBody === '') $htmlBody = (string)$plainBody;
                                $parsedCards = dlbh_bingo_extract_cards_from_order_email_html($htmlBody);
                                foreach ($parsedCards as $parsedCard) {
                                    if (!is_array($parsedCard)) continue;
                                    $sig = $dlbh_card_signature($parsedCard);
                                    if (!is_string($sig) || isset($playSeenSignatures[$sig])) continue;
                                    $playSeenSignatures[$sig] = true;
                                    $parsedId = isset($parsedCard['card_id']) ? preg_replace('/[^0-9]/', '', (string)$parsedCard['card_id']) : '';
                                    if (!is_string($parsedId) || strlen($parsedId) !== 5 || isset($playSeenCardIds[$parsedId])) continue;
                                    $playSeenCardIds[$parsedId] = true;
                                    $dlbh_play_cards[] = $parsedCard;
                                }
                            }
                        }
                        @imap_close($inventoryRw);
                    }
                }
                if ($dlbh_should_prg_redirect) {
                    $redirect_args = array(
                        'dlbh_screen' => 'fundraising_bingo',
                        'dlbh_bingo_tier' => (int)$dlbh_selected_tier,
                    );
                    if ($dlbh_bingo_mode === 'play') {
                        $redirect_args['dlbh_bingo_mode'] = 'play';
                    }
                    if ($dlbh_checkout_view) {
                        $redirect_args['dlbh_bingo_view'] = 'checkout';
                    }
                    if (!empty($_GET['dlbh_verify'])) {
                        $redirect_args['dlbh_verify'] = (string)$_GET['dlbh_verify'];
                    }
                    if ($dlbh_auto_upgrade) {
                        $redirect_args['dlbh_auto_upgrade'] = '1';
                    }
                    if ($dlbh_order_notice_redirect !== '') {
                        $redirect_args['dlbh_order_notice'] = $dlbh_order_notice_redirect;
                    }
                    $redirect_url = add_query_arg(
                        $redirect_args,
                        remove_query_arg(
                            array('dlbh_bingo_action', 'dlbh_tier_key', 'dlbh_upgrade_target', 'dlbh_bingo_generate', 'dlbh_checkout_email', 'dlbh_order_notice', 'dlbh_bingo_mode'),
                            (string)$_SERVER['REQUEST_URI']
                        )
                    );
                    if (function_exists('wp_safe_redirect') && !headers_sent()) {
                        wp_safe_redirect($redirect_url);
                        exit;
                    }
                    echo '<script>window.location.replace(' . wp_json_encode($redirect_url) . ');</script>';
                    return;
                }
                ?>
                <div class="dlbh-details-shell" style="--dlbh-bingo-accent: <?php echo htmlspecialchars(($dlbh_bingo_mode === 'play' ? '#e53935' : $dlbh_bingo_accent), ENT_QUOTES, 'UTF-8'); ?>;">
                    <div class="dlbh-details-head">
                        <span class="dlbh-details-head-title"><?php echo htmlspecialchars($selectedRosterRow ? (string)(isset($selectedRosterRow['Name']) ? $selectedRosterRow['Name'] : 'Bingo') : 'Bingo', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dlbh-details-head-actions">
                            <?php if ($selectedRosterRow): ?>
                                <?php
                                $bingoRosterKey = isset($selectedRosterRow['Member Key']) ? (string)$selectedRosterRow['Member Key'] : '';
                                $bingoRosterDetailFieldsForMenu = isset($selectedRosterRow['Profile Fields']) && is_array($selectedRosterRow['Profile Fields']) ? $selectedRosterRow['Profile Fields'] : array();
                                $bingoProfileSections = array();
                                $bingoProfileSectionOrder = array('Membership', 'Account', 'Billing', 'Payment History', 'Household', 'Contact');
                                $bingoHouseholdSections = array();
                                foreach ($bingoRosterDetailFieldsForMenu as $detailFieldForMenu) {
                                    if (!is_array($detailFieldForMenu)) continue;
                                    $detailTypeForMenu = isset($detailFieldForMenu['type']) ? strtolower(trim((string)$detailFieldForMenu['type'])) : 'field';
                                    if ($detailTypeForMenu !== 'header') continue;
                                    $detailLabelForMenu = trim((string)(isset($detailFieldForMenu['label']) ? $detailFieldForMenu['label'] : ''));
                                    if ($detailLabelForMenu === '') continue;
                                    if (
                                        strcasecmp($detailLabelForMenu, 'Primary Member Information') === 0 ||
                                        strcasecmp($detailLabelForMenu, 'Spouse Information') === 0 ||
                                        preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $detailLabelForMenu)
                                    ) {
                                        $bingoProfileSections['Household'] = 'Household';
                                        $bingoHouseholdSections[] = $detailLabelForMenu;
                                        continue;
                                    }
                                    if (strcasecmp($detailLabelForMenu, 'Membership Information') === 0) {
                                        $bingoProfileSections['Membership'] = 'Membership';
                                        $bingoProfileSections['Account'] = 'Account';
                                        continue;
                                    }
                                    if (strcasecmp($detailLabelForMenu, 'Account Summary Information') === 0) {
                                        $bingoProfileSections['Billing'] = 'Billing';
                                        $bingoProfileSections['Payment History'] = 'Payment History';
                                        continue;
                                    }
                                    if (strcasecmp($detailLabelForMenu, 'Contact Information') === 0) {
                                        $bingoProfileSections['Contact'] = 'Contact';
                                        continue;
                                    }
                                    foreach ($bingoProfileSectionOrder as $orderedSectionLabel) {
                                        if (strcasecmp($detailLabelForMenu, $orderedSectionLabel) === 0) {
                                            $bingoProfileSections[$orderedSectionLabel] = $orderedSectionLabel;
                                            break;
                                        }
                                    }
                                }
                                $bingoCurrentStatementOffset = dlbh_inbox_get_current_statement_offset(
                                    $bingoRosterDetailFieldsForMenu,
                                    (string)(isset($selectedRosterRow['Commencement Date']) ? $selectedRosterRow['Commencement Date'] : '')
                                );
                                $bingoEffectiveHouseholdMemberOffset = $householdMemberOffset;
                                if ($bingoEffectiveHouseholdMemberOffset >= count($bingoHouseholdSections)) {
                                    $bingoEffectiveHouseholdMemberOffset = max(0, count($bingoHouseholdSections) - 1);
                                }
                                ?>
                                <div class="dlbh-head-menu" style="margin-left:0;">
                                    <button type="button" class="dlbh-inbox-head-link dlbh-head-menu-toggle" id="dlbh-bingo-profile-menu-toggle" aria-haspopup="true" aria-expanded="false" onclick="(function(btn){var panel=document.getElementById('dlbh-bingo-profile-menu-panel');var overlay=document.getElementById('dlbh-bingo-profile-menu-overlay');if(!panel||!overlay)return false;var isHidden=panel.hasAttribute('hidden');if(isHidden){panel.removeAttribute('hidden');overlay.removeAttribute('hidden');btn.setAttribute('aria-expanded','true');}else{panel.setAttribute('hidden','hidden');overlay.setAttribute('hidden','hidden');btn.setAttribute('aria-expanded','false');}return false;})(this); return false;">Profile</button>
                                    <div class="dlbh-head-menu-overlay" id="dlbh-bingo-profile-menu-overlay" hidden onclick="(function(){var panel=document.getElementById('dlbh-bingo-profile-menu-panel');var overlay=document.getElementById('dlbh-bingo-profile-menu-overlay');var btn=document.getElementById('dlbh-bingo-profile-menu-toggle');if(panel)panel.setAttribute('hidden','hidden');if(overlay)overlay.setAttribute('hidden','hidden');if(btn)btn.setAttribute('aria-expanded','false');})(); return false;"></div>
                                    <div class="dlbh-head-menu-panel" id="dlbh-bingo-profile-menu-panel" hidden>
                                        <?php foreach ($bingoProfileSectionOrder as $bingoSectionLabel): ?>
                                            <?php if (!isset($bingoProfileSections[$bingoSectionLabel])) continue; ?>
                                            <?php
                                            $bingoProfileSectionUrl = '';
                                            if ($rosterScreenUrl !== '' && $bingoRosterKey !== '') {
                                                $parts = parse_url($rosterScreenUrl);
                                                $path = isset($parts['path']) ? (string)$parts['path'] : $rosterScreenUrl;
                                                $query = array();
                                                if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
                                                $query['dlbh_screen'] = 'roster';
                                                $query['dlbh_roster_member'] = $bingoRosterKey;
                                                $query['dlbh_profile_section'] = $bingoSectionLabel;
                                                unset($query['dlbh_statement_offset']);
                                                if ($bingoSectionLabel === 'Billing' && $bingoCurrentStatementOffset > 0) {
                                                    $query['dlbh_statement_offset'] = $bingoCurrentStatementOffset;
                                                } elseif ($bingoSectionLabel !== 'Billing' && $statementOffset > 0) {
                                                    $query['dlbh_statement_offset'] = $statementOffset;
                                                }
                                                if ($bingoSectionLabel === 'Household' && $bingoEffectiveHouseholdMemberOffset > 0) {
                                                    $query['dlbh_household_offset'] = $bingoEffectiveHouseholdMemberOffset;
                                                }
                                                unset($query['dlbh_profile_compose']);
                                                $bingoProfileSectionUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
                                            }
                                            ?>
                                            <a href="<?php echo htmlspecialchars($bingoProfileSectionUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item" style="text-decoration:none;box-sizing:border-box;"><?php echo htmlspecialchars($bingoSectionLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                                            <?php if ($bingoSectionLabel === 'Billing'): ?>
                                                <a href="https://billing.stripe.com/p/login/7sY4gz9i555GeKO8N15Vu00" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;" target="_blank" rel="noopener noreferrer">Customer Portal</a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <span class="dlbh-head-menu-label">Fundraising</span>
                                        <a href="<?php echo htmlspecialchars($fundraisingBingoUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Bingo</a>
                                        <a href="<?php echo htmlspecialchars($fundraisingBingoPlayUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-head-menu-item dlbh-head-menu-item-sub" style="text-decoration:none;box-sizing:border-box;">Play</a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($rosterScreenUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-head-link">Profile</a>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if ($dlbh_bingo_mode === 'play'): ?>
                            <?php if ($dlbh_play_moved_count > 0): ?>
                                <div class="dlbh-compose-status success" style="margin-bottom:10px;"><?php echo (int)$dlbh_play_moved_count; ?> order email(s) moved to Bingo/Inventory.</div>
                            <?php endif; ?>
                            <?php
                            $dlbh_play_codes = array();
                            foreach ($dlbh_play_cards as $dlbh_play_card_for_code) {
                                if (!is_array($dlbh_play_card_for_code)) continue;
                                $pcid = isset($dlbh_play_card_for_code['card_id']) ? preg_replace('/[^0-9]/', '', (string)$dlbh_play_card_for_code['card_id']) : '';
                                if (is_string($pcid) && strlen($pcid) === 5) $dlbh_play_codes[] = $pcid;
                            }
                            $dlbh_play_access_code = $dlbh_compute_access_code_from_card_codes($dlbh_play_codes);
                            $dlbh_play_total_pages = max(1, (int)ceil(((int)count($dlbh_play_cards)) / 4));
                            $dlbh_play_page = isset($_GET['dlbh_play_page']) ? (int)$_GET['dlbh_play_page'] : 1;
                            if ($dlbh_play_page < 1) $dlbh_play_page = 1;
                            if ($dlbh_play_page > $dlbh_play_total_pages) $dlbh_play_page = $dlbh_play_total_pages;
                            $dlbh_play_offset = ($dlbh_play_page - 1) * 4;
                            $dlbh_play_cards_page = array_slice($dlbh_play_cards, $dlbh_play_offset, 4);
                            $dlbh_play_prev_page = max(1, $dlbh_play_page - 1);
                            $dlbh_play_next_page = min($dlbh_play_total_pages, $dlbh_play_page + 1);
                            $dlbh_play_parts = parse_url((string)$_SERVER['REQUEST_URI']);
                            $dlbh_play_path = isset($dlbh_play_parts['path']) ? (string)$dlbh_play_parts['path'] : (string)$_SERVER['REQUEST_URI'];
                            $dlbh_play_query = array();
                            if (isset($dlbh_play_parts['query'])) parse_str((string)$dlbh_play_parts['query'], $dlbh_play_query);
                            $dlbh_play_query['dlbh_screen'] = 'fundraising_bingo';
                            $dlbh_play_query['dlbh_bingo_mode'] = 'play';
                            unset($dlbh_play_query['dlbh_bingo_view']);
                            $dlbh_play_prev_query = $dlbh_play_query;
                            $dlbh_play_prev_query['dlbh_play_page'] = $dlbh_play_prev_page;
                            $dlbh_play_next_query = $dlbh_play_query;
                            $dlbh_play_next_query['dlbh_play_page'] = $dlbh_play_next_page;
                            $dlbh_play_prev_url = $dlbh_play_path . (!empty($dlbh_play_prev_query) ? ('?' . http_build_query($dlbh_play_prev_query)) : '');
                            $dlbh_play_next_url = $dlbh_play_path . (!empty($dlbh_play_next_query) ? ('?' . http_build_query($dlbh_play_next_query)) : '');
                            ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
                                <div class="dlbh-inbox-field-value" style="font-weight:700;">Play</div>
                                <div class="dlbh-inbox-field-value" style="font-weight:700;">Access Code: <?php echo htmlspecialchars((string)$dlbh_play_access_code, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div style="display:flex;justify-content:center;align-items:center;margin:4px 0 14px 0;">
                                <div id="dlbh-live-call-pill" class="dlbh-inbox-field-value" style="font-weight:700;font-size:1.25rem;padding:8px 14px;border:1px solid #d1d5db;border-radius:8px;background:#ffffff;">Current Call: --</div>
                            </div>
                            <div style="display:flex;justify-content:center;align-items:center;margin:0 0 12px 0;">
                                <a href="https://dewitt-steward.github.io/Bingo/" target="lpb_caller_window" class="dlbh-inbox-btn" style="text-decoration:none;">Open Live Caller</a>
                            </div>
                            <?php if (empty($dlbh_play_cards)): ?>
                                <div class="dlbh-inbox-field-value">No bingo cards found for this family in Bingo/Inventory.</div>
                            <?php else: ?>
                                <div class="dlbh-bingo-carousel">
                                    <a href="<?php echo htmlspecialchars($dlbh_play_prev_url, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-bingo-arrow" aria-label="Previous cards" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;<?php echo ($dlbh_play_page <= 1 ? 'opacity:.35;pointer-events:none;' : ''); ?>">&#8592;</a>
                                    <div class="dlbh-bingo-carousel-viewport" style="overflow:hidden;">
                                        <div class="dlbh-bingo-page" style="width:100%;min-width:100%;max-width:100%;">
                                            <?php foreach ($dlbh_play_cards_page as $card): ?>
                                                <?php
                                                $cardId = isset($card['card_id']) ? preg_replace('/[^0-9]/', '', (string)$card['card_id']) : '';
                                                if (!is_string($cardId) || strlen($cardId) !== 5) $cardId = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                                                $cols = array($card['B'], $card['I'], $card['N'], $card['G'], $card['O']);
                                                ?>
                                                <div class="dlbh-bingo-card-shell">
                                                    <div class="dlbh-bingo-card-title"><?php echo htmlspecialchars($cardId, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <div class="dlbh-bingo-card-wrap">
                                                        <table class="dlbh-bingo-card-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>B</th>
                                                                    <th>I</th>
                                                                    <th>N</th>
                                                                    <th>G</th>
                                                                    <th>O</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php for ($r = 0; $r < 5; $r++): ?>
                                                                    <tr>
                                                                        <?php for ($c = 0; $c < 5; $c++): ?>
                                                                            <?php if ($r === 2 && $c === 2): ?>
                                                                                <td class="dlbh-bingo-card-cell is-free">FREE</td>
                                                                            <?php else: ?>
                                                                                <td class="dlbh-bingo-card-cell"><?php echo (int)$cols[$c][$r]; ?></td>
                                                                            <?php endif; ?>
                                                                        <?php endfor; ?>
                                                                    </tr>
                                                                <?php endfor; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <a href="<?php echo htmlspecialchars($dlbh_play_next_url, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-bingo-arrow" aria-label="Next cards" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;<?php echo ($dlbh_play_page >= $dlbh_play_total_pages ? 'opacity:.35;pointer-events:none;' : ''); ?>">&#8594;</a>
                                </div>
                                <div class="dlbh-bingo-page-indicator dlbh-inbox-field-value" style="text-align:center;margin-top:8px;font-weight:700;">Page <?php echo (int)$dlbh_play_page; ?> of <?php echo (int)$dlbh_play_total_pages; ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                        <?php if ($dlbh_checkout_view && $dlbh_order_notice === 'sent'): ?>
                            <div class="dlbh-compose-status success" style="margin-bottom:10px;">Order page sent to office@dlbhfamily.com.</div>
                        <?php elseif ($dlbh_checkout_view && $dlbh_order_notice === 'failed'): ?>
                            <div class="dlbh-compose-status error" style="margin-bottom:10px;">Order email failed to send. Please try again.</div>
                        <?php endif; ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
                            <div class="dlbh-inbox-field-value" style="font-weight:700;">Choose Your Play. Claim Your Cards. Get Ready for All 6 Games.</div>
                        </div>
                        <?php if (!$dlbh_checkout_view): ?>
                        <div id="dlbh-bingo-pricing-panel" class="dlbh-bingo-pricing">
                            <div class="dlbh-bingo-pricing-list">
                                <div class="dlbh-bingo-pricing-item <?php echo htmlspecialchars((string)$dlbh_active_package['class'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <h4><?php echo htmlspecialchars((string)$dlbh_active_package['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                    <p class="dlbh-bingo-pricing-price"><?php echo htmlspecialchars((string)$dlbh_active_package['price'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p><?php echo htmlspecialchars((string)$dlbh_active_package['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="dlbh-bingo-pricing-minimum"><?php echo htmlspecialchars((string)$dlbh_active_package['minimum'], ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php
                        $dlbh_upgrade_options = array();
                        $dlbh_has_platinum_in_cart = isset($dlbh_cart_by_id['24']) && (int)(isset($dlbh_cart_by_id['24']['books']) ? $dlbh_cart_by_id['24']['books'] : 0) > 0;
                        if (!$dlbh_checkout_view && (int)$dlbh_cart_count > 0 && (int)$dlbh_selected_tier < 24 && !$dlbh_has_platinum_in_cart) {
                            $dlbh_current_total_cards = $dlbh_cart_total_cards($dlbh_cart_by_id);
                            $dlbh_current_total_amount = $dlbh_cart_total_amount($dlbh_cart_by_id);
                            $bronzeRate = isset($dlbh_pricing_packages[6]['amount']) ? ((float)$dlbh_pricing_packages[6]['amount'] / 6.0) : 0.0;
                            foreach ($dlbh_tier_options as $offerTier => $offerLabel) {
                                $offerTier = (int)$offerTier;
                                if ($offerTier <= (int)$dlbh_selected_tier) continue;
                                $offerTotalCards = max($dlbh_current_total_cards, $offerTier);
                                $rebuiltOfferCart = $dlbh_rebuild_cart_from_total_cards($offerTotalCards, $offerTier);
                                $offerAmount = $dlbh_cart_total_amount($rebuiltOfferCart);
                                $offerMix = $dlbh_cart_mix_label($rebuiltOfferCart);
                                $additionalCards = max(0, $offerTotalCards - $dlbh_current_total_cards);
                                $costAtBronzeRate = $offerTotalCards > 0 ? ($bronzeRate * (float)$offerTotalCards) : 0.0;
                                $savingsAmount = $costAtBronzeRate - (float)$offerAmount;
                                if ($savingsAmount < 0) $savingsAmount = 0.0;
                                $savingsPct = ($costAtBronzeRate > 0) ? (100.0 * ($savingsAmount / $costAtBronzeRate)) : 0.0;
                                if ($savingsPct < 0) $savingsPct = 0.0;
                                $deltaPrice = (float)$offerAmount - (float)$dlbh_current_total_amount;
                                if ($deltaPrice < 0) $deltaPrice = 0.0;
                            $detailText = 'Step into ' . (string)$offerMix
                                . ', add ' . (int)$additionalCards . ' more cards'
                                . ', save $' . number_format($savingsAmount, 2, '.', '')
                                . ', and lock in ' . rtrim(rtrim(number_format($savingsPct, 1, '.', ''), '0'), '.') . '% savings off the Bronze card rate.'
                                . ' Additional payment: $' . number_format($deltaPrice, 2, '.', '') . '.';
                                $dlbh_upgrade_options[] = array(
                                    'tier' => $offerTier,
                                    'class' => isset($dlbh_pricing_packages[$offerTier]['class']) ? (string)$dlbh_pricing_packages[$offerTier]['class'] : '',
                                    'label' => 'Upgrade Offer',
                                    'text' => (string)$detailText,
                                    'delta_price' => (float)$deltaPrice,
                                    'savings_amount' => (float)$savingsAmount,
                                );
                            }
                            $dlbh_zero_pay_offers = array();
                            foreach ($dlbh_upgrade_options as $zidx => $zoffer) {
                                $zdelta = isset($zoffer['delta_price']) ? (float)$zoffer['delta_price'] : 0.0;
                                if (abs($zdelta) < 0.00001) $dlbh_zero_pay_offers[] = $zidx;
                            }
                            if (count($dlbh_zero_pay_offers) > 1) {
                                $bestIdx = $dlbh_zero_pay_offers[0];
                                $bestSavings = isset($dlbh_upgrade_options[$bestIdx]['savings_amount']) ? (float)$dlbh_upgrade_options[$bestIdx]['savings_amount'] : 0.0;
                                foreach ($dlbh_zero_pay_offers as $zidx) {
                                    $curSavings = isset($dlbh_upgrade_options[$zidx]['savings_amount']) ? (float)$dlbh_upgrade_options[$zidx]['savings_amount'] : 0.0;
                                    if ($curSavings > $bestSavings) {
                                        $bestSavings = $curSavings;
                                        $bestIdx = $zidx;
                                    }
                                }
                                $dlbh_upgrade_options = array($dlbh_upgrade_options[$bestIdx]);
                            }
                        }
                        ?>
                        <?php if ($dlbh_cards_ui_enabled && (int)$dlbh_visible_cards_target > 0): ?>
                            <div class="dlbh-bingo-carousel" data-page="0">
                                <button type="button" class="dlbh-bingo-arrow" data-bingo-dir="-1" aria-label="Previous cards">&#8592;</button>
                                <div class="dlbh-bingo-carousel-viewport" id="dlbh-bingo-viewport">
                                    <div class="dlbh-bingo-carousel-rail" id="dlbh-bingo-rail">
                                        <?php
                                        foreach ($dlbh_cards as $index => $card):
                                            $cardId = isset($card['card_id']) ? preg_replace('/[^0-9]/', '', (string)$card['card_id']) : '';
                                            if (!is_string($cardId) || strlen($cardId) !== 5) $cardId = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                                            $cols = array($card['B'], $card['I'], $card['N'], $card['G'], $card['O']);
                                            if ($index % 4 === 0) echo '<div class="dlbh-bingo-page">';
                                        ?>
                                            <div class="dlbh-bingo-card-shell">
                                                <div class="dlbh-bingo-card-title"><?php echo htmlspecialchars($cardId, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="dlbh-bingo-card-wrap">
                                                    <table class="dlbh-bingo-card-table">
                                                        <thead>
                                                            <tr>
                                                                <th>B</th>
                                                                <th>I</th>
                                                                <th>N</th>
                                                                <th>G</th>
                                                                <th>O</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php for ($r = 0; $r < 5; $r++): ?>
                                                                <tr>
                                                                    <?php for ($c = 0; $c < 5; $c++): ?>
                                                                        <?php if ($r === 2 && $c === 2): ?>
                                                                            <td class="dlbh-bingo-card-cell is-free">FREE</td>
                                                                        <?php else: ?>
                                                                            <td class="dlbh-bingo-card-cell"><?php echo (int)$cols[$c][$r]; ?></td>
                                                                        <?php endif; ?>
                                                                    <?php endfor; ?>
                                                                </tr>
                                                            <?php endfor; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php
                                            if ($index % 4 === 3 || $index === (count($dlbh_cards) - 1)) echo '</div>';
                                        endforeach;
                                        ?>
                                    </div>
                                </div>
                                <button type="button" class="dlbh-bingo-arrow" data-bingo-dir="1" aria-label="Next cards">&#8594;</button>
                            </div>
                            <div class="dlbh-bingo-page-indicator dlbh-inbox-field-value" style="text-align:center;margin-top:8px;font-weight:700;">Page 1 of <?php echo (int)max(1, (int)ceil(count($dlbh_cards) / 4)); ?></div>
                        <?php elseif ($dlbh_cards_ui_enabled): ?>
                            <div class="dlbh-inbox-field-value" style="margin:8px 0 0 0;">No cards loaded yet. Add this tier to cart to load cards.</div>
                        <?php endif; ?>
                        <?php if (!$dlbh_checkout_view && (int)$dlbh_cart_count === 0): ?>
                            <div class="dlbh-bingo-deck-actions">
                                <form method="get" style="margin:0;">
                                    <input type="hidden" name="dlbh_screen" value="fundraising_bingo">
                                    <input type="hidden" name="dlbh_bingo_tier" value="<?php echo (int)$dlbh_selected_tier; ?>">
                                    <?php if (!empty($_GET['dlbh_verify'])): ?>
                                        <input type="hidden" name="dlbh_verify" value="<?php echo htmlspecialchars((string)$_GET['dlbh_verify'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php endif; ?>
                                    <?php if ($dlbh_auto_upgrade): ?>
                                        <input type="hidden" name="dlbh_auto_upgrade" value="1">
                                    <?php endif; ?>
                                    <input type="hidden" name="dlbh_bingo_action" value="add_deck">
                                    <button type="submit" class="dlbh-inbox-btn">Add to cart</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <div class="dlbh-bingo-cart">
                            <div class="dlbh-bingo-cart-head">
                                <span class="dlbh-bingo-cart-title">CART</span>
                                <div class="dlbh-bingo-cart-head-actions">
                                    <span class="dlbh-bingo-cart-total">Items: <?php echo (int)$dlbh_cart_count; ?></span>
                                    <?php if ($dlbh_checkout_view): ?>
                                        <form method="get" style="margin:0;">
                                            <input type="hidden" name="dlbh_screen" value="fundraising_bingo">
                                            <input type="hidden" name="dlbh_bingo_tier" value="<?php echo (int)$dlbh_selected_tier; ?>">
                                            <?php if (!empty($_GET['dlbh_verify'])): ?>
                                                <input type="hidden" name="dlbh_verify" value="<?php echo htmlspecialchars((string)$_GET['dlbh_verify'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php endif; ?>
                                            <?php if ($dlbh_auto_upgrade): ?>
                                                <input type="hidden" name="dlbh_auto_upgrade" value="1">
                                            <?php endif; ?>
                                            <button type="submit" class="dlbh-inbox-btn">Back</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!empty($dlbh_upgrade_options)): ?>
                                        <button type="submit" form="dlbh-bingo-upgrade-form" class="dlbh-inbox-btn">Upgrade</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!$dlbh_checkout_view && !empty($dlbh_upgrade_options)): ?>
                                <div class="dlbh-bingo-upgrades">
                                    <p class="dlbh-bingo-upgrades-title">The floor is now extending premium offers.</p>
                                    <form method="get" id="dlbh-bingo-upgrade-form" style="margin:0;">
                                        <input type="hidden" name="dlbh_screen" value="fundraising_bingo">
                                        <input type="hidden" name="dlbh_bingo_tier" value="<?php echo (int)$dlbh_selected_tier; ?>">
                                        <?php if (!empty($_GET['dlbh_verify'])): ?>
                                            <input type="hidden" name="dlbh_verify" value="<?php echo htmlspecialchars((string)$_GET['dlbh_verify'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php endif; ?>
                                        <?php if ($dlbh_auto_upgrade): ?>
                                            <input type="hidden" name="dlbh_auto_upgrade" value="1">
                                        <?php endif; ?>
                                        <input type="hidden" name="dlbh_bingo_action" value="upgrade_offer">
                                        <?php foreach ($dlbh_upgrade_options as $idx => $uo): ?>
                                            <label class="dlbh-bingo-upgrade-option <?php echo htmlspecialchars((string)(isset($uo['class']) ? $uo['class'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="radio" name="dlbh_upgrade_target" value="<?php echo (int)$uo['tier']; ?>" <?php echo $idx === 0 ? 'checked' : ''; ?>>
                                                <div class="dlbh-bingo-upgrade-copy">
                                                    <strong><?php echo htmlspecialchars((string)$uo['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <span><?php echo htmlspecialchars((string)$uo['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </form>
                                </div>
                            <?php endif; ?>
                            <?php if (empty($dlbh_cart_items)): ?>
                                <div class="dlbh-bingo-cart-empty">No items in cart yet.</div>
                            <?php else: ?>
                                <?php if ($dlbh_checkout_view): ?>
                                    <div class="dlbh-bingo-cart-item" style="margin-bottom:10px;">
                                        <div class="dlbh-bingo-cart-fields">
                                            <div class="dlbh-bingo-cart-field">
                                                <span class="dlbh-bingo-cart-field-label">Primary Member</span>
                                                <span class="dlbh-bingo-cart-field-value"><?php echo htmlspecialchars((string)$dlbh_checkout_primary_member, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <div class="dlbh-bingo-cart-field">
                                                <span class="dlbh-bingo-cart-field-label">Family ID</span>
                                                <span class="dlbh-bingo-cart-field-value"><?php echo htmlspecialchars((string)$dlbh_checkout_family_id, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <div class="dlbh-bingo-cart-field">
                                                <span class="dlbh-bingo-cart-field-label">Email</span>
                                                <form method="post" style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                                    <input type="hidden" name="dlbh_bingo_action" value="set_checkout_email">
                                                    <input class="dlbh-wpf-input" type="email" name="dlbh_checkout_email" value="<?php echo htmlspecialchars((string)$dlbh_checkout_email, ENT_QUOTES, 'UTF-8'); ?>" style="max-width:280px;">
                                                    <button type="submit" class="dlbh-inbox-btn">Update</button>
                                                </form>
                                            </div>
                                            <div class="dlbh-bingo-cart-field">
                                                <span class="dlbh-bingo-cart-field-label">Phone</span>
                                                <span class="dlbh-bingo-cart-field-value"><?php echo htmlspecialchars((string)$dlbh_checkout_phone, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="dlbh-bingo-cart-list">
                                    <?php foreach ($dlbh_cart_items as $cart_index => $cart_item): ?>
                                        <div class="dlbh-bingo-cart-item">
                                            <div class="dlbh-bingo-cart-fields">
                                                <?php
                                                $rowTier = isset($cart_item['tier']) ? (string)$cart_item['tier'] : '';
                                                $rowQty = (int)(isset($cart_item['books']) ? $cart_item['books'] : 0);
                                                if ($rowQty < 1) $rowQty = 1;
                                                $rowCardsPerBook = (int)(isset($cart_item['cards_per_book']) ? $cart_item['cards_per_book'] : 0);
                                                if ($rowCardsPerBook < 1 && isset($cart_item['tier_key'])) $rowCardsPerBook = (int)$cart_item['tier_key'];
                                                $rowTotalCards = (int)(isset($cart_item['total_cards']) ? $cart_item['total_cards'] : ($rowCardsPerBook * $rowQty));
                                                $rowMinimumPerGame = (int)floor($rowCardsPerBook / 6) * $rowQty;
                                                if ($rowMinimumPerGame < 1) $rowMinimumPerGame = $rowQty;
                                                $rowSubtotal = (float)(isset($cart_item['amount']) ? $cart_item['amount'] : 0);
                                                $rowUnit = $rowQty > 0 ? ($rowSubtotal / $rowQty) : 0;
                                                ?>
                                                <div class="dlbh-bingo-cart-field">
                                                    <span class="dlbh-bingo-cart-field-label">Tier</span>
                                                    <span class="dlbh-bingo-cart-field-value"><?php echo htmlspecialchars($rowTier, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                                <div class="dlbh-bingo-cart-field">
                                                    <span class="dlbh-bingo-cart-field-label">Minimum</span>
                                                    <span class="dlbh-bingo-cart-field-value"><?php echo (int)$rowMinimumPerGame; ?> Cards Per Game</span>
                                                </div>
                                                <div class="dlbh-bingo-cart-field">
                                                    <span class="dlbh-bingo-cart-field-label">Total Cards</span>
                                                    <span class="dlbh-bingo-cart-field-value"><?php echo (int)$rowTotalCards; ?></span>
                                                </div>
                                                <div class="dlbh-bingo-cart-field">
                                                    <span class="dlbh-bingo-cart-field-label">Quantity</span>
                                                    <span class="dlbh-bingo-cart-field-value"><?php echo (int)$rowQty; ?></span>
                                                </div>
                                                <div class="dlbh-bingo-cart-field">
                                                    <span class="dlbh-bingo-cart-field-label">Price</span>
                                                    <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($rowUnit, 2), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php if (!$dlbh_checkout_view): ?>
                                                    <div class="dlbh-bingo-qty-controls" style="margin-top:6px;">
                                                        <form method="get" style="margin:0;">
                                                            <input type="hidden" name="dlbh_screen" value="fundraising_bingo">
                                                            <input type="hidden" name="dlbh_bingo_tier" value="<?php echo (int)$dlbh_selected_tier; ?>">
                                                            <?php if (!empty($_GET['dlbh_verify'])): ?>
                                                                <input type="hidden" name="dlbh_verify" value="<?php echo htmlspecialchars((string)$_GET['dlbh_verify'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php endif; ?>
                                                            <?php if ($dlbh_auto_upgrade): ?>
                                                                <input type="hidden" name="dlbh_auto_upgrade" value="1">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="dlbh_bingo_action" value="decrement_deck">
                                                            <input type="hidden" name="dlbh_tier_key" value="<?php echo htmlspecialchars((string)(isset($cart_item['tier_key']) ? $cart_item['tier_key'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <button type="submit" class="dlbh-inbox-btn dlbh-bingo-qty-btn">-</button>
                                                        </form>
                                                        <span class="dlbh-bingo-qty-count"><?php echo (int)(isset($cart_item['books']) ? $cart_item['books'] : 0); ?></span>
                                                        <form method="get" style="margin:0;">
                                                            <input type="hidden" name="dlbh_screen" value="fundraising_bingo">
                                                            <input type="hidden" name="dlbh_bingo_tier" value="<?php echo (int)(isset($cart_item['tier_key']) ? $cart_item['tier_key'] : $dlbh_selected_tier); ?>">
                                                            <?php if (!empty($_GET['dlbh_verify'])): ?>
                                                                <input type="hidden" name="dlbh_verify" value="<?php echo htmlspecialchars((string)$_GET['dlbh_verify'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php endif; ?>
                                                            <?php if ($dlbh_auto_upgrade): ?>
                                                                <input type="hidden" name="dlbh_auto_upgrade" value="1">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="dlbh_bingo_action" value="add_deck">
                                                            <input type="hidden" name="dlbh_tier_key" value="<?php echo htmlspecialchars((string)(isset($cart_item['tier_key']) ? $cart_item['tier_key'] : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <button type="submit" class="dlbh-inbox-btn dlbh-bingo-qty-btn">+</button>
                                                        </form>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="dlbh-bingo-cart-field">
                                                    <span class="dlbh-bingo-cart-field-label">Subtotal</span>
                                                    <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($rowSubtotal, 2), ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php
                            $dlbh_items_amount = 0.0;
                            foreach ($dlbh_cart_items as $sum_item) {
                                if (!is_array($sum_item)) continue;
                                $dlbh_items_amount += (float)(isset($sum_item['amount']) ? $sum_item['amount'] : 0);
                            }
                            $dlbh_shipping = 0.0;
                            $dlbh_free_shipping = 0.0;
                            $dlbh_est_tax = 0.0;
                            $dlbh_bronze_subtotal = isset($dlbh_cart_by_id['6']['amount']) ? (float)$dlbh_cart_by_id['6']['amount'] : 0.0;
                            $dlbh_silver_subtotal = isset($dlbh_cart_by_id['12']['amount']) ? (float)$dlbh_cart_by_id['12']['amount'] : 0.0;
                            $dlbh_gold_subtotal = isset($dlbh_cart_by_id['18']['amount']) ? (float)$dlbh_cart_by_id['18']['amount'] : 0.0;
                            $dlbh_platinum_subtotal = isset($dlbh_cart_by_id['24']['amount']) ? (float)$dlbh_cart_by_id['24']['amount'] : 0.0;
                            $dlbh_total_cards_all = 0;
                            foreach ($dlbh_cart_items as $sum_item_cards) {
                                if (!is_array($sum_item_cards)) continue;
                                $dlbh_total_cards_all += (int)(isset($sum_item_cards['total_cards']) ? $sum_item_cards['total_cards'] : 0);
                            }
                            $dlbh_bronze_rate = isset($dlbh_pricing_packages[6]['amount']) ? ((float)$dlbh_pricing_packages[6]['amount'] / 6.0) : 0.0;
                            $dlbh_bronze_equivalent = $dlbh_bronze_rate * (float)$dlbh_total_cards_all;
                            $dlbh_discount_amount = $dlbh_bronze_equivalent - $dlbh_items_amount;
                            if ($dlbh_discount_amount < 0) $dlbh_discount_amount = 0.0;
                            $dlbh_order_total = $dlbh_items_amount + $dlbh_shipping + $dlbh_est_tax - $dlbh_free_shipping;
                            $dlbh_total_amount = $dlbh_order_total + $dlbh_discount_amount;
                            ?>
                            <?php if ($dlbh_checkout_view): ?>
                            <div class="dlbh-bingo-cart-item" style="margin-top:8px;padding-top:12px;border-top:1px solid #e5e7eb;">
                                <div class="dlbh-bingo-cart-fields">
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Items (<?php echo (int)$dlbh_cart_count; ?>)</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_items_amount, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Shipping &amp; handling</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_shipping, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Free Shipping</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_free_shipping, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Estimated tax to be collected</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_est_tax, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Bronze Subtotal</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_bronze_subtotal, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Siver Subtotal</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_silver_subtotal, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Gold Subtotal</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_gold_subtotal, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Platinum Subtotal</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_platinum_subtotal, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Discount</span>
                                        <span class="dlbh-bingo-cart-field-value">-$<?php echo htmlspecialchars(number_format($dlbh_discount_amount, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Total</span>
                                        <span class="dlbh-bingo-cart-field-value" style="font-weight:700;">$<?php echo htmlspecialchars(number_format($dlbh_total_amount, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="dlbh-bingo-cart-field">
                                        <span class="dlbh-bingo-cart-field-label">Order Total</span>
                                        <span class="dlbh-bingo-cart-field-value">$<?php echo htmlspecialchars(number_format($dlbh_order_total, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="margin-top:12px;display:flex;justify-content:center;">
                                <form method="get" style="margin:0;">
                                    <input type="hidden" name="dlbh_screen" value="fundraising_bingo">
                                    <input type="hidden" name="dlbh_bingo_tier" value="<?php echo (int)$dlbh_selected_tier; ?>">
                                    <input type="hidden" name="dlbh_bingo_view" value="checkout">
                                    <?php if (!empty($_GET['dlbh_verify'])): ?>
                                        <input type="hidden" name="dlbh_verify" value="<?php echo htmlspecialchars((string)$_GET['dlbh_verify'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php endif; ?>
                                    <?php if ($dlbh_auto_upgrade): ?>
                                        <input type="hidden" name="dlbh_auto_upgrade" value="1">
                                    <?php endif; ?>
                                    <button type="submit" class="dlbh-inbox-btn" <?php echo (int)$dlbh_cart_count <= 0 ? 'disabled' : ''; ?>>Proceed to Checkout (<?php echo (int)$dlbh_cart_count; ?> items)</button>
                                </form>
                            </div>
                            <?php endif; ?>
                            <?php if ($dlbh_checkout_view): ?>
                            <div style="margin-top:12px;display:flex;justify-content:center;">
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="dlbh_bingo_action" value="place_order">
                                    <button type="submit" class="dlbh-inbox-btn" <?php echo (int)$dlbh_cart_count <= 0 ? 'disabled' : ''; ?>>Place your order</button>
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <table class="dlbh-inbox-table">
                    <tr>
                        <th>From</th>
                        <th>Subject</th>
                        <th>Received</th>
                    </tr>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td class="dlbh-inbox-empty" colspan="3">No matching emails found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $idx => $row): ?>
                            <tr class="<?php echo ((int)$idx === (int)$selectedEmailIdx ? 'is-selected' : ''); ?>">
                                <td>
                                    <?php $rowFromLabel = trim((string)$row['from']) !== '' ? (string)$row['from'] : 'View Email'; ?>
                                    <form method="get" style="margin:0;">
                                        <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$idx; ?>">
                                        <input type="hidden" name="dlbh_verify" value="1">
                                        <button type="submit" class="dlbh-row-btn"><?php echo htmlspecialchars($rowFromLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                                    </form>
                                </td>
                                <td>
                                    <form method="get" style="margin:0;">
                                        <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$idx; ?>">
                                        <input type="hidden" name="dlbh_verify" value="1">
                                        <button type="submit" class="dlbh-row-btn"><?php echo htmlspecialchars((string)$row['subject'], ENT_QUOTES, 'UTF-8'); ?></button>
                                    </form>
                                </td>
                                <td class="dlbh-inbox-row-actions">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                        <span><?php echo htmlspecialchars((string)$row['received'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ($trashConfirmIdx === (int)$idx): ?>
                                            <span class="dlbh-trash-confirm">
                                                <span>Are you sure?</span>
                                                <form method="post" style="margin:0;">
                                                    <input type="hidden" name="dlbh_inbox_action" value="trash_email">
                                                    <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$idx; ?>">
                                                    <input type="hidden" name="dlbh_msg_num" value="<?php echo (int)(isset($row['msg_num']) ? $row['msg_num'] : 0); ?>">
                                                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                                                    <button type="submit" class="dlbh-trash-confirm-btn confirm">Yes</button>
                                                </form>
                                                <form method="get" style="margin:0;">
                                                    <?php if ((int)$selectedEmailIdx >= 0): ?>
                                                        <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$selectedEmailIdx; ?>">
                                                    <?php endif; ?>
                                                    <button type="submit" class="dlbh-trash-confirm-btn cancel">No</button>
                                                </form>
                                            </span>
                                        <?php else: ?>
                                            <form method="get" style="margin:0;">
                                                <?php if ((int)$selectedEmailIdx >= 0): ?>
                                                    <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$selectedEmailIdx; ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="dlbh_trash_confirm" value="<?php echo (int)$idx; ?>">
                                                <button type="submit" class="dlbh-trash-btn" aria-label="Move to Trash" title="Move to Trash">&#128465;</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

                <div class="dlbh-details-shell">
                    <?php
                    $headerVerification = $verification;
                    if ($editModeRequested && $selectedEmailOriginal && isset($selectedEmailOriginal['fields']) && is_array($selectedEmailOriginal['fields'])) {
                        $headerVerification = dlbh_inbox_evaluate_eligibility($selectedEmailOriginal['fields']);
                    }
                    $detailsHeadClass = 'dlbh-details-head';
                    if (is_array($headerVerification) && !empty($headerVerification['eligible'])) {
                        $detailsHeadClass .= ' is-eligible';
                    } elseif (is_array($headerVerification) && empty($headerVerification['eligible'])) {
                        $detailsHeadClass .= ' is-ineligible';
                    }
                    ?>
                    <div class="<?php echo htmlspecialchars($detailsHeadClass, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php
                        $detailsTitle = ($selectedEmail && isset($selectedEmail['from']) && trim((string)$selectedEmail['from']) !== '')
                            ? (string)$selectedEmail['from']
                            : 'Email Details';
                        ?>
                        <?php
                        $detailsTitleClass = 'dlbh-details-head-title';
                        if (is_array($headerVerification) && !empty($headerVerification['eligible'])) {
                            $detailsTitleClass .= ' is-eligible';
                        } elseif (is_array($headerVerification) && empty($headerVerification['eligible'])) {
                            $detailsTitleClass .= ' is-ineligible';
                        }
                        ?>
                        <span class="<?php echo htmlspecialchars($detailsTitleClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($detailsTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dlbh-details-head-actions">
                            <?php if ($selectedEmail && !$showCompose && !$showMessage): ?>
                                <?php if ($messageOnUrl !== '' && !$editModeRequested): ?>
                                    <a href="<?php echo htmlspecialchars($messageOnUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn dlbh-edit-toggle-btn" aria-label="View Message" title="View Message" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">&#9993;</a>
                                <?php endif; ?>
                                <?php if (is_array($verification) && !$editModeRequested): ?>
                                    <?php if ($editOnUrl !== ''): ?>
                                        <a id="dlbh-edit-toggle" href="<?php echo htmlspecialchars($editOnUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn dlbh-edit-toggle-btn" aria-label="Edit Fields" title="Edit Fields" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">&#9998;</a>
                                    <?php endif; ?>
                                <?php elseif (is_array($verification)): ?>
                                    <button type="button" id="dlbh-edit-toggle" class="dlbh-inbox-signout-btn dlbh-edit-toggle-btn is-editing" aria-label="Save Fields" title="Save Fields" onclick="(function(){var a=document.getElementById('dlbh-detail-action');var ra=document.getElementById('dlbh-detail-record-action');var rt=document.getElementById('dlbh-detail-record-target');if(a)a.value='save_detail_fields';if(ra)ra.value='';if(rt)rt.value='';var b=document.getElementById('dlbh-detail-submit');if(b){b.click();}})();return false;">&#128190;</button>
                                    <?php if ($editOffUrl !== ''): ?>
                                        <a id="dlbh-edit-cancel" href="<?php echo htmlspecialchars($editOffUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn dlbh-edit-cancel-btn is-visible" aria-label="Cancel Edit" title="Cancel Edit" style="text-decoration:none;">&#10005;</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($showMessage): ?>
                                <?php if ($messageOffUrl !== ''): ?>
                                    <a href="<?php echo htmlspecialchars($messageOffUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">Back</a>
                                <?php endif; ?>
                            <?php elseif ($selectedEmail && !$showCompose && !$showMessage && !$editModeRequested && ($selectedHasEdits || $submitCompleted)): ?>
                                <button type="submit" id="dlbh-verify-btn" form="dlbh-detail-form" class="dlbh-inbox-signout-btn" onclick="(function(btn){var mode='submit';var m=document.getElementById('dlbh-detail-submit-mode');if(m)m.value=mode;btn.textContent='Sending...';})(this);"><?php echo ($submitCompleted ? 'Sent' : 'Submit'); ?></button>
                            <?php elseif ($selectedEmail && !$showCompose && !$showMessage && !$editModeRequested && is_array($verification) && empty($verification['eligible'])): ?>
                                <form method="get" style="margin:0;">
                                    <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$selectedEmailIdx; ?>">
                                    <input type="hidden" name="dlbh_compose" value="1">
                                    <button type="submit" class="dlbh-inbox-signout-btn">Not Eligible</button>
                                </form>
                            <?php elseif ($selectedEmail && !$showCompose && !$showMessage && !$editModeRequested && is_array($verification) && !empty($verification['eligible']) && !$selectedHasEdits && !$submitCompleted): ?>
                                <?php if ($eligibleConfirmIdx === (int)$selectedEmailIdx): ?>
                                    <span class="dlbh-trash-confirm">
                                        <span>Send Eligible Email?</span>
                                        <form method="get" style="margin:0;">
                                            <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$selectedEmailIdx; ?>">
                                            <input type="hidden" name="dlbh_compose" value="1">
                                            <input type="hidden" name="dlbh_compose_mode" value="eligible">
                                            <button type="submit" class="dlbh-trash-confirm-btn confirm">Yes</button>
                                        </form>
                                        <form method="post" style="margin:0;">
                                            <input type="hidden" name="dlbh_inbox_action" value="eligible_roster_only">
                                            <input type="hidden" name="dlbh_msg_num" value="<?php echo (int)(isset($selectedEmail['msg_num']) ? $selectedEmail['msg_num'] : 0); ?>">
                                            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                                            <button type="submit" class="dlbh-trash-confirm-btn cancel">No</button>
                                        </form>
                                    </span>
                                <?php else: ?>
                                    <form method="get" style="margin:0;">
                                        <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$selectedEmailIdx; ?>">
                                        <input type="hidden" name="dlbh_eligible_confirm" value="<?php echo (int)$selectedEmailIdx; ?>">
                                        <button type="submit" class="dlbh-eligible-pill" style="cursor:pointer;">Eligible</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>
                        <?php
                        ?>
                    </div>
                    <div class="dlbh-details-body">
                        <?php if (!$selectedEmail): ?>
                            <div class="dlbh-inbox-field-value">Select an email to view details.</div>
                        <?php else: ?>
                            <?php $detailFields = (isset($selectedEmail['fields']) && is_array($selectedEmail['fields'])) ? $selectedEmail['fields'] : array(); ?>
                            <?php $detailFieldsOriginal = ($selectedEmailOriginal && isset($selectedEmailOriginal['fields']) && is_array($selectedEmailOriginal['fields'])) ? $selectedEmailOriginal['fields'] : $detailFields; ?>
                            <?php
                            $detailOriginalBuckets = array();
                            if (is_array($detailFieldsOriginal)) {
                                foreach ($detailFieldsOriginal as $of) {
                                    if (!is_array($of)) continue;
                                    $ofType = isset($of['type']) ? strtolower(trim((string)$of['type'])) : 'field';
                                    $ofType = ($ofType === 'header') ? 'header' : 'field';
                                    $ofLabel = isset($of['label']) ? trim((string)$of['label']) : '';
                                    if ($ofLabel === '') continue;
                                    $bucketKey = strtolower($ofType . '|' . $ofLabel);
                                    if (!isset($detailOriginalBuckets[$bucketKey])) $detailOriginalBuckets[$bucketKey] = array();
                                    $detailOriginalBuckets[$bucketKey][] = $of;
                                }
                            }
                            $detailOriginalUsage = array();
                            ?>
                            <?php if (empty($detailFields)): ?>
                                <div class="dlbh-inbox-field-value">No parsed details found.</div>
                            <?php else: ?>
                                <?php if ($showCompose): ?>
                                    <div class="dlbh-compose-wrap">
                                        <h4 class="dlbh-compose-title">Email Compose</h4>
                                        <?php if ($composeStatus['message'] !== ''): ?>
                                            <div class="dlbh-compose-status <?php echo ($composeStatus['type'] === 'success' ? 'success' : 'error'); ?>">
                                                <?php echo htmlspecialchars((string)$composeStatus['message'], ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endif; ?>
                                        <form method="post">
                                            <input type="hidden" name="dlbh_inbox_action" value="send_compose_email">
                                            <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$selectedEmailIdx; ?>">
                                            <input type="hidden" name="compose_mode" value="<?php echo htmlspecialchars($effectiveComposeMode, ENT_QUOTES, 'UTF-8'); ?>">
                                            <textarea name="compose_html" id="dlbh-compose-html" style="display:none;"></textarea>
                                            <input type="hidden" name="compose_body" value="<?php echo htmlspecialchars($composeBody, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">From</label>
                                                <input class="dlbh-wpf-input" type="email" name="compose_from" value="<?php echo htmlspecialchars($composeFrom, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">To</label>
                                                <input class="dlbh-wpf-input" type="email" name="compose_to" value="<?php echo htmlspecialchars($composeTo, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-inbox-field">
                                                <label class="dlbh-inbox-field-label">Subject</label>
                                                <input class="dlbh-wpf-input" type="text" name="compose_subject" value="<?php echo htmlspecialchars($composeSubject, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="dlbh-compose-actions">
                                                <button type="submit" class="dlbh-inbox-btn">Send</button>
                                                <?php if ($composeCancelUrl !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($composeCancelUrl, ENT_QUOTES, 'UTF-8'); ?>" class="dlbh-inbox-signout-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">Cancel</a>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                            $previewIssues = (is_array($verification) && isset($verification['issues']) && is_array($verification['issues'])) ? $verification['issues'] : array();
                                            if ($composeHtml === '') {
                                                $composeHtml = dlbh_inbox_build_composed_email_html($composeBody, $previewIssues, $detailFields, $verification, array(
                                                    'compose_mode' => $effectiveComposeMode,
                                                ));
                                            }
                                            ?>
                                            <div class="dlbh-compose-preview-wrap">
                                                <div class="dlbh-compose-preview-head"><?php echo htmlspecialchars($composeSubject, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="dlbh-compose-preview-body">
                                                    <div id="dlbh-compose-editor" class="dlbh-compose-editor" contenteditable="true"><?php echo $composeHtml; ?></div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                <?php if ($showMessage): ?>
                                    <div class="dlbh-message-view">
                                        <h4 class="dlbh-message-title"><?php echo (count($selectedReplyMessages) > 1) ? 'Reply Messages' : 'Reply Message'; ?></h4>
                                        <?php foreach ($selectedReplyMessages as $replyEntry): ?>
                                            <?php
                                            $replyText = isset($replyEntry['message']) ? trim((string)$replyEntry['message']) : '';
                                            if ($replyText === '') continue;
                                            $replyReceived = isset($replyEntry['received']) ? trim((string)$replyEntry['received']) : '';
                                            ?>
                                            <?php if ($replyReceived !== '' && count($selectedReplyMessages) > 1): ?>
                                                <div class="dlbh-message-meta"><?php echo htmlspecialchars($replyReceived, ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                            <p class="dlbh-message-copy"><?php echo nl2br(htmlspecialchars($replyText, ENT_QUOTES, 'UTF-8')); ?></p>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif (!$showCompose): ?>
                                    <form method="post" id="dlbh-detail-form">
                                        <input type="hidden" name="dlbh_inbox_action" id="dlbh-detail-action" value="<?php echo ($editModeRequested ? 'save_detail_fields' : 'save_and_verify'); ?>">
                                        <input type="hidden" name="dlbh_email_idx" value="<?php echo (int)$selectedEmailIdx; ?>">
                                        <input type="hidden" name="detail_submit_mode" id="dlbh-detail-submit-mode" value="<?php echo ($selectedHasEdits ? 'submit' : 'verify'); ?>">
                                        <input type="hidden" name="detail_has_true_changes" id="dlbh-detail-has-changes" value="0">
                                        <input type="hidden" name="detail_record_action" id="dlbh-detail-record-action" value="">
                                        <input type="hidden" name="detail_record_target" id="dlbh-detail-record-target" value="">
                                        <button type="submit" id="dlbh-detail-submit" style="display:none;"></button>
                                        <?php if (function_exists('wp_nonce_field')) wp_nonce_field('dlbh_inbox_signin_submit', 'dlbh_inbox_nonce'); ?>
                                        <div class="dlbh-inbox-fields">
                                        <?php foreach ($detailFields as $fieldIdx => $field): ?>
                                            <?php
                                            $fieldType = (string)(isset($field['type']) ? $field['type'] : 'field');
                                            $fieldLabel = (string)(isset($field['label']) ? $field['label'] : '');
                                            $fieldValueRaw = (string)(isset($field['value']) ? $field['value'] : '');
                                            if (strcasecmp(trim($fieldLabel), 'Reply Message') === 0 || strcasecmp(trim($fieldLabel), 'Message') === 0) {
                                                continue;
                                            }
                                            $fieldTypeNorm = (strtolower(trim($fieldType)) === 'header') ? 'header' : 'field';
                                            $fieldLookupKey = strtolower($fieldTypeNorm . '|' . trim($fieldLabel));
                                            $fieldLookupOffset = isset($detailOriginalUsage[$fieldLookupKey]) ? (int)$detailOriginalUsage[$fieldLookupKey] : 0;
                                            $detailOriginalUsage[$fieldLookupKey] = $fieldLookupOffset + 1;
                                            $originalField = (isset($detailOriginalBuckets[$fieldLookupKey][$fieldLookupOffset]) && is_array($detailOriginalBuckets[$fieldLookupKey][$fieldLookupOffset]))
                                                ? $detailOriginalBuckets[$fieldLookupKey][$fieldLookupOffset]
                                                : array();
                                            $originalFieldType = (string)(isset($originalField['type']) ? $originalField['type'] : $fieldType);
                                            $originalFieldLabel = (string)(isset($originalField['label']) ? $originalField['label'] : $fieldLabel);
                                            $originalFieldValue = (string)(isset($originalField['value']) ? $originalField['value'] : $fieldValueRaw);
                                            $fieldValueDisplay = $fieldValueRaw;
                                            if (strcasecmp(trim($fieldLabel), 'Address') === 0) {
                                                $fieldValueDisplay = ucwords(strtolower($fieldValueDisplay));
                                            }
                                            $isDateField = (stripos($fieldLabel, 'date') !== false || strcasecmp(trim($fieldLabel), 'Date of Birth') === 0);
                                            $dateDisplayValue = $isDateField ? dlbh_inbox_format_date_friendly($fieldValueRaw) : $fieldValueDisplay;
                                            $dateInputValue = $isDateField ? dlbh_inbox_format_date_input_value($fieldValueRaw) : '';
                                            $fieldValueNorm = strtolower(trim($fieldValueRaw));
                                            $isYesNo = ($fieldValueNorm === 'yes' || $fieldValueNorm === 'no');
                                            $isShirtSize = (strcasecmp(trim($fieldLabel), 'T-Shirt Size') === 0);
                                            $useTextarea = (strlen($dateDisplayValue) > 85 || strpos($dateDisplayValue, "\n") !== false);
                                            $isDobField = (strcasecmp(trim($fieldLabel), 'Date of Birth') === 0);
                                            $dobError = '';
                                            $isFieldLocked = !$editModeRequested;
                                            $isRelationshipField = (strcasecmp(trim($fieldLabel), 'Relationship') === 0);
                                            $hiddenValueFieldName = $isFieldLocked ? 'detail_field_value[]' : 'detail_field_value_shadow[]';
                                            if ($isRelationshipField) $hiddenValueFieldName = 'detail_field_value[]';
                                            $editableValueNameAttr = $isFieldLocked ? '' : ' name="detail_field_value[]"';
                                            if ($isDobField && is_array($verification) && isset($verification['invalid_dob_indices'][(int)$fieldIdx])) {
                                                $dobError = isset($verification['dob_errors_by_index'][(int)$fieldIdx]) ? (string)$verification['dob_errors_by_index'][(int)$fieldIdx] : 'Date of Birth does not meet eligibility requirements.';
                                            }
                                            $invalidClass = ($dobError !== '' ? ' dlbh-field-invalid' : '');
                                            $bindId = 'dlbh-detail-field-' . (int)$fieldIdx;
                                            ?>
                                            <?php if ($fieldType === 'header'): ?>
                                                <input type="hidden" name="detail_field_type[]" value="header">
                                                <input type="hidden" name="detail_field_label[]" value="<?php echo htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="detail_field_value[]" value="">
                                                <input type="hidden" name="detail_field_original_type[]" value="<?php echo htmlspecialchars($originalFieldType, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="detail_field_original_label[]" value="<?php echo htmlspecialchars($originalFieldLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="detail_field_original_value[]" value="">
                                                <?php
                                                $headerRecordKind = '';
                                                if ($editModeRequested) {
                                                    if (strcasecmp($fieldLabel, 'Spouse Information') === 0) {
                                                        $headerRecordKind = 'spouse';
                                                    } elseif (preg_match('/^Dependent Information(?:\s*#\d+)?$/i', $fieldLabel)) {
                                                        $headerRecordKind = 'dependent';
                                                    }
                                                }
                                                ?>
                                                <div class="dlbh-inbox-section-header<?php echo ($headerRecordKind !== '' ? ' dlbh-record-header' : ''); ?>">
                                                    <span class="dlbh-record-header-text"><?php echo htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php if ($headerRecordKind !== ''): ?>
                                                        <span class="dlbh-record-header-actions">
                                                            <button type="button" class="dlbh-record-header-btn" data-record-action="remove" data-record-kind="<?php echo htmlspecialchars($headerRecordKind, ENT_QUOTES, 'UTF-8'); ?>" onclick="(function(btn){var a=document.getElementById('dlbh-detail-record-action');var t=document.getElementById('dlbh-detail-record-target');var s=document.getElementById('dlbh-detail-submit');if(a)a.value='remove';if(t)t.value='<?php echo htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?>';if(s)s.click();})(this);return false;">-</button>
                                                            <button type="button" class="dlbh-record-header-btn" data-record-action="add" data-record-kind="<?php echo htmlspecialchars($headerRecordKind, ENT_QUOTES, 'UTF-8'); ?>" onclick="(function(btn){var a=document.getElementById('dlbh-detail-record-action');var t=document.getElementById('dlbh-detail-record-target');var s=document.getElementById('dlbh-detail-submit');if(a)a.value='add';if(t)t.value='<?php echo htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?>';if(s)s.click();})(this);return false;">+</button>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php continue; ?>
                                            <?php endif; ?>
                                            <div class="dlbh-inbox-field">
                                                <input type="hidden" name="detail_field_type[]" value="field">
                                                <input type="hidden" name="detail_field_label[]" value="<?php echo htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" id="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($hiddenValueFieldName, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($fieldValueRaw, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="detail_field_original_type[]" value="<?php echo htmlspecialchars($originalFieldType, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="detail_field_original_label[]" value="<?php echo htmlspecialchars($originalFieldLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="detail_field_original_value[]" value="<?php echo htmlspecialchars($originalFieldValue, ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="dlbh-inbox-field-label"><?php echo htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                                                <?php if ($isRelationshipField): ?>
                                                    <?php $relationshipValue = trim((string)$fieldValueRaw); ?>
                                                    <select class="dlbh-wpf-select<?php echo $invalidClass; ?>" disabled data-lockable="1" data-bind-target="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <option value="Primary Member"<?php echo (strcasecmp($relationshipValue, 'Primary Member') === 0 ? ' selected' : ''); ?>>Primary Member</option>
                                                        <option value="Spouse"<?php echo (strcasecmp($relationshipValue, 'Spouse') === 0 || strcasecmp($relationshipValue, 'Spouse/Partner') === 0 ? ' selected' : ''); ?>>Spouse</option>
                                                        <option value="Dependent"<?php echo (strcasecmp($relationshipValue, 'Dependent') === 0 ? ' selected' : ''); ?>>Dependent</option>
                                                    </select>
                                                <?php elseif ($isShirtSize): ?>
                                                    <?php $shirtValue = strtoupper(trim($fieldValueRaw)); ?>
                                                    <select class="dlbh-wpf-select<?php echo $invalidClass; ?>"<?php echo $editableValueNameAttr; ?><?php echo ($isFieldLocked ? ' disabled' : ''); ?> data-lockable="1" data-bind-target="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <option value="S"<?php echo ($shirtValue === 'S' ? ' selected' : ''); ?>>S</option>
                                                        <option value="M"<?php echo ($shirtValue === 'M' ? ' selected' : ''); ?>>M</option>
                                                        <option value="L"<?php echo ($shirtValue === 'L' ? ' selected' : ''); ?>>L</option>
                                                        <option value="XL"<?php echo ($shirtValue === 'XL' ? ' selected' : ''); ?>>XL</option>
                                                        <option value="2XL"<?php echo ($shirtValue === '2XL' ? ' selected' : ''); ?>>2XL</option>
                                                        <option value="3XL"<?php echo ($shirtValue === '3XL' ? ' selected' : ''); ?>>3XL</option>
                                                    </select>
                                                <?php elseif ($isYesNo): ?>
                                                    <select class="dlbh-wpf-select<?php echo $invalidClass; ?>"<?php echo $editableValueNameAttr; ?><?php echo ($isFieldLocked ? ' disabled' : ''); ?> data-lockable="1" data-bind-target="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <option value="Yes"<?php echo ($fieldValueNorm === 'yes' ? ' selected' : ''); ?>>Yes</option>
                                                        <option value="No"<?php echo ($fieldValueNorm === 'no' ? ' selected' : ''); ?>>No</option>
                                                    </select>
                                                <?php elseif ($isDateField): ?>
                                                    <?php if ($isFieldLocked): ?>
                                                        <input class="dlbh-wpf-input<?php echo $invalidClass; ?>" type="text" readonly value="<?php echo htmlspecialchars($dateDisplayValue, ENT_QUOTES, 'UTF-8'); ?>" data-lockable="1" data-bind-target="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>" data-date-field="1" data-date-edit-value="<?php echo htmlspecialchars($dateInputValue, ENT_QUOTES, 'UTF-8'); ?>" data-date-display-value="<?php echo htmlspecialchars($dateDisplayValue, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php else: ?>
                                                        <input class="dlbh-wpf-input<?php echo $invalidClass; ?>"<?php echo $editableValueNameAttr; ?> type="date" value="<?php echo htmlspecialchars($dateInputValue, ENT_QUOTES, 'UTF-8'); ?>" data-lockable="1" data-bind-target="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>" data-date-field="1" data-date-edit-value="<?php echo htmlspecialchars($dateInputValue, ENT_QUOTES, 'UTF-8'); ?>" data-date-display-value="<?php echo htmlspecialchars($dateDisplayValue, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php endif; ?>
                                                <?php elseif ($useTextarea): ?>
                                                    <textarea class="dlbh-wpf-textarea<?php echo $invalidClass; ?>"<?php echo $editableValueNameAttr; ?><?php echo ($isFieldLocked ? ' readonly' : ''); ?> data-lockable="1" data-bind-target="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($dateDisplayValue, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                <?php else: ?>
                                                    <input class="dlbh-wpf-input<?php echo $invalidClass; ?>"<?php echo $editableValueNameAttr; ?> type="text"<?php echo ($isFieldLocked ? ' readonly' : ''); ?> value="<?php echo htmlspecialchars($dateDisplayValue, ENT_QUOTES, 'UTF-8'); ?>" data-lockable="1" data-bind-target="<?php echo htmlspecialchars($bindId, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php endif; ?>
                                                <?php if ($dobError !== ''): ?>
                                                    <div class="dlbh-field-error">
                                                        <span class="dlbh-field-error-icon">!</span>
                                                        <span>
                                                            <span class="dlbh-field-error-title">Age Verification Required</span>
                                                            <span class="dlbh-field-error-message"><?php echo htmlspecialchars($dobError, ENT_QUOTES, 'UTF-8'); ?></span>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function() {
        var inEditMode = <?php echo ($editModeRequested ? 'true' : 'false'); ?>;
        if (!inEditMode) return;
        try {
            var url = new URL(window.location.href);
            if (!url.searchParams.has('dlbh_edit')) return;
            url.searchParams.delete('dlbh_edit');
            var qs = url.searchParams.toString();
            var nextUrl = url.pathname + (qs ? ('?' + qs) : '') + (url.hash || '');
            window.history.replaceState({}, document.title, nextUrl);
        } catch (e) {}
    })();
    (function() {
        var focusLabel = <?php echo json_encode($recordFocusLabel); ?>;
        if (!focusLabel) return;
        var headers = document.querySelectorAll('.dlbh-inbox-section-header .dlbh-record-header-text, .dlbh-inbox-section-header');
        for (var i = 0; i < headers.length; i++) {
            var el = headers[i];
            var text = String(el.textContent || '').trim();
            if (text !== focusLabel) continue;
            var target = el.closest ? (el.closest('.dlbh-inbox-section-header') || el) : el;
            try {
                if (target && target.scrollIntoView) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } catch (e) {}
            var firstField = target && target.parentNode ? target.parentNode.querySelector('.dlbh-wpf-input, .dlbh-wpf-select, .dlbh-wpf-textarea') : null;
            if (firstField && firstField.focus) {
                try { firstField.focus({ preventScroll: true }); } catch (e2) { try { firstField.focus(); } catch (e3) {} }
            }
            break;
        }
    })();
    (function() {
        var headMenu = document.querySelector('.dlbh-head-menu');
        var headMenuToggle = document.getElementById('dlbh-head-menu-toggle');
        var headMenuPanel = document.getElementById('dlbh-head-menu-panel');
        if (headMenu && headMenuToggle && headMenuPanel) {
            headMenuToggle.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    headMenuPanel.setAttribute('hidden', 'hidden');
                    var overlay = document.getElementById('dlbh-head-menu-overlay');
                    if (overlay) overlay.setAttribute('hidden', 'hidden');
                    headMenuToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    })();
    (function() {
        var composeAction = document.querySelector('form input[name="dlbh_inbox_action"][value="send_compose_email"], form input[name="dlbh_inbox_action"][value="send_roster_compose_email"]');
        if (composeAction) {
            var form = composeAction.closest('form');
            var editor = document.getElementById('dlbh-compose-editor');
            var hidden = document.getElementById('dlbh-compose-html');
            var sendBtn = form ? form.querySelector('button[type="submit"]') : null;
            if (form && editor && hidden) {
                form.addEventListener('submit', function() {
                    hidden.value = editor.innerHTML;
                    if (sendBtn) sendBtn.textContent = 'Sending...';
                });
            }
        }
    })();
    (function() {
        var liveCall = document.getElementById('dlbh-live-call-pill');
        if (!liveCall) return;
        var setCall = function(letter, number) {
            var l = String(letter || '').trim().toUpperCase();
            var n = String(number || '').replace(/[^0-9]/g, '');
            if (!l || !n) return;
            liveCall.textContent = 'Current Call: ' + l + n;
        };
        var isAllowedOrigin = function(origin) {
            if (typeof origin !== 'string') return false;
            return origin.indexOf('https://dewitt-steward.github.io') === 0 ||
                origin.indexOf('https://classic.letsplaybingo.io') === 0;
        };
        try {
            var cached = localStorage.getItem('dlbh_live_call_last');
            if (cached) {
                var parsed = JSON.parse(cached);
                if (parsed && parsed.letter && parsed.number) setCall(parsed.letter, parsed.number);
            }
        } catch (e) {}
        window.addEventListener('message', function(event) {
            if (!isAllowedOrigin(event.origin)) return;
            var data = event.data;
            if (typeof data === 'string') {
                try { data = JSON.parse(data); } catch (e) { return; }
            }
            if (!data || data.type !== 'LPB_CALL') return;
            var letter = (data.letter || '').toString();
            var number = data.number;
            setCall(letter, number);
            try {
                localStorage.setItem('dlbh_live_call_last', JSON.stringify({ letter: letter, number: number }));
            } catch (e) {}
        });
    })();
    (function() {
        var carousels = document.querySelectorAll('.dlbh-bingo-carousel');
        if (!carousels || !carousels.length) return;
        var lastScrollFn = null;
        for (var i = 0; i < carousels.length; i++) {
            (function(carousel) {
                var viewport = carousel.querySelector('.dlbh-bingo-carousel-viewport');
                var rail = carousel.querySelector('.dlbh-bingo-carousel-rail');
                var left = carousel.querySelector('.dlbh-bingo-arrow[data-bingo-dir="-1"]');
                var right = carousel.querySelector('.dlbh-bingo-arrow[data-bingo-dir="1"]');
                var indicator = carousel.nextElementSibling;
                if (!viewport || !rail || !left || !right) return;
                if (!indicator || !indicator.classList || !indicator.classList.contains('dlbh-bingo-page-indicator')) {
                    indicator = null;
                }
                var cardsPerPage = 4;
                var totalCards = rail.querySelectorAll('.dlbh-bingo-card-shell').length;
                var totalPages = Math.max(1, Math.ceil(totalCards / cardsPerPage));
                var currentPage = 0;
                var renderIndicator = function() {
                    if (!indicator) return;
                    indicator.textContent = 'Page ' + (currentPage + 1) + ' of ' + totalPages;
                };
                var scrollByCards = function(direction) {
                    var step = viewport.clientWidth || 1150;
                    var maxPage = Math.max(0, totalPages - 1);
                    currentPage = currentPage + direction;
                    if (currentPage < 0) currentPage = 0;
                    if (currentPage > maxPage) currentPage = maxPage;
                    carousel.setAttribute('data-page', String(currentPage));
                    var target = currentPage * step;
                    try {
                        if (typeof viewport.scrollTo === 'function') viewport.scrollTo({ left: target, behavior: 'smooth' });
                        else viewport.scrollLeft = target;
                    } catch (e) {
                        viewport.scrollLeft = target;
                    }
                    renderIndicator();
                };
                left.addEventListener('click', function(e) {
                    e.preventDefault();
                    scrollByCards(-1);
                });
                right.addEventListener('click', function(e) {
                    e.preventDefault();
                    scrollByCards(1);
                });
                viewport.addEventListener('scroll', function() {
                    var step = viewport.clientWidth || 1150;
                    if (step <= 0) return;
                    var maxPage = Math.max(0, totalPages - 1);
                    var inferredPage = Math.round((viewport.scrollLeft || 0) / step);
                    if (inferredPage < 0) inferredPage = 0;
                    if (inferredPage > maxPage) inferredPage = maxPage;
                    if (inferredPage !== currentPage) {
                        currentPage = inferredPage;
                        carousel.setAttribute('data-page', String(currentPage));
                        renderIndicator();
                    }
                });
                renderIndicator();
                lastScrollFn = scrollByCards;
            })(carousels[i]);
        }
        if (lastScrollFn) window.dlbhBingoScroll = lastScrollFn;
    })();
    (function() {
        var detailForm = document.getElementById('dlbh-detail-form');
        var editToggle = document.getElementById('dlbh-edit-toggle');
        var editCancel = document.getElementById('dlbh-edit-cancel');
        var actionInput = document.getElementById('dlbh-detail-action');
        var hasChangesInput = document.getElementById('dlbh-detail-has-changes');
        var verifyBtn = document.getElementById('dlbh-verify-btn');
        if (!detailForm || !editToggle) return;
        var editToggleIsLink = (String(editToggle.tagName || '').toUpperCase() === 'A');
        var editCancelIsLink = (editCancel && String(editCancel.tagName || '').toUpperCase() === 'A');
        var getLockables = function() {
            return detailForm.querySelectorAll('[data-lockable="1"]');
        };
        var lockables = getLockables();
        var originals = [];
        var editing = false;
        var fieldsContainer = detailForm.querySelector('.dlbh-inbox-fields');

        var normalizeForCompare = function(label, value) {
            var labelNorm = String(label || '').trim().toLowerCase();
            var valueNorm = String(value || '').trim().replace(/\s+/g, ' ');
            if (labelNorm.indexOf('date') !== -1) {
                var low = valueNorm.toLowerCase();
                if (!valueNorm || low === '-' || low === 'n/a' || low === 'na') return '';
                var d = new Date(valueNorm);
                if (!isNaN(d.getTime())) {
                    var y = d.getFullYear();
                    var m = String(d.getMonth() + 1).padStart(2, '0');
                    var day = String(d.getDate()).padStart(2, '0');
                    return y + '-' + m + '-' + day;
                }
            }
            if (labelNorm.indexOf('phone') !== -1) return valueNorm.replace(/[^0-9]/g, '');
            if (labelNorm.indexOf('email') !== -1) return valueNorm.toLowerCase();
            if (labelNorm.indexOf('balance') !== -1 || labelNorm.indexOf('charges') !== -1 || labelNorm.indexOf('due') !== -1 || labelNorm.indexOf('payment') !== -1) {
                return valueNorm.replace(/[^0-9.\-]/g, '');
            }
            return valueNorm.toLowerCase();
        };

        var formatDateDisplay = function(isoDate) {
            var raw = String(isoDate || '').trim();
            if (!raw) return '';
            var parts = raw.split('-');
            if (parts.length !== 3) return raw;
            var year = parseInt(parts[0], 10);
            var month = parseInt(parts[1], 10) - 1;
            var day = parseInt(parts[2], 10);
            if (isNaN(year) || isNaN(month) || isNaN(day)) return raw;
            var dt = new Date(year, month, day);
            if (isNaN(dt.getTime())) return raw;
            return dt.toLocaleString('en-US', { month: 'long' }) + ' ' + dt.getDate() + ', ' + dt.getFullYear();
        };

        var syncField = function(el) {
            var bindId = el.getAttribute('data-bind-target');
            if (!bindId) return;
            var hidden = document.getElementById(bindId);
            if (!hidden) return;
            var rawVal = el.value;
            if (el.getAttribute('data-date-field') === '1' && el.type === 'text') {
                var editVal = el.getAttribute('data-date-edit-value') || '';
                rawVal = editVal || rawVal;
            }
            hidden.value = rawVal;
        };

        var computeHasTrueChanges = function() {
            var types = detailForm.elements['detail_field_type[]'] || [];
            var labels = detailForm.elements['detail_field_label[]'] || [];
            var values = detailForm.elements['detail_field_value[]'] || [];
            var origTypes = detailForm.elements['detail_field_original_type[]'] || [];
            var origLabels = detailForm.elements['detail_field_original_label[]'] || [];
            var origValues = detailForm.elements['detail_field_original_value[]'] || [];

            var toArray = function(v) {
                if (!v) return [];
                if (typeof v.length === 'number' && !v.tagName) return Array.prototype.slice.call(v);
                return [v];
            };
            types = toArray(types);
            labels = toArray(labels);
            values = toArray(values);
            origTypes = toArray(origTypes);
            origLabels = toArray(origLabels);
            origValues = toArray(origValues);

            var len = Math.max(types.length, labels.length, values.length, origTypes.length, origLabels.length, origValues.length);
            for (var i = 0; i < len; i++) {
                var t = types[i] ? String(types[i].value || '').trim().toLowerCase() : '';
                var l = labels[i] ? String(labels[i].value || '').trim() : '';
                var v = values[i] ? String(values[i].value || '') : '';
                var ot = origTypes[i] ? String(origTypes[i].value || '').trim().toLowerCase() : t;
                var ol = origLabels[i] ? String(origLabels[i].value || '').trim() : l;
                var ov = origValues[i] ? String(origValues[i].value || '') : '';
                if (!l && !ol) continue;
                if (t !== ot) return true;
                if (l.toLowerCase() !== ol.toLowerCase()) return true;
                if (normalizeForCompare(l, v) !== normalizeForCompare(ol, ov)) return true;
            }
            return false;
        };

        var bindLockable = function(el) {
            el.addEventListener('change', function() { syncField(el); });
            if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                el.addEventListener('input', function() { syncField(el); });
            }
        };

        Array.prototype.forEach.call(lockables, function(el) {
            var bindId = el.getAttribute('data-bind-target');
            var hidden = bindId ? document.getElementById(bindId) : null;
            originals.push({
                el: el,
                value: el.value,
                hiddenValue: hidden ? hidden.value : '',
                dateEditValue: el.getAttribute('data-date-edit-value') || '',
                dateDisplayValue: el.getAttribute('data-date-display-value') || ''
            });
            bindLockable(el);
        });

        // Server-rendered edit mode: keep sync/change tracking on submit, do not hijack edit/cancel clicks.
        if (editCancelIsLink) {
            if (fieldsContainer) {
                fieldsContainer.addEventListener('click', function(e) {
                    var btn = e.target && e.target.closest ? e.target.closest('.dlbh-record-header-btn') : null;
                    if (!btn) return;
                    e.preventDefault();
                    var action = btn.getAttribute('data-record-action') || '';
                    if (!action) return;
                    var header = btn.closest('.dlbh-inbox-section-header');
                    if (!header) return;
                    var headerText = header.querySelector('.dlbh-record-header-text');
                    var targetLabel = headerText ? String(headerText.textContent || '').trim() : String(header.textContent || '').trim();
                    var actionField = document.getElementById('dlbh-detail-record-action');
                    var targetField = document.getElementById('dlbh-detail-record-target');
                    if (actionField) actionField.value = action;
                    if (targetField) targetField.value = targetLabel;
                    Array.prototype.forEach.call(getLockables(), function(el) { syncField(el); });
                    if (hasChangesInput) hasChangesInput.value = computeHasTrueChanges() ? '1' : '0';
                    detailForm.submit();
                });
            }
            // Force-save click path for environments where external submit buttons are unreliable.
            editToggle.addEventListener('click', function(e) {
                e.preventDefault();
                var action = document.getElementById('dlbh-detail-action');
                if (action) action.value = 'save_detail_fields';
                var recordAction = document.getElementById('dlbh-detail-record-action');
                var recordTarget = document.getElementById('dlbh-detail-record-target');
                if (recordAction) recordAction.value = '';
                if (recordTarget) recordTarget.value = '';
                Array.prototype.forEach.call(getLockables(), function(el) { syncField(el); });
                if (hasChangesInput) hasChangesInput.value = computeHasTrueChanges() ? '1' : '0';
                detailForm.submit();
            });
            detailForm.addEventListener('submit', function() {
                var recordAction = document.getElementById('dlbh-detail-record-action');
                var recordTarget = document.getElementById('dlbh-detail-record-target');
                if (recordAction && recordAction.value === '' && recordTarget) recordTarget.value = '';
                Array.prototype.forEach.call(getLockables(), function(el) { syncField(el); });
                if (hasChangesInput) hasChangesInput.value = computeHasTrueChanges() ? '1' : '0';
            });
            return;
        }

        if (editToggleIsLink) return;

        var setEditing = function(on) {
            editing = !!on;
            Array.prototype.forEach.call(lockables, function(el) {
                var isDateField = (el.getAttribute('data-date-field') === '1');
                var bindId = el.getAttribute('data-bind-target');
                var hidden = bindId ? document.getElementById(bindId) : null;
                if (isDateField && el.tagName === 'INPUT') {
                    if (editing) {
                        var iso = (hidden && /^\d{4}-\d{2}-\d{2}$/.test(hidden.value)) ? hidden.value : (el.getAttribute('data-date-edit-value') || '');
                        el.type = 'date';
                        el.value = iso;
                    } else {
                        var editIso = (el.type === 'date') ? el.value : (el.getAttribute('data-date-edit-value') || '');
                        if (!editIso && hidden && /^\d{4}-\d{2}-\d{2}$/.test(hidden.value)) editIso = hidden.value;
                        if (editIso) el.setAttribute('data-date-edit-value', editIso);
                        el.type = 'text';
                        var disp = formatDateDisplay(editIso) || el.getAttribute('data-date-display-value') || '';
                        el.value = disp;
                        el.setAttribute('data-date-display-value', disp);
                    }
                }
                if (el.tagName === 'SELECT') el.disabled = !editing;
                else el.readOnly = !editing;
            });
            editToggle.classList.toggle('is-editing', editing);
            editToggle.innerHTML = editing ? '&#128190;' : '&#9998;';
            if (editCancel) editCancel.classList.toggle('is-visible', editing);
        };

        editToggle.addEventListener('click', function(e) {
            e.preventDefault();
            if (!editing) {
                setEditing(true);
                return;
            }
            if (hasChangesInput) hasChangesInput.value = computeHasTrueChanges() ? '1' : '0';
            if (actionInput) actionInput.value = 'save_detail_fields';
            detailForm.submit();
        });

        if (editCancel) {
            editCancel.addEventListener('click', function(e) {
                e.preventDefault();
                Array.prototype.forEach.call(originals, function(item) {
                    if (!item || !item.el) return;
                    item.el.value = item.value;
                    if (item.dateEditValue) item.el.setAttribute('data-date-edit-value', item.dateEditValue);
                    if (item.dateDisplayValue) item.el.setAttribute('data-date-display-value', item.dateDisplayValue);
                    var bindId = item.el.getAttribute('data-bind-target');
                    var hidden = bindId ? document.getElementById(bindId) : null;
                    if (hidden) hidden.value = item.hiddenValue || '';
                });
                setEditing(false);
            });
        }

        detailForm.addEventListener('submit', function() {
            if (hasChangesInput) hasChangesInput.value = computeHasTrueChanges() ? '1' : '0';
            var submitModeInput = document.getElementById('dlbh-detail-submit-mode');
            var submitMode = submitModeInput ? String(submitModeInput.value || '').toLowerCase() : '';
            if (verifyBtn) {
                verifyBtn.textContent = (submitMode === 'submit') ? 'Sending...' : 'Verifying...';
            }
        });

        setEditing(false);
    })();
    </script>
    <?php
    return ob_get_clean();
}
}

if (function_exists('add_shortcode')) {
    if (function_exists('remove_shortcode')) remove_shortcode('bingo');
    add_shortcode('bingo', 'dlbh_bingo_portal_shortcode');
}
