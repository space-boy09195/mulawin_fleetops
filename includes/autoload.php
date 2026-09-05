<?php
// ============================================================
// includes/autoload.php
// Lightweight PSR-4-style autoloader for future class-based
// code (e.g. reusable report builders, data models). The app
// doesn't need this yet at its current size, but registering
// it now means new classes just work by naming convention,
// with no per-file require_once list to maintain later.
//
// Usage:
//   1. Put a class in classes/, namespaced App\<SubNamespace>.
//      e.g. classes/Reports/PayrollSummary.php declares
//      `namespace App\Reports; class PayrollSummary { ... }`
//   2. require_once __DIR__ . '/../includes/autoload.php';
//      anywhere before you first reference the class.
//   3. `new \App\Reports\PayrollSummary();` — no require_once
//      needed for that specific file.
// ============================================================

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../classes/';

    // Only handle classes under the App\ namespace.
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});