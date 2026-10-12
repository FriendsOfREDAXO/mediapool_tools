<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex;
use rex_file;
use rex_media_cache;
use rex_path;
use rex_sql;

/**
 * Findet defekte und falsch benannte Dateien im Medienpool:
 *  - missing          Eintrag im Medienpool, Datei fehlt auf dem Server
 *  - empty            Datei ist leer (0 Byte)
 *  - wrong_extension  Inhalt passt nicht zur Endung (z. B. PDF als .jpg) – mit Vorschlag für die richtige Endung
 *  - corrupt          Bild nicht lesbar oder abgeschnitten (JPEG ohne Endmarke, PNG ohne IEND)
 *  - metadata         Größe, Typ oder Abmessungen im Medienpool weichen von der Datei ab
 *  - orphan           Datei im Medienordner ohne Eintrag im Medienpool (nur Hinweis)
 *
 * Läuft wie die Duplikat-Suche in Etappen (Zwischenstand im AddOn-Cache), damit große Medienpools nicht ins Zeitlimit laufen.
 */
class BrokenMediaAnalysis
{
    public const CACHE_KEY = 'mediapool_tools_broken_';

    /** Höchstdauer je Etappe in Sekunden */
    private const BATCH_SECONDS = 2.0;

    /** Endung => passende MIME-Typen laut finfo. Nur diese Endungen werden auf falsche Endung geprüft. */
    public const EXTENSION_MIME = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'jpe' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
        'heic' => ['image/heic', 'image/heif'],
        'svg' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'],
        'pdf' => ['application/pdf'],
        'mp4' => ['video/mp4'],
        'm4v' => ['video/mp4', 'video/x-m4v'],
        'webm' => ['video/webm'],
        'mov' => ['video/quicktime'],
        'mp3' => ['audio/mpeg'],
        'zip' => ['application/zip'],
    ];

    /** Sicher erkannter MIME-Typ => richtige Endung (nur dann gilt eine Endung als falsch) */
    public const MIME_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/tiff' => 'tif',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3',
    ];

    /** Rasterformate, deren Lesbarkeit per getimagesize() geprüft wird */
    private const RASTER = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif'];

    /** Dateien im Medienordner, die nie als „verwaist“ gelten */
    private const ORPHAN_IGNORE = ['.htaccess', 'index.html', 'index.php', 'web.config', 'robots.txt'];

    public static function startAnalysis(): string
    {
        $batchId = uniqid('broken_', true);
        $rows = rex_sql::factory()->getArray('SELECT filename, filesize, filetype, width, height, category_id, title FROM ' . rex::getTable('media') . ' ORDER BY filename');

        $known = [];
        foreach ($rows as $row) {
            $known[(string) $row['filename']] = true;
        }
        $orphans = [];
        foreach (glob(rex_path::media('*'), GLOB_NOSORT) ?: [] as $path) {
            $name = basename($path);
            if (is_file($path) && '.' !== $name[0] && !isset($known[$name]) && !in_array(strtolower($name), self::ORPHAN_IGNORE, true)) {
                $orphans[] = ['filename' => $name, 'size' => (int) filesize($path)];
            }
        }
        usort($orphans, static fn (array $a, array $b): int => strcmp($a['filename'], $b['filename']));

        self::saveStatus($batchId, [
            'id' => $batchId,
            'status' => 'running',
            'rows' => $rows,
            'total' => count($rows),
            'offset' => 0,
            'problems' => [],
            'orphans' => $orphans,
            'start_time' => time(),
        ]);

        return $batchId;
    }

    /**
     * @return array{status: string, progress?: int, found?: int, message?: string}
     */
    public static function processBatch(string $batchId): array
    {
        $status = self::getStatus($batchId);
        if (!$status || 'running' !== $status['status']) {
            return ['status' => 'error', 'message' => 'Batch invalid'];
        }

        $started = microtime(true);
        while ($status['offset'] < $status['total'] && microtime(true) - $started < self::BATCH_SECONDS) {
            $row = $status['rows'][$status['offset']];
            ++$status['offset'];
            $problems = self::inspect((string) $row['filename'], $row);
            if ([] !== $problems) {
                $status['problems'][] = [
                    'filename' => (string) $row['filename'],
                    'title' => (string) $row['title'],
                    'category_id' => (int) $row['category_id'],
                    'problems' => $problems,
                ];
            }
        }
        if ($status['offset'] >= $status['total']) {
            $status['status'] = 'completed';
            $status['rows'] = []; // Ergebnisdatei klein halten
        }
        self::saveStatus($batchId, $status);

        return [
            'status' => $status['status'],
            'progress' => $status['total'] > 0 ? (int) round($status['offset'] / $status['total'] * 100) : 100,
            'found' => count($status['problems']),
        ];
    }

    /**
     * Prüft eine Datei des Medienpools.
     *
     * @param array<string, mixed> $row Datensatz aus rex_media (filesize, filetype, width, height)
     * @return list<array<string, mixed>>
     */
    public static function inspect(string $filename, array $row): array
    {
        $path = rex_path::media($filename);
        if (!is_file($path)) {
            return [['type' => 'missing']];
        }
        $size = (int) filesize($path);
        if (0 === $size) {
            return [['type' => 'empty']];
        }

        $problems = [];
        $extension = strtolower(rex_file::extension($filename));
        $mime = self::detectMime($path);
        $image = null;

        if (self::isWrongExtension($extension, $mime)) {
            $problems[] = ['type' => 'wrong_extension', 'mime' => $mime, 'suggest' => self::MIME_EXTENSION[$mime]];
        } elseif (in_array($extension, self::RASTER, true)) {
            $image = @getimagesize($path);
            if (false === $image) {
                $problems[] = ['type' => 'corrupt', 'detail' => 'unreadable'];
            } elseif (!self::isComplete($path, $mime, $image)) {
                $problems[] = ['type' => 'corrupt', 'detail' => 'truncated'];
            }
        }

        $diff = [];
        if ((int) ($row['filesize'] ?? 0) !== $size) {
            $diff['filesize'] = [(int) ($row['filesize'] ?? 0), $size];
        }
        if (isset(self::MIME_EXTENSION[$mime]) && (string) ($row['filetype'] ?? '') !== $mime && !self::isWrongExtension($extension, $mime)) {
            $diff['filetype'] = [(string) ($row['filetype'] ?? ''), $mime];
        }
        if (is_array($image) && ((int) ($row['width'] ?? 0) !== (int) $image[0] || (int) ($row['height'] ?? 0) !== (int) $image[1])) {
            $diff['dimensions'] = [(int) ($row['width'] ?? 0) . '×' . (int) ($row['height'] ?? 0), $image[0] . '×' . $image[1]];
        }
        if ([] !== $diff) {
            $problems[] = ['type' => 'metadata', 'diff' => $diff];
        }

        return $problems;
    }

    public static function detectMime(string $path): string
    {
        if (!class_exists(\finfo::class)) {
            return (string) (function_exists('mime_content_type') ? mime_content_type($path) : '');
        }

        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    /** Endung bekannt, Inhalt sicher erkannt – und beides passt nicht zusammen */
    public static function isWrongExtension(string $extension, string $mime): bool
    {
        return isset(self::EXTENSION_MIME[$extension], self::MIME_EXTENSION[$mime])
            && !in_array($mime, self::EXTENSION_MIME[$extension], true);
    }

    /**
     * Abgebrochene Uploads erkennen. Schnell: JPEG-Endmarke FFD9 bzw. PNG-IEND in den letzten 1024 Byte → vollständig.
     * Sonst (z. B. Handyfotos mit angehängten Daten wie Samsung „SEFT“ oder Bewegungsfoto-Video hinter der Endmarke) das Bild
     * testweise mit GD öffnen: nur wenn libjpeg/libpng ein vorzeitiges Dateiende meldet, gilt es als abgeschnitten.
     *
     * @param array{0: int, 1: int}|array<int|string, mixed> $image Ergebnis von getimagesize()
     */
    private static function isComplete(string $path, string $mime, array $image): bool
    {
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return true;
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return true;
        }
        $size = (int) filesize($path);
        fseek($handle, max(0, $size - 1024));
        $tail = (string) fread($handle, 1024);
        fclose($handle);
        if (false !== strpos($tail, 'image/jpeg' === $mime ? "\xFF\xD9" : 'IEND')) {
            return true;
        }

        // Probe-Dekodierung nur für Verdachtsfälle und nicht für riesige Bilder (Speicher)
        $open = 'image/jpeg' === $mime ? 'imagecreatefromjpeg' : 'imagecreatefrompng';
        if (!function_exists($open) || (int) $image[0] * (int) $image[1] > 40000000) {
            return true;
        }
        $warning = '';
        $ignore = ini_get('gd.jpeg_ignore_warning');
        ini_set('gd.jpeg_ignore_warning', '0'); // sonst schluckt PHP „Premature end of JPEG file“
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning .= $message . ' ';

            return true;
        });
        try {
            $gd = $open($path);
        } catch (\Throwable $e) {
            $gd = false;
            $warning .= $e->getMessage();
        } finally {
            restore_error_handler();
            ini_set('gd.jpeg_ignore_warning', false === $ignore ? '1' : $ignore);
        }
        $ok = false !== $gd;
        unset($gd);

        return $ok && !preg_match('/premature end|truncated|corrupt|not a png|incomplete/i', $warning);
    }

    /**
     * Größe, Typ und Abmessungen im Medienpool an die Datei angleichen.
     */
    public static function fixMetadata(string $filename): bool
    {
        $path = rex_path::media($filename);
        if (!is_file($path)) {
            return false;
        }
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('media'));
        $sql->setWhere(['filename' => $filename]);
        $sql->setValue('filesize', (int) filesize($path));
        $mime = self::detectMime($path);
        if (isset(self::MIME_EXTENSION[$mime])) {
            $sql->setValue('filetype', $mime);
        }
        $image = @getimagesize($path);
        if (is_array($image)) {
            $sql->setValue('width', (int) $image[0]);
            $sql->setValue('height', (int) $image[1]);
        }
        $sql->addGlobalUpdateFields();
        $sql->update();
        rex_media_cache::delete($filename);

        return true;
    }

    /**
     * Neuer, freier Dateiname mit der richtigen Endung (Datei wird noch nicht umbenannt).
     */
    public static function correctedFilename(string $filename, string $extension): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return \rex_mediapool::filename($base . '.' . $extension);
    }

    /**
     * Endung korrigieren – Schritt 1: Datei unter neuem Namen kopieren (alte bleibt, bis alle Verweise umgestellt sind).
     */
    public static function copyForRename(string $old, string $new): void
    {
        if (!is_file(rex_path::media($old)) || is_file(rex_path::media($new))) {
            throw new \rex_exception('Datei fehlt oder Zielname ist belegt: ' . $new);
        }
        if (!copy(rex_path::media($old), rex_path::media($new))) {
            throw new \rex_exception('Datei konnte nicht kopiert werden: ' . $new);
        }
    }

    /**
     * Endung korrigieren – letzter Schritt: Eintrag im Medienpool umstellen, alte Datei entfernen.
     */
    public static function finishRename(string $old, string $new): void
    {
        $path = rex_path::media($new);
        if (!is_file($path)) {
            throw new \rex_exception('Neue Datei fehlt: ' . $new);
        }
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('media'));
        $sql->setWhere(['filename' => $old]);
        $sql->setValue('filename', $new);
        $mime = self::detectMime($path);
        if ('' !== $mime) {
            $sql->setValue('filetype', $mime);
        }
        $sql->addGlobalUpdateFields();
        $sql->update();

        rex_file::delete(rex_path::media($old));
        rex_media_cache::delete($old);
        rex_media_cache::delete($new);
        rex_delete_cache();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getStatus(string $batchId): ?array
    {
        if (!preg_match('/^broken_[a-f0-9.]+$/', $batchId)) {
            return null;
        }
        $file = rex_path::addonCache('mediapool_tools', self::CACHE_KEY . $batchId . '.json');
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) rex_file::get($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function saveStatus(string $batchId, array $data): void
    {
        rex_file::put(rex_path::addonCache('mediapool_tools', self::CACHE_KEY . $batchId . '.json'), json_encode($data));
    }
}
