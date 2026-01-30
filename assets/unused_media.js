
$(document).on('rex:ready', function (event, element) {
    if (!$('body#rex-page-mediapool-tools-unused-media').length) {
        return;
    }

    const $container = $('#unused-media-container');
    const $startBtn = $('#start-analysis');
    const $resultContainer = $('#analysis-result');
    const $actionsContainer = $('#result-actions');
    const $tableContainer = $('#result-table-container');

    // Scannen
    $startBtn.on('click', function () {
        $startBtn.prop('disabled', true).html('<i class="rex-icon rex-icon-search"></i> Suche läuft...');
        $resultContainer.hide();
        $actionsContainer.hide();
        
        showProgressModal();
        startScan();
    });

    function startScan() {
        $.ajax({
            url: window.location.pathname + '?rex-api-call=mediapool_tools_unused_media',
            method: 'POST',
            data: { action: 'start_scan' },
            success: function (res) {
                if (res.success) {
                    processStep(res.data.batchId);
                } else {
                    handleError(res.message);
                }
            },
            error: function () { handleError('Netzwerkfehler'); }
        });
    }

    function processStep(batchId) {
        $.ajax({
            url: window.location.pathname + '?rex-api-call=mediapool_tools_unused_media',
            method: 'POST',
            data: { action: 'scan_step', batchId: batchId },
            success: function (res) {
                if (res.success) {
                    if (res.data.status === 'completed') {
                        hideProgressModal();
                        location.reload(); // Seite neu laden um Ergebnisse anzuzeigen
                    } else {
                        updateProgress(res.data.progress);
                        processStep(batchId); // Recursion / Next Step
                    }
                } else {
                    handleError(res.message);
                }
            },
            error: function () { handleError('Netzwerkfehler beim Scannen'); }
        });
    }
    
    // UI Helpers für Modal (Kopie von bulk_rework angepasst)
    function showProgressModal() {
        // Prüfen ob Modal existiert
        if ($('#scan-progress-modal').length) return;
        
        let modal = `
            <div id="scan-progress-modal" class="modal fade" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h4 class="modal-title">Datenbank wird durchsucht...</h4>
                        </div>
                        <div class="modal-body">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%">
                                    0%
                                </div>
                            </div>
                            <div class="text-center margin-top-10" id="scan-status-text">Starte...</div>
                        </div>
                    </div>
                </div>
            </div>`;
        $('body').append(modal);
        $('#scan-progress-modal').modal('show');
    }
    
    function hideProgressModal() {
        $('#scan-progress-modal').modal('hide').remove();
        $('.modal-backdrop').remove(); // Cleanup falls Backdrops hängen bleiben
    }
    
    function updateProgress(progress) {
        let percent = progress.percent;
        $('#scan-progress-modal .progress-bar').css('width', percent + '%').text(percent + '%');
        $('#scan-status-text').text('Durchsuche Tabelle: ' + progress.currentTable + ' (' + progress.tableIndex + '/' + progress.totalTables + ')');
    }
    
    function handleError(msg) {
        hideProgressModal();
        alert('Fehler: ' + msg);
        $startBtn.prop('disabled', false).text('Analyse starten');
    }

    // Aktionen für Ergebnisse
    $('#select-all-files').on('change', function() {
        $('.unused-file-checkbox').prop('checked', $(this).prop('checked'));
        updateCounter();
    });

    $(document).on('change', '.unused-file-checkbox', function() {
        updateCounter();
    });

    function updateCounter() {
        let count = $('.unused-file-checkbox:checked').length;
        $('.selected-count').text(count);
        $('.action-btn').prop('disabled', count === 0);
    }
    
    // Löschen
    $('#btn-delete-selected').on('click', function() {
        let count = $('.unused-file-checkbox:checked').length;
        let force = $('#force-delete').is(':checked');
        let msg = 'Wirklich ' + count + ' Dateien unwiderruflich löschen?';
        
        if (force) {
            msg += '\n\nACHTUNG: "Verwendung ignorieren" ist aktiv. Dateien werden gelöscht, auch wenn sie laut System in Benutzung sind!';
        }
        
        if (!confirm(msg)) return;
        
        let files = getSelectedFiles();
        performAction('delete_files', { files: files, force: force }, function(res) {
             alert(res.data.deleted + ' Dateien gelöscht. ' + (res.data.errors.length > 0 ? '\nFehler: ' + res.data.errors.join(', ') : ''));
             location.reload();
        });
    });
    
    // Verschieben
    $('#btn-move-selected').on('click', function() {
        let files = getSelectedFiles();
        // Prompt oder besseres UI für Kategorie Auswahl? 
        // Wir nehmen hier das Element aus dem Footer
        /* Da Prompt doof ist, haben wir unten im Footer ein Select gebaut, wir lesen das aus. */
        /* Moment, im HTML unten müssen wir das Select bauen. */
    });
    
    // Da ich das Dropdown für Move im HTML noch nicht kenne, baue ich es hier generisch ein:
    // Der Button im HTML muss ein data-target haben oder wir nutzen ein Modal für die Zielkategorie.
    // Einfacher: Ein Select neben dem Verschieben Button anzeigen?
    
    $('#btn-move-selected').on('click', function() {
         let targetCatId = $('select[name="target_category_id"]').val();
         let files = getSelectedFiles();
         
         performAction('move_files', { files: files, category_id: targetCatId }, function(res) {
             alert(res.data.moved + ' Dateien verschoben.');
             location.reload();
         });
    });

    // Schützen
    $('#btn-protect-selected').on('click', function() {
        let files = getSelectedFiles();
        performAction('protect_files', { files: files }, function(res) {
             alert(res.data.count + ' Dateien als geschützt markiert. Sie werden gelistet, wenn die Seite neu geladen wird.');
             location.reload();
        });
    });

    function getSelectedFiles() {
        let files = [];
        $('.unused-file-checkbox:checked').each(function() {
            files.push($(this).val());
        });
        return files;
    }
    
    function performAction(action, data, cb) {
        showProgressModal();
        
        // Switch to spinner layout for indeterminate operations
        $('#scan-progress-modal .modal-title').text('Verarbeite Daten...');
        $('#scan-progress-modal .progress').replaceWith('<div class="text-center" style="margin-bottom: 20px; color: #3bb594;"><i class="rex-icon fa-spinner fa-spin fa-3x"></i></div>');
        $('#scan-status-text').html('<strong>Bitte warten...</strong><br>Dies kann je nach Anzahl der Dateien einen Moment dauern.');
        
        $.ajax({
            url: window.location.pathname + '?rex-api-call=mediapool_tools_unused_media',
            method: 'POST',
            data: Object.assign({ action: action }, data),
            success: function(res) {
                hideProgressModal();
                if (res.success) {
                    cb(res);
                } else {
                    alert('Fehler: ' + res.message);
                }
            },
            error: function() {
                handleError('Netzwerkfehler');
            }
        });
    }

    // Preview Modal Logic
    $(document).on('click', '.unused-media-preview', function(e) {
        e.preventDefault();
        let url = $(this).attr('href');
        let type = $(this).data('type');
        let title = $(this).data('title') || 'Vorschau';
        
        showPreviewModal(url, type, title);
    });

    function showPreviewModal(url, type, title) {
        // Remove existing modal if present
        $('#media-preview-modal').remove();
        
        let content = '';
        if (type === 'image') {
            content = '<img src="' + url + '" class="img-responsive" style="margin: 0 auto; max-height: 80vh;">';
        } else if (type === 'video') {
            content = '<video controls style="width: 100%; max-height: 80vh;"><source src="' + url + '">Your browser does not support the video tag.</video>';
        }
        
        let modal = `
            <div id="media-preview-modal" class="modal fade" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title">${title}</h4>
                        </div>
                        <div class="modal-body text-center" style="background: #333; min-height: 200px; display: flex; align-items: center; justify-content: center;">
                            ${content}
                        </div>
                    </div>
                </div>
            </div>`;
            
        $('body').append(modal);
        $('#media-preview-modal').modal('show');
    }

});
