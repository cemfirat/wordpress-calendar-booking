<?php
namespace Wpcb\Database;

/**
 * Verifies the structural contract produced by Schema::statements().
 *
 * dbDelta may report no PHP exception while individual CREATE/ALTER/INDEX
 * operations fail. Completion is therefore based on observed tables, columns
 * and indexes, never on the absence of an exception alone.
 */
final class SchemaVerifier {
    /**
     * @return array{ready:bool,issues:string[]}
     */
    public static function verify(): array {
        global $wpdb;

        $issues = [];
        foreach (self::contract() as $table => $expected) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                $issues[] = 'missing_table:' . self::shortName($table);
                continue;
            }

            $safeTable = self::quoteIdentifier($table);
            $columnRows = $wpdb->get_results("SHOW COLUMNS FROM {$safeTable}", ARRAY_A);
            if (!is_array($columnRows)) {
                $issues[] = 'columns_unreadable:' . self::shortName($table);
                continue;
            }
            $columns = [];
            foreach ($columnRows as $row) {
                if (isset($row['Field'])) {
                    $columns[(string)$row['Field']] = true;
                }
            }
            foreach ($expected['columns'] as $column) {
                if (!isset($columns[$column])) {
                    $issues[] = 'missing_column:' . self::shortName($table) . '.' . $column;
                }
            }

            $indexRows = $wpdb->get_results("SHOW INDEX FROM {$safeTable}", ARRAY_A);
            if (!is_array($indexRows)) {
                $issues[] = 'indexes_unreadable:' . self::shortName($table);
                continue;
            }
            $indexes = [];
            foreach ($indexRows as $row) {
                if (isset($row['Key_name'])) {
                    $indexes[(string)$row['Key_name']] = true;
                }
            }
            foreach ($expected['indexes'] as $index) {
                if (!isset($indexes[$index])) {
                    $issues[] = 'missing_index:' . self::shortName($table) . '.' . $index;
                }
            }
        }

        return [
            'ready' => $issues === [],
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @return array<string,array{columns:string[],indexes:string[]}>
     */
    private static function contract(): array {
        $contract = [];

        foreach (Schema::statements() as $statement) {
            if (!preg_match('/^\s*CREATE\s+TABLE\s+([^\s(]+)\s*\((.*)\)\s*[^;]*;\s*$/is', $statement, $match)) {
                continue;
            }

            $table = trim((string)$match[1], "` \t\r\n");
            $columns = [];
            $indexes = [];

            foreach (preg_split('/\r?\n/', (string)$match[2]) ?: [] as $rawLine) {
                $line = rtrim(trim($rawLine), ',');
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^PRIMARY\s+KEY\s*\(/i', $line)) {
                    $indexes[] = 'PRIMARY';
                    continue;
                }
                if (preg_match('/^(?:UNIQUE\s+)?KEY\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $line, $indexMatch)) {
                    $indexes[] = (string)$indexMatch[1];
                    continue;
                }
                if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+[A-Za-z]/', $line, $columnMatch)) {
                    $columns[] = (string)$columnMatch[1];
                }
            }

            if ($table !== '') {
                $contract[$table] = [
                    'columns' => array_values(array_unique($columns)),
                    'indexes' => array_values(array_unique($indexes)),
                ];
            }
        }

        return $contract;
    }

    private static function quoteIdentifier(string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function shortName(string $table): string {
        global $wpdb;
        $prefix = $wpdb->prefix . 'wpcb_';
        return strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table;
    }
}
