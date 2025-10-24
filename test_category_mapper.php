<?php
/**
 * CLI Test Script: CategoryMapper.php
 * 
 * Safely verifies mapping combinations for Alto → OS Property categories.
 * 
 * Usage:
 *   php test_category_mapper.php
 */

require_once __DIR__ . '/Logger.php';             // So we can see logs
require_once __DIR__ . '/Mapper/CategoryMapper.php';

use AltoSync\Mapper\CategoryMapper;

// ------------------------------------------------------------
// TEST MATRIX – all valid Alto combinations + a few edge cases
// ------------------------------------------------------------
$tests = [
    ['For Sale', 'Residential', 5],
    ['For Sale', 'Commercial', 7],
    ['To Let', 'Residential', 6],
    ['To Let', 'Commercial', 8],
    ['SALE', 'Res', 5],                // Case + shorthand test
    ['lettings', 'comm', 8],           // Synonym test
    ['rental', 'commercials', 8],      // Another synonym test
    ['unknown', 'residential', null],  // Invalid market
    [null, 'residential', null],       // Missing market
    ['For Sale', null, null],          // Missing category
];

// ------------------------------------------------------------
// RUN TESTS
// ------------------------------------------------------------
echo "---------------------------------------------\n";
echo "CategoryMapper CLI Test\n";
echo "---------------------------------------------\n";

$passCount = 0;
$failCount = 0;

foreach ($tests as $row) {
    [$market, $category, $expected] = $row;
    $result = CategoryMapper::toOsCategoryId($market, $category);

    $status = ($result === $expected) ? "✅ PASS" : "❌ FAIL";
    if ($status === "✅ PASS") $passCount++; else $failCount++;

    printf(
        "%-25s | %-15s => %-5s (expected %-5s) %s\n",
        $market ?? 'NULL',
        $category ?? 'NULL',
        var_export($result, true),
        var_export($expected, true),
        $status
    );
}

echo "---------------------------------------------\n";
echo "Tests complete. Passed: {$passCount}, Failed: {$failCount}\n";
echo "---------------------------------------------\n";
