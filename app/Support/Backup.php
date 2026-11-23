<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

final class Backup
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $backupDirectory,
    ) {
    }

    public function create(?string $filename = null): string
    {
        if (!is_dir($this->backupDirectory)
            && !mkdir($this->backupDirectory, 0700, true)
            && !is_dir($this->backupDirectory)
        ) {
            throw new RuntimeException('Unable to create the backup directory.');
        }
        if (!is_writable($this->backupDirectory)) {
            throw new RuntimeException('Backup directory is not writable.');
        }

        if ($filename === null) {
            $filename = 'quizmyclass-' . gmdate('Ymd-His') . '.sqlite3';
        }
        if (!preg_match('/^[A-Za-z0-9._-]+\.sqlite3$/', $filename)) {
            throw new RuntimeException('Backup filename is invalid.');
        }

        $path = $this->backupDirectory . '/' . $filename;
        if (file_exists($path)) {
            throw new RuntimeException('Backup already exists; refusing to overwrite it.');
        }

        $quotedPath = "'" . str_replace("'", "''", $path) . "'";
        $this->database->exec('VACUUM INTO ' . $quotedPath);

        if (!is_file($path)) {
            throw new RuntimeException('SQLite did not create the requested backup.');
        }
        chmod($path, 0600);

        return $path;
    }

    /**
     * Delete the oldest automatic backups, keeping the newest $keep.
     *
     * @return list<string> removed file names
     */
    public function prune(int $keep): array
    {
        if ($keep < 1) {
            throw new RuntimeException('Keep at least one backup.');
        }
        $files = glob($this->backupDirectory . '/quizmyclass-*.sqlite3') ?: [];
        rsort($files, SORT_STRING); // names embed a UTC timestamp, so name order is age order
        $removed = [];
        foreach (array_slice($files, $keep) as $file) {
            if (is_file($file) && unlink($file)) {
                $removed[] = basename($file);
            }
        }
        return $removed;
    }
}
