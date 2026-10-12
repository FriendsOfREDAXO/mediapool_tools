<?php

/**
 * Defekte Dateien: fehlende, leere, falsch benannte oder beschädigte Dateien im Medienpool finden und beheben.
 */

$addon = rex_addon::get('mediapool_tools');

echo '<div class="panel panel-default" id="broken-media-app" data-api="' . rex_escape(rex_url::backendController(FriendsOfRedaxo\MediapoolTools\ApiBrokenMedia::getUrlParams(), false)) . '">
    <div class="panel-heading">' . $addon->i18n('broken_media_scan_heading') . '</div>
    <div class="panel-body">
        <p>' . $addon->i18n('broken_media_description') . '</p>
        <button class="btn btn-primary" id="broken-media-start">' . $addon->i18n('broken_media_btn_start') . '</button>
    </div>
</div>
<div id="broken-media-result" style="display:none; margin-top: 20px;"></div>';

echo '<script>
var mediapool_tools_i18n_broken = ' . json_encode([
    'analyzing' => $addon->i18n('broken_media_analyzing'),
    'confirm_delete' => $addon->i18n('broken_media_confirm_delete'),
    'confirm_rename' => $addon->i18n('broken_media_confirm_rename'),
    'renaming' => $addon->i18n('broken_media_renaming'),
    'error' => $addon->i18n('broken_media_error'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
</script>';
