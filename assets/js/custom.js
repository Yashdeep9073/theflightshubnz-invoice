if (window.history.replaceState) {
  window.history.replaceState(null, null, window.location.href);
}

// Function to export to Excel
function exportToExcel() {
    let table = $('#myTable').DataTable(); // Initialize DataTables API
    let selectedRows = [];

    // Get selected invoice IDs
    let checkboxes = table.rows().nodes().to$().find('input[name="invoiceIds"]:checked');
    let selectedInvoiceIds = checkboxes.map((i, checkbox) => checkbox.value).get();

    // If no checkboxes are selected, include all rows; otherwise, filter rows
    if (selectedInvoiceIds.length === 0) {
        selectedRows = table.rows().nodes().toArray(); // Get all rows from DataTables
    } else {
        selectedRows = [table.rows().nodes().toArray()[0]]; // Include header row
        table.rows().nodes().each(function(row) {
            let checkbox = $(row).find('input[name="invoiceIds"]');
            if (checkbox.length && selectedInvoiceIds.includes(checkbox.val())) {
                selectedRows.push(row);
            }
        });
    }

    // Create a new table for export
    let tempTable = document.createElement("table");
    for (let row of selectedRows) {
        tempTable.appendChild(row.cloneNode(true));
    }

    // Remove the Action column
    let actionColumnIndex = -1;
    let headerCells = tempTable.rows[0].cells;
    for (let i = 0; i < headerCells.length; i++) {
        if (headerCells[i].innerText.trim().toLowerCase() === "action") {
            actionColumnIndex = i;
            break;
        }
    }
    if (actionColumnIndex !== -1) {
        for (let row of tempTable.rows) {
            if (row.cells.length > actionColumnIndex) {
                row.deleteCell(actionColumnIndex);
            }
        }
    }

    // Remove the checkbox column (first column) and currency symbols
    for (let row of tempTable.rows) {
        if (row.cells.length > 0) {
            row.deleteCell(0); // Remove checkbox column
        }
        for (let cell of row.cells) {
            cell.innerText = cell.innerText.replace(/[₹$]/g, '').trim(); // Remove currency symbols
        }
    }

    // Export to Excel
    let workbook = XLSX.utils.table_to_book(tempTable, { sheet: "Sheet1" });
    XLSX.writeFile(workbook, "invoices.xlsx");
}

// Function to export to PDF
function exportToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    let table = $('#myTable').DataTable(); // Initialize DataTables API
    let selectedRows = [];

    // Get selected invoice IDs
    let checkboxes = table.rows().nodes().to$().find('input[name="invoiceIds"]:checked');
    let selectedInvoiceIds = checkboxes.map((i, checkbox) => checkbox.value).get();

    // If no checkboxes are selected, include all rows; otherwise, filter rows
    if (selectedInvoiceIds.length === 0) {
        selectedRows = table.rows().nodes().toArray(); // Get all rows from DataTables
    } else {
        selectedRows = [table.rows().nodes().toArray()[0]]; // Include header row
        table.rows().nodes().each(function(row) {
            let checkbox = $(row).find('input[name="invoiceIds"]');
            if (checkbox.length && selectedInvoiceIds.includes(checkbox.val())) {
                selectedRows.push(row);
            }
        });
    }

    // Prepare data for PDF
    let tableData = [];
    let headers = [];
    for (let i = 0; i < selectedRows[0].cells.length; i++) {
        let headerText = selectedRows[0].cells[i].innerText.trim().toLowerCase();
        if (headerText !== "action" && headerText !== "") { // Skip Action and checkbox columns
            headers.push(selectedRows[0].cells[i].innerText);
        }
    }

    for (let i = 1; i < selectedRows.length; i++) {
        let rowData = [];
        for (let j = 0; j < selectedRows[i].cells.length; j++) {
            let cellText = selectedRows[i].cells[j].innerText.trim();
            if (j !== 0 && selectedRows[i].cells[j].innerText.trim().toLowerCase() !== "action") { // Skip checkbox and Action columns
                // Remove currency symbols (₹, $) from cell text
                cellText = cellText.replace(/[₹$]/g, '').trim();
                rowData.push(cellText);
            }
        }
        if (rowData.length > 0) {
            tableData.push(rowData);
        }
    }

    // Generate PDF
    doc.autoTable({
        head: [headers],
        body: tableData,
        theme: 'striped',
        styles: { fontSize: 10 },
        margin: { top: 20 }
    });

    doc.save("invoices.pdf");
}


