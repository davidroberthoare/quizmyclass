<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $migrationDirectory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function migrate(): array
    {
        if (!is_dir($this->migrationDirectory)) {
            throw new RuntimeException('Migration directory does not exist.');
        }

        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS qmc_migrations (' .
            'version TEXT PRIMARY KEY, ' .
            'checksum TEXT NOT NULL, ' .
            'applied_at TEXT NOT NULL' .
            ')'
        );

        $files = glob($this->migrationDirectory . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Unable to enumerate migrations.');
        }
        sort($files, SORT_STRING);

        $applied = [];
        $statement = $this->database->prepare(
            'SELECT checksum FROM qmc_migrations WHERE version = :version'
        );

        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if ($version === '' || !preg_match('/^[0-9]+_[a-z0-9_]+$/', $version)) {
                throw new RuntimeException('Invalid migration filename: ' . basename($file));
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Migration cannot be read: ' . basename($file));
            }
            $checksum = hash('sha256', $sql);

            $statement->execute(['version' => $version]);
            $knownChecksum = $statement->fetchColumn();
            if ($knownChecksum !== false) {
                if (!hash_equals((string) $knownChecksum, $checksum)) {
                    throw new RuntimeException('Applied migration has been modified: ' . basename($file));
                }
                continue;
            }

            $this->database->beginTransaction();
            try {
                $this->database->exec($sql);
                $insert = $this->database->prepare(
                    'INSERT INTO qmc_migrations (version, checksum, applied_at) ' .
                    'VALUES (:version, :checksum, :applied_at)'
                );
                $insert->execute([
                    'version' => $version,
                    'checksum' => $checksum,
                    'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $this->database->commit();
            } catch (\Throwable $exception) {
                if ($this->database->inTransaction()) {
                    $this->database->rollBack();
                }
                throw $exception;
            }

            $applied[] = $version;
        }

        return $applied;
    }

    public function appliedCount(): int
    {
        $tableExists = (int) $this->database->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'qmc_migrations'"
        )->fetchColumn();

        if ($tableExists === 0) {
            return 0;
        }

        return (int) $this->database->query('SELECT COUNT(*) FROM qmc_migrations')->fetchColumn();
    }
}

