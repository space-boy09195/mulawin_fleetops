<?php

use PHPUnit\Framework\TestCase;

final class EmployeeValidationTest extends TestCase
{
    /**
     * Every key validateEmpFields() reads, defaulted to empty/null so a
     * test only needs to override the fields it cares about.
     */
    private function baseFields(array $overrides = []): array
    {
        return array_merge([
            'employee_code'  => 'EMP-001',
            'full_name'      => 'Juan Dela Cruz',
            'position'       => 'Dispatcher',
            'contact_number' => '09171234567',
            'address'        => null,
            'license_number' => null,
            'license_expiry' => null,
            'license_type'   => null,
            'date_hired'     => null,
        ], $overrides);
    }

    public function testValidNonDriverEmployeePasses(): void
    {
        $this->assertNull(validateEmpFields($this->baseFields()));
    }

    public function testMissingEmployeeCode(): void
    {
        $f = $this->baseFields(['employee_code' => '']);
        $this->assertSame('Employee code is required.', validateEmpFields($f));
    }

    public function testMissingFullName(): void
    {
        $f = $this->baseFields(['full_name' => '']);
        $this->assertSame('Full name is required.', validateEmpFields($f));
    }

    public function testMissingPosition(): void
    {
        $f = $this->baseFields(['position' => '']);
        $this->assertSame('Position is required.', validateEmpFields($f));
    }

    public function testLicenseExpiryWithoutNumberFails(): void
    {
        $f = $this->baseFields(['license_expiry' => '2027-01-01']);
        $this->assertSame('License number is required with expiry.', validateEmpFields($f));
    }

    public function testLicenseNumberWithoutExpiryFails(): void
    {
        $f = $this->baseFields(['license_number' => 'N01-12-123456']);
        $this->assertSame('License expiry is required with license number.', validateEmpFields($f));
    }

    public function testDriverMissingLicenseNumberFails(): void
    {
        $f = $this->baseFields(['position' => 'Driver']);
        $this->assertSame('License number is required for drivers.', validateEmpFields($f));
    }

    public function testDriverMissingLicenseTypeFails(): void
    {
        $f = $this->baseFields([
            'position'       => 'Driver',
            'license_number' => 'N01-12-123456',
            'license_expiry' => date('Y-m-d', strtotime('+1 year')),
        ]);
        $this->assertSame('License type is required for drivers.', validateEmpFields($f));
    }

    public function testDriverMissingDateHiredFails(): void
    {
        $f = $this->baseFields([
            'position'       => 'Driver',
            'license_number' => 'N01-12-123456',
            'license_expiry' => date('Y-m-d', strtotime('+1 year')),
            'license_type'   => 'Non-Professional',
        ]);
        $this->assertSame('Date hired is required for drivers.', validateEmpFields($f));
    }

    public function testDriverPositionCheckIsCaseInsensitive(): void
    {
        // strcasecmp() in the source means "driver", "DRIVER", "Driver" all
        // trigger the driver-specific requirements the same way.
        $f = $this->baseFields(['position' => 'DRIVER']);
        $this->assertSame('License number is required for drivers.', validateEmpFields($f));
    }

    public function testInvalidLtoLicenseFormatFails(): void
    {
        $f = $this->baseFields([
            'license_number' => '12345',
            'license_expiry' => date('Y-m-d', strtotime('+1 year')),
        ]);
        $this->assertSame(
            'License number must be in the format X00-00-000000 (e.g. N01-12-123456).',
            validateEmpFields($f)
        );
    }

    public function testInvalidLicenseExpiryFormatFails(): void
    {
        $f = $this->baseFields([
            'license_number' => 'N01-12-123456',
            'license_expiry' => '01/01/2027',
        ]);
        $this->assertSame('Invalid license expiry date.', validateEmpFields($f));
    }

    public function testInvalidDateHiredFails(): void
    {
        $f = $this->baseFields(['date_hired' => '2026-13-40']);
        $this->assertSame('Invalid date hired.', validateEmpFields($f));
    }

    public function testFutureDateHiredFails(): void
    {
        $f = $this->baseFields(['date_hired' => date('Y-m-d', strtotime('+1 day'))]);
        $this->assertSame('Hire date cannot be in the future.', validateEmpFields($f));
    }

    public function testPassedLicenseExpiryRejectedByDefault(): void
    {
        $f = $this->baseFields([
            'license_number' => 'N01-12-123456',
            'license_expiry' => date('Y-m-d', strtotime('-1 day')),
        ]);
        $this->assertSame(
            'New employees cannot use a passed license expiry date.',
            validateEmpFields($f, false)
        );
    }

    public function testPassedLicenseExpiryAllowedWhenFlagSet(): void
    {
        // The batch CSV import (users_handler.php) passes true here, since
        // importing existing staff records shouldn't require every license
        // to still be unexpired.
        $f = $this->baseFields([
            'license_number' => 'N01-12-123456',
            'license_expiry' => date('Y-m-d', strtotime('-1 day')),
        ]);
        $this->assertNull(validateEmpFields($f, true));
    }

    public function testFullyValidDriverPasses(): void
    {
        $f = $this->baseFields([
            'position'       => 'Driver',
            'license_number' => 'N01-12-123456',
            'license_expiry' => date('Y-m-d', strtotime('+1 year')),
            'license_type'   => 'Non-Professional',
            'date_hired'     => date('Y-m-d', strtotime('-30 days')),
        ]);
        $this->assertNull(validateEmpFields($f));
    }
}
