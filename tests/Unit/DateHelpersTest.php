<?php

use PHPUnit\Framework\TestCase;

final class DateHelpersTest extends TestCase
{
    public function testValidDateAcceptsCorrectFormat(): void
    {
        $this->assertTrue(isValidDate('2026-01-15'));
    }

    public function testValidDateRejectsWrongFormat(): void
    {
        $this->assertFalse(isValidDate('01/15/2026'));
        $this->assertFalse(isValidDate('2026-1-15'));
    }

    public function testValidDateRejectsImpossibleCalendarDate(): void
    {
        // DateTime::createFromFormat with '!' resets to the Unix epoch and
        // does not auto-roll invalid dates like "2026-13-40" into a valid one.
        $this->assertFalse(isValidDate('2026-13-40'));
        $this->assertFalse(isValidDate('2026-02-30'));
    }

    public function testPassedDateTrueForYesterday(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->assertTrue(isPassedDate($yesterday));
    }

    public function testPassedDateFalseForToday(): void
    {
        // Comparison is strictly "<", so today itself is not "passed".
        $this->assertFalse(isPassedDate(date('Y-m-d')));
    }

    public function testPassedDateFalseForFutureDate(): void
    {
        $nextYear = date('Y-m-d', strtotime('+1 year'));
        $this->assertFalse(isPassedDate($nextYear));
    }

    public function testPassedDateFalseForInvalidDate(): void
    {
        $this->assertFalse(isPassedDate('not-a-date'));
    }
}
