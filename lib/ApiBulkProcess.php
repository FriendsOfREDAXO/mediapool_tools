<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_api_function;
use rex_api_result;
use rex_request;
use rex_response;

/**
 * API Endpunkt für die asynchrone Bulk-Verarbeitung
 */
class ApiBulkProcess extends rex_api_function
{
    /**
     * @return rex_api_result
     */
    public function execute()
    {
        rex_response::cleanOutputBuffers();
        
        // Nur für Backend-Nutzer mit entsprechenden Rechten
        if (!rex::isBackend() || !rex::getUser() || !rex::getUser()->hasPerm('mediapool_tools[bulk_rework]')) {
            $this->sendJsonResponse(false, 'Access denied');
        }

        $action = rex_request('action', 'string');
        
        switch ($action) {
            case 'start':
                $this->sendJsonResponse(true, $this->startBatch());
            case 'process':
                $this->sendJsonResponse(true, $this->processNext());
            case 'status':
                $this->sendJsonResponse(true, $this->getStatus());
            default:
                $this->sendJsonResponse(false, 'Unknown action');
        }
    }

    /**
     * @param bool $success
     * @param mixed $data
     * @return never
     */
    private function sendJsonResponse(bool $success, $data)
    {
        rex_response::setHeader('Content-Type', 'application/json');
        echo json_encode([
            'success' => $success,
            'data' => $data
        ]);
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function startBatch(): array
    {
        $filenames = rex_request('filenames', 'array', []);
        $maxWidth = rex_request('maxWidth', 'int', 0);
        $maxHeight = rex_request('maxHeight', 'int', 0);

        if ($maxWidth === 0) {
            $maxWidth = null;
        }
        if ($maxHeight === 0) {
            $maxHeight = null;
        }

        if (empty($filenames)) {
            return ['error' => 'No files provided'];
        }

        // Bereinige alte Batches
        BulkRework::cleanupOldBatches();

        $batchId = BulkRework::startBatchProcessing($filenames, $maxWidth, $maxHeight);
        
        return [
            'batchId' => $batchId,
            'status' => BulkRework::getBatchStatus($batchId)
        ];
    }

    /**
     * @return array<mixed>
     */
    private function processNext(): array
    {
        $batchId = rex_request('batchId', 'string');
        
        if (!$batchId) {
            return ['error' => 'No batch ID provided'];
        }

        // Verwende die neue parallele Verarbeitungsmethode
        $result = BulkRework::processNextBatchItems($batchId);
        
        return $result;
    }

    /**
     * @return array<mixed>
     */
    private function getStatus(): array
    {
        $batchId = rex_request('batchId', 'string');
        
        if (!$batchId) {
            return ['error' => 'No batch ID provided'];
        }

        // Verwende erweiterten Status für detailliertere Informationen
        $status = BulkRework::getBatchStatusExtended($batchId);
        
        if (!$status) {
            return ['error' => 'Batch not found'];
        }

        return $status;
    }
}
