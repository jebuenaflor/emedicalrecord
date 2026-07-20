<?php

/**
 * Consultation report with CSV and XLSX exports.
 *
 * Install this file in interface/reports and add it to the Reports menu using
 * Administration > Menus.  It reads OpenEMR's standard encounter, patient,
 * calendar-category, and user tables.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * OpenEMR has no standard "nurse on duty" encounter field.  If the site keeps
 * it in a custom form/table, replace the expression below and add its JOIN to
 * consultationReportSql().  For example: CONCAT_WS(' ', nurse.fname,
 * nurse.mname, nurse.lname) and LEFT JOIN users AS nurse ON nurse.id = cf.nurse_id.
 */
const CONSULTATION_NURSE_EXPRESSION = "''";

if (!AclMain::aclCheckCore('patients', 'med')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render(
        'core/unauthorized.html.twig',
        ['pageTitle' => xl('Consultation Report')]
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    CsrfUtils::csrfNotVerified();
}

function consultationReportDate(string $value, string $fallback): string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function consultationReportSql(string $fromDate, string $toDate, int $categoryId, int $providerId): array
{
    $bind = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
    $sql = "SELECT
                fe.pid AS patient_id,
                fe.encounter AS transaction_id,
                DATE(fe.date) AS date_of_consult,
                TIME(fe.date) AS consult_time,
                CONCAT_WS(' ', p.lname, p.fname, NULLIF(p.mname, '')) AS patient_name,
                TIMESTAMPDIFF(YEAR, p.dob, DATE(fe.date))
                    - (DATE_FORMAT(DATE(fe.date), '%m%d') < DATE_FORMAT(p.dob, '%m%d')) AS age,
                p.sex AS gender,
                fe.class_code AS classification,
                COALESCE(cat.pc_catname, '') AS category,
                COALESCE(NULLIF(fe.encounter_type_description, ''), NULLIF(fe.reason, '')) AS consultation_type,
                CONCAT_WS(' ', physician.lname, physician.fname, NULLIF(physician.mname, '')) AS physician_on_duty,
                " . CONSULTATION_NURSE_EXPRESSION . " AS nurse_on_duty
            FROM form_encounter AS fe
            INNER JOIN patient_data AS p ON p.pid = fe.pid
            LEFT JOIN users AS physician ON physician.id = fe.provider_id
            LEFT JOIN openemr_postcalendar_categories AS cat ON cat.pc_catid = fe.pc_catid
            WHERE fe.date >= ? AND fe.date <= ? ";

    if ($categoryId > 0) {
        $sql .= 'AND fe.pc_catid = ? ';
        $bind[] = $categoryId;
    }
    if ($providerId > 0) {
        $sql .= 'AND fe.provider_id = ? ';
        $bind[] = $providerId;
    }

    $sql .= 'ORDER BY fe.date ASC, fe.encounter ASC';
    return [$sql, $bind];
}

function consultationReportRows(string $fromDate, string $toDate, int $categoryId, int $providerId): array
{
    [$sql, $bind] = consultationReportSql($fromDate, $toDate, $categoryId, $providerId);
    $statement = sqlStatement($sql, $bind);
    $rows = [];
    while ($row = sqlFetchArray($statement)) {
        $rows[] = $row;
    }
    return $rows;
}

function consultationReportHeaders(): array
{
    return [
        'Patient ID', 'Transaction ID', 'Date of Consult', 'Time', 'Name', 'Age',
        'Gender', 'Classification', 'Category', 'Consultation Type',
        'Physician on Duty', 'Nurse on Duty',
    ];
}

function consultationReportExportRows(array $rows): array
{
    return array_map(static fn($row) => array_values($row), $rows);
}

$today = date('Y-m-d');
$fromDate = consultationReportDate($_REQUEST['from_date'] ?? $today, $today);
$toDate = consultationReportDate($_REQUEST['to_date'] ?? $today, $today);
if ($toDate < $fromDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}
$categoryId = max(0, (int) ($_REQUEST['category_id'] ?? 0));
$providerId = max(0, (int) ($_REQUEST['provider_id'] ?? 0));
$action = $_POST['action'] ?? '';

if (in_array($action, ['csv', 'xlsx'], true)) {
    $rows = consultationReportExportRows(consultationReportRows($fromDate, $toDate, $categoryId, $providerId));
    $filename = 'consultation_report_' . $fromDate . '_to_' . $toDate;

    if ($action === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo "\xEF\xBB\xBF"; // Excel UTF-8 BOM
        $output = fopen('php://output', 'wb');
        fputcsv($output, consultationReportHeaders());
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Consultations');
    $sheet->fromArray(consultationReportHeaders(), null, 'A1');
    $sheet->fromArray($rows, null, 'A2');
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);
    $sheet->freezePane('A2');
    foreach (range('A', 'L') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

$categories = [];
$categoryStatement = sqlStatement('SELECT pc_catid, pc_catname FROM openemr_postcalendar_categories WHERE pc_active = 1 ORDER BY pc_catname');
while ($category = sqlFetchArray($categoryStatement)) {
    $categories[] = $category;
}
$providers = [];
$providerStatement = sqlStatement("SELECT id, fname, mname, lname FROM users WHERE active = 1 ORDER BY lname, fname");
while ($provider = sqlFetchArray($providerStatement)) {
    $providers[] = $provider;
}
$rows = consultationReportRows($fromDate, $toDate, $categoryId, $providerId);
?>
<!doctype html>
<html lang="en">
<head>
    <title><?php echo xlt('Consultation Report'); ?></title>
    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>
</head>
<body class="body_top">
<span class="title"><?php echo xlt('Consultation Report'); ?></span>
<form method="post" action="consultation_report.php" class="my-3" onsubmit="return top.restoreSession()">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
    <div class="form-row align-items-end">
        <div class="col-md-2"><label for="from_date"><?php echo xlt('From'); ?></label><input class="form-control datepicker" id="from_date" name="from_date" value="<?php echo attr($fromDate); ?>"></div>
        <div class="col-md-2"><label for="to_date"><?php echo xlt('To'); ?></label><input class="form-control datepicker" id="to_date" name="to_date" value="<?php echo attr($toDate); ?>"></div>
        <div class="col-md-3"><label for="category_id"><?php echo xlt('Category'); ?></label><select class="form-control" id="category_id" name="category_id"><option value="0"><?php echo xlt('All'); ?></option><?php foreach ($categories as $category) { ?><option value="<?php echo attr($category['pc_catid']); ?>"<?php echo $categoryId === (int) $category['pc_catid'] ? ' selected' : ''; ?>><?php echo text(xl_appt_category($category['pc_catname'])); ?></option><?php } ?></select></div>
        <div class="col-md-3"><label for="provider_id"><?php echo xlt('Physician'); ?></label><select class="form-control" id="provider_id" name="provider_id"><option value="0"><?php echo xlt('All'); ?></option><?php foreach ($providers as $provider) { ?><option value="<?php echo attr($provider['id']); ?>"<?php echo $providerId === (int) $provider['id'] ? ' selected' : ''; ?>><?php echo text(trim($provider['lname'] . ', ' . $provider['fname'] . ' ' . $provider['mname'])); ?></option><?php } ?></select></div>
        <div class="col-md-2"><button class="btn btn-primary" name="action" value="view"><?php echo xlt('Search'); ?></button> <button class="btn btn-secondary" name="action" value="csv"><?php echo xlt('CSV'); ?></button> <button class="btn btn-secondary" name="action" value="xlsx"><?php echo xlt('XLSX'); ?></button></div>
    </div>
</form>
<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><?php foreach (consultationReportHeaders() as $header) { ?><th><?php echo text(xl($header)); ?></th><?php } ?></tr></thead><tbody><?php foreach ($rows as $row) { ?><tr><?php foreach ($row as $value) { ?><td><?php echo text($value); ?></td><?php } ?></tr><?php } ?></tbody></table></div>
</body>
</html>
