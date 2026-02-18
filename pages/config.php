<?php

$addon = rex_addon::get('mediapool_tools');

$content = '';

$form = rex_config_form::factory($addon->getPackageId());

$form->addFieldset($addon->i18n('config_resize_label'));

$field = $form->addTextField('image-max-width');
$field->setLabel($addon->i18n('config_max_width'));
$field->setNotice($addon->i18n('config_max_width_notice'));
$field->getValidator()->add('min', 'Das Feld muss > 0 sein', 0);

$field = $form->addTextField('image-max-height');
$field->setLabel($addon->i18n('config_max_height'));
$field->setNotice($addon->i18n('config_max_height_notice'));
$field->getValidator()->add('min', 'Das Feld muss > 0 sein', 0);

$form->addFieldset($addon->i18n('config_search_label'));

$field = $form->addTextField('search-min-size-mb');
$field->setLabel($addon->i18n('config_min_size'));
$field->setNotice($addon->i18n('config_min_size_notice'));

$field = $form->addTextField('search-min-width');
$field->setLabel($addon->i18n('config_min_width'));
$field->setNotice($addon->i18n('config_min_width_notice'));

$field = $form->addTextField('search-min-height');
$field->setLabel($addon->i18n('config_min_height'));
$field->setNotice($addon->i18n('config_min_height_notice'));

$form->addFieldset($addon->i18n('config_excluded_tables_label'));

$field = $form->addSelectField('excluded_tables');
$field->setLabel($addon->i18n('config_excluded_tables'));
$field->setNotice($addon->i18n('config_excluded_tables_notice'));

$select = $field->getSelect();
$select->setMultiple();
$select->setSize(10);
$select->setAttribute('class', 'form-control selectpicker'); // Use Bootstrap/REDAXO select styling
$select->setAttribute('data-live-search', 'true');

$tables = rex_sql::factory()->getTablesAndViews();
foreach ($tables as $table) {
    if (strpos($table, 'tmp_') === 0) continue; // Hide tmp tables
    $select->addOption($table, $table);
}

$content .= $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('config'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
