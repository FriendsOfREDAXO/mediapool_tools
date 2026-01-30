<?php

$addon = rex_addon::get('mediapool_tools');

if (rex::isBackend() && rex::getUser()) {
    rex_perm::register('mediapool_tools[]');
    rex_perm::register('mediapool_tools[config]');
    rex_perm::register('mediapool_tools[bulk_rework]');
    rex_perm::register('mediapool_tools[unused_media]');
    rex_perm::register('mediapool_tools[duplicates]');

    // API explizit registrieren
    rex_api_function::register('mediapool_tools_bulk_process', FriendsOfRedaxo\MediapoolTools\ApiBulkProcess::class);
    rex_api_function::register('mediapool_tools_unused_media', FriendsOfRedaxo\MediapoolTools\ApiUnusedMedia::class);
    rex_api_function::register('mediapool_tools_duplicates', FriendsOfRedaxo\MediapoolTools\ApiDuplicates::class);
    
    // Subpages in Mediapool einhängen
    rex_extension::register('PAGES_PREPARED', function () {
        $page = rex_be_controller::getPageObject('mediapool');
        $addon = rex_addon::get('mediapool_tools');
        
        if ($page && rex::getUser()->hasPerm('mediapool_tools[]')) {
            $tools = new rex_be_page('tools', $addon->i18n('tools_title'));
            $tools->setIcon('rex-icon fa-wrench');

            $added = false;

            if (rex::getUser()->hasPerm('mediapool_tools[bulk_rework]')) {
                $tools->addSubpage((new rex_be_page('bulk_rework', $addon->i18n('bulk_rework_title')))
                    ->setSubPath($addon->getPath('pages/bulk_rework.php'))
                    ->setIcon('rex-icon fa-crop')
                );
                $added = true;
            }
            
            if (rex::getUser()->hasPerm('mediapool_tools[unused_media]')) {
                $tools->addSubpage((new rex_be_page('unused_media', $addon->i18n('unused_media_title')))
                    ->setSubPath($addon->getPath('pages/unused_media.php'))
                    ->setIcon('rex-icon fa-search')
                );
                $added = true;
            }

            if (rex::getUser()->hasPerm('mediapool_tools[duplicates]')) {
                $tools->addSubpage((new rex_be_page('duplicates', $addon->i18n('duplicates_title')))
                    ->setSubPath($addon->getPath('pages/duplicates.php'))
                    ->setIcon('rex-icon fa-clone')
                );
                $added = true;
            }

            if (rex::getUser()->hasPerm('mediapool_tools[config]')) {
                $tools->addSubpage((new rex_be_page('config', $addon->i18n('config')))
                    ->setSubPath($addon->getPath('pages/config.php'))
                    ->setIcon('rex-icon fa-gears')
                );
                $added = true;
            }

            if ($added) {
                $page->addSubpage($tools);
            }
        }
    });

    // Assets einbinden
    $page = rex_be_controller::getCurrentPage();
    
    if ($page === 'mediapool/tools/bulk_rework') {
        rex_view::addJsFile($addon->getAssetsUrl('bulk_rework.js'));
    }
    
    if ($page === 'mediapool/tools/unused_media') {
        rex_view::addJsFile($addon->getAssetsUrl('unused_media.js'));
    }
}

// Media In Use Check registrieren (Global, auch wenn nicht im Addon Backend)
rex_extension::register('MEDIA_IS_IN_USE', function(rex_extension_point $ep) {
    $filename = $ep->getParam('filename');
    if (empty($filename)) return;

    $sql = rex_sql::factory();
    // Prüfen ob Tabelle existiert um Fehler bei frischer Installation zu vermeiden
    try {
        $sql->setQuery('SELECT filename FROM ' . rex::getTable('mediapool_tools_protected') . ' WHERE filename = ? LIMIT 1', [$filename]);
        if ($sql->getRows() > 0) {
            $ep->setSubject(true);
            $warning = $ep->getParam('warning');
            if (!is_array($warning)) $warning = [];
            $warning[] = rex_i18n::rawMsg('mediapool_tools_media_protected_warning', $filename);
            $ep->setParam('warning', $warning);
        }
    } catch (rex_sql_exception $e) {
        // Tabelle existiert noch nicht -> keine Protection
    }
});
