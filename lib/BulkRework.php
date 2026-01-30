<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_addon;
use rex_file;
use rex_media;
use rex_media_cache;
use rex_path;
use rex_logger;
use rex_sql;
use Exception;

/**
 * Class BulkRework
 *
 * @package mediapool_tools\lib
 */
class BulkRework
{
    // Unterstützte Bildformate
    const SUPPORTED_FORMATS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'
    ];
    
    // Problematische Formate die nur mit ImageMagick funktionieren
    const IMAGEMAGICK_ONLY_FORMATS = [
        'psd'
    ];
    
    // Übersprungene Formate bei Bulk-Verarbeitung
    const SKIPPED_FORMATS = [
        'tif', 'tiff', 'svg', 'heic'
    ];
    
    // Batch processing status cache key
    const BATCH_CACHE_KEY = 'mediapool_tools_bulk_batch_';
    
    // Maximale parallele Verarbeitung
    const MAX_PARALLEL_PROCESSES = 3;
    
    /**
     * Gibt die maximale Anzahl paralleler Prozesse zurück (konfigurierbar)
     *
     * @return int
     */
    public static function getMaxParallelProcesses(): int
    {
        return (int)rex_addon::get('mediapool_tools')->getConfig('bulk-max-parallel', self::MAX_PARALLEL_PROCESSES);
    }
    
    /**
     * Prüft ob ein Bildformat verarbeitet werden kann
     *
     * @param string $filename
     * @return array ['canProcess' => bool, 'needsImageMagick' => bool, 'format' => string]
     */
    public static function canProcessImage(string $filename): array
    {
        $extension = strtolower(rex_file::extension($filename));
        
        $result = [
            'canProcess' => false,
            'needsImageMagick' => false,
            'format' => $extension,
            'reason' => ''
        ];
        
        // Überspringe TIFF und SVG bei Bulk-Verarbeitung
        if (in_array($extension, self::SKIPPED_FORMATS)) {
            $result['reason'] = 'Format wird bei Bulk-Verarbeitung übersprungen';
            return $result;
        }
        
        if (in_array($extension, self::SUPPORTED_FORMATS)) {
            $result['canProcess'] = true;
        } elseif (in_array($extension, self::IMAGEMAGICK_ONLY_FORMATS)) {
            if (self::hasImageMagick()) {
                $result['canProcess'] = true;
                $result['needsImageMagick'] = true;
            } else {
                $result['reason'] = 'Format benötigt ImageMagick';
            }
        } else {
            $result['reason'] = 'Nicht unterstütztes Format';
        }
        
        return $result;
    }
    
    /**
     * Prüft ob ImageMagick verfügbar ist
     *
     * @return bool
     */
    public static function hasImageMagick(): bool
    {
        return class_exists('Imagick') || !empty(self::getConvertPath());
    }
    
    /**
     * Ermittelt den Pfad zum ImageMagick convert Binary
     *
     * @return string
     */
    private static function getConvertPath(): string
    {
        $path = '';

        if (function_exists('exec')) {
            $out = [];
            $cmd = 'command -v convert || which convert';
            exec($cmd, $out, $ret);

            if (0 === $ret && !empty($out[0])) {
                $path = (string) $out[0];
            }
        }
        return $path;
    }
    
    /**
     * Prüft ob GD verfügbar ist
     *
     * @return bool
     */
    public static function hasGD(): bool
    {
        return extension_loaded('gd');
    }
    
    /**
     * Startet einen Batch-Verarbeitungsvorgang
     *
     * @param array $filenames
     * @param int|null $maxWidth
     * @param int|null $maxHeight
     * @return string Batch-ID
     */
    public static function startBatchProcessing(array $filenames, ?int $maxWidth = null, ?int $maxHeight = null): string
    {
        $batchId = uniqid('batch_', true);
        
        $batchData = [
            'id' => $batchId,
            'filenames' => $filenames,
            'maxWidth' => $maxWidth,
            'maxHeight' => $maxHeight,
            'total' => count($filenames),
            'processed' => 0,
            'successful' => 0,
            'errors' => [],
            'skipped' => [],
            'status' => 'running',
            'currentFiles' => [],
            'processQueue' => array_values($filenames),
            'startTime' => time()
        ];
        
        // Status in Cache speichern
        rex_file::put(
            rex_path::addonCache('mediapool_tools', self::BATCH_CACHE_KEY . $batchId . '.json'),
            json_encode($batchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        return $batchId;
    }
    
    /**
     * Holt den Status eines Batch-Vorgangs
     *
     * @param string $batchId
     * @return array|null
     */
    public static function getBatchStatus(string $batchId): ?array
    {
        $cacheFile = rex_path::addonCache('mediapool_tools', self::BATCH_CACHE_KEY . $batchId . '.json');
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $content = rex_file::get($cacheFile);
        return json_decode($content, true);
    }
    
    /**
     * Holt erweiterten Status eines Batch-Vorgangs
     *
     * @param string $batchId
     * @return array|null
     */
    public static function getBatchStatusExtended(string $batchId): ?array
    {
        $status = self::getBatchStatus($batchId);
        
        if (!$status) {
            return null;
        }
        
        $status['processed'] = $status['processed'] ?? 0;
        $status['total'] = $status['total'] ?? 0;
        $status['successful'] = $status['successful'] ?? 0;
        $status['errors'] = $status['errors'] ?? [];
        $status['skipped'] = $status['skipped'] ?? [];
        $status['currentFiles'] = $status['currentFiles'] ?? [];
        $status['processQueue'] = $status['processQueue'] ?? [];
        
        $progress = $status['total'] > 0 ? round(($status['processed'] / $status['total']) * 100, 1) : 0;
        
        $elapsed = time() - ($status['startTime'] ?? time());
        $remainingTime = null;
        
        if ($status['processed'] > 0 && $elapsed > 0) {
            $avgTimePerFile = $elapsed / $status['processed'];
            $remaining = $status['total'] - $status['processed'];
            $remainingTime = round($avgTimePerFile * $remaining);
        }
        
        $currentlyProcessing = [];
        if (!empty($status['currentFiles']) && is_array($status['currentFiles'])) {
            foreach ($status['currentFiles'] as $process) {
                if (is_array($process) && isset($process['filename'])) {
                    $currentlyProcessing[] = [
                        'filename' => $process['filename'],
                        'duration' => isset($process['startTime']) ? 
                            round(microtime(true) - $process['startTime'], 1) : 0
                    ];
                }
            }
        }
        
        return array_merge($status, [
            'progress' => $progress,
            'remainingTime' => $remainingTime,
            'elapsedTime' => $elapsed,
            'currentlyProcessing' => $currentlyProcessing,
            'queueLength' => count($status['processQueue']),
            'activeProcesses' => count($status['currentFiles'])
        ]);
    }
    
    /**
     * Aktualisiert den Status eines Batch-Vorgangs
     *
     * @param string $batchId
     * @param array $updates
     * @return bool
     */
    private static function updateBatchStatus(string $batchId, array $updates): bool
    {
        $status = self::getBatchStatus($batchId);
        if (!$status) {
            return false;
        }
        
        $status = array_merge($status, $updates);
        
        rex_file::put(
            rex_path::addonCache('mediapool_tools', self::BATCH_CACHE_KEY . $batchId . '.json'),
            json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        return true;
    }
    
    public static function processNextBatchItems(string $batchId): array
    {
        $batchStatus = self::getBatchStatus($batchId);
        
        if (!$batchStatus || $batchStatus['status'] !== 'running') {
            return ['status' => 'error', 'message' => 'Batch nicht gefunden oder bereits beendet'];
        }
        
        $processQueue = $batchStatus['processQueue'] ?? [];
        
        if (empty($processQueue)) {
            self::updateBatchStatus($batchId, [
                'status' => 'completed',
                'endTime' => time(),
                'currentFiles' => []
            ]);
            return ['status' => 'completed', 'batch' => self::getBatchStatusExtended($batchId)];
        }
        
        $maxParallel = self::getMaxParallelProcesses();
        $filesToProcess = array_slice($processQueue, 0, $maxParallel);
        $remainingQueue = array_slice($processQueue, $maxParallel);
        
        $currentFiles = [];
        foreach ($filesToProcess as $index => $filename) {
            $processId = uniqid('process_', true);
            $currentFiles[$processId] = [
                'filename' => $filename,
                'startTime' => microtime(true),
                'status' => 'processing'
            ];
        }
        
        self::updateBatchStatus($batchId, [
            'processQueue' => $remainingQueue,
            'currentFiles' => $currentFiles
        ]);
        
        $results = [];
        foreach ($filesToProcess as $filename) {
            $result = self::reworkFileWithFallback(
                $filename, 
                $batchStatus['maxWidth'], 
                $batchStatus['maxHeight']
            );
            $results[] = $result;
        }
        
        $updates = [
            'processQueue' => $remainingQueue,
            'currentFiles' => [],
            'processed' => $batchStatus['processed'] + count($results)
        ];
        
        foreach ($results as $result) {
            if ($result['success']) {
                $updates['successful'] = ($batchStatus['successful'] ?? 0) + 1;
            } elseif ($result['skipped']) {
                $updates['skipped'] = array_merge(
                    $batchStatus['skipped'] ?? [], 
                    [$result['filename'] => $result['reason']]
                );
            } else {
                $updates['errors'] = array_merge(
                    $batchStatus['errors'] ?? [], 
                    [$result['filename'] => $result['error'] ?? 'Unbekannter Fehler']
                );
            }
        }
        
        self::updateBatchStatus($batchId, $updates);
        
        $finalStatus = self::getBatchStatusExtended($batchId);
        if (empty($finalStatus['processQueue'])) {
            self::updateBatchStatus($batchId, [
                'status' => 'completed',
                'endTime' => time()
            ]);
            $finalStatus = self::getBatchStatusExtended($batchId);
            return ['status' => 'completed', 'batch' => $finalStatus];
        }
        
        return [
            'status' => 'processing',
            'batch' => $finalStatus,
            'results' => $results,
            'processedInThisStep' => count($results)
        ];
    }

    public static function processNextBatchItem(string $batchId): array
    {
        return self::processNextBatchItems($batchId);
    }
    
    public static function reworkFileWithFallback(string $filename, ?int $maxWidth = null, ?int $maxHeight = null): array
    {
        try {
            $canProcess = self::canProcessImage($filename);
            
            if (!$canProcess['canProcess']) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'reason' => $canProcess['reason'],
                    'filename' => $filename
                ];
            }
            
            if ($canProcess['needsImageMagick'] && self::hasImageMagick()) {
                // Hier müsste die ImageMagick Logik aus Uploader übernommen werden
                // Da diese tief integriert ist, kopieren wir sie am besten
                // Falls sie fehlt, müssen wir sie kopieren.
                // Ich übernehme hier die Logik aus der Originaldatei und adaptiere sie
                $result = self::reworkFileWithImageMagick($filename, $maxWidth, $maxHeight);
                if ($result) {
                    return ['success' => true, 'skipped' => false, 'method' => 'ImageMagick', 'filename' => $filename];
                }
            }
            
            if (self::hasGD()) {
                // Gleiches für GD
               $result = self::reworkFile($filename, $maxWidth, $maxHeight);
                if ($result) {
                    return ['success' => true, 'skipped' => false, 'method' => 'GD/MediaManager', 'filename' => $filename];
                }
            }
            
            return [
                'success' => false,
                'skipped' => false,
                'error' => 'Keine Bildverarbeitungsbibliothek verfügbar',
                'filename' => $filename
            ];
            
        } catch (Exception $e) {
            rex_logger::logException($e);
            return [
                'success' => false,
                'skipped' => false,
                'error' => $e->getMessage(),
                'filename' => $filename
            ];
        }
    }

    // Adaptierte private Methoden für ImageMagick & GD müssen noch ergänzt werden
    // Ich importiere hier die gekürzte Version von oben und ergänze die fehlenden Methoden die im uploader addon waren
    
    public static function reworkFileWithImageMagick(string $filename, ?int $maxWidth = null, ?int $maxHeight = null): bool
    {
        if (!self::hasImageMagick()) {
            return false;
        }
        
        $media = rex_media::get($filename);
        if ($media == null || !$media->isImage()) {
            return false;
        }
        
        if (is_null($maxWidth) || is_null($maxHeight)) {
            $maxWidth = (int)rex_addon::get('mediapool_tools')->getConfig('image-max-width', 0);
            $maxHeight = (int)rex_addon::get('mediapool_tools')->getConfig('image-max-height', 0);
        }
        
        $imagePath = rex_path::media($filename);
        $imageSizes = getimagesize($imagePath);
        
        if (
            !is_array($imageSizes) ||
            $imageSizes[0] == 0 ||
            $imageSizes[1] == 0 ||
            ($maxWidth == 0 && $maxHeight == 0) ||
            (
                ($maxWidth == 0 || $imageSizes[0] <= $maxWidth) &&
                ($maxHeight == 0 || $imageSizes[1] <= $maxHeight)
            )
        ) {
            return false;
        }
        
        try {
            if (class_exists('Imagick')) {
                return self::processWithImagickExtension($filename, $maxWidth, $maxHeight, $imagePath);
            } else {
                return self::processWithImageMagickBinary($filename, $maxWidth, $maxHeight, $imagePath);
            }
        } catch (Exception $e) {
            rex_logger::logException($e);
            return false;
        }
    }

    private static function processWithImagickExtension(string $filename, int $maxWidth, int $maxHeight, string $imagePath): bool
    {
        $imagick = new \Imagick($imagePath);
        
        // Autorotation
        switch ($imagick->getImageOrientation()) {
            case \Imagick::ORIENTATION_TOPLEFT:
                break;
            case \Imagick::ORIENTATION_TOPRIGHT:
                $imagick->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $imagick->rotateImage(new \ImagickPixel(), 180);
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:
                $imagick->flopImage();
                $imagick->rotateImage(new \ImagickPixel(), 180);
                break;
            case \Imagick::ORIENTATION_LEFTTOP:
                $imagick->flopImage();
                $imagick->rotateImage(new \ImagickPixel(), -90);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $imagick->rotateImage(new \ImagickPixel(), 90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM:
                $imagick->flopImage();
                $imagick->rotateImage(new \ImagickPixel(), 90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $imagick->rotateImage(new \ImagickPixel(), -90);
                break;
        }
        $imagick->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

        // Profil entfernen für geringere Dateigröße
        $imagick->stripImage();

        // Resize
        $targetWidth = $imagick->getImageWidth();
        $targetHeight = $imagick->getImageHeight();

        if ($maxWidth > 0 && $targetWidth > $maxWidth) {
            $targetHeight = (int) ($targetHeight * ($maxWidth / $targetWidth));
            $targetWidth = $maxWidth;
        }

        if ($maxHeight > 0 && $targetHeight > $maxHeight) {
            $targetWidth = (int) ($targetWidth * ($maxHeight / $targetHeight));
            $targetHeight = $maxHeight;
        }

        $imagick->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1);
        
        $success = $imagick->writeImage($imagePath);
        $imagick->clear();
        $imagick->destroy();
        
        if ($success) {
            self::updateMediaInfo($filename);
        }
        return $success;
    }

    private static function processWithImageMagickBinary(string $filename, int $maxWidth, int $maxHeight, string $imagePath): bool
    {
        $convertPath = self::getConvertPath();
        if (!$convertPath) {
            return false;
        }

        // Auto-Orient nur einmal
        $cmd = escapeshellcmd($convertPath) . ' ' . escapeshellarg($imagePath) . ' -auto-orient ' . escapeshellarg($imagePath);
        exec($cmd);

        $resizeCmd = [];
        if ($maxWidth > 0) {
            if ($maxHeight > 0) {
                $resizeCmd[] = '-resize ' . escapeshellarg($maxWidth . 'x' . $maxHeight . '>');
            } else {
                $resizeCmd[] = '-resize ' . escapeshellarg($maxWidth . 'x>');
            }
        } elseif ($maxHeight > 0) {
            $resizeCmd[] = '-resize ' . escapeshellarg('x' . $maxHeight . '>');
        }

        if (!empty($resizeCmd)) {
            $cmd = escapeshellcmd($convertPath) . ' ' . escapeshellarg($imagePath) . ' ' . implode(' ', $resizeCmd) . ' ' . escapeshellarg($imagePath);
            exec($cmd, $out, $ret);
            
            if ($ret === 0) {
                self::updateMediaInfo($filename);
                return true;
            }
        }
        
        return false;
    }

    public static function reworkFile(string $filename, ?int $maxWidth = null, ?int $maxHeight = null): bool
    {
        // Verwendung von rex_media_manager (GD)
        $media = rex_media::get($filename);
        if ($media == null || !$media->isImage()) {
            return false;
        }
        
        if (is_null($maxWidth) || is_null($maxHeight)) {
            $maxWidth = (int)rex_addon::get('mediapool_tools')->getConfig('image-max-width', 0);
            $maxHeight = (int)rex_addon::get('mediapool_tools')->getConfig('image-max-height', 0);
        }
        
        $imagePath = rex_path::media($filename);
        $imageSizes = getimagesize($imagePath);
        
        if (
            !is_array($imageSizes) ||
            $imageSizes[0] == 0 ||
            $imageSizes[1] == 0 ||
            ($maxWidth == 0 && $maxHeight == 0) ||
            (
                ($maxWidth == 0 || $imageSizes[0] <= $maxWidth) &&
                ($maxHeight == 0 || $imageSizes[1] <= $maxHeight)
            )
        ) {
            return false;
        }

        // Wir nutzen rex_media_manager Effekte direkt auf der Originaldatei
        // Das ist etwas tricky, da rex_media_manager normalerweise Cachedateien erstellt.
        // Wir wollen aber die Originaldatei überschreiben.
        
        $image = imagecreatefromstring(rex_file::get($imagePath));
        if (!$image) {
            return false;
        }

        $currentWidth = imagesx($image);
        $currentHeight = imagesy($image);
        
        $newWidth = $currentWidth;
        $newHeight = $currentHeight;
        
        // Berechne neue Dimensionen
        if ($maxWidth > 0 && $newWidth > $maxWidth) {
            $newHeight = (int) ($newHeight * ($maxWidth / $newWidth));
            $newWidth = $maxWidth;
        }

        if ($maxHeight > 0 && $newHeight > $maxHeight) {
            $newWidth = (int) ($newWidth * ($maxHeight / $newHeight));
            $newHeight = $maxHeight;
        }
        
        if ($newWidth !== $currentWidth || $newHeight !== $currentHeight) {
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Transparenz erhalten
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $currentWidth, $currentHeight);
            
            // Speichern
            $extension = strtolower(rex_file::extension($filename));
            $success = false;
            
            switch($extension) {
                case 'jpg':
                case 'jpeg':
                    $success = imagejpeg($newImage, $imagePath, 85);
                    break;
                case 'png':
                    $success = imagepng($newImage, $imagePath);
                    break;
                case 'gif':
                    $success = imagegif($newImage, $imagePath);
                    break;
                case 'webp':
                    if (function_exists('imagewebp')) {
                        $success = imagewebp($newImage, $imagePath);
                    }
                    break;
            }
            
            imagedestroy($image);
            imagedestroy($newImage);
            
            if ($success) {
                self::updateMediaInfo($filename);
                return true;
            }
        }
        
        return false;
    }

    private static function updateMediaInfo(string $filename): void
    {
        // Cache löschen
        rex_media_cache::delete($filename);
        
        // Media-Objekt holen um DB Infos zu aktualisieren
        $media = rex_media::get($filename);
        if ($media) {
            $sql = rex_sql::factory();
            $sql->setTable(rex::getTable('media'));
            $sql->setWhere(['filename' => $filename]);
            
            $imagePath = rex_path::media($filename);
            
            if (file_exists($imagePath)) {
                $filesize = filesize($imagePath);
                $size = getimagesize($imagePath);
                
                if (false !== $filesize) {
                    $sql->setValue('filesize', $filesize);
                }
                if ($size) {
                    $sql->setValue('width', $size[0]);
                    $sql->setValue('height', $size[1]);
                }
                $sql->update();
            }
        }
    }

    
    // Hilfsfunktion für Farben in der Liste
    public static function lightenColor(string $hex, int $percent): string
    {
        // ... (vereinfachte Implementierung für CSS Farbmanipulation wenn nötig)
        return '#'.$hex; // Placeholder
    }
    
    // Bereinige alte Batches
    public static function cleanupOldBatches(): void
    {
        $dir = rex_path::addonCache('mediapool_tools');
        if (!is_dir($dir)) return;
        
        $files = glob($dir . self::BATCH_CACHE_KEY . '*.json');
        if (false === $files || empty($files)) return;
        
        $now = time();
        $ttl = 3600; // 1 Stunde
        
        foreach ($files as $file) {
            if ($now - filemtime($file) > $ttl) {
                rex_file::delete($file);
            }
        }
    }
}

