import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const source = "C:/Users/Acer/Downloads/02_Client Work/UPV_SeniorDatabaseAdministrator/03_ActiveProjects/HSU_eMedicalRecords/HSU_Reports TODO/Annex A - EMR Sample Report.xlsx";
const file = await FileBlob.load(source);
const workbook = await SpreadsheetFile.importXlsx(file);
const summary = await workbook.inspect({
  kind: "workbook,sheet,table,formula,drawing",
  maxChars: 12000,
  tableMaxRows: 18,
  tableMaxCols: 16,
});
console.log(summary.ndjson);
const preview = await workbook.render({ sheetName: "DEC2024", autoCrop: "all", scale: 2, format: "png" });
await fs.writeFile("source-preview.png", new Uint8Array(await preview.arrayBuffer()));
for (const name of ["Sheet1", "Report", "EMR Report"]) {
  try {
    const sheet = workbook.worksheets.getItem(name);
    const used = sheet.getUsedRange();
    console.log(`USED ${name}: ${used.address}`);
  } catch {}
}
