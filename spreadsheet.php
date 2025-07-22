<?php
/**
 * CSV Exporter from $_SESSION['xlsquery'] or $_SESSION['xlsdata']
 * Modified for lightweight CSV export (no PhpSpreadsheet needed)
 * File Name : spreadsheet.php
 * Modified By : Erwan Setyo Budi
 * Function : Change export spreadsheet in Reporting Menu
 * Location File : ../admin/modules/reporting
 */

define('INDEX_AUTH', '1');
require_once __DIR__ . '/../../../sysconfig.inc.php';
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

// Privilege check
$can_read = utility::havePrivilege('reporting', 'r');
if (!$can_read) {
    die('You don\'t have enough privileges to access this area.');
}

// Output filename
$filename = ($_SESSION['tblout'] ?? 'report') . '.csv';

// Set headers to force download as CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility

$output = fopen('php://output', 'w');

// Ambil data dari session
if (isset($_SESSION['xlsquery'])) {
    $result = $dbs->query($_SESSION['xlsquery']);

    // Tulis header kolom
    $headers = [];
    while ($field = $result->fetch_field()) {
        $headers[] = $field->name;
    }
    fputcsv($output, $headers);

    // Tulis isi data
    while ($row = $result->fetch_row()) {
        fputcsv($output, $row);
    }
} elseif (isset($_SESSION['xlsdata'])) {
    foreach ($_SESSION['xlsdata'] as $row) {
        fputcsv($output, $row);
    }
} else {
    fputcsv($output, ['No data to export']);
}

fclose($output);
exit;
