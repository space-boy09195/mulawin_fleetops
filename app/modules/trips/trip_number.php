<?php

// Allocates the next TRP-YYYY-NNNN number from trip_number_counters. Must run
// inside the caller's transaction, on the connection that inserts the trip:
// the upsert takes an exclusive lock on the year's counter row and holds it
// until that transaction commits or rolls back, so concurrent approvals are
// serialized on this row and a rolled-back trip never consumes a number.
function nextTripNumber(PDO $pdo): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('nextTripNumber() must be called inside the trip-creation transaction.');
    }
    $year = (int)date('Y');

    $allocate = $pdo->prepare(
        'INSERT INTO trip_number_counters (`year`, next_number)
         VALUES (?, 2)
         ON DUPLICATE KEY UPDATE next_number = next_number + 1'
    );
    $read = $pdo->prepare('SELECT next_number FROM trip_number_counters WHERE `year` = ? FOR UPDATE');
    $taken = $pdo->prepare('SELECT 1 FROM trips WHERE trip_number = ? LIMIT 1');

    // A counter that lags behind existing trips (e.g. rows added outside this
    // function) skips numbers already used instead of failing on the unique
    // trip_number. Still serialized: the counter row stays locked throughout.
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        $allocate->execute([$year]);
        $read->execute([$year]);
        $nextNumber = (int)$read->fetchColumn();
        if ($nextNumber < 2) {
            throw new RuntimeException('Trip number counter returned an invalid value.');
        }
        $tripNumber = sprintf('TRP-%d-%04d', $year, $nextNumber - 1);
        $taken->execute([$tripNumber]);
        if (!$taken->fetchColumn()) {
            return $tripNumber;
        }
    }
    throw new RuntimeException('Trip number counter is far behind existing trips; it needs to be resynchronized.');
}
