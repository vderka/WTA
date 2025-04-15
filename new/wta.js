/**
 * Skrypt do obsługi interfejsu systemu WTA
 * 
 * Zmienne konfiguracyjne WTA_PREVIEW_DAYS i WTA_MAX_ANALYSIS_DAYS 
 * powinny być zdefiniowane w index.php przed dołączeniem tego skryptu
 */
$(document).ready(function() {
    // Obsługa formularza analizy czasu pracy
    $('#dateRangeForm').on('submit', function(event) {
        event.preventDefault();
        
        // Pobierz dane z formularza
        const startDate = $('#startDate').val();
        const endDate = $('#endDate').val();
        const samNumber = $('#samNumber').val();
        
        // Walidacja dat
        if (!validateDates(startDate, endDate)) {
            return;
        }
        
        // Sprawdź, czy okres spełnia kryteria dla szybkiego podglądu
        const start = new Date(startDate);
        const end = new Date(endDate);
        const daysDiff = Math.floor((end - start) / (1000 * 60 * 60 * 24)) + 1;
        
        // Pokaż wskaźnik ładowania
        showLoading();
        
        // Jeśli okres nie przekracza limitu dla szybkiego podglądu
        if (daysDiff <= WTA_PREVIEW_DAYS) {
            // Szybki podgląd dla krótkich okresów
            $.ajax({
                url: 'preview.php',
                type: 'POST',
                data: {
                    startDate: startDate,
                    endDate: endDate,
                    samNumber: samNumber
                },
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    
                    if (response.error) {
                        showError(response.error);
                        return;
                    }
                    
                    // Wyświetl wyniki podglądu
                    $('#previewContent').html(response.html);
                    $('#previewResults').show();
                    
                    // Przewiń do wyników
                    $('html, body').animate({
                        scrollTop: $('#previewResults').offset().top - 50
                    }, 500);
                    
                    // Obsługa przycisku eksportu
                    setupExportButton(startDate, endDate, samNumber);
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    var errorMsg = 'Wystąpił błąd: ' + error;
                    try {
                        // Spróbuj odczytać treść błędu z odpowiedzi
                        var responseText = xhr.responseText.substring(0, 500); // Pokaż tylko pierwsze 500 znaków
                        errorMsg += '<br><br>Fragment odpowiedzi:<br><code>' + responseText + '...</code>';
                    } catch(e) {}
                    
                    showError(errorMsg);
                }
            });
        } else {
            // Przetwarzanie w tle dla dłuższych okresów
            $.ajax({
                url: 'batch_process.php',
                type: 'POST',
                data: {
                    startDate: startDate,
                    endDate: endDate,
                    samNumber: samNumber
                },
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    
                    if (response.error) {
                        showError(response.error);
                        return;
                    }
                    
                    // Pokaż modal z informacją o dodaniu zadania do kolejki
                    showQueuedTaskModal(response.task_id, startDate, endDate, samNumber);
                    
                    // Odśwież stronę po 2 sekundach, aby zaktualizować listę zadań
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    var errorMsg = 'Wystąpił błąd: ' + error;
                    try {
                        // Spróbuj odczytać treść błędu z odpowiedzi
                        var responseText = xhr.responseText.substring(0, 500); // Pokaż tylko pierwsze 500 znaków
                        errorMsg += '<br><br>Fragment odpowiedzi:<br><code>' + responseText + '...</code>';
                    } catch(e) {}
                    
                    showError(errorMsg);
                }
            });
        }
    });
    
    // Obsługa przycisku eksportu dla podglądu
    function setupExportButton(startDate, endDate, samNumber) {
        $('#exportPreviewBtn').off('click').on('click', function() {
            showLoading(); // Pokaż wskaźnik ładowania
            
            // Wykonaj żądanie AJAX do dodania zadania do kolejki
            $.ajax({
                url: 'batch_process.php',
                type: 'POST',
                data: {
                    startDate: startDate,
                    endDate: endDate,
                    samNumber: samNumber
                },
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    
                    if (response.error) {
                        showError(response.error);
                        return;
                    }
                    
                    // Pokaż modal z informacją o dodaniu zadania do kolejki
                    showQueuedTaskModal(response.task_id, startDate, endDate, samNumber);
                    
                    // Odśwież stronę po 2 sekundach
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    var errorMsg = 'Wystąpił błąd: ' + error;
                    try {
                        // Spróbuj odczytać treść błędu z odpowiedzi
                        var responseText = xhr.responseText.substring(0, 500); // Pokaż tylko pierwsze 500 znaków
                        errorMsg += '<br><br>Fragment odpowiedzi:<br><code>' + responseText + '...</code>';
                    } catch(e) {}
                    
                    showError(errorMsg);
                }
            });
        });
    }
    
    // Walidacja dat
    function validateDates(startDate, endDate) {
        if (!startDate || !endDate) {
            showError('Proszę wybrać datę początkową i końcową');
            return false;
        }
        
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        if (start > end) {
            showError('Data początkowa nie może być późniejsza niż data końcowa');
            return false;
        }
        
        const daysDiff = Math.floor((end - start) / (1000 * 60 * 60 * 24)) + 1;
        if (daysDiff > WTA_MAX_ANALYSIS_DAYS) {
            showError('Maksymalny okres analizy to ' + WTA_MAX_ANALYSIS_DAYS + ' dni');
            return false;
        }
        
        return true;
    }
    
    // Pokaż modal z informacją o dodaniu zadania do kolejki
    function showQueuedTaskModal(taskId, startDate, endDate, samNumber) {
        let infoText = `Zadanie ID: ${taskId}<br>Okres: ${startDate} — ${endDate}`;
        
        if (samNumber) {
            infoText += `<br>Numer SAM: ${samNumber}`;
        }
        
        $('#queuedTaskInfo').html(infoText);
        const modal = new bootstrap.Modal(document.getElementById('queuedTaskModal'));
        modal.show();
    }
    
    // Pokaż wskaźnik ładowania
    function showLoading() {
        $('body').append('<div id="loadingOverlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; justify-content: center; align-items: center;"><div style="background: white; padding: 20px; border-radius: 10px; text-align: center;"><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"></div><p class="mt-2 mb-0">Przetwarzanie danych...</p></div></div>');
    }
    
    // Ukryj wskaźnik ładowania
    function hideLoading() {
        $('#loadingOverlay').remove();
    }
    
    // Pokaż komunikat o błędzie
    function showError(message) {
        const alertHTML = `
            <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        $('#dateRangeForm').after(alertHTML);
        
        // Przewiń do komunikatu
        $('html, body').animate({
            scrollTop: $('.alert-danger').offset().top - 100
        }, 500);
        
        // Automatyczne usunięcie komunikatu po 5 sekundach
        setTimeout(function() {
            $('.alert-danger').fadeOut('slow', function() {
                $(this).remove();
            });
        }, 5000);
    }
});