<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_request;
use rex_response;
use rex_api_function;
use rex_api_result;

class ApiDuplicates extends rex_api_function
{
    public function execute()
    {
        $action = rex_request('action', 'string');
        
        switch ($action) {
            case 'start_scan':
                $this->startScan();
                break;
            case 'process_batch':
                $this->processBatch();
                break;
            case 'get_result':
                $this->fetchResult();
                break;
            case 'merge_files':
                $this->mergeFiles();
                break;
            case 'get_merge_tables':
                $this->getMergeTables();
                break;
            case 'process_table_merge':
                $this->processTableMerge();
                break;
            case 'finish_merge':
                $this->finishMerge();
                break;
            default:
                throw new \rex_api_exception('Unknown action');
        }

        return new rex_api_result(true);
    }

    private function getMergeTables()
    {
        $tables = DuplicateAnalysis::getTablesForSearch();
        rex_response::sendJson(['success' => true, 'tables' => $tables]);
        exit;
    }

    private function processTableMerge()
    {
        if (!rex::getUser()->isAdmin() && !rex::getUser()->hasPerm('mediapool_tools[duplicates]')) {
            $this->sendError('Permission denied');
        }

        $table = rex_request('table', 'string');
        $keep = rex_request('keep', 'string');
        $replace = rex_request('replace', 'array');
        
        if (!$table || !$keep || empty($replace)) {
            $this->sendError('Missing input');
        }

        // Security check: ensure table is one of the allowed ones to prevent arbitrary SQL exec if logic was different?
        // But getTablesForSearch() returns all tables, so...
        // Strictly speaking, we should validate against known tables.
        if (!in_array($table, DuplicateAnalysis::getTablesForSearch())) {
            $this->sendError('Invalid table');
        }

        $count = DuplicateAnalysis::processTable($table, $keep, $replace);

        rex_response::sendJson(['success' => true, 'replaced_refs' => $count]);
        exit;
    }

    private function finishMerge()
    {
        if (!rex::getUser()->isAdmin() && !rex::getUser()->hasPerm('mediapool_tools[duplicates]')) {
            $this->sendError('Permission denied');
        }

        $keep = rex_request('keep', 'string');
        $replace = rex_request('replace', 'array');
        
        if (!$keep || empty($replace)) {
            $this->sendError('Missing input');
        }

        $result = DuplicateAnalysis::deleteFiles($keep, $replace);
        
        $msg = \rex_addon::get('mediapool_tools')->i18n('duplicates_merge_success', $result['deleted_files'], 0); // Replaced refs count is unknown here, accumulated in JS
        
        rex_response::sendJson(['success' => true, 'data' => $result, 'message' => $msg]);
        exit;
    }

    private function mergeFiles()
    {
        if (!rex::getUser()->isAdmin() && !rex::getUser()->hasPerm('mediapool_tools[duplicates]')) {
            $this->sendError('Permission denied');
        }

        $keep = rex_request('keep', 'string');
        $replace = rex_request('replace', 'array');
        
        if (!$keep || empty($replace)) {
            $this->sendError('Missing input');
        }

        $result = DuplicateAnalysis::mergeFiles($keep, $replace);

        $msg = \rex_addon::get('mediapool_tools')->i18n('duplicates_merge_success', $result['deleted_files'], $result['replaced_refs']);
        if (!empty($result['log'])) {
             // Optional: Be strict about showing logs if errors occurred?
             // $msg .= "\n\n" . implode("\n", $result['log']);
        }

        rex_response::sendJson(['success' => true, 'data' => $result, 'message' => $msg]);
        exit;
    }

    private function sendError($msg) {
        rex_response::sendJson(['success' => false, 'message' => $msg]);
        exit;
    }

    private function startScan()
    {
        $batchId = DuplicateAnalysis::startAnalysis();
        rex_response::sendJson(['success' => true, 'batchId' => $batchId]);
        exit;
    }

    private function processBatch()
    {
        $batchId = rex_request('batchId', 'string');
        $result = DuplicateAnalysis::processBatch($batchId);
        rex_response::sendJson(['success' => true, 'data' => $result]);
        exit;
    }

    private function fetchResult() {
        $batchId = rex_request('batchId', 'string');
        $data = DuplicateAnalysis::getLastResult($batchId);
        
        // Render simple HTML list here
        $html = '';
        if (empty($data['duplicates'])) {
            $html = '<div class="alert alert-info">'.\rex_addon::get('mediapool_tools')->i18n('duplicates_no_duplicates').'</div>';
        } else {
            // Calculate stats
            $totalGroups = count($data['duplicates']);
            $totalFiles = 0;
            $redundantFiles = 0;
            foreach ($data['duplicates'] as $files) {
                $count = count($files);
                $totalFiles += $count;
                $redundantFiles += ($count - 1);
            }

            $html .= '<div class="alert alert-warning mediapool-tools-duplicates-hint">'.\rex_addon::get('mediapool_tools')->i18n('duplicates_usage_hint').'</div>';
            $html .= '<div class="alert alert-warning" style="margin-bottom: 20px;">';
            $html .= '<strong>'.\rex_addon::get('mediapool_tools')->i18n('duplicates_summary_title').'</strong><br>';
            $html .= \rex_addon::get('mediapool_tools')->i18n('duplicates_summary_text', $totalGroups, $totalFiles, $redundantFiles);
            $html .= '</div>';

            foreach ($data['duplicates'] as $hash => $files) {
                // Ensure unique ID for form
                $groupId = substr($hash, 0, 8);
                
                $html .= '<div class="panel panel-warning" id="group-'.$groupId.'"><div class="panel-heading">Hash: '.$hash.' ('.count($files).' '.\rex_addon::get('mediapool_tools')->i18n('duplicates_files').')</div>
                <div class="panel-body">
                    <p>'.\rex_addon::get('mediapool_tools')->i18n('duplicates_group_instruction').'</p>
                    <form class="duplicate-group-form" data-group="'.$groupId.'" onsubmit="return false;">
                    <ul class="list-group">';
                
                foreach ($files as $index => $file) {
                    $media = \rex_media::get($file);
                    $title = $media ? $media->getTitle() : $file;
                    $html .= '<li class="list-group-item">';
                    
                    // Radio button
                    $html .= '<label style="display:flex; align-items:center; width:100%; cursor:pointer;">';
                    $html .= '<input type="radio" name="keep" value="'.htmlspecialchars($file).'" '.($index === 0 ? 'checked' : '').' style="margin-right:10px;">';
                    
                    // Preview
                    if ($media && $media->isImage()) {
                        $html .= '<img src="index.php?rex_media_type=rex_mediapool_preview&rex_media_file='.urlencode($file).'" style="height:30px; margin-right:10px;">';
                    }
                    $html .= '<span><b><a href="index.php?page=mediapool/media&file_name='.urlencode($file).'" target="_blank">'.htmlspecialchars($file).'</a></b> - '.htmlspecialchars($title).'</span>';
                    $html .= '</label>';
                    
                    // Hidden input for membership
                    $html .= '<input type="hidden" name="files[]" value="'.htmlspecialchars($file).'">';
                    
                    $html .= '</li>';
                }
                
                $html .= '</ul>
                <div class="text-right" style="margin-top:10px;">
                    <button type="submit" class="btn btn-warning btn-sm">'.\rex_addon::get('mediapool_tools')->i18n('duplicates_btn_merge').'</button>
                </div>
                </form>
                </div></div>';
            }
        }
        
        rex_response::sendJson(['success' => true, 'html' => $html]);
        exit;
    }
}
