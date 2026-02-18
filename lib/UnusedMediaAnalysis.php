<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_addon;
use rex_file;
use rex_path;
use rex_sql;
use rex_logger;
use rex_media_service;
use rex_sql_table;
use rex_sql_column;
use rex_sql_exception;

class UnusedMediaAnalysis
{
    const BATCH_CACHE_KEY = 'mediapool_tools_unused_analysis_';
    const RESULT_CACHE_FILE = 'unused_media_result.json';
    const ROWS_PER_STEP = 500; // Anzahl Zeilen pro Ajax-Request pro Tabelle

    /**
     * Startet eine neue Analyse
     * @return string BatchID
     */
    public static function startAnalysis(): string
    {
        $batchId = uniqid('analysis_', true);
        
        // Tabellen ermitteln
        $tables = rex_sql::factory()->getTablesAndViews();
        $tablesToScan = [];
        
        // Wir scannen alle Tabellen, außer rex_media eigene Tabellen
        
        // Add user defined excluded tables
        $userExcluded = \rex_config::get('mediapool_tools', 'excluded_tables', []);
        $userExcludedTables = [];

        // Ensure it's an array
        if (!is_array($userExcluded)) {
            if (is_string($userExcluded) && $userExcluded !== '') {
                 // Try pipe separated (rex_config default list save)
                 if (strpos($userExcluded, '|') !== false) {
                     $userExcluded = explode('|', trim($userExcluded, '|'));
                 }
                 // Try newline separated (old text area)
                 else {
                     $userExcluded = explode("\n", str_replace(["\r", ","], "\n", $userExcluded));
                 }
            } else {
                $userExcluded = [];
            }
        }

        foreach ($userExcluded as $ut) {
             $ut = trim($ut);
             if ($ut != '') {
                 $userExcludedTables[] = $ut;
             }
        }

        foreach ($tables as $table) {
            // Ignoriere Tabellen, die sicher keine Medien enthalten (z.B. rex_user_passkey, Cache tables etc)
            // Auch rex_media und rex_media_category ignorieren, da hier die Dateien definiert sind, nicht genutzt.
            if (strpos($table, 'tmp_') === 0) continue;
            
            // Definitionstabellen ausschließen, sonst findet sich jede Datei selbst
            if ($table == rex::getTable('media')) continue;
            if ($table == rex::getTable('media_category')) continue;
            if ($table == rex::getTable('mediapool_tools_protected')) continue; 
            
            if (in_array($table, $userExcludedTables)) continue;

            $tablesToScan[] = $table;
        }

        $batchData = [
            'id' => $batchId,
            'status' => 'running',
            'tables' => $tablesToScan,
            'currentTableIndex' => 0,
            'currentRowOffset' => 0,
            'totalTables' => count($tablesToScan),
            'foundFiles' => [], // Array mit Dateinamen (Keys) um Duplikate zu vermeiden
            'startTime' => time(),
            'currentTable' => $tablesToScan[0] ?? '',
            'scannedRows' => 0
        ];
        
        self::saveBatchStatus($batchId, $batchData);
        
        return $batchId;
    }

    /**
     * Führt den nächsten Schritt der Analyse aus
     * @param string $batchId
     * @return array<string, mixed>
     */
    public static function processNextStep(string $batchId): array
    {
        $batch = self::getBatchStatus($batchId);
        if (!$batch || $batch['status'] !== 'running') {
            return ['status' => 'error', 'message' => 'Analyse nicht aktiv'];
        }

        $maxTime = 2; // Sekunden pro Request max (Soft Limit)
        $startTime = microtime(true);
        $tablesProcessedInStep = 0;
        
        while (microtime(true) - $startTime < $maxTime) {
            if ($batch['currentTableIndex'] >= count($batch['tables'])) {
                // Fertig!
                return self::finalizeAnalysis($batchId);
            }

            $tableName = $batch['tables'][$batch['currentTableIndex']];
            
            // Hole Spalten der aktuellen Tabelle
            $columns = self::getStringColumns($tableName);
            
            if (empty($columns)) {
                // Tabelle hat keine scanbaren Spalten, weiter zur nächsten
                $batch['currentTableIndex']++;
                $batch['currentRowOffset'] = 0;
                $batch['currentTable'] = $batch['tables'][$batch['currentTableIndex']] ?? '';
                continue;
            }

            // Scanne Tabelle Stück für Stück
            $sql = rex_sql::factory();
            // $sql->setDebug(true);
            
            // Query bauen: Wir brauchen nur die Spalten, die Text enthalten könnten
            $colsSelect = implode(', ', array_map(function($c) { return '`'.$c.'`'; }, $columns));
            
            // Paginierter Zugriff
            $query = "SELECT $colsSelect FROM `$tableName` LIMIT " . (int)$batch['currentRowOffset'] . ", " . self::ROWS_PER_STEP;
            $sql->setQuery($query);
            
            if ($sql->getRows() == 0) {
                // Tabelle abgearbeitet
                $batch['currentTableIndex']++;
                $batch['currentRowOffset'] = 0;
                $batch['currentTable'] = $batch['tables'][$batch['currentTableIndex']] ?? '';
                $tablesProcessedInStep++;
                continue; // Nächste Iteration der While-Schleife
            }
            
            // Zeilen durchsuchen
            $foundInStep = [];
            foreach ($sql as $row) {
                foreach ($columns as $col) {
                    $content = $row->getValue($col);
                    if (empty($content) || !is_string($content)) continue;
                    
                    // Regex für Dateinamen: Standard REDAXO Medien (Dateiname + Ext)
                    // Wir suchen nach strings die wie medien aussehen.
                    // Annahme: Wenn ein String "mein_bild.jpg" enthält, ist es genutzt.
                    if (preg_match_all('/([a-zA-Z0-9_\-\.]+)\.(jpg|jpeg|png|gif|webp|pdf|svg|mp4|mp3|zip)/i', $content, $matches)) {
                        foreach ($matches[0] as $match) {
                            $foundInStep[$match] = true;
                        }
                    }
                }
                $batch['scannedRows']++;
            }
            
            // Merge results
            $batch['foundFiles'] = array_merge($batch['foundFiles'], $foundInStep);
            
            // Bereite nächsten Schritt in gleicher Tabelle vor
            $batch['currentRowOffset'] += self::ROWS_PER_STEP;
        }
        
        // Speichere Zwischenstand
        self::saveBatchStatus($batchId, $batch);
        
        return [
            'status' => 'processing',
            'progress' => [
                'currentTable' => $batch['currentTable'],
                'tableIndex' => $batch['currentTableIndex'],
                'totalTables' => $batch['totalTables'],
                'percent' => round(($batch['currentTableIndex'] / $batch['totalTables']) * 100)
            ]
        ];
    }
    
    /**
     * @param string $batchId
     * @return array<string, mixed>
     */
    private static function finalizeAnalysis(string $batchId): array
    {
        $batch = self::getBatchStatus($batchId);
        
        // Alle Medien aus Pool holen
        $sql = rex_sql::factory();
        $sql->setQuery("SELECT filename FROM " . rex::getTable('media'));
        $allMedia = [];
        foreach ($sql as $row) {
            $allMedia[$row->getValue('filename')] = true;
        }
        
        $foundFiles = $batch['foundFiles']; // Keys sind die Dateinamen
        
        // Protected files laden
        $protectedFiles = array_fill_keys(self::getProtectedFiles(), true);
        
        // Unused = All - Found - Protected
        // Array_diff_key ist performant
        $unusedFiles = array_diff_key($allMedia, $foundFiles, $protectedFiles);
        
        // Ergebnis speichern
        $resultData = [
            'timestamp' => time(),
            'count_total' => count($allMedia),
            'count_unused' => count($unusedFiles),
            'files' => array_keys($unusedFiles)
        ];
        
        rex_file::put(
            rex_path::addonCache('mediapool_tools', self::RESULT_CACHE_FILE),
            json_encode($resultData)
        );
        
        // Batch beenden
        $batch['status'] = 'completed';
        self::saveBatchStatus($batchId, $batch);
        
        return [
            'status' => 'completed',
            'result' => $resultData
        ];
    }

    /**
     * @param string $tableName
     * @return array<string>
     */
    private static function getStringColumns(string $tableName): array
    {
        $sql = rex_sql::factory();
        $cols = $sql->showColumns($tableName);
        $relevant = [];
        foreach ($cols as $col) {
            $type = strtolower($col['type']);
            if (
                strpos($type, 'char') !== false || 
                strpos($type, 'text') !== false ||
                strpos($type, 'blob') !== false ||
                strpos($type, 'json') !== false
            ) {
                $relevant[] = $col['name'];
            }
        }
        return $relevant;
    }

    /**
     * @param string $batchId
     * @param array<string, mixed> $data
     * @return void
     */
    private static function saveBatchStatus(string $batchId, array $data): void
    {
        rex_file::put(
            rex_path::addonCache('mediapool_tools', self::BATCH_CACHE_KEY . $batchId . '.json'),
            json_encode($data)
        );
    }
    
    /**
     * @param string $batchId
     * @return array<string, mixed>|null
     */
    public static function getBatchStatus(string $batchId): ?array
    {
        $file = rex_path::addonCache('mediapool_tools', self::BATCH_CACHE_KEY . $batchId . '.json');
        if (file_exists($file)) {
            return json_decode(rex_file::get($file), true);
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getLastResult(): ?array
    {
        $file = rex_path::addonCache('mediapool_tools', self::RESULT_CACHE_FILE);
        if (file_exists($file)) {
            $data = json_decode(rex_file::get($file), true);
            if ($data && isset($data['files'])) {
                 // Filter protected files at runtime to be safe
                 $protected = self::getProtectedFiles();
                 if (!empty($protected)) {
                     $data['files'] = array_values(array_diff($data['files'], $protected));
                 }
                 
                 // Filter by Config (Size, Dimensions)
                 $addon = rex_addon::get('mediapool_tools');
                 $minSizeMB = (float)$addon->getConfig('search-min-size-mb', 0);
                 $minWidth = (int)$addon->getConfig('search-min-width', 0);
                 $minHeight = (int)$addon->getConfig('search-min-height', 0);
                 
                 if (($minSizeMB > 0 || $minWidth > 0 || $minHeight > 0) && !empty($data['files'])) {
                     $minBytes = $minSizeMB * 1024 * 1024;
                     $filtered = [];
                     
                     // Helper: Chunk processing
                     $chunks = array_chunk($data['files'], 500);
                     $sql = rex_sql::factory();
                     
                     foreach ($chunks as $chunk) {
                         $in = $sql->in($chunk);
                         $where = ['filename IN (' . $in . ')'];
                         
                         if ($minBytes > 0) {
                             $where[] = 'filesize >= ' . (int)$minBytes;
                         }
                         // OR or AND? "dimensions OR MB". 
                         // Usually filters are restrictive (AND). "Files that match criteria".
                         // User said "dimensions or MB after which should be searched".
                         // Usually implies "Show me things that are BIG (width > X OR height > Y OR size > Z)".
                         // Let's implement OR logic for "Bigness"? Or AND logic for "Search Criteria"?
                         // "suche nach x ODER y".
                         // If I have ANY criteria set, I want files matching ANY of them?
                         // "Files > 5MB" -> List.
                         // "Images > 2000px" -> List.
                         // "Files > 5MB OR Images > 2000px".
                         
                         $criteria = [];
                         if ($minBytes > 0) $criteria[] = 'filesize >= ' . (int)$minBytes;
                         if ($minWidth > 0) $criteria[] = 'width >= ' . $minWidth;
                         if ($minHeight > 0) $criteria[] = 'height >= ' . $minHeight;
                         
                         if (!empty($criteria)) {
                             $whereStatement = implode(' OR ', $criteria);
                             $query = 'SELECT filename FROM ' . rex::getTable('media') . ' WHERE filename IN (' . $in . ') AND (' . $whereStatement . ')';
                             
                             $sql->setQuery($query);
                             foreach ($sql as $row) {
                                 $filtered[] = (string)$row->getValue('filename');
                             }
                         } else {
                             // No effective criteria (values are 0)
                             $filtered = array_merge($filtered, $chunk);
                         }
                     }
                     $data['files'] = $filtered;
                     // Note: We don't update 'count_unused' from the cache, so the UI might show "Found X unused (filtered from Y)" logic if we want.
                     // But here we overwrite it for the simple view.
                     $data['count_unused'] = count($filtered);
                 }
            }
            return $data;
        }
        return null;
    }

    /**
     * @return array<string>
     */
    public static function getProtectedFiles(): array
    {
        $sql = rex_sql::factory();
        // Check if table exists (lazy check, catch exception)
        try {
            $sql->setQuery('SELECT filename FROM ' . rex::getTable('mediapool_tools_protected'));
            $files = [];
            foreach ($sql as $row) {
                $files[] = $row->getValue('filename');
            }
            return $files;
        } catch (rex_sql_exception $e) {
            // Table might not exist yet
            return [];
        }
    }

    /**
     * @param array<string> $filesToRemove
     * @return void
     */
    public static function removeFilesFromCache(array $filesToRemove): void
    {
        $file = rex_path::addonCache('mediapool_tools', self::RESULT_CACHE_FILE);
        if (!file_exists($file)) return;
        
        $data = json_decode(rex_file::get($file), true);
        if (!$data || !isset($data['files'])) return;
        
        // Remove files
        $data['files'] = array_values(array_diff($data['files'], $filesToRemove));
        $data['count_unused'] = count($data['files']);
        
        rex_file::put($file, json_encode($data));
    }

    /**
     * @param array<string> $files
     * @return void
     */
    public static function protectFiles(array $files): void
    {
        // Ensure table exists
        rex_sql_table::get(rex::getTable('mediapool_tools_protected'))
            ->ensureColumn(new rex_sql_column('filename', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
            ->ensureColumn(new rex_sql_column('createuser', 'varchar(191)'))
            ->setPrimaryKey('filename')
            ->ensure();

        $sql = rex_sql::factory();
        $user = rex::getUser() ? rex::getUser()->getLogin() : 'system';
        $prio = 0;
        
        foreach ($files as $file) {
             $sql->setTable(rex::getTable('mediapool_tools_protected'));
             $sql->setValue('filename', $file);
             $sql->setValue('createdate', date('Y-m-d H:i:s'));
             $sql->setValue('createuser', $user);
             try {
                $sql->insert();
             } catch (rex_sql_exception $e) {
                 // Ignore duplicates
             }
        }
    }
    
    // Hilfsmethode für AJAX Cleanup
    /**
     * @return void
     */
    public static function cleanup() 
    {
       // Alte Batches löschen
       $dir = rex_path::addonCache('mediapool_tools');
       $files = glob($dir . self::BATCH_CACHE_KEY . '*.json');
       if ($files) {
           foreach ($files as $file) {
               if (time() - filemtime($file) > 3600) rex_file::delete($file);
           }
       }
    }
}
