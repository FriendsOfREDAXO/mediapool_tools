<?php

$addon = rex_addon::get('mediapool_tools');

echo '<div class="panel panel-default" id="duplicates-app">
    <div class="panel-heading">' . $addon->i18n('duplicates_scan_heading') . '</div>
    <div class="panel-body">
        <p>' . $addon->i18n('duplicates_description') . '</p>
        <button class="btn btn-primary" id="start-scan">' . $addon->i18n('duplicates_btn_start') . '</button>
    </div>
</div>';

echo '<div id="duplicates-result" style="display:none; max-height: 75vh; overflow-y: auto; margin-top: 20px; border: 1px solid #ddd; padding: 15px; background: #f9f9f9;"></div>';

// Translations for JS
echo '<script>
var mediapool_tools_i18n_duplicates = {
    analyzing: "' . $addon->i18n('duplicates_analyzing') . '",
    found_heading: "' . $addon->i18n('duplicates_found_heading') . '",
    no_duplicates: "' . $addon->i18n('duplicates_no_duplicates') . '"
};
</script>';

// Load JS Asset (we assume it's loaded via package.yml or manually injected if needed, but here we likely need to inject it manually or rely on assets folder)
echo '<script src="' . $addon->getAssetsUrl('duplicates.js') . '"></script>';
