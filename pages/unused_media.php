<?php
/** @var rex_addon $this */

$addon = rex_addon::get('mediapool_tools');

use FriendsOfRedaxo\MediapoolTools\UnusedMediaAnalysis;

$lastResult = UnusedMediaAnalysis::getLastResult();
$hasResult = $lastResult !== null;

// Pass translations to JS
echo '<script>
var mediapool_tools_i18n = {
    processing: "' . $addon->i18n('unused_media_processing') . '",
    please_wait: "' . $addon->i18n('unused_media_please_wait') . '"
};
</script>';

// Info Header
echo '<div class="panel panel-info" id="unused-media-container">
    <div class="panel-heading">' . $addon->i18n('unused_media_info_title') . '</div>
    <div class="panel-body">
        <p>' . $addon->i18n('unused_media_info_text') . '</p>
        <button class="btn btn-primary" id="start-analysis">' . 
            ($hasResult ? $addon->i18n('unused_media_btn_restart') : $addon->i18n('unused_media_btn_start')) . 
        '</button>
        ' . ($hasResult ? '<span class="text-muted" style="margin-left: 10px;">' . sprintf($addon->i18n('unused_media_last_scan'), date('d.m.Y H:i', $lastResult['timestamp'])) . '</span>' : '') . '
    </div>
</div>';

// Ergebnis-Tabelle
if ($hasResult) {
    echo '<div id="analysis-result">';
    
    if (empty($lastResult['files'])) {
        echo rex_view::info($addon->i18n('unused_media_no_files_found'));
    } else {
        $content = '';
        
        // Actions Header
        $content .= '<div class="panel-body bg-gray text-right" id="result-actions" style="border-bottom: 1px solid #ddd; padding: 10px;">
             <strong><span class="selected-count">0</span> ' . $addon->i18n('unused_media_selected') . '</strong>
             
             <!-- Verschieben -->
             <div style="display:inline-block; margin-left: 20px;">
                 <label>' . $addon->i18n('unused_media_target_category') . ':</label>
                 ' . buildCategorySelect() . '
                 <button class="btn btn-warning action-btn" id="btn-move-selected" disabled>' . $addon->i18n('unused_media_btn_move') . '</button>
             </div>
             
             <!-- Löschen -->
             <div style="display:inline-block; margin-left: 20px;">
                 <label style="font-weight:normal; margin-right:5px;"><input type="checkbox" id="force-delete"> ' . $addon->i18n('unused_media_force_delete') . '</label>
                 <button class="btn btn-danger action-btn" id="btn-delete-selected" disabled>' . $addon->i18n('unused_media_btn_delete') . '</button>
             </div>

             <!-- Schützen -->
             <div style="display:inline-block; margin-left: 20px; float:right;">
                 <button class="btn btn-success action-btn" id="btn-protect-selected" disabled><i class="rex-icon fa-shield"></i> ' . $addon->i18n('unused_media_btn_protect') . '</button>
             </div>
        </div>';
        
        // Table
        $content .= '<div class="table-responsive"><table class="table table-striped table-hover">';
        $content .= '<thead><tr>
            <th class="rex-table-icon"><input type="checkbox" id="select-all-files"></th>
            <th class="rex-table-icon"></th>
            <th>' . $addon->i18n('pool_filename') . '</th>
            <th>' . $addon->i18n('pool_file_title') . '</th>
            <th>' . $addon->i18n('pool_file_category') . '</th>
            <th>' . $addon->i18n('filesize') . '</th>
            <th>' . $addon->i18n('createdate') . '</th>
        </tr></thead><tbody>';
        
        $limit = 500;
        $shown = 0;
        
        $warningSizeMb = (float) $addon->getConfig('search-min-size-mb', 0);
        $warningSizeBytes = $warningSizeMb > 0 ? $warningSizeMb * 1024 * 1024 : 0;
        
        $files = $lastResult['files'];
        foreach ($files as $filename) {
            $media = rex_media::get($filename);
            if (!$media) continue; // Sollte nicht passieren
            
            if ($shown >= $limit) break;
            
            $fileExt = strtolower(rex_file::extension($filename));
            $isImage = $media->isImage();
            // Video extensions common in web
            $isVideo = in_array($fileExt, ['mp4', 'webm', 'ogg', 'mov']); 
            $isPdf = $fileExt === 'pdf';

            $mediaPath = rex_url::media($filename);
            $preview = '';
            $linkStart = '';
            $linkEnd = '</a>';

            if ($isImage) {
                 $preview = '<img src="index.php?rex_media_type=rex_mediapool_preview&rex_media_file='.rex_escape($filename).'" style="max-height: 40px; max-width: 40px;" />';
                 $linkStart = '<a href="'.$mediaPath.'" class="unused-media-preview" data-type="image" data-title="'.rex_escape($media->getTitle()).'">';
            } elseif ($isVideo) {
                 $preview = '<i class="rex-icon fa-file-video-o fa-2x text-muted"></i>';
                 $linkStart = '<a href="'.$mediaPath.'" class="unused-media-preview" data-type="video" data-title="'.rex_escape($media->getTitle()).'">';
            } elseif ($isPdf) {
                 $preview = '<i class="rex-icon fa-file-pdf-o fa-2x text-danger"></i>';
                 $linkStart = '<a href="'.$mediaPath.'" target="_blank" onclick="event.stopPropagation()">'; // Stop propagation to prevent row clicks or other handlers
            } else {
                 $preview = '<i class="rex-icon fa-file-o fa-2x text-muted"></i>';
                 $linkStart = '<a href="'.$mediaPath.'" target="_blank" onclick="event.stopPropagation()">';
            }
            
            $checkbox = '<input type="checkbox" class="unused-file-checkbox" value="'.rex_escape($filename).'">';
            $cat = $media->getCategory() ? $media->getCategory()->getName() : '-';
            
            $formattedSize = $media->getFormattedSize();
            if ($warningSizeBytes > 0 && $media->getSize() > $warningSizeBytes) {
                // Highlight only the size column
                $formattedSize = '<span class="text-danger"><i class="rex-icon fa-exclamation-triangle" title="Große Datei"></i> ' . $formattedSize . '</span>';
            }
            
            $content .= '<tr>
                <td class="rex-table-icon">'.$checkbox.'</td>
                <td class="rex-table-icon">'.$linkStart.$preview.$linkEnd.'</td>
                <td><a href="'.$mediaPath.'" target="_blank" onclick="event.stopPropagation()">'.rex_escape($filename).'</a></td>
                <td>'.rex_escape($media->getTitle()).'</td>
                <td>'.rex_escape($cat).'</td>
                <td data-title="' . $addon->i18n('filesize') . '">'.$formattedSize.'</td>
                <td>'.rex_formatter::format((string) $media->getCreateDate(), 'date', 'd.m.Y').'</td>
            </tr>';
            
            $shown++;
        }
        
        $content .= '</tbody></table></div>';
        
        if (count($files) > $limit) {
            $content .= '<div class="panel-footer text-center text-muted">Zeige erste ' . $limit . ' von ' . count($files) . ' Dateien. Löschen/Verschieben wirkt nur auf Auswahl.</div>';
        }
        
        $fragment = new rex_fragment();
        $fragment->setVar('title', sprintf($addon->i18n('unused_media_result_title'), count($files), $lastResult['count_total']));
        $fragment->setVar('content', $content, false);
        echo $fragment->parse('core/page/section.php');
    }
    
    echo '</div>';
}

function buildCategorySelect(): string {
    $select = new rex_media_category_select();
    $select->setName('target_category_id');
    $select->setAttribute('class', 'form-control selectpicker');
    $select->setAttribute('data-live-search', 'true');
    $select->setSize(1);
    $select->addOption(rex_i18n::msg('pool_kats_no'), 0);
    return $select->get();
}

