<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WTApp</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css" rel="stylesheet">
    <style>
                    .container {
                margin-top: 50px;
                max-width: 1200px;
            }

            .company-button {
                width: 48%;
                margin: 1%;
                padding: 20px;
                font-size: 1.5rem;
                background-color: #007bff;
                color: #fff;
                border: none;
                border-radius: 5px;
                transition: background-color 0.3s ease;
            }

            .company-button:hover {
                background-color: #0056b3;
                color: #fff;
            }

            .sam-block {
                margin-bottom: 20px;
                padding: 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                background-color: #f8f9fa;
            }

            .details-table {
                display: none;
            }

            .table-nowrap th, .table-nowrap td {
                white-space: nowrap;
            }

            table {
                width: 100%; /* Ustawienie tabeli na pełną szerokość */
                margin-top: 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                background-color: #ffffff;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            table th, table td {
                padding: 10px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }

            table th {
                background-color: #007bff;
                color: #ffffff;
            }

            table tr:nth-child(even) {
                background-color: #f2f2f2;
            }

            .summary {
                background-color: #e9ecef;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 10px;
            }

            .summary strong {
                display: block;
                font-size: 1.2rem;
            }

            .summary p {
                margin: 5px 0;
            }

            .icon {
                margin-right: 10px;
            }

    </style>
</head>
<body>
    <div class="container">
        <h1 class="text-center">WTApp</h1>
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form id="dateForm">
                            <div class="form-group">
                                <label for="startDate">Data początkowa:</label>
                                <input type="text" id="startDate" class="form-control datepicker" placeholder="Wybierz datę początkową" required>
                            </div>
                            <div class="form-group">
                                <label for="endDate">Data końcowa:</label>
                                <input type="text" id="endDate" class="form-control datepicker" placeholder="Wybierz datę końcową" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="bi bi-download icon"></i>Pobierz dane
                            </button>
                        </form>
                    </div>
                </div>
                <!-- Loading indicator -->
                <div id="loadingMessage" class="loading" style="display: none;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">Loading...</span>
                    </div>
                    <span>Trwa pobieranie danych...</span>
                </div>
                <!-- Company code buttons -->
                <div id="companyButtons" class="company-buttons-container" style="display:none;"></div>
                <!-- Placeholder for SAM details -->
                <div id="samDetails"></div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
    <script src="script.js"></script>


    <!-- Modal -->
<div class="modal fade" id="entriesModal" tabindex="-1" role="dialog" aria-labelledby="entriesModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="entriesModalLabel">Odbicia</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <!-- Zawartość modala będzie tutaj dynamicznie wstawiana -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>


</body>
</html>
