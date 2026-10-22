<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use RuntimeException;

/**
 * Append-only writer for the convex feature matrix (one row per
 * (ticker, tenor, snapshot)). Writes the header once on file creation, then
 * appends rows in the sealed FEATURE_COLUMNS order. The reserved outcome
 * columns are emitted as empty cells — the schema-valid null placeholder
 * (convex_feature_schema treats empty as null; pandas reads an empty cell as
 * NaN on the later outcome-feedback join). This never writes a literal "nan"
 * token: that would fail the frozen value contract (e.g. o_entry_fill_px
 * requires >0), and the schema is a sealed library.
 *
 * Column order comes from the emitter itself (via GreeksRunClient), never a
 * hand-maintained copy — so a sealed-primitive column change can't silently
 * desync this writer from the matrix.
 */
final class FeatureMatrixWriter
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Append one feature row. Header is written iff the file is new/empty.
     * The whole write is taken under an exclusive lock so a looped sweep and a
     * concurrent reader never interleave a half-written line.
     *
     * @param  list<string>  $columns  canonical FEATURE_COLUMNS order
     * @param  array<string,mixed>  $row  column => value (missing => null)
     */
    public function append(array $columns, array $row): void
    {
        if ($columns === []) {
            throw new RuntimeException('feature matrix columns are empty — refusing to write a headerless row');
        }

        $dir = dirname($this->path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create matrix directory: {$dir}");
        }

        $handle = fopen($this->path, 'a');
        if ($handle === false) {
            throw new RuntimeException("cannot open feature matrix for append: {$this->path}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("cannot lock feature matrix: {$this->path}");
            }

            // Decide header under the lock, from the live file size — so a
            // just-created empty file gets its header exactly once.
            clearstatcache(true, $this->path);
            if ((int) (filesize($this->path) ?: 0) === 0) {
                fputcsv($handle, $columns);
            }

            fputcsv($handle, array_map(
                fn (string $col): string => self::cell($row[$col] ?? null),
                $columns,
            ));

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** Same identity as a CSV readback, including booleans and empty outcomes. */
    public static function rowHash(array $row): string
    {
        return hash('sha256', json_encode(array_map(self::cell(...), $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return ''; // reserved/nullable column → empty == schema null == pandas NaN
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
