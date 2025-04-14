$(document).ready(function() {
    $(".datepicker").datepicker({
        dateFormat: "yy-mm-dd",
        firstDay: 1,
        maxDate: 0,
        changeMonth: true,
        changeYear: true,
        showButtonPanel: true,
        beforeShow: function(input, inst) {
            setTimeout(function() {
                inst.dpDiv.css({
                    zIndex: 9999
                });
            }, 0);
        },
        monthNames: ["Styczeń", "Luty", "Marzec", "Kwiecień", "Maj", "Czerwiec", "Lipiec", "Sierpień", "Wrzesień", "Październik", "Listopad", "Grudzień"],
        monthNamesShort: ["Sty", "Lut", "Mar", "Kwi", "Maj", "Cze", "Lip", "Sie", "Wrz", "Paź", "Lis", "Gru"],
        dayNames: ["Niedziela", "Poniedziałek", "Wtorek", "Środa", "Czwartek", "Piątek", "Sobota"],
        dayNamesShort: ["Nie", "Pn", "Wt", "Śr", "Cz", "Pt", "So"],
        dayNamesMin: ["N", "Pn", "Wt", "Śr", "Cz", "Pt", "So"]
    }).attr("autocomplete", "off");

    $("#dateForm").on("submit", function(event) {
        event.preventDefault();

        const startDate = $("#startDate").val();
        const endDate = $("#endDate").val();

        const start = new Date(startDate);
        const end = new Date(endDate);
        const diff = Math.floor((end - start) / (1000 * 60 * 60 * 24));

        if (startDate >= endDate || diff > 31) {
            alert("Data początkowa nie może być większa niż data końcowa, a różnica nie może przekraczać 31 dni.");
            return;
        }

        $("#loadingMessage").show();
        $("#companyButtons, #samDetails").hide();
        $("#companyButtons").empty();
        $("#samDetails").empty();

        let workTimeData, ordersData;

        // Pobierz dane JSON dotyczące czasu pracy
        $.ajax({
            url: 'fetch_json.php',
            method: 'POST',
            data: { startDate, endDate },
            success: function(response) {
                workTimeData = response;
                if (ordersData) {
                    displayCompanyButtons(ordersData);
                }
            },
            error: function() {
                $("#loadingMessage").hide();
                alert("Wystąpił błąd podczas przetwarzania danych pracy.");
            }
        });

        // Pobierz dane JSON dotyczące zamówień
        $.ajax({
            url: 'fetch_orders.php',
            method: 'POST',
            data: { startDate, endDate },
            success: function(response) {
                ordersData = response;
                if (workTimeData) {
                    displayCompanyButtons(ordersData);
                }
            },
            error: function() {
                $("#loadingMessage").hide();
                alert("Wystąpił błąd podczas przetwarzania zamówień.");
            }
        });

        function displayCompanyButtons(ordersData) {
            $("#loadingMessage").hide();
            $("#companyButtons").show();
            $.each(ordersData, function(companyCode) {
                const button = $('<button>')
                    .addClass('btn btn-secondary company-button')
                    .html(`<i class="bi bi-building"></i> ${companyCode}`)
                    .on('click', function() {
                        $("#samDetails").show();
                        displaySAMDetails(ordersData[companyCode], startDate, endDate);
                    });
                $("#companyButtons").append(button);
            });
        }

        function displaySAMDetails(samData, startDate, endDate) {
            $("#samDetails").empty();
            $.each(samData, function(samNumber, details) {
                
                let firstShiftOrders = 0;
                let secondShiftOrders = 0;
                let totalWorkedMinutes = 0;

                $.each(details.orders, function(date, shifts) {
                    $.each(shifts, function(shift, entries) {
                        const entry = entries[0];
                        const workHoursStart = entry.dep_info.shift_start_time;
                        const workHoursEnd = entry.dep_info.shift_end_time;
                        const breakHoursStart = entry.dep_info.breaks.length > 0 ? entry.dep_info.breaks[0].break_start_time : "00:00:00";
                        const breakHoursEnd = entry.dep_info.breaks.length > 0 ? entry.dep_info.breaks[0].break_end_time : "00:00:00";

                        const workedMinutes = calculateWorkedMinutes(workHoursStart, workHoursEnd, breakHoursStart, breakHoursEnd);
                        totalWorkedMinutes += workedMinutes;

                        if (shift === "1") {
                            firstShiftOrders++;
                        } else if (shift === "2") {
                            secondShiftOrders++;
                        }
                    });
                });

                const totalWorkedHours = (totalWorkedMinutes / 60).toFixed(2);
                const totalOrders = firstShiftOrders + secondShiftOrders;

                const samBlock = $('<div>').addClass('sam-block');
                const summary = $('<h4>').html(`<strong>Numer SAM:</strong> ${samNumber}, <strong>Imię i Nazwisko:</strong> ${details.name} ${details.surname}`);
                const orderSummary = $('<p>').html(`
                    <strong>Liczba zamówień:</strong> ${totalOrders}<br>
                    <i class="bi bi-sunrise"></i> Zamówienia na 1. zmianę: ${firstShiftOrders}<br>
                    <i class="bi bi-moon"></i> Zamówienia na 2. zmianę: ${secondShiftOrders}<br>
                    <strong>Łączna liczba przepracowanych godzin:</strong> ${totalWorkedHours} godzin (w okresie: ${startDate} - ${endDate})
                `);
                const detailsButton = $('<button>').addClass('btn btn-secondary btn-sm').html('<i class="bi bi-eye"></i> Pokaż szczegóły').on('click', function() {
                    $(this).next('.details-table').toggle();
                });

                const detailsTable = $('<div>').addClass('details-table').append(createDetailsTable(details.orders, samNumber, workTimeData));

                samBlock.append(summary, orderSummary, detailsButton, detailsTable);
                $("#samDetails").append(samBlock);
            });
        }

        function createDetailsTable(orders, samNumber, workTimeData) {
            const table = $('<table>').addClass('table table-bordered table-sm table-nowrap');
            const thead = $('<thead>').append('<tr><th>Data</th><th>Zmiana</th><th>Department</th><th>Start Pracy</th><th>Koniec Pracy</th><th>Przepracowane Minuty</th><th>Potracone Minuty</th><th>Alert</th><th>Odbicia</th></tr>');
            table.append(thead);

            const tbody = $('<tbody>');

            $.each(orders, function(date, shifts) {
                $.each(shifts, function(shift, entries) {
                    const entry = entries[0];
                    const departmentName = entry.dep_info.dep_name;
                    const workHoursStart = entry.dep_info.shift_start_time;
                    const workHoursEnd = entry.dep_info.shift_end_time;
                    const breakHoursStart = entry.dep_info.breaks.length > 0 ? entry.dep_info.breaks[0].break_start_time : "Brak";
                    const breakHoursEnd = entry.dep_info.breaks.length > 0 ? entry.dep_info.breaks[0].break_end_time : "Brak";
                    const workedMinutes = calculateWorkedMinutes(workHoursStart, workHoursEnd, breakHoursStart, breakHoursEnd);
                    const deductedMinutes = calculateDeductedMinutes(workHoursStart, workHoursEnd, breakHoursStart, breakHoursEnd);
                    const alert = getAlert(workHoursStart, workHoursEnd);

                    const row = $('<tr>')
                        .append(
                            $('<td>').text(date),
                            $('<td>').text(shift),
                            $('<td>').text(departmentName),
                            $('<td>').text(workHoursStart),
                            $('<td>').text(workHoursEnd),
                            $('<td>').text(workedMinutes),
                            $('<td>').text(deductedMinutes),
                            $('<td>').text(alert),
                            $('<td>').html('<button class="btn btn-primary btn-sm">Odbicia</button>').on('click', function() {
                                const entries = getEntriesByDay(workTimeData, "SAM_62805", date, parseInt(shift));
                                showEntriesModal(entries, date, shift);
                            })
                        );

                    tbody.append(row);
                });
            });

            table.append(tbody);
            return table;
        }

        function showEntriesModal(entries, date, zmiana) {
            const modalBody = $('#entriesModal .modal-body');
            modalBody.empty(); // Wyczyść poprzednią zawartość

            if (entries.length === 0) {
                modalBody.append('<p>Brak odbić dla wybranej daty i zmiany.</p>');
            } else {
                const table = $('<table>').addClass('table table-bordered table-sm');
                const thead = $('<thead>').append('<tr><th>Data i Czas</th><th>Czytnik</th><th>Funkcja Czytnika</th></tr>');
                table.append(thead);

                const tbody = $('<tbody>');
                $.each(entries, function(index, entry) {
                    const row = $('<tr>').append(
                        $('<td>').text(entry.read_at),
                        $('<td>').text(entry.reader),
                        $('<td>').text(entry.reader_function)
                    );
                    tbody.append(row);
                });

                table.append(tbody);
                modalBody.append(table);
            }

            $('#entriesModal').modal('show'); // Pokaż modal
        }

        function calculateWorkedMinutes(start, end, breakStart, breakEnd) {
            const startTime = new Date(`1970-01-01T${start}:00`);
            const endTime = new Date(`1970-01-01T${end}:00`);
            const breakStartTime = new Date(`1970-01-01T${breakStart}:00`);
            const breakEndTime = new Date(`1970-01-01T${breakEnd}:00`);

            const workDuration = (endTime - startTime) / (1000 * 60); // Różnica w minutach
            const breakDuration = (breakEndTime - breakStartTime) / (1000 * 60);

            return workDuration - breakDuration;
        }

        function calculateDeductedMinutes(start, end, breakStart, breakEnd) {
            const startTime = new Date(`1970-01-01T${start}:00`);
            const endTime = new Date(`1970-01-01T${end}:00`);
            const breakStartTime = new Date(`1970-01-01T${breakStart}:00`);
            const breakEndTime = new Date(`1970-01-01T${breakEnd}:00`);

            let deductedMinutes = 0;
            if (startTime.getHours() > 8) {
                deductedMinutes += (startTime.getHours() - 8) * 60 + startTime.getMinutes();
            }
            if (endTime.getHours() < 16) {
                deductedMinutes += (16 - endTime.getHours()) * 60 - endTime.getMinutes();
            }
            return deductedMinutes;
        }

        function getAlert(start, end) {
            const startTime = new Date(`1970-01-01T${start}:00`);
            const endTime = new Date(`1970-01-01T${end}:00`);

            if (startTime.getHours() < 6 || endTime.getHours() > 18) {
                return "Praca poza godzinami normatywnymi";
            }
            return "OK";
        }
    });
});

function getEntriesByDay(data, numerSam, date, zmiana) {
    let selectedEntries = [];

    const record = Object.values(data).find(record => 
        record.personal_info.some(info => info.numer_sam === numerSam)
    );

    if (!record) {
        return selectedEntries; // Jeśli nie znaleziono, zwróć pustą tablicę
    }

    const numerrfid = record.personal_info.find(info => info.numer_sam === numerSam).numerrfid;

    const filterEntries = (date) => {
        if (record.entries[date]) {
            const entries = record.entries[date].filter(entry => entry.numerrfid === numerrfid);
            selectedEntries = selectedEntries.concat(entries);
        }
    };

    filterEntries(date);
    if (zmiana === 2) {
        const nextDate = new Date(date);
        nextDate.setDate(nextDate.getDate() + 1);
        const nextDateString = nextDate.toISOString().split('T')[0];
        filterEntries(nextDateString);
    }

    return selectedEntries;
}
