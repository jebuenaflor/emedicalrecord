<?php

/**
 * Consultation report with CSV and XLSX exports.
 *
 * Parameters: date_start, date_end, category, physician, and format.
 * Set format to csv or xlsx to download the report; omit it to view the report.
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

function consultationReportSql(string $fromDate, string $toDate, string $category, int $providerId): array
{
    $bind = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
    $sql = "SELECT
                fe.pid AS patient_id,
                fe.encounter AS transaction_id,
                DATE(fe.date) AS date_of_consult,
                TIME(fe.date) AS consult_time,
                CONCAT_WS(' ', p.fname, NULLIF(p.mname, ''), p.lname) AS patient_name,
                TIMESTAMPDIFF(YEAR, p.dob, DATE(fe.date))
                    - (DATE_FORMAT(DATE(fe.date), '%m%d') < DATE_FORMAT(p.dob, '%m%d')) AS age,
                p.sex AS gender,
                fe.encounter_type AS classification,
                fe.visit_category AS category,
                fe.encounter_type AS consultation_type,
                CONCAT_WS(' ', physician.fname, NULLIF(physician.mname, ''), physician.lname) AS physician_on_duty,
                CONCAT_WS(' ', nurse.fname, NULLIF(nurse.mname, ''), nurse.lname) AS nurse_on_duty
            FROM form_encounter AS fe
            INNER JOIN patient_data AS p ON p.pid = fe.pid
            LEFT JOIN users AS physician ON physician.id = fe.provider_id
            LEFT JOIN users AS nurse ON nurse.id = fe.supervisor_id
            WHERE fe.date >= ? AND fe.date <= ? ";

    if ($category !== '') {
        $sql .= 'AND fe.visit_category = ? ';
        $bind[] = $category;
    }
    if ($providerId > 0) {
        $sql .= 'AND fe.provider_id = ? ';
        $bind[] = $providerId;
    }

    $sql .= 'ORDER BY fe.date ASC, fe.encounter ASC';
    return [$sql, $bind];
}

function consultationReportRows(string $fromDate, string $toDate, string $category, int $providerId): array
{
    [$sql, $bind] = consultationReportSql($fromDate, $toDate, $category, $providerId);
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
$fromDate = consultationReportDate($_REQUEST['date_start'] ?? $today, $today);
$toDate = consultationReportDate($_REQUEST['date_end'] ?? $today, $today);
if ($toDate < $fromDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}
$category = trim((string) ($_REQUEST['category'] ?? ''));
$providerId = max(0, (int) ($_REQUEST['physician'] ?? 0));
$action = strtolower((string) ($_REQUEST['format'] ?? ''));

if (in_array($action, ['csv', 'xlsx'], true)) {
    $rows = consultationReportExportRows(consultationReportRows($fromDate, $toDate, $category, $providerId));
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
$categoryStatement = sqlStatement("SELECT DISTINCT visit_category FROM form_encounter WHERE visit_category IS NOT NULL AND visit_category <> '' ORDER BY visit_category");
while ($categoryRow = sqlFetchArray($categoryStatement)) {
    $categories[] = $categoryRow;
}
$providers = [];
$providerStatement = sqlStatement("SELECT id, fname, mname, lname FROM users WHERE active = 1 ORDER BY lname, fname");
while ($provider = sqlFetchArray($providerStatement)) {
    $providers[] = $provider;
}
$rows = consultationReportRows($fromDate, $toDate, $category, $providerId);
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
        <div class="col-md-2"><label for="date_start"><?php echo xlt('From'); ?></label><input class="form-control datepicker" id="date_start" name="date_start" value="<?php echo attr($fromDate); ?>"></div>
        <div class="col-md-2"><label for="date_end"><?php echo xlt('To'); ?></label><input class="form-control datepicker" id="date_end" name="date_end" value="<?php echo attr($toDate); ?>"></div>
        <div class="col-md-3"><label for="category"><?php echo xlt('Category'); ?></label><select class="form-control" id="category" name="category"><option value=""><?php echo xlt('All'); ?></option><?php foreach ($categories as $categoryRow) { ?><option value="<?php echo attr($categoryRow['visit_category']); ?>"<?php echo $category === $categoryRow['visit_category'] ? ' selected' : ''; ?>><?php echo text($categoryRow['visit_category']); ?></option><?php } ?></select></div>
        <div class="col-md-3"><label for="physician"><?php echo xlt('Physician'); ?></label><select class="form-control" id="physician" name="physician"><option value="0"><?php echo xlt('All'); ?></option><?php foreach ($providers as $provider) { ?><option value="<?php echo attr($provider['id']); ?>"<?php echo $providerId === (int) $provider['id'] ? ' selected' : ''; ?>><?php echo text(trim($provider['lname'] . ', ' . $provider['fname'] . ' ' . $provider['mname'])); ?></option><?php } ?></select></div>
        <div class="col-md-2"><button class="btn btn-primary"><?php echo xlt('Search'); ?></button> <button class="btn btn-secondary" name="format" value="csv"><?php echo xlt('CSV'); ?></button> <button class="btn btn-secondary" name="format" value="xlsx"><?php echo xlt('XLSX'); ?></button></div>
    </div>
</form>
<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><?php foreach (consultationReportHeaders() as $header) { ?><th><?php echo text(xl($header)); ?></th><?php } ?></tr></thead><tbody><?php foreach ($rows as $row) { ?><tr><?php foreach ($row as $value) { ?><td><?php echo text($value); ?></td><?php } ?></tr><?php } ?></tbody></table></div>
</body>
</html>
