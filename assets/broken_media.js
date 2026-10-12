/* Mediapool Tools – Defekte Dateien: Analyse in Etappen, Aktionen je Datei (Angaben korrigieren, Endung korrigieren, löschen) */
$(document).on('rex:ready', function () {
    var $app = $('#broken-media-app');
    if (!$app.length) return;

    var api = $app.data('api');
    var i18n = window.mediapool_tools_i18n_broken || {};
    var $start = $('#broken-media-start');
    var $result = $('#broken-media-result');

    function call(data) {
        return $.ajax({ url: api, method: 'POST', data: data, dataType: 'json' }).then(function (res) {
            if (!res || !res.success) {
                return $.Deferred().reject(res && res.message ? res.message : i18n.error);
            }
            return res;
        }, function () {
            return $.Deferred().reject(i18n.error);
        });
    }

    $start.on('click', function () {
        $start.prop('disabled', true);
        $result.hide().empty();
        showProgress(i18n.analyzing);
        call({ action: 'start_scan' }).then(function (res) {
            batch(res.batchId);
        }, fail);
    });

    function batch(batchId) {
        call({ action: 'process_batch', batchId: batchId }).then(function (res) {
            setProgress(res.data.progress || 0);
            if (res.data.status === 'completed') {
                call({ action: 'get_result', batchId: batchId }).then(function (r) {
                    hideProgress();
                    $result.html(r.html).show();
                    $start.prop('disabled', false);
                }, fail);
            } else if (res.data.status === 'running') {
                batch(batchId);
            } else {
                fail(res.data.message);
            }
        }, fail);
    }

    // Alle veralteten Angaben korrigieren – in Paketen zu 25 Dateien
    $result.on('click', '[data-broken-fix-all]', function () {
        var $btn = $(this);
        var files = $btn.data('broken-fix-all') || [];
        if (!files.length || !confirm(i18n.confirm_fix_all.replace('{0}', files.length))) return;
        $btn.prop('disabled', true);
        showProgress(i18n.fixing_all);
        var done = 0, fixed = 0;
        function next() {
            if (done >= files.length) {
                hideProgress();
                $btn.replaceWith('<span class="text-success"><i class="rex-icon fa-check"></i> ' + $('<span>').text(i18n.fixed_all.replace('{0}', fixed)).html() + '</span>');
                $result.find('[data-broken-action="fix_meta"]').each(function () {
                    var $row = $(this).closest('tr');
                    $(this).replaceWith('<span class="text-success"><i class="rex-icon fa-check"></i></span>');
                    $row.attr('data-done', '1');
                });
                return;
            }
            var chunk = files.slice(done, done + 25);
            call({ action: 'fix_meta_batch', files: chunk }).then(function (res) {
                done += chunk.length;
                fixed += res.fixed || 0;
                setProgress(Math.round(done / files.length * 100));
                next();
            }, function (message) {
                hideProgress();
                $btn.prop('disabled', false);
                alert(message || i18n.error);
            });
        }
        next();
    });

    // Aktionen je Zeile
    $result.on('click', '[data-broken-action]', function () {
        var $btn = $(this);
        var action = $btn.data('broken-action');
        var file = String($btn.data('file'));
        var $row = $btn.closest('tr');

        if (action === 'delete' && !confirm(i18n.confirm_delete.replace('{0}', file))) return;
        if (action === 'rename' && !confirm(i18n.confirm_rename.replace('{0}', file).replace('{1}', $btn.data('extension')))) return;

        $row.find('button').prop('disabled', true);
        var done = function (message) {
            $row.attr('data-done', '1').find('td:last').html('<span class="text-success"><i class="rex-icon fa-check"></i> ' + $('<span>').text(message).html() + '</span>');
        };
        var rowFail = function (message) {
            $row.find('button').prop('disabled', false);
            alert(message || i18n.error);
        };

        if (action === 'rename') {
            rename(file, String($btn.data('extension'))).then(done, rowFail);
            return;
        }
        call({ action: action, file: file }).then(function (res) { done(res.message); }, rowFail);
    });

    // Endung korrigieren: Kopie unter neuem Namen, Verweise Tabelle für Tabelle umstellen, dann Medienpool-Eintrag + alte Datei
    function rename(file, extension) {
        showProgress(i18n.renaming);
        return call({ action: 'rename_start', file: file, extension: extension }).then(function (res) {
            var tables = res.tables || [];
            var newName = res.newName;
            var i = 0;
            function next() {
                if (i >= tables.length) {
                    return call({ action: 'rename_finish', file: file, newName: newName });
                }
                setProgress(Math.round(i / tables.length * 100));
                return call({ action: 'rename_table', file: file, newName: newName, table: tables[i++] }).then(next);
            }
            return next();
        }).then(function (res) {
            hideProgress();
            return res.message;
        }, function (message) {
            hideProgress();
            return $.Deferred().reject(message);
        });
    }

    function fail(message) {
        hideProgress();
        alert(message || i18n.error);
        $start.prop('disabled', false);
    }

    function showProgress(text) {
        hideProgress();
        $('body').append('<div id="broken-media-progress" class="modal fade" data-backdrop="static" data-keyboard="false"><div class="modal-dialog"><div class="modal-content"><div class="modal-body text-center">'
            + '<div class="progress"><div class="progress-bar progress-bar-striped active" style="width:0%">0%</div></div><div>' + $('<span>').text(text).html() + '</div>'
            + '</div></div></div></div>');
        $('#broken-media-progress').modal('show');
    }

    function setProgress(percent) {
        $('#broken-media-progress .progress-bar').css('width', percent + '%').text(percent + '%');
    }

    function hideProgress() {
        var $m = $('#broken-media-progress');
        if (!$m.length) return;
        $m.modal('hide').remove();
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open').css({ 'padding-right': '', overflow: '' });
    }
});
