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
                $this->getResult();
                break;
            default:
                throw new \rex_api_exception('Unknown action');

        }

        return new rex_api_result(true);
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

    private function getResult() {
        $batchId = rex_request('batchId', 'string');
        $data = DuplicateAnalysis::getLastResult($batchId);
        
        // Render simple HTML list here
        $html = '';
        if (empty($data['duplicates'])) {
            $html = '<div class="alert alert-info">Keine Duplikate gefunden.</div>';
        } else {
            foreach ($data['duplicates'] as $hash => $files) {
                $html .= '<div class="panel panel-warning"><div class="panel-heading">Hash: '.$hash.' ('.count($files).' Dateien)</div><ul class="list-group">';
                foreach ($files as $file) {
                    $media = \rex_media::get($file);
                    $title = $media ? $media->getTitle() : $file;
                    $html .= '<li class="list-group-item">';
                    // Preview
                    if ($media && $media->isImage()) {
                        $html .= '<img src="index.php?rex_media_type=rex_mediapool_preview&rex_media_file='.urlencode($file).'" style="height:30px; margin-right:10px;">';
                    }
                    $html .= '<b><a href="index.php?page=mediapool/media&file_name='.urlencode($file).'">'.htmlspecialchars($file).'</a></b> - '.htmlspecialchars($title);
                    $html .= '</li>';
                }
                $html .= '</ul></div>';
            }
        }
        
        rex_response::sendJson(['success' => true, 'html' => $html]);
        exit;
    }
}
