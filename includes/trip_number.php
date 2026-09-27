<?php

function nextTripNumber(PDO $pdo): string
{
    $year = (int)date('Y');

    $pdo->prepare(
        'INSERT INTO trip_number_counters (`year`, next_number)
         VALUES (?, 2)
         ON DUPLICATE KEY UPDATE next_number = next_number + 1'
    )->execute([$year]);

    $stmt = $pdo->prepare('SELECT next_number FROM trip_number_counters WHERE `year` = ?');
    $stmt->execute([$year]);
    $nextNumber = (int)$stmt->fetchColumn();

    return sprintf('TRP-%d-%04d', $year, $nextNumber - 1);
}
