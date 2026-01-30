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
}
