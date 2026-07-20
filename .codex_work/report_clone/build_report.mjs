import fs from "node:fs/promises";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const outputDir = "../../outputs/emr_monthly_report";
await fs.mkdir(outputDir, { recursive: true });

const workbook = Workbook.create();
const sheet = workbook.worksheets.add("DEC2024");
sheet.showGridLines = false;

const headers = [[
  "Patient ID /\nTransaction ID", "Date of\nConsult", "Time", "Name", "Age", "Gender",
  "Classification", "Category", "Consultation\nType", "Physician on Duty", "Nurse on Duty",
]];
sheet.getRange("A1:K1").values = headers;
sheet.getRange("A1:K1").format = {
  fill: "#00E6E6",
  font: { bold: true, color: "#000000", size: 16 },
  horizontalAlignment: "center",
  verticalAlignment: "center",
  wrapText: true,
  borders: { preset: "all", style: "medium", color: "#000000" },
};
sheet.getRange("A1:K1").format.rowHeight = 64;

const widths = [31, 19, 19, 38, 12, 15, 27, 27, 28, 42, 42];
for (let col = 0; col < widths.length; col += 1) {
  sheet.getRangeByIndexes(0, col, 101, 1).format.columnWidth = widths[col];
}

const entryRange = sheet.getRange("A2:K101");
entryRange.format = {
  verticalAlignment: "center",
  wrapText: true,
  borders: { preset: "all", style: "thin", color: "#BFBFBF" },
};
entryRange.format.rowHeight = 25;
sheet.getRange("A2:A101").format.numberFormat = "@";
sheet.getRange("B2:B101").format.numberFormat = "dd-mmm-yy";
sheet.getRange("C2:C101").format.numberFormat = "h:mm AM/PM";
sheet.getRange("E2:E101").format.numberFormat = "#,##0";
sheet.getRange("E2:E101").format.horizontalAlignment = "center";
sheet.getRange("B2:C101").format.horizontalAlignment = "center";

sheet.getRange("F2:F101").dataValidation = { rule: { type: "list", values: ["Male", "Female", "Other", "Prefer not to say"] } };
sheet.getRange("I2:I101").dataValidation = { rule: { type: "list", values: ["Face to Face", "Teleconsult", "Follow-up"] } };

sheet.freezePanes.freezeRows(1);
const preview = await workbook.render({ sheetName: "DEC2024", range: "A1:K18", scale: 1, format: "png" });
await fs.writeFile(`${outputDir}/preview.png`, new Uint8Array(await preview.arrayBuffer()));

const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(`${outputDir}/Annex A - EMR Monthly Report Template.xlsx`);

const check = await workbook.inspect({ kind: "table", range: "A1:K12", include: "values,formulas", tableMaxRows: 12, tableMaxCols: 11 });
console.log(check.ndjson);
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A", options: { useRegex: true, maxResults: 50 }, summary: "formula error scan" });
console.log(errors.ndjson);
