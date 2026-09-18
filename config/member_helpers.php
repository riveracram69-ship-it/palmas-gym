<?php
/**
 * config/member_helpers.php
 * Palma's Elite Gym — Central helper functions for Member DOB, Computed Age, and Structured Address
 */

if (!function_exists('compute_member_age')) {
    /**
     * Compute member's current age dynamically from Date of Birth.
     * 
     * @param string|null $dob Date of Birth string (e.g. '1998-05-15')
     * @param int|null $fallback_age Fallback static age if DOB is not set
     * @return int|null Age in years, or null if neither is available
     */
    function compute_member_age(?string $dob, ?int $fallback_age = null): ?int {
        if (!empty($dob) && $dob !== '0000-00-00') {
            try {
                $dob_date = new DateTime($dob);
                $today = new DateTime('today');
                if ($dob_date <= $today) {
                    return $dob_date->diff($today)->y;
                }
            } catch (Exception $e) {
                // Ignore parse errors and use fallback
            }
        }
        return ($fallback_age !== null && $fallback_age > 0) ? (int)$fallback_age : null;
    }
}

if (!function_exists('format_member_dob')) {
    /**
     * Formats Date of Birth for display with computed age.
     * Example: "May 15, 2000 (24 yrs old)" or "24 yrs old"
     * 
     * @param string|null $dob
     * @param int|null $fallback_age
     * @return string
     */
    function format_member_dob(?string $dob, ?int $fallback_age = null): string {
        $age = compute_member_age($dob, $fallback_age);
        if (!empty($dob) && $dob !== '0000-00-00') {
            $formatted_date = date('M d, Y', strtotime($dob));
            if ($age !== null) {
                return "{$formatted_date} ({$age} yrs old)";
            }
            return $formatted_date;
        }
        if ($age !== null) {
            return "{$age} yrs old";
        }
        return '—';
    }
}

if (!function_exists('format_member_address')) {
    /**
     * Formats member address into a clean single line from structured components.
     * Example: "123 Rizal St., Brgy. Poblacion, Talavera, Nueva Ecija 3114"
     * 
     * @param array|object $member
     * @return string
     */
    function format_member_address($member): string {
        $data = is_object($member) ? (array)$member : $member;
        
        $street = trim($data['house_street'] ?? '');
        $brgy   = trim($data['barangay'] ?? '');
        $muni   = trim($data['municipality'] ?? '');
        $prov   = trim($data['province'] ?? '');
        $zip    = trim($data['zip_code'] ?? '');

        // Format barangay prefix cleanly if needed
        if (!empty($brgy) && !preg_match('/^(brgy\.?|barangay)\s+/i', $brgy)) {
            $brgy = 'Brgy. ' . $brgy;
        }

        $parts = [];
        if (!empty($street)) $parts[] = $street;
        if (!empty($brgy))   $parts[] = $brgy;
        if (!empty($muni))   $parts[] = $muni;
        if (!empty($prov))   $parts[] = $prov;

        if (!empty($parts)) {
            $formatted = implode(', ', $parts);
            if (!empty($zip)) {
                $formatted .= ' ' . $zip;
            }
            return $formatted;
        }

        // Fallback to legacy address column if structured fields are blank
        $legacy = trim($data['address'] ?? '');
        return !empty($legacy) ? $legacy : '—';
    }
}

if (!function_exists('compose_member_address_string')) {
    /**
     * Composes the combined address string for storing in legacy `address` column.
     */
    function compose_member_address_string(?string $street, ?string $brgy, ?string $muni, ?string $prov, ?string $zip, string $fallback = ''): string {
        $street = trim($street ?? '');
        $brgy   = trim($brgy ?? '');
        $muni   = trim($muni ?? '');
        $prov   = trim($prov ?? '');
        $zip    = trim($zip ?? '');

        if (!empty($brgy) && !preg_match('/^(brgy\.?|barangay)\s+/i', $brgy)) {
            $brgy = 'Brgy. ' . $brgy;
        }

        $parts = array_filter([$street, $brgy, $muni, $prov]);
        if (!empty($parts)) {
            $res = implode(', ', $parts);
            if (!empty($zip)) {
                $res .= ' ' . $zip;
            }
            return $res;
        }

        return trim($fallback);
    }
}

if (!function_exists('sync_attendance_auto_checkout')) {
    /**
     * Auto-close unclosed attendance sessions:
     * 1. Past Days: Any check-in from previous dates (date < CURRENT_DATE()) without time_out
     *    is closed at gym closing (22:00:00) or +2.5 hours from time_in.
     * 2. Today: Any check-in from today exceeding 4 hours workout duration is auto-closed.
     * 
     * @param PDO $pdo
     * @return int Number of sessions auto-closed
     */
    function sync_attendance_auto_checkout($pdo): int {
        if (!isset($pdo) || !$pdo) return 0;
        $affected = 0;
        try {
            // 1. Auto-close previous days
            $stmt1 = $pdo->prepare("
                UPDATE attendance
                SET time_out = CASE 
                    WHEN ADDTIME(time_in, '02:30:00') > '22:00:00' THEN '22:00:00'
                    ELSE ADDTIME(time_in, '02:30:00')
                END
                WHERE (time_out IS NULL OR TRIM(time_out) = '' OR time_out = '00:00:00')
                  AND date < CURRENT_DATE()
            ");
            $stmt1->execute();
            $affected += $stmt1->rowCount();

            // 2. Auto-close today's sessions exceeding 4 hours workout duration
            $stmt2 = $pdo->prepare("
                UPDATE attendance
                SET time_out = ADDTIME(time_in, '02:30:00')
                WHERE (time_out IS NULL OR TRIM(time_out) = '' OR time_out = '00:00:00')
                  AND date = CURRENT_DATE()
                  AND TIMESTAMPDIFF(MINUTE, time_in, NOW()) >= 240
            ");
            $stmt2->execute();
            $affected += $stmt2->rowCount();
        } catch (\Throwable $e) {
            error_log('Auto checkout sync error: ' . $e->getMessage());
        }
        return $affected;
    }
}

if (!function_exists('is_official_member')) {
    /**
     * Checks if a member has active Official Member status based on annual_membership_expiry.
     *
     * @param array|object|string|null $member Member record or annual_membership_expiry string
     * @return bool
     */
    function is_official_member($member): bool {
        if ($member === null) return false;
        $exp = '';
        if (is_array($member)) {
            $exp = $member['annual_membership_expiry'] ?? '';
        } elseif (is_object($member)) {
            $exp = $member->annual_membership_expiry ?? '';
        } elseif (is_string($member)) {
            $exp = $member;
        }
        $exp = trim((string)$exp);
        if (empty($exp) || $exp === '0000-00-00') return false;
        $exp_ts = (strpos($exp, ':') !== false) ? strtotime($exp) : strtotime($exp . ' 23:59:59');
        return ($exp_ts !== false && $exp_ts >= time());
    }
}

if (!function_exists('validate_plan_tier_eligibility')) {
    /**
     * Validates if a member is eligible to purchase a specific membership plan based on tier rules.
     *
     * @param array|object $plan The plan record
     * @param bool|array|object $member_or_status Either bool is_official_member or member array/object
     * @return array ['allowed' => bool, 'eligible' => bool, 'reason' => string]
     */
    function validate_plan_tier_eligibility($plan, $member_or_status): array {
        $p = is_object($plan) ? (array)$plan : (array)$plan;
        $is_official = is_bool($member_or_status) ? $member_or_status : is_official_member($member_or_status);
        $cat = $p['plan_category'] ?? 'legacy';
        $is_active = (int)($p['is_active'] ?? 0);

        if ($is_active !== 1 || $cat === 'legacy') {
            return ['allowed' => false, 'eligible' => false, 'reason' => 'This plan is no longer available.'];
        }

        // Member Rates (member_pass) require Official Member status
        if ($cat === 'member_pass' && !$is_official) {
            return [
                'allowed'  => false,
                'eligible' => false,
                'reason'   => 'Member discounted rates require an active ₱1,000 Annual Membership. Please purchase the Annual Membership Fee or select a Non-Member pass.'
            ];
        }

        // Non-Member Rates (non_member_pass) are for non-members (Official Members should select member rates)
        if ($cat === 'non_member_pass' && $is_official) {
            return [
                'allowed'  => false,
                'eligible' => false,
                'reason'   => 'You are an Official Member. Please select a discounted Member Rate pass.'
            ];
        }

        // Annual Membership Fee (membership_fee): check duplicate purchase (>30 days remaining)
        if ($cat === 'membership_fee') {
            if ($is_official) {
                // Check remaining days on annual membership
                $ann_exp = is_array($member_or_status) ? ($member_or_status['annual_membership_expiry'] ?? null) : null;
                if (!empty($ann_exp)) {
                    $diff_days = (int)ceil((strtotime($ann_exp . ' 23:59:59') - time()) / 86400);
                    if ($diff_days > 30) {
                        return [
                            'allowed'  => false,
                            'eligible' => false,
                            'reason'   => "Your Official Membership is already active ({$diff_days} days remaining). Renewal becomes available within 30 days of expiry."
                        ];
                    }
                }
            }
        }

        return ['allowed' => true, 'eligible' => true, 'reason' => ''];
    }
}

if (!function_exists('can_renew_annual_membership')) {
    /**
     * Checks if a member can purchase or renew their ₱1,000 Annual Membership.
     * Allowed if:
     * - Non-member (purchase to become official member)
     * - Official Member whose annual membership is expired or within 30 days of expiration
     *
     * @param array|object|null $member
     * @return array ['can_renew' => bool, 'is_official' => bool, 'days_remaining' => int, 'reason' => string]
     */
    function can_renew_annual_membership($member): array {
        $is_official = is_official_member($member);
        if (!$is_official) {
            return [
                'can_renew'      => true,
                'is_official'    => false,
                'days_remaining' => 0,
                'reason'         => ''
            ];
        }

        $exp = is_array($member) ? ($member['annual_membership_expiry'] ?? '') : ($member->annual_membership_expiry ?? '');
        $exp_ts = (strpos($exp, ':') !== false) ? strtotime($exp) : strtotime($exp . ' 23:59:59');
        $diff_sec = $exp_ts ? ($exp_ts - time()) : 0;
        $days_left = max(0, (int)ceil($diff_sec / 86400));

        if ($days_left <= 30) {
            return [
                'can_renew'      => true,
                'is_official'    => true,
                'days_remaining' => $days_left,
                'reason'         => ''
            ];
        }

        return [
            'can_renew'      => false,
            'is_official'    => true,
            'days_remaining' => $days_left,
            'reason'         => "Your Annual Membership is active ({$days_left} days remaining). Renewal is available within 30 days of expiry."
        ];
    }
}

if (!function_exists('can_renew_gym_access')) {
    /**
     * Checks if a member can purchase or renew their workout floor pass (Gym Access).
     * Allowed if:
     * - No active gym pass subscription
     * - Active gym pass is expiring within 3 days (or 5 mins for short minute promos)
     *
     * @param string|null $expiry_date Gym access pass expiry date
     * @param int $duration_minutes Plan duration in minutes (if any)
     * @param string|null $plan_name Plan name (if any)
     * @return array ['can_renew' => bool, 'has_active_pass' => bool, 'days_remaining' => int, 'reason' => string]
     */
    function can_renew_gym_access(?string $expiry_date, int $duration_minutes = 0, ?string $plan_name = null): array {
        if (empty($expiry_date)) {
            return [
                'can_renew'       => true,
                'has_active_pass' => false,
                'days_remaining'  => 0,
                'reason'          => ''
            ];
        }

        $exp_ts = (strpos($expiry_date, ':') !== false) ? strtotime($expiry_date) : strtotime($expiry_date . ' 23:59:59');
        $now_time = time();

        if ($exp_ts <= $now_time) {
            return [
                'can_renew'       => true,
                'has_active_pass' => false,
                'days_remaining'  => 0,
                'reason'          => 'Pass has expired'
            ];
        }

        $diff_sec = $exp_ts - $now_time;
        $days_left = max(0, (int)ceil($diff_sec / 86400));

        $is_minute_promo = ($duration_minutes > 0 && $duration_minutes < 1440) ||
            ($plan_name && preg_match('/(\d+)\s*(?:min|minute)/i', $plan_name));
        $threshold_sec = $is_minute_promo ? 300 : (3 * 86400);

        if ($diff_sec <= $threshold_sec) {
            return [
                'can_renew'       => true,
                'has_active_pass' => true,
                'days_remaining'  => $days_left,
                'reason'          => 'Pass is expiring soon'
            ];
        }

        $rem_text = ($is_minute_promo || $diff_sec < 86400) ? ceil($diff_sec / 60) . ' min(s)' : $days_left . ' day(s)';
        $rule_text = $is_minute_promo ? 'within 5 minutes of expiration' : 'within 3 days of expiration';

        return [
            'can_renew'       => false,
            'has_active_pass' => true,
            'days_remaining'  => $days_left,
            'reason'          => "Your gym pass is still active ({$rem_text} remaining). Renewal is available when expired or {$rule_text}."
        ];
    }
}

