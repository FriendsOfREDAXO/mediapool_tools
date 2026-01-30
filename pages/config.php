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

$content .= $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('config'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
