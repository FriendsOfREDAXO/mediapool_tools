<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_addon;
use rex_api_function;
use rex_api_result;
use rex_media;
use rex_request;
use rex_response;

/**
 * Defekte Dateien: Analyse in Etappen, Ergebnisliste und Aktionen (Angaben korrigieren, Endung korrigieren, löschen).
 */
class ApiBrokenMedia extends rex_api_function
{
    protected function requiresCsrfProtection()
    {
        return true;
    }

    public function execute()
    {
        $user = rex::getUser();
        if (!$user || (!$user->isAdmin() && !$user->hasPerm('mediapool_tools[broken_media]'))) {
            $this->sendError('Permission denied');
        }

        switch (rex_request('action', 'string')) {
            case 'start_scan':
                rex_response::sendJson(['success' => true, 'batchId' => BrokenMediaAnalysis::startAnalysis()]);
                exit;
            case 'process_batch':
                rex_response::sendJson(['success' => true, 'data' => BrokenMediaAnalysis::processBatch(rex_request('batchId', 'string'))]);
                exit;
            case 'get_result':
                $this->sendResult(rex_request('batchId', 'string'));
                // no break
            case 'fix_meta':
                $file = $this->mediaFile();
                BrokenMediaAnalysis::fixMetadata($file);
                rex_response::sendJson(['success' => true, 'message' => $this->i18n('broken_media_fixed', $file)]);
                exit;
            case 'fix_meta_batch':
                $fixed = 0;
                foreach (array_slice(rex_request('files', 'array', []), 0, 100) as $file) {
                    if (is_string($file) && rex_media::get($file) && BrokenMediaAnalysis::fixMetadata($file)) {
                        ++$fixed;
                    }
                }
                rex_response::sendJson(['success' => true, 'fixed' => $fixed]);
                exit;
            case 'delete':
                $file = $this->mediaFile();
                try {
                    \rex_media_service::deleteMedia($file);
                } catch (\Exception $e) {
                    $this->sendError(strip_tags($e->getMessage()));
                }
                rex_response::sendJson(['success' => true, 'message' => $this->i18n('broken_media_deleted', $file)]);
                exit;
            case 'rename_start':
                $file = $this->mediaFile();
                $extension = rex_request('extension', 'string');
                if (!in_array($extension, BrokenMediaAnalysis::MIME_EXTENSION, true)) {
                    $this->sendError('Invalid extension');
                }
                $new = BrokenMediaAnalysis::correctedFilename($file, $extension);
                try {
                    BrokenMediaAnalysis::copyForRename($file, $new);
                } catch (\Exception $e) {
                    $this->sendError($e->getMessage());
                }
                rex_response::sendJson(['success' => true, 'newName' => $new, 'tables' => DuplicateAnalysis::getTablesForSearch()]);
                exit;
            case 'rename_table':
                [$old, $new] = $this->renamePair();
                $table = rex_request('table', 'string');
                if (!in_array($table, DuplicateAnalysis::getTablesForSearch(), true)) {
                    $this->sendError('Invalid table');
                }
                rex_response::sendJson(['success' => true, 'replaced' => DuplicateAnalysis::processTable($table, $new, [$old])]);
                exit;
            case 'rename_finish':
                [$old, $new] = $this->renamePair();
                try {
                    BrokenMediaAnalysis::finishRename($old, $new);
                } catch (\Exception $e) {
                    $this->sendError($e->getMessage());
                }
                rex_response::sendJson(['success' => true, 'message' => $this->i18n('broken_media_renamed', $old, $new)]);
                exit;
        }

        throw new \rex_api_exception('Unknown action');
    }

    /** Dateiname aus der Anfrage – muss im Medienpool existieren */
    private function mediaFile(): string
    {
        $file = rex_request('file', 'string');
        if ('' === $file || !rex_media::get($file)) {
            $this->sendError($this->i18n('broken_media_not_found', $file));
        }

        return $file;
    }

    /**
     * @return array{string, string}
     */
    private function renamePair(): array
    {
        $old = $this->mediaFile();
        $new = rex_request('newName', 'string');
        if ('' === $new || basename($new) !== $new || !is_file(\rex_path::media($new))) {
            $this->sendError('Invalid target');
        }

        return [$old, $new];
    }

    private function sendResult(string $batchId): void
    {
        $data = BrokenMediaAnalysis::getStatus($batchId);
        if (!$data) {
            $this->sendError('Batch invalid');
        }
        rex_response::sendJson(['success' => true, 'html' => BrokenMediaList::render($data)]);
        exit;
    }

    private function i18n(string $key, string ...$args): string
    {
        return rex_addon::get('mediapool_tools')->i18n($key, ...$args);
    }

    /**
     * @return never
     */
    private function sendError(string $message): void
    {
        rex_response::sendJson(['success' => false, 'message' => $message]);
        exit;
    }
}
