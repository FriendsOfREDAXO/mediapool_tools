<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_api_function;
use rex_api_result;
use rex_request;
use rex_response;
use rex_media_service;
use rex_sql;
use rex_file;
use rex_path;

class ApiUnusedMedia extends rex_api_function
{
    /**
     * @return rex_api_result
     */
    public function execute()
    {
        rex_response::cleanOutputBuffers();
        
        if (!rex::isBackend() || !rex::getUser() || !rex::getUser()->hasPerm('mediapool_tools[unused_media]')) {
            $this->sendError('Zugriff verweigert');
        }

        $action = rex_request('action', 'string');
        
        switch ($action) {
            case 'start_scan':
                UnusedMediaAnalysis::cleanup();
                $batchId = UnusedMediaAnalysis::startAnalysis();
                $this->sendSuccess(['batchId' => $batchId]);
                
            case 'scan_step':
                $batchId = rex_request('batchId', 'string');
                if (!$batchId) $this->sendError('Keine Batch ID');
                $result = UnusedMediaAnalysis::processNextStep($batchId);
                $this->sendSuccess($result);
                
            case 'delete_files':
                $this->deleteFiles();
                
            case 'move_files':
                $this->moveFiles();

            case 'protect_files':
                $this->protectFiles();
                
            default:
                $this->sendError('Unbekannte Aktion');
        }
    }

    /**
     * @return void
     */
    private function protectFiles()
    {
        $files = rex_request('files', 'array');
        if (empty($files)) $this->sendError('Keine Dateien ausgewählt');
        
        try {
            UnusedMediaAnalysis::protectFiles($files);
            // Auch aus dem Cache entfernen für sofortiges Update
            UnusedMediaAnalysis::removeFilesFromCache($files);
            
            $this->sendSuccess(['count' => count($files)]);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage());
        }
    }

    /**
     * @return void
     */
    private function deleteFiles()
    {
        $files = rex_request('files', 'array');
        $force = rex_request('force', 'boolean', false);
        
        if (empty($files)) $this->sendError('Keine Dateien ausgewählt');
        
        $count = 0;
        $errors = [];
        $deletedFiles = [];
        $sql = rex_sql::factory();
        
        foreach ($files as $file) {
            $media = \rex_media::get($file);
            
            // Usage Check (skip if force is true)
            if ($media && !$force) {
                $warning = \rex_extension::registerPoint(new \rex_extension_point(
                    'MEDIA_IS_IN_USE',
                    [],
                    ['filename' => $file, 'medium' => $media]
                ));

                if (is_array($warning) && [] !== $warning) {
                    $reason = implode(', ', $warning);
                    $errors[] = "<b>$file</b> kann nicht gelöscht werden:<br> - $reason";
                    continue;
                }
            }

            try {
                // Determine delete method
                $deleted = false;
                
                if ($force) {
                    // Manual Force Delete
                    $sql->setQuery('DELETE FROM '.rex::getTable('media').' WHERE filename = ?', [$file]);
                    if ($sql->getRows() > 0) {
                        rex_file::delete(rex_path::media($file));
                        \rex_media_manager::deleteCache($file);
                        
                        // Notify system (optional, best effort)
                        \rex_extension::registerPoint(new \rex_extension_point(
                            'MEDIA_DELETED', 
                            null, 
                            ['filename' => $file]
                        ));
                        $deleted = true;
                    } else {
                        // Maybe file didn't exist in DB but existed in list?
                        // Or already deleted.
                         $errors[] = "$file existiert nicht in DB.";
                         // Even if not in DB, remove from list as it is gone
                         $deletedFiles[] = $file;
                    }
                } else {
                    // Standard Delete
                    rex_media_service::deleteMedia($file);
                    $deleted = true;
                }

                if ($deleted) {
                    $count++;
                    $deletedFiles[] = $file;
                } else {
                    if (!$force) $errors[] = "$file konnte nicht gelöscht werden (unbekannter Fehler).";
                }
            } catch (\Exception $e) {
                $errors[] = "$file: " . $e->getMessage();
            }
        }
        
        // Update Cache
        if (!empty($deletedFiles)) {
            UnusedMediaAnalysis::removeFilesFromCache($deletedFiles);
        }
        
        $this->sendSuccess([
            'deleted' => $count,
            'errors' => $errors
        ]);
    }

    /**
     * @return void
     */
    private function moveFiles()
    {
        $files = rex_request('files', 'array');
        $categoryId = rex_request('category_id', 'int');
        
        if (empty($files)) $this->sendError('Keine Dateien ausgewählt');
        
        // Kategorie Validierung
        if ($categoryId > 0) {
            $cat = \rex_media_category::get($categoryId);
            if (!$cat) $this->sendError('Kategorie nicht gefunden');
        }
        
        $count = 0;
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('media'));
        
        foreach ($files as $file) {
            // rex_media_service hat keine move Methode, wir machen es direkt SQL
            // EPs beachten? Ja, MEDIA_UPDATED wäre gut.
            // Aber Bulk-Update ist schneller via SQL und dann Cache clearen.
            
            $sql->setWhere(['filename' => $file]);
            $sql->setValue('category_id', $categoryId);
            $sql->setValue('updatedate', date('Y-m-d H:i:s'));
            $sql->setValue('updateuser', rex::getUser()->getLogin());
            
            try {
                $sql->update();
                $count++;
                \rex_media_cache::delete($file);
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
        
        $this->sendSuccess(['moved' => $count]);
    }

    /**
     * @param array<mixed> $data
     * @return never
     */
    private function sendSuccess($data)
    {
        rex_response::sendJson(['success' => true, 'data' => $data]);
        exit;
    }

    /**
     * @param string $msg
     * @return never
     */
    private function sendError($msg)
    {
        rex_response::sendJson(['success' => false, 'message' => $msg]);
        exit;
    }
}
