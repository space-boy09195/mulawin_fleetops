<?php

require_once __DIR__ . '/../documents/document_storage.php';

function backupRootDirectory(): string {
    $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Backup directory could not be created.');
    }
    $guard = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($guard)) {
        file_put_contents($guard, "Require all denied\n");
    }
    return $root;
}

function isValidBackupName(string $name): bool {
    return preg_match('/^\d{8}_\d{6}$/', $name) === 1;
}

function backupWriteDatabaseDump(PDO $pdo, string $file): void {
    $out = fopen($file, 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not write the database dump.');
    }
    fwrite($out, "-- Mulawin FleetOps database backup " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

    $tables = [];
    $views  = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        if (strtoupper($row[1]) === 'VIEW') {
            $views[] = $row[0];
        } else {
            $tables[] = $row[0];
        }
    }

    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_NUM);
        fwrite($out, "DROP TABLE IF EXISTS $quoted;\n" . $create[1] . ";\n\n");

        $stmt = $pdo->query("SELECT * FROM $quoted");
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $values = array_map(
                static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                $row
            );
            $batch[] = '(' . implode(',', $values) . ')';
            if (count($batch) >= 200) {
                fwrite($out, "INSERT INTO $quoted VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($out, "INSERT INTO $quoted VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        fwrite($out, "\n");
    }

    foreach ($views as $view) {
        $quoted = '`' . str_replace('`', '``', $view) . '`';
        $create = $pdo->query("SHOW CREATE VIEW $quoted")->fetch(PDO::FETCH_NUM);
        fwrite($out, "DROP VIEW IF EXISTS $quoted;\n" . $create[1] . ";\n\n");
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($out);
}

function backupCopyDirectory(string $source, string $target): int {
    if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
        throw new RuntimeException('Could not create the documents backup folder.');
    }
    $count = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $dest = $target . DIRECTORY_SEPARATOR . $items->getSubPathname();
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0750, true);
            }
        } elseif (copy($item->getPathname(), $dest)) {
            $count++;
        }
    }
    return $count;
}

function createSystemBackup(PDO $pdo): array {
    $name = date('Ymd_His');
    $dir  = backupRootDirectory() . DIRECTORY_SEPARATOR . $name;
    if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the backup folder.');
    }

    backupWriteDatabaseDump($pdo, $dir . DIRECTORY_SEPARATOR . 'database.sql');

    $documents = 0;
    $storage = documentStorageDirectory();
    if (strpos(realpath($dir) . DIRECTORY_SEPARATOR, $storage . DIRECTORY_SEPARATOR) !== 0) {
        $documents = backupCopyDirectory($storage, $dir . DIRECTORY_SEPARATOR . 'documents');
    }

    return ['name' => $name, 'documents' => $documents, 'db_bytes' => filesize($dir . DIRECTORY_SEPARATOR . 'database.sql')];
}

function listSystemBackups(): array {
    $list = [];
    foreach (glob(backupRootDirectory() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        $sql  = $dir . DIRECTORY_SEPARATOR . 'database.sql';
        if (isValidBackupName($name) && is_file($sql)) {
            $list[] = [
                'name'     => $name,
                'created'  => DateTime::createFromFormat('Ymd_His', $name)->format('Y-m-d H:i:s'),
                'db_bytes' => filesize($sql),
            ];
        }
    }
    usort($list, static fn($a, $b) => strcmp($b['name'], $a['name']));
    return $list;
}
