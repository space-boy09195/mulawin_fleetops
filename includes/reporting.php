<?php

function reportDateRange(array $input, string $fromKey = 'from', string $toKey = 'to'): array {
    $from = trim((string)($input[$fromKey] ?? ''));
    $to = trim((string)($input[$toKey] ?? ''));

    foreach ([$from, $to] as $date) {
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Report dates must use YYYY-MM-DD format.');
        }
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Report contains an invalid calendar date.');
            }
        }
    }
    if ($from !== '' && $to !== '' && $from > $to) {
        throw new InvalidArgumentException('The report start date must not be after the end date.');
    }

    return ['from' => $from !== '' ? $from : null, 'to' => $to !== '' ? $to : null];
}

function streamCsvReport(string $filename, array $headers, iterable $rows): never {
    if (headers_sent()) {
        throw new RuntimeException('CSV response headers have already been sent.');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Could not open the CSV response stream.');
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        $safeRow = array_map(
            static function (mixed $value): mixed {
                if (is_string($value) && preg_match('/^[\s]*[=+\-@]/', $value)) {
                    return "'" . $value;
                }
                return $value;
            },
            array_values((array)$row)
        );
        fputcsv($output, $safeRow);
    }
    fclose($output);
    exit;
}
