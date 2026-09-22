<?php

use PHPUnit\Framework\TestCase;

final class DuplicateEmployeeExistsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE employees (
                employee_id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                contact_number TEXT NOT NULL
            )
        ');
        $this->pdo->exec("INSERT INTO employees (full_name, contact_number) VALUES ('Juan Dela Cruz', '09171234567')");
    }

    public function testReturnsFalseWhenNoMatch(): void
    {
        $f = ['full_name' => 'Maria Santos', 'contact_number' => '09179999999'];
        $this->assertFalse(duplicateEmployeeExists($this->pdo, $f));
    }

    public function testReturnsTrueWhenNameAndContactBothMatch(): void
    {
        $f = ['full_name' => 'Juan Dela Cruz', 'contact_number' => '09171234567'];
        $this->assertTrue(duplicateEmployeeExists($this->pdo, $f));
    }

    public function testReturnsFalseWhenOnlyNameMatches(): void
    {
        // Same person could share a name with someone else; only a matching
        // full_name + contact_number pair counts as a duplicate.
        $f = ['full_name' => 'Juan Dela Cruz', 'contact_number' => '09170000000'];
        $this->assertFalse(duplicateEmployeeExists($this->pdo, $f));
    }

    public function testWhitespaceIsNormalizedBeforeComparing(): void
    {
        // The function collapses repeated whitespace and trims before
        // querying, so "Juan  Dela   Cruz" (typo'd extra spaces) still
        // matches the stored "Juan Dela Cruz".
        $f = ['full_name' => '  Juan  Dela   Cruz ', 'contact_number' => ' 09171234567 '];
        $this->assertTrue(duplicateEmployeeExists($this->pdo, $f));
    }

    public function testSelfIdExcludesOwnRecordWhenEditing(): void
    {
        $f = ['full_name' => 'Juan Dela Cruz', 'contact_number' => '09171234567'];
        // Editing that same employee (employee_id 1) shouldn't flag itself
        // as a duplicate of itself.
        $this->assertFalse(duplicateEmployeeExists($this->pdo, $f, 1));
    }

    public function testEmptyNameOrContactNeverCountsAsDuplicate(): void
    {
        $this->assertFalse(duplicateEmployeeExists($this->pdo, ['full_name' => '', 'contact_number' => '']));
        $this->assertFalse(duplicateEmployeeExists($this->pdo, ['full_name' => 'Someone', 'contact_number' => '']));
    }
}
