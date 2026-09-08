<?php
/**
 * config/notification_scheduler.php
 * Configurable Membership Lifecycle & Expiration Notification Scheduler
 * 
 * Supports both temporary-duration test promotions (30m, 60m) and standard month-based plans.
 * Enforces stage-level idempotency so multiple executions produce exactly ONE alert per milestone.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/logger.php';

class NotificationScheduler {

    /**
     * Run all notification evaluation schedules and member status synchronizations
     * 
     * @param PDO $pdo
     * @return array Summary of actions performed
     */
    public static function run(PDO $pdo): array {
        $summary = [
            'status_sync_expired'  => 0,
            'status_sync_active'   => 0,
            'promo_reminders_sent' => 0,
            'standard_reminders_sent' => 0,
            'expiration_alerts_sent'  => 0,
            'errors' => []
        ];

        self::syncMemberStatuses($pdo, $summary);
        self::processMinutePromoSchedule($pdo, $summary);
        self::processStandardPlanSchedule($pdo, $summary);
        self::processExpiredMemberships($pdo, $summary);

        return $summary;
    }

    /**
     * Synchronize member status between 'Active' and 'Expired' based on latest subscription date
     */
    private static function syncMemberStatuses(PDO $pdo, array &$summary): void {
        try {
            // Find active members whose latest subscription has passed
            $stmt = $pdo->query("
                SELECT m.id, m.full_name, m.membership_id, MAX(s.expiry_date) as latest_expiry
                FROM members m
                JOIN subscriptions s ON s.member_id = m.id
                WHERE m.status = 'Active'
                GROUP BY m.id
                HAVING latest_expiry < NOW()
            ");
            $to_expire = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $upd = $pdo->prepare("UPDATE members SET status = 'Expired' WHERE id = ?");
            foreach ($to_expire as $m) {
                $upd->execute([$m['id']]);
                $summary['status_sync_expired']++;
            }

            // Restore active status for members who have a future subscription but are marked Expired
            $reactivateStmt = $pdo->query("
                SELECT m.id, m.full_name, m.membership_id, MAX(s.expiry_date) as latest_expiry
                FROM members m
                JOIN subscriptions s ON s.member_id = m.id
                WHERE m.status = 'Expired'
                GROUP BY m.id
                HAVING latest_expiry >= NOW()
            ");
            $to_reactivate = $reactivateStmt->fetchAll(PDO::FETCH_ASSOC);

            $reactUpd = $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?");
            foreach ($to_reactivate as $m) {
                $reactUpd->execute([$m['id']]);
                $summary['status_sync_active']++;
            }

        } catch (Exception $e) {
            $summary['errors'][] = 'syncMemberStatuses: ' . $e->getMessage();
        }
    }

    /**
     * Process approaching expiration schedules for minute-based test promotions (30m & 60m)
     */
    private static function processMinutePromoSchedule(PDO $pdo, array &$summary): void {
        try {
            // Fetch active promo subscriptions
            $stmt = $pdo->query("
                SELECT s.id as subscription_id, s.member_id, s.start_date, s.expiry_date,
                       p.name as plan_name, p.duration_minutes,
                       m.full_name, m.email, m.membership_id
                FROM subscriptions s
                JOIN membership_plans p ON p.id = s.plan_id
                JOIN members m ON m.id = s.member_id
                WHERE p.duration_minutes > 0
                  AND s.expiry_date > NOW()
                  AND m.status = 'Active'
            ");
            $promos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $now = time();

            foreach ($promos as $sub) {
                $expiry_ts = strtotime($sub['expiry_date']);
                $remaining_seconds = $expiry_ts - $now;
                $remaining_minutes = (int)ceil($remaining_seconds / 60);

                if ($remaining_seconds <= 0) continue;

                $duration = (int)$sub['duration_minutes'];

                // 1. 30-Minute Remaining Stage (Applicable to 60m promo)
                if ($duration >= 60 && $remaining_minutes <= 30 && $remaining_minutes > 15) {
                    $sent = create_notification(
                        $pdo,
                        (int)$sub['member_id'],
                        'MEMBERSHIP_EXPIRING',
                        "Promo Notice: 30 Minutes Remaining ⏱️",
                        "Your '{$sub['plan_name']}' test membership expires in ~30 minutes (at " . date('g:i A', $expiry_ts) . ").",
                        'Sent',
                        (int)$sub['subscription_id'],
                        'STAGE_30M'
                    );
                    if ($sent) $summary['promo_reminders_sent']++;
                }

                // 2. 10-Minute Remaining Stage (Applicable to both 30m and 60m promos)
                if ($remaining_minutes <= 10 && $remaining_minutes > 5) {
                    $sent = create_notification(
                        $pdo,
                        (int)$sub['member_id'],
                        'MEMBERSHIP_EXPIRING',
                        "Promo Notice: 10 Minutes Remaining ⚠️",
                        "Your '{$sub['plan_name']}' test membership expires in ~10 minutes (at " . date('g:i A', $expiry_ts) . ").",
                        'Sent',
                        (int)$sub['subscription_id'],
                        'STAGE_10M'
                    );
                    if ($sent) $summary['promo_reminders_sent']++;
                }

                // 3. 5-Minute Remaining Stage (Urgent alert)
                if ($remaining_minutes <= 5 && $remaining_minutes > 0) {
                    $sent = create_notification(
                        $pdo,
                        (int)$sub['member_id'],
                        'MEMBERSHIP_EXPIRING',
                        "Urgent: 5 Minutes Remaining! 🚨",
                        "Your '{$sub['plan_name']}' test membership expires in 5 minutes (at " . date('g:i A', $expiry_ts) . ").",
                        'Sent',
                        (int)$sub['subscription_id'],
                        'STAGE_5M'
                    );
                    if ($sent) $summary['promo_reminders_sent']++;
                }
            }

        } catch (Exception $e) {
            $summary['errors'][] = 'processMinutePromoSchedule: ' . $e->getMessage();
        }
    }

    /**
     * Process 3-day and 1-day reminders for standard month-based plans
     */
    private static function processStandardPlanSchedule(PDO $pdo, array &$summary): void {
        try {
            // A. 3-Day Expiry Notice
            $stmt3d = $pdo->query("
                SELECT s.id as subscription_id, s.member_id, s.expiry_date,
                       p.name as plan_name, m.full_name, m.email, m.membership_id
                FROM subscriptions s
                JOIN membership_plans p ON p.id = s.plan_id
                JOIN members m ON m.id = s.member_id
                WHERE (p.duration_minutes IS NULL OR p.duration_minutes = 0)
                  AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
                  AND s.expiry_date > DATE_ADD(NOW(), INTERVAL 1 DAY)
                  AND m.status = 'Active'
            ");
            $subs3d = $stmt3d->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subs3d as $sub) {
                $expFormatted = date('M d, Y', strtotime($sub['expiry_date']));
                $sent = create_notification(
                    $pdo,
                    (int)$sub['member_id'],
                    'MEMBERSHIP_EXPIRING',
                    "Membership Expiring in 3 Days ({$expFormatted})",
                    "Hi {$sub['full_name']}, your '{$sub['plan_name']}' membership expires on {$expFormatted}. Renew today to continue access.",
                    'Sent',
                    (int)$sub['subscription_id'],
                    'STAGE_3D'
                );
                if ($sent) {
                    $summary['standard_reminders_sent']++;
                    if (!empty($sub['email'])) {
                        @send_email_notification(
                            $sub['email'],
                            "Reminder: Membership Expires in 3 Days - Palma's Elite Gym",
                            "Membership Expiration Notice",
                            "Hi <strong>{$sub['full_name']}</strong>,<br><br>Your {$sub['plan_name']} membership expires on {$expFormatted}."
                        );
                    }
                }
            }

            // B. 1-Day Final Notice
            $stmt1d = $pdo->query("
                SELECT s.id as subscription_id, s.member_id, s.expiry_date,
                       p.name as plan_name, m.full_name, m.email, m.membership_id
                FROM subscriptions s
                JOIN membership_plans p ON p.id = s.plan_id
                JOIN members m ON m.id = s.member_id
                WHERE (p.duration_minutes IS NULL OR p.duration_minutes = 0)
                  AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)
                  AND m.status = 'Active'
            ");
            $subs1d = $stmt1d->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subs1d as $sub) {
                $expFormatted = date('M d, Y', strtotime($sub['expiry_date']));
                $sent = create_notification(
                    $pdo,
                    (int)$sub['member_id'],
                    'MEMBERSHIP_EXPIRING',
                    "Urgent: Membership Expires Tomorrow ({$expFormatted})",
                    "Hi {$sub['full_name']}, your '{$sub['plan_name']}' membership expires tomorrow. Please renew to keep workout access.",
                    'Sent',
                    (int)$sub['subscription_id'],
                    'STAGE_1D'
                );
                if ($sent) {
                    $summary['standard_reminders_sent']++;
                    if (!empty($sub['email'])) {
                        @send_email_notification(
                            $sub['email'],
                            "Urgent: Membership Expires Tomorrow - Palma's Elite Gym",
                            "Final Expiration Notice",
                            "Hi <strong>{$sub['full_name']}</strong>,<br><br>Your {$sub['plan_name']} membership expires tomorrow ({$expFormatted})."
                        );
                    }
                }
            }

        } catch (Exception $e) {
            $summary['errors'][] = 'processStandardPlanSchedule: ' . $e->getMessage();
        }
    }

    /**
     * Dispatch expiration notification when membership has expired
     */
    private static function processExpiredMemberships(PDO $pdo, array &$summary): void {
        try {
            // Find recent subscriptions that expired within the last 48 hours and have not received an expiration notice
            $stmt = $pdo->query("
                SELECT s.id as subscription_id, s.member_id, s.expiry_date,
                       p.name as plan_name, p.duration_minutes,
                       m.full_name, m.email, m.membership_id
                FROM subscriptions s
                JOIN membership_plans p ON p.id = s.plan_id
                JOIN members m ON m.id = s.member_id
                WHERE s.expiry_date <= NOW()
                  AND s.expiry_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                  AND NOT EXISTS (
                      SELECT 1 FROM notifications n
                      WHERE n.subscription_id = s.id AND n.stage = 'STAGE_EXPIRED'
                  )
            ");
            $expiredSubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($expiredSubs as $sub) {
                $is_min = (!empty($sub['duration_minutes']) && (int)$sub['duration_minutes'] > 0);
                $expTime = $is_min
                    ? date('F j, Y, g:i A', strtotime($sub['expiry_date']))
                    : date('F j, Y', strtotime($sub['expiry_date']));

                $sent = create_notification(
                    $pdo,
                    (int)$sub['member_id'],
                    'MEMBERSHIP_EXPIRED',
                    "Membership Expired ❌",
                    "Your '{$sub['plan_name']}' membership expired on {$expTime}. Please renew to regain gym access.",
                    'Sent',
                    (int)$sub['subscription_id'],
                    'STAGE_EXPIRED'
                );

                if ($sent) {
                    $summary['expiration_alerts_sent']++;
                    if (!empty($sub['email'])) {
                        @send_email_notification(
                            $sub['email'],
                            "Membership Expired - Palma's Elite Gym",
                            "Membership Expired",
                            "Hi <strong>{$sub['full_name']}</strong>,<br><br>Your {$sub['plan_name']} pass expired on {$expTime}. Please renew via our mobile app or at the reception kiosk."
                        );
                    }
                }
            }

        } catch (Exception $e) {
            $summary['errors'][] = 'processExpiredMemberships: ' . $e->getMessage();
        }
    }
}
