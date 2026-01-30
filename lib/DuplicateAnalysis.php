<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_path;
use rex_file;
use rex_sql;
use rex_media;

class DuplicateAnalysis
{
    const CACHE_KEY = 'mediapool_tools_duplicates_';

    public static function startAnalysis(): string
    {
        $batchId = uniqid('dup_', true);

        // 1. Gruppieren nach Dateigröße um Kandidaten zu finden
        // Wir nehmen nur Gruppen, die mehr als 1 Eintrag haben
        $sql = rex_sql::factory();
        $query = 'SELECT filesize, GROUP_CONCAT(filename) as filenames, COUNT(*) as count 
                  FROM ' . rex::getTable('media') . ' 
                  WHERE filesize > 0
                  GROUP BY filesize 
                  HAVING count > 1';
        
        $sql->setQuery($query);
        $candidates = [];
        
        foreach ($sql as $row) {
            $files = explode(',', $row->getValue('filenames'));
            if (count($files) > 1) {
                // Wir speichern Kandidaten-Gruppen. 
                // Jede Gruppe muss später gehasht werden.
                $candidates[] = $files;
            }
        }

        $status = [
            'id' => $batchId,
            'status' => 'running',
            'candidates' => $candidates, // Array von Arrays mit Dateinamen
            'total_groups' => count($candidates),
            'processed_groups' => 0,
            'duplicates' => [], // Gefundene Duplikate: Hash => [Dateinamen]
            'start_time' => time()
        ];

        self::saveStatus($batchId, $status);
        return $batchId;
    }

    public static function processBatch(string $batchId): array
    {
        $status = self::getStatus($batchId);
        if (!$status || $status['status'] !== 'running') {
            return ['status' => 'error', 'message' => 'Batch invalid'];
        }

        $candidates = $status['candidates'];
        $duplicates = $status['duplicates'];
        $processed = 0;
        $limit = 5; // Verarbeite 5 Dateigrößen-Gruppen pro Batch (kann viele Dateien enthalten)
        $startTime = microtime(true);

        while (!empty($candidates) && $processed < $limit) {
            // Zeitschutz (2 Sekunden pro Request max)
            if (microtime(true) - $startTime > 2.0) {
                break;
            }

            $group = array_shift($candidates); // Nimm erste Gruppe
            $processed++;
            $status['processed_groups']++;

            // Hashe diese Gruppe
            $hashes = [];
            foreach ($group as $filename) {
                $path = rex_path::media($filename);
                if (file_exists($path)) {
                    $hash = md5_file($path);
                    if ($hash) {
                        $hashes[$hash][] = $filename;
                    }
                }
            }

            // Prüfe auf Duplikate innerhalb der Gruppe
            foreach ($hashes as $hash => $files) {
                if (count($files) > 1) {
                    $duplicates[$hash] = $files;
                }
            }
        }

        $status['candidates'] = $candidates;
        $status['duplicates'] = $duplicates;
        
        if (empty($candidates)) {
            $status['status'] = 'completed';
        }

        self::saveStatus($batchId, $status);

        return [
            'status' => $status['status'],
            'progress' => ($status['total_groups'] > 0) ? round(($status['processed_groups'] / $status['total_groups']) * 100) : 100,
            'found' => count($status['duplicates'])
        ];
    }

    public static function getLastResult(string $batchId)
    {
        return self::getStatus($batchId);
    }

    private static function getStatus(string $batchId)
    {
        $file = rex_path::addonCache('mediapool_tools', self::CACHE_KEY . $batchId . '.json');
        if (file_exists($file)) {
            return json_decode(rex_file::get($file), true);
        }
        return null;
    }

    private static function saveStatus(string $batchId, array $data)
    {
        $file = rex_path::addonCache('mediapool_tools', self::CACHE_KEY . $batchId . '.json');
        rex_file::put($file, json_encode($data));
    }

    /**
     * @param string $keepFilename
     * @param array $replaceFilenames
     * @return array
     */
    public static function mergeFiles(string $keepFilename, array $replaceFilenames): array
    {
        $log = [];
        $countReplaced = 0;
        $countDeleted = 0;

        foreach ($replaceFilenames as $oldFilename) {
            if ($oldFilename === $keepFilename) continue;

            // 1. Replace usages
            $replaced = self::replaceMediaUsage($oldFilename, $keepFilename);
            $countReplaced += $replaced;
            
            // 2. Delete file
            try {
                \rex_media_service::deleteMedia($oldFilename);
                $countDeleted++;
            } catch (\Exception $e) {
                // If deletion fails (e.g. because we just replaced usages but checks still fail?), try force delete
                // Usually deleteMedia checks usage. Since we replaced usage, it SHOULD work.
                // However, if we missed some usage, it fails.
                // In that case, we force delete from DB.
                
                $sql = rex_sql::factory();
                $sql->setQuery('DELETE FROM '.rex::getTable('media').' WHERE filename = ?', [$oldFilename]);
                if ($sql->getRows() > 0) {
                    rex_file::delete(rex_path::media($oldFilename));
                    \rex_media_cache::delete($oldFilename);
                    $countDeleted++;
                    $log[] = "Forced delete for $oldFilename after error: " . $e->getMessage();
                } else {
                    $log[] = "Failed to delete $oldFilename: " . $e->getMessage();
                }
            }
        }
        
        rex_delete_cache();

        return [
            'replaced_refs' => $countReplaced,
            'deleted_files' => $countDeleted,
            'log' => $log
        ];
    }

    private static function replaceMediaUsage(string $old, string $new): int
    {
        $count = 0;
        $sql = rex_sql::factory();
        
        // Tables to exclude from scanning
        $ignoredTables = [
            rex::getTable('media'),         // Do not replace in the media pool table itself (we delete the old entry later)
            rex::getTable('media_category'),// Usually doesn't contain filenames, but if so, safe to ignore? Or safe to replace? Let's check logic.
                                            // IDs are used. Path names? No.
            // rex::getTable('user'),       // Users might have avatar fields? If so, we SHOULD replace.
            
            // System-internal tables we probably shouldn't mess with blindly
            rex::getTable('logging'),
            rex::getTable('action'),        // Contains PHP code. Modifying code is advanced.
                                            // Use Case: Hardcoded image in action? Rare.
                                            // Risk: Breaking code signatures or logic.
                                            // Decision: Include it. If the image is hardcoded, it's dead anyway if we delete the file.
        ];
        
        $tables = rex_sql::factory()->getTablesAndViews();

        foreach ($tables as $table) {
             if (in_array($table, $ignoredTables)) continue;
             
             // Determine Primary Key for updates
             $pkResult = $sql->getArray("SHOW KEYS FROM ".$sql->escapeIdentifier($table)." WHERE Key_name = 'PRIMARY'");
             $pkName = '';
             if (count($pkResult) > 0) {
                 $pkName = $pkResult[0]['Column_name'];
             } else {
                 // Tables without internal Primary Key (rare in REDAXO).
                 // We can try using unique index or just skip safe updating?
                 // Or we use LIMIT 1 with all current values in WHERE? Too complex.
                 // Skip tables without PK.
                 continue;
             }

             // Get String Columns
             $columns = rex_sql::factory()->showColumns($table);
             
             foreach ($columns as $column) {
                 $colName = $column['name'];
                 $type = strtolower($column['type']);
                 
                 // Process matches in string-like columns
                 if (
                     strpos($type, 'char') !== false || 
                     strpos($type, 'text') !== false || 
                     strpos($type, 'blob') !== false
                 ) {
                     // 1. Find candidates (Optimization)
                     // Use simple LIKE query to find rows that *might* contain the file
                     // This reduces the number of PHP regex operations significantly
                     $candidateSql = rex_sql::factory();
                     $query = 'SELECT '.$candidateSql->escapeIdentifier($pkName).', '.$candidateSql->escapeIdentifier($colName).' 
                               FROM '.$candidateSql->escapeIdentifier($table).' 
                               WHERE '.$candidateSql->escapeIdentifier($colName).' LIKE ?';
                     
                     $rows = $candidateSql->getArray($query, ['%'.$old.'%']);
                     
                     foreach ($rows as $row) {
                         $currentVal = $row[$colName];
                         
                         // 2. Precise Regex Replacement
                         // Avoid partial matches (e.g. replacing 'foo.jpg' inside 'my_foo.jpg')
                         // Allowed chars in filenames: alphanumeric, ., _, -
                         // Look for $old surrounded by non-filename-chars (or start/end of string)
                         
                         $regex = '/(?<![a-zA-Z0-9._+-])' . preg_quote($old, '/') . '(?![a-zA-Z0-9._+-])/';
                         
                         $newVal = preg_replace($regex, $new, $currentVal);
                         
                         if ($newVal !== null && $newVal !== $currentVal) {
                             // 3. Update Row
                             $upd = rex_sql::factory();
                             $upd->setTable($table);
                             $upd->setWhere([$pkName => $row[$pkName]]);
                             $upd->setValue($colName, $newVal);
                             
                             try {
                                 $upd->update();
                                 // Add metadata for history if needed (e.g., updated_at). 
                                 // Or just trust REDAXO's internal handling (if via Dataset).
                                 // Since we use raw SQL via rex_sql, timestamps aren't auto-updated unless trigger exists.
                                 // This is acceptable for a maintenance tool.
                                 $count++;
                             } catch (\Exception $e) {
                                 // Ignore update errors (e.g. constraints)
                                 // But maybe log them?
                             }
                         }
                     }
                 }
             }
        }

        return $count;
    }
}
