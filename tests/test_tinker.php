<?php

use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;

// Try to get 1 employee just to see if syntax works.
try {
    $employee = Employee::with('party')->first();
    if ($employee) {
        // Test our new syntax
        $userId = $employee->party?->users()->first()?->id;
        echo "Found employee ID {$employee->id}, User ID: " . ($userId ?: 'NULL') . "\n";
    } else {
        echo "No employee found.\n";
    }

    $request = EmployeeRequest::with('party')->first();
    if ($request) {
        $userId = $request->party?->users()->first()?->id;
        echo "Found employee request ID {$request->id}, User ID: " . ($userId ?: 'NULL') . "\n";
    } else {
        echo "No request found.\n";
    }

    echo "Syntax checks passed.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
