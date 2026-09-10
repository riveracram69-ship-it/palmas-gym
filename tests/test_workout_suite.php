<?php
/**
 * tests/test_workout_suite.php
 * Automated verification suite for Member Workouts & Streak Tracker API logic
 */

echo "========================================================\n";
echo " PALMA'S ELITE GYM — WORKOUT TRACKER LOGIC SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$name} - {$details}\n";
        $failCount++;
    }
}

// 1. Streak Calculation Unit Test
function calculateTestStreak(array $dates, $todayStr = null) {
    if (empty($dates)) return 0;
    $today = new DateTime($todayStr ?: 'today');
    $yesterday = (clone $today)->modify('-1 day');
    $latest = new DateTime($dates[0]);

    if ($latest < $yesterday) {
        return 0; // Streak broken if latest was before yesterday
    }

    $streak = 0;
    $currentCheck = clone $latest;
    foreach ($dates as $d) {
        $dObj = new DateTime($d);
        if ($dObj->format('Y-m-d') === $currentCheck->format('Y-m-d')) {
            $streak++;
            $currentCheck->modify('-1 day');
        } else {
            break;
        }
    }
    return $streak;
}

echo "[1/4] Testing Streak Calculation Algorithm...\n";

// Case A: 3 consecutive days including today
$datesA = ['2026-09-10', '2026-09-09', '2026-09-08'];
$streakA = calculateTestStreak($datesA, '2026-09-10');
assertTest("Consecutive 3-day active streak", $streakA === 3, "Expected 3, got {$streakA}");

// Case B: Streak ending yesterday (not yet worked out today)
$datesB = ['2026-09-09', '2026-09-08', '2026-09-07'];
$streakB = calculateTestStreak($datesB, '2026-09-10');
assertTest("Active streak extending from yesterday", $streakB === 3, "Expected 3, got {$streakB}");

// Case C: Broken streak (last workout 3 days ago)
$datesC = ['2026-09-07', '2026-09-06'];
$streakC = calculateTestStreak($datesC, '2026-09-10');
assertTest("Broken streak resets to 0", $streakC === 0, "Expected 0, got {$streakC}");

// Case D: Empty workouts
$datesD = [];
$streakD = calculateTestStreak($datesD, '2026-09-10');
assertTest("Empty history streak is 0", $streakD === 0, "Expected 0, got {$streakD}");

// 2. Goal Percentage Calculation
echo "\n[2/4] Testing Goal Percentage Logic...\n";
function calcGoalPct($completed, $target) {
    if ($target <= 0) return 0;
    return min(100, (int)round(($completed / $target) * 100));
}

assertTest("Goal percentage 2 of 4 (50%)", calcGoalPct(2, 4) === 50);
assertTest("Goal percentage 4 of 4 (100%)", calcGoalPct(4, 4) === 100);
assertTest("Goal percentage capped at 100% (6 of 4)", calcGoalPct(6, 4) === 100);
assertTest("Goal percentage 0 of 4 (0%)", calcGoalPct(0, 4) === 0);

// 3. Workout Type Validation Logic
echo "\n[3/4] Testing Workout Type Whitelist...\n";
$allowedTypes = ['Cardio', 'Strength', 'HIIT', 'Functional', 'Yoga', 'Boxing', 'General', 'Other'];
function sanitizeWorkoutType($type, $allowed) {
    $t = trim($type);
    return in_array($t, $allowed) ? $t : 'General';
}

assertTest("Valid workout type 'Strength' accepted", sanitizeWorkoutType('Strength', $allowedTypes) === 'Strength');
assertTest("Valid workout type 'HIIT' accepted", sanitizeWorkoutType('HIIT', $allowedTypes) === 'HIIT');
assertTest("Invalid workout type 'RandomMalicious' sanitized to 'General'", sanitizeWorkoutType('RandomMalicious', $allowedTypes) === 'General');
assertTest("Empty workout type defaults to 'General'", sanitizeWorkoutType('', $allowedTypes) === 'General');

// 4. Endpoint File Syntax & Structure Integrity
echo "\n[4/4] Verifying File Integrity...\n";
$apiFile = __DIR__ . '/../api/member_workouts.php';
assertTest("member_workouts.php exists", file_exists($apiFile));
$contents = file_get_contents($apiFile);
assertTest("CORS handler included", strpos($contents, "require_once __DIR__ . '/cors.php'") !== false);
assertTest("No UTF-8 BOM present", substr($contents, 0, 3) !== "\xEF\xBB\xBF");
assertTest("JSON Content-Type header emitted", strpos($contents, "header('Content-Type: application/json; charset=utf-8')") !== false);

echo "\n========================================================\n";
echo " TEST SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
