<?php

namespace FriendsOfRedaxo\MediapoolTools;

use rex_addon;
use rex_formatter;

/**
 * HTML der Ergebnisliste „Defekte Dateien“: Zusammenfassung je Art, Tabelle mit Aktionen, verwaiste Dateien als Hinweisliste.
 */
class BrokenMediaList
{
    private const TYPES = ['wrong_extension', 'corrupt', 'empty', 'missing', 'metadata'];

    /** Höchstzahl der Zeilen in der Tabelle (große Medienpools, z. B. lokale Kopie ohne Dateien) */
    private const MAX_ROWS = 300;

    /**
     * @param array<string, mixed> $data Ergebnis aus BrokenMediaAnalysis::getStatus()
     */
    public static function render(array $data): string
    {
        $addon = rex_addon::get('mediapool_tools');
        $e = static fn ($v): string => rex_escape((string) $v);
        $problems = $data['problems'] ?? [];
        $orphans = $data['orphans'] ?? [];

        $counts = array_fill_keys(self::TYPES, 0);
        foreach ($problems as $item) {
            foreach ($item['problems'] as $problem) {
                ++$counts[$problem['type']];
            }
        }

        if ([] === $problems && [] === $orphans) {
            return '<div class="alert alert-success">' . $addon->i18n('broken_media_none', (string) ($data['total'] ?? 0)) . '</div>';
        }

        $html = '<div class="alert alert-warning"><strong>' . $addon->i18n('broken_media_summary_title') . '</strong><br>'
            . $addon->i18n('broken_media_summary_text', (string) count($problems), (string) ($data['total'] ?? 0)) . '<br>';
        $badges = [];
        foreach ($counts as $type => $count) {
            if ($count > 0) {
                $badges[] = '<span class="label label-' . self::level($type) . '">' . $count . ' × ' . $addon->i18n('broken_media_type_' . $type) . '</span>';
            }
        }
        $html .= implode(' ', $badges) . '</div>';
        $html .= '<p class="help-block">' . $addon->i18n('broken_media_actions_hint') . '</p>';

        // Schwerste Befunde zuerst (Reihenfolge wie TYPES), dann nach Dateiname
        $rank = array_flip(self::TYPES);
        usort($problems, static function (array $a, array $b) use ($rank): int {
            $ra = min(array_map(static fn (array $p): int => $rank[$p['type']], $a['problems']));
            $rb = min(array_map(static fn (array $p): int => $rank[$p['type']], $b['problems']));

            return $ra <=> $rb ?: strcmp($a['filename'], $b['filename']);
        });
        $hidden = max(0, count($problems) - self::MAX_ROWS);
        $problems = array_slice($problems, 0, self::MAX_ROWS);

        if ([] !== $problems) {
            $html .= '<table class="table table-striped table-hover mediapool-tools-broken"><thead><tr>'
                . '<th>' . $addon->i18n('broken_media_col_file') . '</th>'
                . '<th>' . $addon->i18n('broken_media_col_problem') . '</th>'
                . '<th class="text-right">' . $addon->i18n('broken_media_col_action') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($problems as $item) {
                $file = (string) $item['filename'];
                $lines = [];
                $buttons = [];
                foreach ($item['problems'] as $problem) {
                    $type = $problem['type'];
                    $text = '<span class="label label-' . self::level($type) . '">' . $addon->i18n('broken_media_type_' . $type) . '</span> ';
                    switch ($type) {
                        case 'wrong_extension':
                            $text .= $addon->i18n('broken_media_detail_wrong_extension', $problem['mime'], $problem['suggest']);
                            $buttons[] = self::button('rename', $file, $addon->i18n('broken_media_btn_rename', $problem['suggest']), 'btn-primary', ['extension' => $problem['suggest']]);
                            break;
                        case 'corrupt':
                            $text .= $addon->i18n('broken_media_detail_' . $problem['detail']);
                            break;
                        case 'metadata':
                            $parts = [];
                            foreach ($problem['diff'] as $field => [$old, $new]) {
                                $parts[] = $addon->i18n('broken_media_field_' . $field) . ': ' . $e(self::show($field, $old)) . ' → ' . $e(self::show($field, $new));
                            }
                            $text .= implode(', ', $parts);
                            $buttons[] = self::button('fix_meta', $file, $addon->i18n('broken_media_btn_fix_meta'), 'btn-default');
                            break;
                        default:
                            $text .= $addon->i18n('broken_media_detail_' . $type);
                    }
                    $lines[] = $text;
                }
                $types = array_column($item['problems'], 'type');
                if (array_intersect($types, ['missing', 'empty', 'wrong_extension', 'corrupt'])) {
                    $buttons[] = self::button('delete', $file, $addon->i18n('broken_media_btn_delete'), 'btn-delete');
                }
                $html .= '<tr data-file="' . $e($file) . '">'
                    . '<td><a href="index.php?page=mediapool/media&amp;file_name=' . $e(urlencode($file)) . '" target="_blank"><b>' . $e($file) . '</b></a>'
                    . ('' !== $item['title'] ? '<br><small class="text-muted">' . $e($item['title']) . '</small>' : '') . '</td>'
                    . '<td>' . implode('<br>', $lines) . '</td>'
                    . '<td class="text-right" style="white-space:nowrap">' . implode(' ', $buttons) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
            if ($hidden > 0) {
                $html .= '<p class="alert alert-info">' . $addon->i18n('broken_media_more_rows', (string) $hidden) . '</p>';
            }
        }

        if ([] !== $orphans) {
            $html .= '<details class="mediapool-tools-orphans"><summary><strong>' . $addon->i18n('broken_media_orphans_title', (string) count($orphans)) . '</strong></summary>'
                . '<p class="help-block">' . $addon->i18n('broken_media_orphans_hint') . '</p><ul>';
            foreach ($orphans as $orphan) {
                $html .= '<li><code>' . $e($orphan['filename']) . '</code> <small class="text-muted">(' . $e(rex_formatter::bytes((int) $orphan['size'])) . ')</small></li>';
            }
            $html .= '</ul></details>';
        }

        return $html;
    }

    /**
     * @param array<string, string> $data
     */
    private static function button(string $action, string $file, string $label, string $class, array $data = []): string
    {
        $attributes = '';
        foreach ($data as $key => $value) {
            $attributes .= ' data-' . $key . '="' . rex_escape($value) . '"';
        }

        return '<button type="button" class="btn btn-xs ' . $class . '" data-broken-action="' . $action . '" data-file="' . rex_escape($file) . '"' . $attributes . '>' . $label . '</button>';
    }

    private static function level(string $type): string
    {
        return ['missing' => 'danger', 'empty' => 'danger', 'corrupt' => 'danger', 'wrong_extension' => 'warning'][$type] ?? 'info';
    }

    /**
     * @param int|string $value
     */
    private static function show(string $field, $value): string
    {
        return 'filesize' === $field ? rex_formatter::bytes((int) $value) : (string) $value;
    }
}
