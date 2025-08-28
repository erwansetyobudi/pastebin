<?php
/**
 * @Created by          : Waris Agung Widodo (ido.alit@gmail.com)
 * @Modifiedby          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 06/11/20 00.56
 * @File name           : index.php
 */

// Penambahan Warna pada Plugin Label Barcode Default SLiMS

defined('INDEX_AUTH') OR die('Direct access not allowed!');

// IP based access limitation
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');

// start the session
require SB . 'admin/default/session.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';

function httpQuery(array $query = [])
{
    // gabungkan GET sekarang dengan override dari $query, buang nilai null
    $merged = array_merge($_GET, $query);
    $merged = array_filter($merged, static function ($v) { return $v !== null; });
    return http_build_query($merged);
}

// privileges checking
$can_read = utility::havePrivilege('bibliography', 'r');
if (!$can_read) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$max_print = 50;

// generate barcode (Zend)
ini_set('include_path', LIB);
require_once LIB . 'Zend/Barcode.php';

function ensureDir(string $dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function generateBarcode($code)
{
    $dir = __DIR__ . '/../../images/barcodes';
    ensureDir($dir);
    $file_name = $dir . '/' . $code . '.png';

    $fontPath = realpath(LIB . 'phpbarcode/DejaVuSans.ttf');
    $options = [
        'text' => (string)$code,
        'factor' => 2,
        'font' => $fontPath ?: null,
        'fontSize' => 8,
    ];

    $renderer = Zend_Barcode::factory('code128', 'image', $options);
    $img = $renderer->draw();
    // @: beberapa hosting meng-disable warning GD
    @imagepng($img, $file_name);
    @imagedestroy($img);
}

$colorMap = [
    "0" => '#14d4ff', // Biru cerah
    "1" => '#ff4ab1', // Pink terang
    "2" => '#40ffdf', // Hijau kebiruan
    "3" => '#a3f59f', // Hijau muda
    "4" => '#e5f59f', // Kuning kehijauan
    "5" => '#f5ec9f', // Kuning pastel
    "6" => '#9fdef5', // Biru muda
    "7" => '#f39ff5', // Pink pastel
    "8" => '#ff8ca3', // Salmon
    "9" => '#8cf0ff'  // Biru langit
];
$defaultHeaderColor = '#e5e7eb'; // fallback

/* RECORD OPERATION */
if (isset($_POST['itemID']) && !empty($_POST['itemID']) && isset($_POST['itemAction'])) {

    if (!$can_read) {
        die();
    }

    if (!is_array($_POST['itemID'])) {
        $_POST['itemID'] = [$_POST['itemID']];
    }

    // Inisialisasi counter jika belum ada
    if (!isset($_SESSION['labels'])) {
        $_SESSION['labels'] = ['biblio' => [], 'item' => []];
    }
    $print_count_biblio = isset($_SESSION['labels']['biblio']) ? count($_SESSION['labels']['biblio']) : 0;
    $print_count_item   = isset($_SESSION['labels']['item']) ? count($_SESSION['labels']['item']) : 0;

    $limit_reach = false;

    foreach ($_POST['itemID'] as $rawID) {
        // Hitung total saat ini sebelum menambah
        $current_total = $print_count_item + $print_count_biblio;
        if ($current_total >= $max_print) {
            $limit_reach = true;
            break;
        }

        $rawID = (string)$rawID;

        // Deteksi: biblio diawali 'b' atau 'B'
        if ($rawID !== '' && ($rawID[0] === 'b' || $rawID[0] === 'B')) {
            $biblioID = (int)substr($rawID, 1);
            if ($biblioID <= 0 || isset($_SESSION['labels']['biblio'][$biblioID])) {
                continue;
            }
            $_SESSION['labels']['biblio'][$biblioID] = $biblioID;
            $print_count_biblio++;
        } else {
            // Item ID numeric
            $itemID = (int)$rawID;
            if ($itemID <= 0 || isset($_SESSION['labels']['item'][$itemID])) {
                continue;
            }
            $_SESSION['labels']['item'][$itemID] = $itemID;
            $print_count_item++;
        }
    }

    $print_count = $print_count_item + $print_count_biblio;
    echo '<script type="text/javascript">top.$("#queueCount").html("' . $print_count . '");</script>';

    if ($limit_reach) {
        $msg = str_replace('{max_print}', $max_print, __('Selected items NOT ADDED to print queue. Only {max_print} can be printed at once'));
        utility::jsToastr('Labels Printing', $msg, 'warning');
    } else {
        utility::jsToastr('Labels Printing', __('Selected items added to print queue'), 'success');
    }
    exit();
}

// clean print queue
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    utility::jsToastr('Labels Printing', __('Print queue cleared!'), 'success');
    echo '<script type="text/javascript">top.$("#queueCount").html("0");</script>';
    unset($_SESSION['labels']);
    exit();
}

// on print action
if (isset($_GET['action']) && $_GET['action'] === 'print') {
    if (empty($_SESSION['labels']['item']) && empty($_SESSION['labels']['biblio'])) {
        utility::jsToastr('Labels Printing', __('There is no data to print!'), 'error');
        die();
    }

    // Build ID arrays (aman, integer)
    $item_ids_arr   = isset($_SESSION['labels']['item']) ? array_map('intval', array_values($_SESSION['labels']['item'])) : [];
    $biblio_ids_arr = isset($_SESSION['labels']['biblio']) ? array_map('intval', array_values($_SESSION['labels']['biblio'])) : [];

    $item_ids   = $item_ids_arr ? implode(',', $item_ids_arr) : '';
    $biblio_ids = $biblio_ids_arr ? implode(',', $biblio_ids_arr) : '';

    // SQL criteria
    $criteriaParts = [];
    if ($item_ids !== '')   { $criteriaParts[]   = "i.item_id IN($item_ids)"; }
    if ($biblio_ids !== '') { $criteriaParts[] = "b.biblio_id IN($biblio_ids)"; }
    $criteria = implode(' OR ', $criteriaParts);

    // send query to database
    $sql = 'SELECT IF(i.call_number<>\'\', i.call_number, b.call_number) AS cn, i.item_code 
            FROM biblio AS b 
            LEFT JOIN item AS i ON b.biblio_id=i.biblio_id 
            WHERE ' . $criteria;
    $biblio_q = $dbs->query($sql);

    $label_data_array = [];
    while ($row = $biblio_q->fetch_row()) {
        if ($row[0]) { // hanya jika ada call number
            $label_data_array[] = $row;
        }
    }

    // include printed settings configuration file
    include SB . 'admin' . DS . 'admin_template' . DS . 'printed_settings.inc.php';
    $custom_settings = SB . 'admin' . DS . $sysconf['admin_template']['dir'] . DS . $sysconf['template']['theme'] . DS . 'printed_settings.inc.php';
    if (file_exists($custom_settings)) {
        include $custom_settings;
    }
    loadPrintSettings($dbs, 'label');

    // chunk per 2
    $chunked_label_arrays = array_chunk($label_data_array, 2);

    // HTML
    $html_str = '<table class="table table-borderless">' . "\n";
    echo '<script type="text/javascript" src="' . JWB . 'jquery.js"></script>';

    foreach ($chunked_label_arrays as $label_data) {
        $html_str .= '<tr>' . "\n";
        foreach ($label_data as $labels) {
            $barcode_text = trim($labels[1]);
            // sanitize kode: spasi & karakter tak valid
            $barcode_text = str_replace([' ', '/', '\/'], '_', $barcode_text);
            $barcode_text = str_replace([':', ',', '*', '@'], '', $barcode_text);

            generateBarcode($barcode_text);

            $label = $labels[0];

            // Warna header by digit pertama pada call number (fallback default)
            $firstChar = substr((string)$label, 0, 1);
            $headerColor = isset($colorMap[$firstChar]) ? $colorMap[$firstChar] : $defaultHeaderColor;

            $html_str .= '<td valign="top">';
            $html_str .= '<div class="card card-body"><div class="d-flex align-items-center">';
            $html_str .= '<div style="width:240px; margin-right: 40px">';
            $html_str .= '<img class="img-fluid" src="' . SWB . IMG . '/barcodes/' . urlencode($barcode_text) . '.png?' . date('YmdHis') . '" border="0" />';
            $html_str .= '</div>';
            $html_str .= '<div style="width: 80%">';
            if (!empty($sysconf['print']['label']['include_header_text'])) {
                $headerTxt = !empty($sysconf['print']['label']['header_text']) ? $sysconf['print']['label']['header_text'] : $sysconf['library_name'];
                $html_str .= '<div class="labelHeaderStyle pl-2 pt-2" style="background-color: ' . htmlspecialchars($headerColor) . '">' . $headerTxt . '</div>';
            }

            // pecah label (call number) antar huruf/angka agar rapi per baris
            $sliced_label = preg_split("/((?<=\w)\s+(?=\D))|((?<=\D)\s+(?=\d))/m", (string)$label);
            $html_str .= '<div class="labelStyle pl-2">';
            foreach ($sliced_label as $slice_label_item) {
                $html_str .= htmlspecialchars($slice_label_item) . '<br />';
            }
            $html_str .= '</div></div></div>';
            $html_str .= '</div>';
            $html_str .= '</td>';
        }
        $html_str .= '</tr>' . "\n";
    }
    $html_str .= '</table>' . "\n";

    $__ = '__';
    $SWB = SWB;
    $template = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Label & Barcode Printing</title>
    <link rel="stylesheet" href="{$SWB}css/bootstrap.min.css">
    <style>
        @media print { .no-print { display: none !important; } }
        .rotate { transform: rotate(-90deg); -webkit-transform: rotate(-90deg); -moz-transform: rotate(-90deg); -ms-transform: rotate(-90deg); -o-transform: rotate(-90deg); filter: progid:DXImageTransform.Microsoft.BasicImage(rotation=3); }
        .labelHeaderStyle { border-bottom: 1px solid #8d8d8d; padding-bottom: 8px; margin-bottom: 8px; }
        .labelStyle { font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>
    <a href="#" class="no-print btn btn-success mb-4" onclick="window.print()">{$__('Print Again')}</a>
    {$html_str}
    <script type="text/javascript">self.print();</script>
</body>
</html>
HTML;

    // reset session
    unset($_SESSION['labels']);

    // tulis file hasil
    $print_file_name = 'label_print_result_' . strtolower(str_replace(' ', '_', $_SESSION['uname'] ?? 'user')) . '.html';
    $file_write = @file_put_contents(UPLOAD . $print_file_name, $template);
    if ($file_write) {
        echo '<script type="text/javascript">parent.$("#queueCount").html("0");</script>';
        echo '<script type="text/javascript">top.$.colorbox({href: "' . SWB . FLS . '/' . $print_file_name . '", iframe: true, width: (1200), height: (parent.window.innerHeight - 200), title: "' . __('Labels Printing') . '"})</script>';
    } else {
        utility::jsToastr('Labels Printing', str_replace('{directory}', SB . FLS, __('ERROR! Label failed to generate, possibly because {directory} directory is not writable')), 'error');
    }
    exit();
}

/* search form */
?>
<div class="menuBox">
    <div class="menuBoxInner printIcon">
        <div class="per_title">
            <h2><?php echo __('Labels & Barcode Printing'); ?></h2>
        </div>
        <div class="sub_section">
            <div class="btn-group">
                <a target="blindSubmit" href="<?= $_SERVER['PHP_SELF'] . '?' . httpQuery(['action' => 'clear']) ?>"
                   class="btn btn-default notAJAX "><?php echo __('Clear Print Queue'); ?></a>
                <a target="blindSubmit" href="<?= $_SERVER['PHP_SELF'] . '?' . httpQuery(['action' => 'print']) ?>"
                   class="btn btn-success notAJAX "><?php echo __('Print Labels for Selected Data'); ?></a>
            </div>
            <form name="search" action="<?= $_SERVER['PHP_SELF'] . '?' . httpQuery() ?>" id="search" method="get"
                  class="form-inline"><?php echo __('Search'); ?>
                <input type="text" name="keywords" class="form-control col-md-3"/>
                <input type="submit" id="doSearch" value="<?php echo __('Search'); ?>"
                       class="s-btn btn btn-default"/>
            </form>
        </div>
        <div class="infoBox">
            <?php
            echo __('Maximum') . ' <strong class="text-danger">' . $max_print . '</strong> ' . __('records can be printed at once. Currently there is') . ' ';
            if (isset($_SESSION['labels'])) {
                $qItem = isset($_SESSION['labels']['item']) ? count($_SESSION['labels']['item']) : 0;
                $qBib  = isset($_SESSION['labels']['biblio']) ? count($_SESSION['labels']['biblio']) : 0;
                echo '<strong id="queueCount" class="text-danger">' . ($qItem + $qBib) . '</strong>';
            } else {
                echo '<strong id="queueCount" class="text-danger">0</strong>';
            }
            echo ' ' . __('in queue waiting to be printed.');
            ?>
        </div>
    </div>
</div>
<?php
/* search form end */

// create datagrid
$datagrid = new simbio_datagrid();

/* BIBLIOGRAPHY LIST */
require SIMBIO . 'simbio_UTILS/simbio_tokenizecql.inc.php';
require LIB . 'biblio_list_model.inc.php';

// index choice
if ($sysconf['index']['type'] == 'index' || ($sysconf['index']['type'] == 'sphinx' && file_exists(LIB . 'sphinx/sphinxapi.php'))) {
    if ($sysconf['index']['type'] == 'sphinx') {
        require LIB . 'sphinx/sphinxapi.php';
        require LIB . 'biblio_list_sphinx.inc.php';
    } else {
        require LIB . 'biblio_list_index.inc.php';
    }
    // table spec
    $table_spec = 'search_biblio AS `index` LEFT JOIN `item` ON `index`.biblio_id=`item`.biblio_id';
    if ($can_read) {
        $datagrid->setSQLColumn(
            'IF(item.item_id IS NOT NULL, item.item_id, CONCAT(\'b\', index.biblio_id))',
            'index.title AS `' . __('Title') . '`',
            'IF(item.call_number<>\'\', item.call_number, index.call_number) AS `' . __('Call Number') . '`',
            'item.item_code AS `' . __('Item Code') . '`'
        );
    }
} else {
    require LIB . 'biblio_list.inc.php';
    $table_spec = 'biblio LEFT JOIN item ON biblio.biblio_id=item.biblio_id';
    if ($can_read) {
        $datagrid->setSQLColumn(
            'IF(item.item_id IS NOT NULL, item.item_id, CONCAT(\'b\', biblio.biblio_id))',
            'biblio.title AS `' . __('Title') . '`',
            'IF(item.call_number<>\'\', item.call_number, biblio.call_number) AS `' . __('Call Number') . '`',
            'item.item_code AS `' . __('Item Code') . '`'
        );
    }
}

$datagrid->setSQLorder('item.last_update DESC');

// search
if (isset($_GET['keywords']) && $_GET['keywords']) {
    $keywords = utility::filterData('keywords', 'get', true, true, true);
    $searchable_fields = ['title', 'author', 'class', 'callnumber', 'itemcode'];
    $search_str = '';

    if (!preg_match('@[a-z]+\s*=\s*@i', $keywords)) {
        foreach ($searchable_fields as $search_field) {
            $search_str .= $search_field . '=' . $keywords . ' OR ';
        }
    } else {
        $search_str = $keywords;
    }
    $biblio_list = new biblio_list($dbs, 20);
    $criteria = $biblio_list->setSQLcriteria($search_str);
}

$criteria_str = 'item.item_code IS NOT NULL';
if (isset($criteria)) {
    $criteria_str .= ' AND (' . $criteria['sql_criteria'] . ')';
}
$datagrid->setSQLCriteria($criteria_str);

// table attrs
$datagrid->table_attr = 'id="dataList" class="s-table table"';
$datagrid->table_header_attr = 'class="dataListHeader" style="font-weight: bold;"';
// controls
$datagrid->edit_property = false;
$datagrid->chbox_property = ['itemID', __('Add')];
$datagrid->chbox_action_button = __('Add To Print Queue');
$datagrid->chbox_confirm_msg = __('Add to print queue?');
$datagrid->chbox_form_URL = $_SERVER['PHP_SELF'] . '?' . httpQuery();
$datagrid->column_width = [0 => '75%', 1 => '20%'];

// render
$datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, $can_read);
if (isset($_GET['keywords']) && $_GET['keywords']) {
    $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords'));
    echo '<div class="infoBox">' . $msg . ' : "' . htmlspecialchars($_GET['keywords']) . '"<div>' . __('Query took') . ' <b>' . $datagrid->query_time . '</b> ' . __('second(s) to complete') . '</div></div>';
}
echo $datagrid_result;
/* main content end */
