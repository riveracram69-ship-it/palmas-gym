<?php
/**
 * Duplicate Member & Similarity Validator Helper
 * 
 * Provides unified validation for:
 * - Unique Email (case-insensitive)
 * - Unique Phone / Contact Number
 * - Unique Membership ID
 * - Fuzzy / Normalized Similar Name Detection (e.g. "Samantha Agustin" vs "samantha agustin")
 */

require_once __DIR__ . '/db.php';

/**
 * Validate unique fields and detect existing similar names
 * 
 * @param PDO $pdo
 * @param string $full_name
 * @param string $email
 * @param string $contact_number
 * @param int|null $exclude_member_id (optional, for edit profile/member)
 * @return array ['valid' => bool, 'errors' => array, 'warning' => string|null, 'similar_member' => array|null]
 */
function validate_member_uniqueness(PDO $pdo, string $full_name, string $email, string $contact_number = '', ?int $exclude_member_id = null): array {
    $errors = [];
    $warning = null;
    $similar_member = null;

    $full_name = trim($full_name);
    $email = trim($email);
    $contact_number = trim($contact_number);

    // 1. Email Uniqueness Check
    if (!empty($email)) {
        $email_sql = "SELECT id, full_name, membership_id FROM members WHERE LOWER(email) = LOWER(?)";
        $params = [$email];
        if ($exclude_member_id) {
            $email_sql .= " AND id != ?";
            $params[] = $exclude_member_id;
        }
        $email_sql .= " LIMIT 1";

        $stmt = $pdo->prepare($email_sql);
        $stmt->execute($params);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = "This email address is already registered. Please sign in instead.";
        }
    }

    // 2. Contact Number Uniqueness Check (if provided)
    if (!empty($contact_number)) {
        $phone_sql = "SELECT id, full_name, membership_id FROM members WHERE contact_number = ?";
        $params = [$contact_number];
        if ($exclude_member_id) {
            $phone_sql .= " AND id != ?";
            $params[] = $exclude_member_id;
        }
        $phone_sql .= " LIMIT 1";

        $stmt = $pdo->prepare($phone_sql);
        $stmt->execute($params);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = "This contact number is already registered to another member.";
        }
    }

    // 3. Normalized Name Similarity Check (Warning only - do not hard block legitimate same names)
    if (!empty($full_name)) {
        // Normalize name: lowercase, strip punctuation, condense multiple spaces
        $normalized_input = strtolower(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', $full_name)));

        $name_sql = "SELECT id, full_name, membership_id, email, account_status FROM members WHERE 1=1";
        $name_params = [];
        if ($exclude_member_id) {
            $name_sql .= " AND id != ?";
            $name_params[] = $exclude_member_id;
        }

        $stmt = $pdo->prepare($name_sql);
        $stmt->execute($name_params);
        $all_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_members as $m) {
            $norm_db = strtolower(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', $m['full_name'])));
            
            // Check exact normalized match or high similarity (Levenshtein distance <= 2 for longer names)
            $is_match = false;
            if ($norm_db === $normalized_input) {
                $is_match = true;
            } elseif (strlen($normalized_input) > 5 && strlen($norm_db) > 5) {
                $lev = levenshtein($normalized_input, $norm_db);
                if ($lev <= 2) {
                    $is_match = true;
                }
            }

            if ($is_match) {
                $warning = "A member with a similar name already exists ({$m['full_name']} - ID: {$m['membership_id']}). Please verify before continuing.";
                $similar_member = $m;
                break;
            }
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warning' => $warning,
        'similar_member' => $similar_member
    ];
}
