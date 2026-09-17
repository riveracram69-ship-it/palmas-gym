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
