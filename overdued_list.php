<?php
/**
 *
 * Copyright (C) 2007,2008  Arie Nugraha (dicarve@yahoo.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

/* 
 * Overdues Report Module (Laporan Keterlambatan Pengembalian Buku)
 * ---------------------------------------------------------------
 * Author: Erwan Setyo Budi
 * 
 * Deskripsi Fitur:
 * 1. Menampilkan daftar anggota perpustakaan yang memiliki pinjaman buku
 *    dengan status terlambat dikembalikan (overdue), lengkap dengan detail buku.
 * 
 * 2. Filter Laporan:
 *    - Pencarian berdasarkan Member ID atau Nama Anggota.
 *    - Filter berdasarkan rentang tanggal peminjaman (Loan Date From – Until).
 *    - Pengaturan jumlah data per halaman (20–200 data).
 * 
 * 3. Informasi Anggota:
 *    - Menampilkan nama anggota, alamat email, alamat surat, dan nomor telepon/HP.
 *    - Menampilkan jumlah hari keterlambatan dan total denda yang dikenakan.
 *    - Menampilkan lokasi koleksi (location name) jika tersedia.
 * 
 * 4. Tombol Notifikasi Email:
 *    - Tombol untuk mengirim notifikasi email secara individual ke anggota.
 *    - Tombol “Kirim Semua Notifikasi Email” untuk mengirim ke seluruh anggota yang terlambat.
 * 
 * 5. Tombol Notifikasi WhatsApp:
 *    - Tombol “Kirim Notifikasi WA” untuk membuka chat WhatsApp ke anggota dengan pesan otomatis.
 *    - Nomor HP di-normalisasi ke format internasional Indonesia (+62) agar kompatibel dengan wa.me.
 *    - Mendukung berbagai format input nomor (0821-xxxx, 0858 xxxx, +62 xxxxx, dst.).
 * 
 * 6. Perhitungan Denda:
 *    - Menghitung jumlah hari keterlambatan berdasarkan aturan grace period di modul sirkulasi.
 *    - Menghitung nominal denda harian berdasarkan tipe anggota atau aturan peminjaman.
 * 
 * 7. Tampilan Laporan:
 *    - Menampilkan data dalam bentuk tabel dengan pagination.
 *    - Menyertakan detail buku (judul, harga, lokasi) dan informasi pinjaman (tanggal pinjam, tanggal jatuh tempo).
 * 
 * Catatan Teknis:
 * - Menggunakan class `report_datagrid` untuk membuat tabel laporan.
 * - Menggunakan AJAX untuk pengiriman email tanpa reload halaman.
 * - Menggunakan `normalize_phone_id()` untuk memastikan format nomor WA konsisten.
 */


// key to authenticate
define('INDEX_AUTH', '1');

// main system configuration
require '../../../../sysconfig.inc.php';
// IP based access limitation
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-circulation');
// start the session
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';
require SIMBIO . 'simbio_UTILS/simbio_date.inc.php';
require MDLBS . 'membership/member_base_lib.inc.php';
require MDLBS . 'circulation/circulation_base_lib.inc.php';

// privileges checking
$can_read = utility::havePrivilege('circulation', 'r') || utility::havePrivilege('reporting', 'r');
$can_write = utility::havePrivilege('circulation', 'w') || utility::havePrivilege('reporting', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('You don\'t have enough privileges to access this area!') . '</div>');
}

require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/form_maker/simbio_form_element.inc.php';
require SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';
require MDLBS . 'reporting/report_dbgrid.inc.php';

$page_title = 'Overdued List Report';
$reportView = false;
$num_recs_show = 20;
if (isset($_GET['reportView'])) {
    $reportView = true;
}

if (!$reportView) {
    ?>
    <!-- filter -->
    <div>
        <div class="per_title">
            <h2><?php echo __('Overdued List'); ?></h2>
        </div>
        <div class="infoBox">
          <?php echo __('Report Filter'); ?>
        </div>
        <div class="sub_section">
            <form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>" target="reportView">
                <div id="filterForm">
                    <div class="divRow">
                        <div class="divRowLabel"><?php echo __('Member ID') . '/' . __('Member Name'); ?></div>
                        <div class="divRowContent">
                          <?php
                            echo simbio_form_element::textField('text', 'id_name', '', 'class="form-control" style="width: 50%"');
                                ?>
                        </div>
                    </div>
                    <div class="form-group divRow">
                        <div class="divRowContent">
                            <div>
                                <label style="width: 195px;"><?php echo __('Loan Date From'); ?></label>
                                <label><?php echo __('Loan Date Until'); ?></label>
                            </div>
                            <div id="range">
                                <input type="text" name="startDate" value="2000-01-01">
                                <span><?= __('to') ?></span>
                                <input type="text" name="untilDate" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="divRow">
                        <div class="divRowLabel"><?php echo __('Record each page'); ?></div>
                        <div class="divRowContent"><input type="text" name="recsEachPage" class="form-control col-1" size="3" maxlength="3" value="<?php echo $num_recs_show; ?>"/> <?php echo __('Set between 20 and 200'); ?>
                        </div>
                    </div>
                </div>
                <div style="padding-top: 10px; clear: both;">
                    <input type="button" name="moreFilter" class="btn btn-default"  value="<?php echo __('Show More Filter Options'); ?>"/>
                    <input type="submit" class="btn btn-primary" name="applyFilter" value="<?php echo __('Apply Filter'); ?>"/>
                    <input type="hidden" name="reportView" value="true"/>
                </div>
            </form>
        </div>
    </div>
    <script>
        $(document).ready(function(){
            const elem = document.getElementById('range');
            const dateRangePicker = new DateRangePicker(elem, {
                language: '<?= substr($sysconf['default_lang'], 0,2) ?>',
                format: 'yyyy-mm-dd',
            });
        })
    </script>
    <!-- filter end -->
    <div class="dataListHeader" style="padding: 3px;"><span id="pagingBox"></span></div>
    <iframe name="reportView" id="reportView" src="<?php echo $_SERVER['PHP_SELF'] . '?reportView=true'; ?>"
            frameborder="0" style="width: 100%; height: 500px;"></iframe>
  <?php
} else {
    ob_start();
    // table spec
    $table_spec = 'member AS m
      LEFT JOIN loan AS l ON m.member_id=l.member_id';

    // create datagrid
    $reportgrid = new report_datagrid();
    $reportgrid->setSQLColumn('m.member_id AS \'' . __('Member ID') . '\'');
    $reportgrid->setSQLorder('MAX(l.due_date) DESC');
    $reportgrid->sql_group_by = 'm.member_id';

    $overdue_criteria = ' (l.is_lent=1 AND l.is_return=0 AND TO_DAYS(due_date) < TO_DAYS(\'' . date('Y-m-d') . '\')) ';
    // is there any search
    if (isset($_GET['id_name']) and $_GET['id_name']) {
        $keyword = $dbs->escape_string(trim($_GET['id_name']));
        $words = explode(' ', $keyword);
        if (count($words) > 1) {
            $concat_sql = ' (';
            foreach ($words as $word) {
                $concat_sql .= " (m.member_id LIKE '%$word%' OR m.member_name LIKE '%$word%') AND";
            }
            // remove the last AND
            $concat_sql = substr_replace($concat_sql, '', -3);
            $concat_sql .= ') ';
            $overdue_criteria .= ' AND ' . $concat_sql;
        } else {
            $overdue_criteria .= " AND m.member_id LIKE '%$keyword%' OR m.member_name LIKE '%$keyword%'";
        }
    }
    // loan date
    if (isset($_GET['startDate']) and isset($_GET['untilDate'])) {
        $date_criteria = ' AND (TO_DAYS(l.loan_date) BETWEEN TO_DAYS(\'' . $_GET['startDate'] . '\') AND
          TO_DAYS(\'' . $_GET['untilDate'] . '\'))';
        $overdue_criteria .= $date_criteria;
    }
    if (isset($_GET['recsEachPage'])) {
        $recsEachPage = (integer) $_GET['recsEachPage'];
        $num_recs_show = ($recsEachPage >= 5 && $recsEachPage <= 200) ? $recsEachPage : $num_recs_show;
    }
    $reportgrid->setSQLCriteria($overdue_criteria);

    // set table and table header attributes
    $reportgrid->table_attr = 'align="center" class="dataListPrinted" cellpadding="5" cellspacing="0"';
    $reportgrid->table_header_attr = 'class="dataListHeaderPrinted"';
    $reportgrid->column_width = array('1' => '80%');

    
    /**
     * Normalisasi nomor HP Indonesia -> 62xxxxxxxxxx
     * Menerima input: 0821-9580-3039, 0858 4933 9348, +62 877 8077 4181, 6281299..., dst.
     * Menghasilkan hanya digit, diawali 62. Jika kosong/invalid -> return null.
     */
    function normalize_phone_id($phone)
    {
        // buang semua non-digit
        $digits = preg_replace('/\D+/', '', (string)$phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        // perbaiki kasus "620812..." (orang nulis +6208...)
        $digits = preg_replace('/^620+/', '62', $digits);

        if (strpos($digits, '62') === 0) {
            return $digits; // sudah OK
        }

        // 08xxxxxxxxxx -> 62xxxxxxxxxx
        if ($digits[0] === '0') {
            return '62' . substr($digits, 1);
        }

        // 8xxxxxxxxxx -> 62xxxxxxxxxx (kadang user ngetik tanpa 0)
        if ($digits[0] === '8') {
            return '62' . $digits;
        }

        // fallback: apa pun yang belum ada kode negara, tambahkan 62
        return '62' . $digits;
    }

    // callback function to show overdued list
    function showOverduedList($obj_db, $array_data)
    {
        global $date_criteria, $sysconf;

        $circulation = new circulation($obj_db, $array_data[0]);
        $circulation->ignore_holidays_fine_calc = $sysconf['ignore_holidays_fine_calc'];
        $circulation->holiday_dayname = $_SESSION['holiday_dayname'];
        $circulation->holiday_date = $_SESSION['holiday_date'];

        // member name
        $member_q = $obj_db->query('SELECT m.member_name, m.member_email, m.member_phone, m.member_mail_address, mmt.fine_each_day 
                                   FROM member m 
                                   LEFT JOIN mst_member_type mmt on m.member_type_id = mmt.member_type_id
                                   WHERE m.member_id=\'' . $array_data[0] . '\'');
        $member_d = $member_q->fetch_row();

        $member_name = $member_d[0];
        $member_mail_address = $member_d[3];

        // Normalisasi nomor WA sekali di awal
        $waNumber = normalize_phone_id($member_d[2]);

        unset($member_q);

        $ovd_title_q = $obj_db->query('SELECT l.loan_id, l.item_code, i.price, i.price_currency,
          b.title, l.loan_date,
          l.due_date, (TO_DAYS(DATE(NOW()))-TO_DAYS(due_date)) AS \'Overdue Days\', mlr.fine_each_day,
          loc.location_name
          FROM loan AS l
              LEFT JOIN item AS i ON l.item_code=i.item_code
              LEFT JOIN biblio AS b ON i.biblio_id=b.biblio_id
              LEFT JOIN mst_loan_rules mlr on l.loan_rules_id = mlr.loan_rules_id
              LEFT JOIN mst_location loc ON i.location_id = loc.location_id
          WHERE (l.is_lent=1 AND l.is_return=0 AND TO_DAYS(due_date) < TO_DAYS(\'' . date('Y-m-d') . '\')) AND l.member_id=\'' . $array_data[0] . '\'' . (!empty($date_criteria) ? $date_criteria : ''));
        $_buffer = '<div style="font-weight: bold; color: black; font-size: 10pt; margin-bottom: 3px;">' . $member_name . ' (' . $array_data[0] . ')</div>';
        $_buffer .= '<div style="color: black; font-size: 10pt; margin-bottom: 3px;">' . $member_mail_address . '</div>';
        if (!empty($member_d[1])) $_buffer .= '<div style="font-size: 10pt; margin-bottom: 3px;"><div id="' . $array_data[0] . 'emailStatus"></div>' . __('E-mail') . ' : <a href="mailto:' . $member_d[1] . '">' . $member_d[1] . '</a> - <a class="usingAJAX btn btn-sm btn-outline-primary" href="' . MWB . 'membership/overdue_mail.php' . '" postdata="memberID=' . $array_data[0] . '" loadcontainer="' . $array_data[0] . 'emailStatus"><i class="fa fa-paper-plane-o"></i>&nbsp;' . __('Send Notification e-mail') . '</a> <br/>';
        $_buffer .='</div>';
        $_buffer .= '<table width="100%" cellspacing="0">';
        while ($ovd_title_d = $ovd_title_q->fetch_assoc()) {

            //calculate Fines
            $overdue_days = $circulation->countOverdueValue($ovd_title_d['loan_id'], date('Y-m-d'))['days'];
            // because SLiMS have a grace periode feature in circulation modules,
            // make sure $overdue_days is numeric or not, if not then set it to 0
            // or if its bool then cast to integer
            $overdue_days = !is_numeric($overdue_days) ? 0 : (int)$overdue_days;
            $fines = currency($overdue_days * $member_d[4]);
            if (!is_null($ovd_title_d['fine_each_day'])) $fines = $overdue_days * $ovd_title_d['fine_each_day'];
            // format number
            $overdue_days = number_format($overdue_days, '0', ',', '.');

            $_buffer .= '<tr>';
            $_buffer .= '<td valign="top" width="10%">';
            $_buffer .= $ovd_title_d['item_code'];
            if (!empty($ovd_title_d['location_name'])) {
                $_buffer .= '<div style="font-size: 9pt; font-weight: bold; font-style: italic; color: #007BFF;">';
                $_buffer .= '📍 <span>' . __('Location') . ': ' . $ovd_title_d['location_name'] . '</span>';
                $_buffer .= '</div>';
            }
            $_buffer .= '</td>';

            $_buffer .= '<td valign="top" width="20%">' . $ovd_title_d['title'] . '<div>' . __('Book Price') . ': ' . currency($ovd_title_d['price']) . '</div></td>';
            $_buffer .= '<td width="20%"><div>' . __('Overdue') . ': ' . $overdue_days . ' ' . __('day(s)') . '</div><div>'.__('Fines').': '.$fines.'</div></td>';
            $_buffer .= '<td width="30%">' . __('Loan Date') . ': ' . $ovd_title_d['loan_date'] . ' &nbsp; ' . __('Due Date') . ': ' . $ovd_title_d['due_date'] . '</td>';
            // Add by Erwan Setyo Budi
            // simpan nilai integer-nya dulu sebelum diformat untuk tampilan
            $overdue_days_int = (int)$overdue_days; // sudah dipastikan numeric di atas

            $message = "Pemberitahuan bahwa *{$member_d[0]},* ada pinjaman buku dengan keterlambatan *{$overdue_days_int} hari* di Perpustakaan dengan Kode Barcode: *{$ovd_title_d['item_code']}*, Judul: *{$ovd_title_d['title']}*. Tanggal harus kembali : {$ovd_title_d['due_date']}. " . __('Fines') . ": {$fines}. Terima Kasih. {$sysconf['library_name']}-{$sysconf['library_subname']}";
            $waLink  = $waNumber ? ("https://wa.me/{$waNumber}?text=" . rawurlencode($message)) : '#';
            $waLabel = $waNumber ? $waNumber : __('No phone number');

            $_buffer .= '<td width="20%"><p>Kirim Notifikasi WA ';
            $_buffer .= '<a class="btn btn-sm btn-outline-primary'.($waNumber ? '' : ' disabled').'" href="'.$waLink.'" target="_blank">';
            $_buffer .= '<i class="fa fa-paper-plane-o"></i> '.$waLabel.'</a></p></td>';
            $_buffer .= '</tr>';
        }
        if ($waNumber) {
            $_buffer .= '<div style="color: black; font-size: 10pt; margin-bottom: 3px;">WhatsApp: '.$waNumber.'</div>';
        }

        $_buffer .= '</table>';
        return $_buffer;
    }

    // modify column value
    $reportgrid->modifyColumnContent(0, 'callback{showOverduedList}');

    echo '<div style="margin: 10px 0;"><button id="sendAllEmails" class="btn btn-danger">📧 Kirim Semua Notifikasi Email</button></div>';

    // put the result into variables
    echo $reportgrid->createDataGrid($dbs, $table_spec, $num_recs_show);

    // Ambil semua member_id yang tampil
    $all_member_ids = [];
    $member_id_result = $dbs->query("SELECT DISTINCT m.member_id FROM member AS m
        LEFT JOIN loan AS l ON m.member_id=l.member_id
        WHERE l.is_lent=1 AND l.is_return=0 AND TO_DAYS(due_date) < TO_DAYS(CURDATE()) $date_criteria");

    while ($row = $member_id_result->fetch_assoc()) {
        $all_member_ids[] = $row['member_id'];
    }
    echo '<script>const overdueMembers = ' . json_encode($all_member_ids) . ';</script>';


    ?>
    <script type="text/javascript" src="<?php echo JWB . 'jquery.js'; ?>"></script>
    <script type="text/javascript" src="<?php echo JWB . 'updater.js'; ?>"></script>
    <script type="text/javascript">
        // registering event for send email button
        $(document).ready(function () {
            parent.$('#pagingBox').html('<?php echo str_replace(array("\n", "\r", "\t"), '', $reportgrid->paging_set) ?>');
            $('a.usingAJAX').click(function (evt) {
                evt.preventDefault();
                var anchor = $(this);
                // get anchor href
                var url = anchor.attr('href');
                var postData = anchor.attr('postdata');
                var loadContainer = anchor.attr('loadcontainer');
                if (loadContainer) {
                    container = jQuery('#' + loadContainer);
                    container.html('<div class="alert alert-info">Please wait....</div>');
                }
                // set ajax
                if (postData) {
                    container.simbioAJAX(url, {method: 'post', addData: postData});
                } else {
                    container.simbioAJAX(url, {addData: {ajaxload: 1}});
                }
            });
        });
        $('#sendAllEmails').click(async function () {
            if (!confirm('Yakin ingin mengirim notifikasi ke semua anggota yang terlambat?')) return;

            let delay = 1000; // jeda antar email
            for (let i = 0; i < overdueMembers.length; i++) {
                let memberID = overdueMembers[i];
                let containerID = memberID + 'emailStatus';
                let container = $('#' + containerID);
                if (container.length === 0) {
                    container = $('<div id="' + containerID + '"></div>');
                    $('body').append(container);
                }
                container.html('<div class="alert alert-info">Mengirim email ke ' + memberID + '...</div>');

                await $.ajax({
                    url: '<?php echo MWB . "membership/overdue_mail.php"; ?>',
                    method: 'POST',
                    data: {memberID: memberID},
                    success: function (res) {
                        container.html('<div class="alert alert-success">Sukses kirim ke ' + memberID + '</div>');
                    },
                    error: function () {
                        container.html('<div class="alert alert-danger">Gagal kirim ke ' + memberID + '</div>');
                    }
                });

                await new Promise(r => setTimeout(r, delay));
            }

            alert('Selesai mengirim semua notifikasi!');
        });

    </script>
  <?php

    $content = ob_get_clean();
    // include the page template
    require SB . '/admin/' . $sysconf['admin_template']['dir'] . '/printed_page_tpl.php';
}
