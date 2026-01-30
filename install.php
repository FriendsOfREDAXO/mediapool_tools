<?php

/** @var rex_addon $this */

rex_sql_table::get(rex::getTable('mediapool_tools_protected'))
    ->ensureColumn(new rex_sql_column('filename', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('createuser', 'varchar(191)'))
    ->setPrimaryKey('filename')
    ->ensure();

// Standard-Einstellungen setzen falls noch nicht vorhanden
if (!$this->hasConfig('image-max-width')) {
    $this->setConfig('image-max-width', 2000);
}
if (!$this->hasConfig('image-max-height')) {
    $this->setConfig('image-max-height', 2000);
}
if (!$this->hasConfig('bulk-max-parallel')) {
    $this->setConfig('bulk-max-parallel', 3);
}
if (!$this->hasConfig('bulk-rework-hits-per-page')) {
    $this->setConfig('bulk-rework-hits-per-page', 200);
}
if (!$this->hasConfig('search-min-size-mb')) {
    $this->setConfig('search-min-size-mb', 0);
}
if (!$this->hasConfig('search-min-width')) {
    $this->setConfig('search-min-width', 0);
}
if (!$this->hasConfig('search-min-height')) {
    $this->setConfig('search-min-height', 0);
}
