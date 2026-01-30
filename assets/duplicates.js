$(document).on('rex:ready', function () {
    if (!$('#duplicates-app').length) return;

    var $btnStart = $('#start-scan');
    var $resultDiv = $('#duplicates-result');

    $btnStart.on('click', function () {
        $btnStart.prop('disabled', true);
        $resultDiv.hide().html('');
        startScan();
    });

    function startScan() {
        showProgressModal();
        
        $.ajax({
            url: window.location.pathname + '?rex-api-call=mediapool_tools_duplicates',
            method: 'POST',
            data: { action: 'start_scan' },
            success: function (res) {
                if (res.success) {
                    processBatch(res.batchId);
                } else {
                    handleError('Error starting scan');
                }
            },
            error: function () { handleError('Network error'); }
        });
    }

    function processBatch(batchId) {
        $.ajax({
            url: window.location.pathname + '?rex-api-call=mediapool_tools_duplicates',
            method: 'POST',
            data: { action: 'process_batch', batchId: batchId },
            success: function (res) {
                if (res.success) {
                    var data = res.data;
                    updateProgress(data.progress);
                    
                    if (data.status === 'completed') {
                        hideProgressModal();
                        showResults(batchId);
                    } else {
                        processBatch(batchId);
                    }
                } else {
                    handleError('Error processing batch');
                }
            },
            error: function () { handleError('Network error'); }
        });
    }

    function showResults(batchId) {
        $.ajax({
             url: window.location.pathname + '?rex-api-call=mediapool_tools_duplicates',
             method: 'POST',
             data: { action: 'get_result', batchId: batchId },
             success: function (res) {
                 if (res.success) {
                     $resultDiv.show().html(res.html);
                     $btnStart.prop('disabled', false);
                     initMergeButtons();
                 } else {
                     handleError('Error fetching results');
                 }
             },
             error: function () { handleError('Network error'); }
        });
    }

    function initMergeButtons() {
        $('.duplicate-group-form').on('submit', function(e) {
             e.preventDefault();
             var $form = $(this);
             var keep = $form.find('input[name="keep"]:checked').val();
             var allFiles = [];
             $form.find('input[name="files[]"]').each(function() {
                 allFiles.push($(this).val());
             });
             
             // Remove keep from replace list
             var replace = allFiles.filter(function(file) { return file !== keep; });
             
             if (!confirm('Sind Sie sicher? ' + replace.length + ' Dateien werden gelöscht und durch "' + keep + '" ersetzt.')) {
                 return;
             }
             
             var $btn = $form.find('button[type="submit"]');
             var originalText = $btn.text();
             $btn.prop('disabled', true).text('Merge...');
             
             $.ajax({
                 url: window.location.pathname + '?rex-api-call=mediapool_tools_duplicates',
                 method: 'POST',
                 data: { action: 'merge_files', keep: keep, replace: replace },
                 success: function (res) {
                     if (res.success) {
                         // Remove group from UI
                         $form.closest('.panel').fadeOut(500, function() { $(this).remove(); });
                         // Show success message somewhere?
                         // Maybe toast?
                     } else {
                         alert('Error: ' + res.message);
                         $btn.prop('disabled', false).text(originalText);
                     }
                 },
                 error: function () { 
                     alert('Network Error');
                     $btn.prop('disabled', false).text(originalText);
                 }
             });
        });
    }

    // Modal Helpers (Reusing similar style to unused_media)
    function showProgressModal() {
        if ($('#dup-progress-modal').length) return;
        var modal = '<div id="dup-progress-modal" class="modal fade" data-backdrop="static" data-keyboard="false">' +
            '<div class="modal-dialog"><div class="modal-content"><div class="modal-body text-center">' +
            '<div class="progress"><div class="progress-bar progress-bar-striped active" style="width:0%">0%</div></div>' +
            '<div id="dup-status-text">' + mediapool_tools_i18n_duplicates.analyzing + '</div>' +
            '</div></div></div></div>';
        $('body').append(modal);
        $('#dup-progress-modal').modal('show');
    }

    function hideProgressModal() {
        $('#dup-progress-modal').modal('hide').data('bs.modal', null);
        $('#dup-progress-modal').remove();
        $('.modal-backdrop').remove();
    }

    function updateProgress(percent) {
        $('#dup-progress-modal .progress-bar').css('width', percent + '%').text(percent + '%');
    }

    function handleError(msg) {
        hideProgressModal();
        alert(msg);
        $btnStart.prop('disabled', false);
    }
});
