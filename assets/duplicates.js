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
                 } else {
                     handleError('Error fetching results');
                 }
             },
             error: function () { handleError('Network error'); }
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
