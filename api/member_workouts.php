<?php
/**
 * api/member_workouts.php
 * Palma's Elite Gym — Workout Logging & Streak Tracking API
 * Supports Bearer token (Mobile App) and Web Session (Member Portal)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

// Resolve authentication (Bearer token or web session)
$member_id = null;
$headers = function_exists('apache_request_headers')
    ? apache_request_headers()
    : (function_exists('getallheaders') ? getallheaders() : []);

$authHeader = $headers['Authorization']
    ?? $headers['authorization']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? null;

if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
    $token = $matches[1];
    $stmt = $pdo->prepare("
        SELECT t.member_id 
        FROM auth_tokens t 
        JOIN members m ON m.id = t.member_id 
        WHERE t.token = ? AND t.expires_at > NOW() 
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($auth) {
        $member_id = (int)$auth['member_id'];
    }
}

if (!$member_id) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['member_id'])) {
        $member_id = (int)$_SESSION['member_id'];
    }
}

if (!$member_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please sign in.']);
    exit;
}

// Auto-create table if not exists (self-healing)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `member_workouts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `workout_type` VARCHAR(50) NOT NULL DEFAULT 'General',
            `duration_minutes` INT(11) NOT NULL DEFAULT 30,
            `calories_burned` INT(11) NULL DEFAULT 0,
            `notes` TEXT NULL,
            `logged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_member_workouts_member_id` (`member_id`),
            KEY `idx_member_workouts_logged_at` (`logged_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `member_fitness_goals` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `goal_type` VARCHAR(50) NOT NULL DEFAULT 'weekly_workouts',
            `target_value` DECIMAL(6,2) NOT NULL DEFAULT 4.00,
            `current_value` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `unit` VARCHAR(20) NOT NULL DEFAULT 'sessions',
            `start_date` DATE NOT NULL,
            `target_date` DATE NULL,
            `status` ENUM('In Progress', 'Achieved', 'Abandoned') NOT NULL DEFAULT 'In Progress',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_member_fitness_goals_member_id` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Throwable $e) {}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: Fetch workouts, streak, and weekly goal stats ─────────────
if ($method === 'GET') {
    try {
        // Fetch recent workouts
        $stmt = $pdo->prepare("
            SELECT id, workout_type, duration_minutes, calories_burned, notes, logged_at, created_at 
            FROM member_workouts 
            WHERE member_id = ? 
            ORDER BY logged_at DESC 
            LIMIT 50
        ");
        $stmt->execute([$member_id]);
        $workouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate this week's workout count (Monday - Sunday)
        $stmtWeek = $pdo->prepare("
            SELECT COUNT(*) AS count_this_week, COALESCE(SUM(duration_minutes), 0) AS minutes_this_week 
            FROM member_workouts 
            WHERE member_id = ? 
              AND YEARWEEK(logged_at, 1) = YEARWEEK(CURDATE(), 1)
        ");
        $stmtWeek->execute([$member_id]);
        $weekStats = $stmtWeek->fetch(PDO::FETCH_ASSOC);
        $workoutsThisWeek = (int)($weekStats['count_this_week'] ?? 0);
        $minutesThisWeek = (int)($weekStats['minutes_this_week'] ?? 0);

        // Calculate All-time stats
        $stmtAll = $pdo->prepare("
            SELECT COUNT(*) AS total_count, COALESCE(SUM(duration_minutes), 0) AS total_minutes, COALESCE(SUM(calories_burned), 0) AS total_calories 
            FROM member_workouts 
            WHERE member_id = ?
        ");
        $stmtAll->execute([$member_id]);
        $allStats = $stmtAll->fetch(PDO::FETCH_ASSOC);

        // Calculate Streak (consecutive days or weekly consistency)
        $stmtDates = $pdo->prepare("
            SELECT DISTINCT DATE(logged_at) AS workout_date 
            FROM member_workouts 
            WHERE member_id = ? 
            ORDER BY workout_date DESC 
            LIMIT 60
        ");
        $stmtDates->execute([$member_id]);
        $dates = $stmtDates->fetchAll(PDO::FETCH_COLUMN);

        $streak = 0;
        if (!empty($dates)) {
            $today = new DateTime('today');
            $yesterday = (new DateTime('today'))->modify('-1 day');
            $latest = new DateTime($dates[0]);

            // Allow streak to continue if latest was today or yesterday
            if ($latest >= $yesterday) {
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
            }
        }

        // Fetch Weekly Goal
        $stmtGoal = $pdo->prepare("
            SELECT target_value 
            FROM member_fitness_goals 
            WHERE member_id = ? AND goal_type = 'weekly_workouts' AND status = 'In Progress' 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtGoal->execute([$member_id]);
        $targetWeekly = (int)($stmtGoal->fetchColumn() ?: 4);

        echo json_encode([
            'success' => true,
            'data' => [
                'workouts' => $workouts,
                'streak' => $streak,
                'this_week' => [
                    'completed' => $workoutsThisWeek,
                    'target' => $targetWeekly,
                    'minutes' => $minutesThisWeek,
                    'percentage' => min(100, (int)round(($workoutsThisWeek / max(1, $targetWeekly)) * 100))
                ],
                'totals' => [
                    'total_workouts' => (int)($allStats['total_count'] ?? 0),
                    'total_minutes' => (int)($allStats['total_minutes'] ?? 0),
                    'total_calories' => (int)($allStats['total_calories'] ?? 0)
                ]
            ]
        ]);
    } catch (Throwable $e) {
        error_log("Workouts fetch error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load workout data.']);
    }
    exit;
}

// ── POST: Log Workout, Update Goal, or Delete Workout ──────────────
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = $input['action'] ?? 'log_workout';

    if ($action === 'log_workout') {
        $type = trim($input['workout_type'] ?? 'General');
        $duration = max(5, min(360, (int)($input['duration_minutes'] ?? 30)));
        $calories = max(0, (int)($input['calories_burned'] ?? 0));
        $notes = trim($input['notes'] ?? '');
        $loggedAt = !empty($input['logged_at']) ? date('Y-m-d H:i:s', strtotime($input['logged_at'])) : date('Y-m-d H:i:s');

        // Validation
        $allowedTypes = ['Cardio', 'Strength', 'HIIT', 'Functional', 'Yoga', 'Boxing', 'General', 'Other'];
        if (!in_array($type, $allowedTypes)) {
            $type = 'General';
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO member_workouts (member_id, workout_type, duration_minutes, calories_burned, notes, logged_at) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$member_id, $type, $duration, $calories, $notes, $loggedAt]);
            $insertedId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Workout logged successfully! Keep up the momentum!',
                'workout_id' => $insertedId
            ]);
        } catch (Throwable $e) {
            error_log("Workout insert error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save workout log.']);
        }
        exit;
    }

    if ($action === 'set_goal') {
        $weeklyTarget = max(1, min(7, (int)($input['target_weekly_workouts'] ?? 4)));
        try {
            // Close previous goals
            $stmt = $pdo->prepare("
                UPDATE member_fitness_goals 
                SET status = 'Abandoned' 
                WHERE member_id = ? AND goal_type = 'weekly_workouts' AND status = 'In Progress'
            ");
            $stmt->execute([$member_id]);

            // Insert new goal
            $stmt = $pdo->prepare("
                INSERT INTO member_fitness_goals (member_id, goal_type, target_value, unit, start_date, status) 
                VALUES (?, 'weekly_workouts', ?, 'sessions', CURDATE(), 'In Progress')
            ");
            $stmt->execute([$member_id, $weeklyTarget]);

            echo json_encode([
                'success' => true,
                'message' => 'Weekly fitness goal updated to ' . $weeklyTarget . ' workouts per week.'
            ]);
        } catch (Throwable $e) {
            error_log("Set goal error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update goal.']);
        }
        exit;
    }

    if ($action === 'delete_workout') {
        $workoutId = (int)($input['workout_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM member_workouts WHERE id = ? AND member_id = ?");
            $stmt->execute([$workoutId, $member_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Workout entry removed.'
            ]);
        } catch (Throwable $e) {
            error_log("Delete workout error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete workout.']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}
