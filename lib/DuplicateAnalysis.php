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
        
        // 1. Slices - Media & Medialist
        // rex_article_slice
        
        // Single Media Columns (media1 - media10)
        for ($i = 1; $i <= 10; $i++) {
            $sql->setQuery('UPDATE ' . rex::getTable('article_slice') . ' SET media'.$i.' = ? WHERE media'.$i.' = ?', [$new, $old]);
            $count += $sql->getRows();
        }
        
        // Media List Columns (medialist1 - medialist10)
        // This is complex. We fetch rows containing the file and update via PHP to be safe.
        for ($i = 1; $i <= 10; $i++) {
            $col = 'medialist'.$i;
            $rows = $sql->getArray('SELECT id, '.$col.' FROM ' . rex::getTable('article_slice') . ' WHERE FIND_IN_SET(?, '.$col.')', [$old]);
            
            foreach ($rows as $row) {
                $files = explode(',', $row[$col]);
                $pos = array_search($old, $files);
                if ($pos !== false) {
                    $files[$pos] = $new;
                    // Remove duplicates if new file is already in list?
                    // $files = array_unique($files); // Better not change logic too much
                    $newVal = implode(',', $files);
                    
                    $upd = rex_sql::factory();
                    $upd->setQuery('UPDATE ' . rex::getTable('article_slice') . ' SET '.$col.' = ? WHERE id = ?', [$newVal, $row['id']]);
                    if ($upd->getRows()) $count++;
                }
            }
        }
        
        // Values (Rely on String Replace strictly for now, but only on value columns)
        // Values can contain the filename in Textile/HTML.
        // E.g. "index.php?rex_media_file=old.jpg"
        for ($i = 1; $i <= 20; $i++) {
             $col = 'value'.$i;
             // We do a simple Replace.
             // Risk: "my_old.jpg" matches "old.jpg".
             // We can try to be specific? No, in Text fields we assume the user wants to replace all occurrences.
             // But we should use REPLACE(val, 'old.jpg', 'new.jpg')
             $query = 'UPDATE ' . rex::getTable('article_slice') . ' SET '.$col.' = REPLACE('.$col.', ?, ?) WHERE '.$col.' LIKE ?';
             $sql->setQuery($query, [$old, $new, '%'.$old.'%']);
             $count += $sql->getRows();
        }

        // 2. Meta Info (yform tables or rex_metainfo tables?)
        // Core has rex_metainfo management.
        // We scan all tables that contain 'file' or 'image' or 'media' in column name?
        // Or better: Check known metainfo definitions.
        
        if (rex::isBackend() && \rex_addon::get('metainfo')->isAvailable()) {
            // Find fields of type REX_MEDIA_WIDGET (6) or REX_MEDIALIST_WIDGET (7)
            $fields = $sql->getArray('SELECT name, type_id FROM '.rex::getTable('metainfo_field').' WHERE type_id IN (6, 7)');
            
            foreach ($fields as $field) {
                $colName = $field['name'];
                // Table depends on prefix: art_, cat_, med_, clang_
                $prefix = substr($colName, 0, 4);
                $table = '';
                if ($prefix === 'art_') $table = rex::getTable('article');
                if ($prefix === 'cat_') $table = rex::getTable('article'); // categories share table with articles
                if ($prefix === 'med_') $table = rex::getTable('media');
                if ($prefix === 'clan') $table = rex::getTable('clang');
                
                if ($table) {
                    if ($field['type_id'] == 6) { // Single Media
                         $sql->setQuery('UPDATE ' . $table . ' SET '.$colName.' = ? WHERE '.$colName.' = ?', [$new, $old]);
                         $count += $sql->getRows();
                    } elseif ($field['type_id'] == 7) { // Media List
                        $rows = $sql->getArray('SELECT id, '.$colName.' FROM ' . $table . ' WHERE FIND_IN_SET(?, '.$colName.')', [$old]);
                        foreach ($rows as $row) {
                            $files = explode(',', $row[$colName]);
                            $pos = array_search($old, $files);
                            if ($pos !== false) {
                                $files[$pos] = $new;
                                $newVal = implode(',', $files);
                                $upd = rex_sql::factory();
                                $upd->setQuery('UPDATE ' . $table . ' SET '.$colName.' = ? WHERE id = ?', [$newVal, $row['id']]);
                                if ($upd->getRows()) $count++;
                            }
                        }
                    }
                }
            }
        }

        return $count;
    }
}
