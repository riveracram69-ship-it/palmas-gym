<?php
/**
 * migrate_split_name_photo.php
 * Unified migration to add structured name fields (first_name, middle_name, last_name, extension)
 * to the `members` table and backfill from existing `full_name`.
 */

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Database connection failed.\n");
}

echo "=======================================================\n";
echo " MIGRATION: ADD STRUCTURED NAME FIELDS TO MEMBERS\n";
echo "=======================================================\n\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `members`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('first_name', $cols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `first_name` VARCHAR(100) NULL AFTER `full_name`");
        echo "  [+] Added `first_name` column to `members`.\n";
    } else {
        echo "  [*] `first_name` column already exists.\n";
    }

    if (!in_array('middle_name', $cols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `middle_name` VARCHAR(100) NULL AFTER `first_name`");
        echo "  [+] Added `middle_name` column to `members`.\n";
    } else {
        echo "  [*] `middle_name` column already exists.\n";
    }

    if (!in_array('last_name', $cols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `last_name` VARCHAR(100) NULL AFTER `middle_name`");
        echo "  [+] Added `last_name` column to `members`.\n";
    } else {
        echo "  [*] `last_name` column already exists.\n";
    }

    if (!in_array('extension', $cols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `extension` VARCHAR(20) NULL AFTER `last_name`");
        echo "  [+] Added `extension` column to `members`.\n";
    } else {
        echo "  [*] `extension` column already exists.\n";
    }

    // ── Backfill Existing Records ─────────────────────────────────────────────
    echo "\n  [*] Backfilling structured names for existing members...\n";
    $stmt = $pdo->query("SELECT id, full_name FROM members WHERE first_name IS NULL OR first_name = ''");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $known_suffixes = ['JR', 'JR.', 'SR', 'SR.', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];

    $upd = $pdo->prepare("UPDATE members SET first_name = ?, middle_name = ?, last_name = ?, extension = ? WHERE id = ?");

    $count = 0;
    foreach ($members as $m) {
        $raw = trim($m['full_name'] ?? '');
        if (empty($raw)) continue;

        $tokens = preg_split('/\s+/', $raw);
        $ext = null;
        $fn  = '';
        $mn  = null;
        $ln  = '';

        // Check if last token is an extension
        if (count($tokens) > 1) {
            $last_token = strtoupper(rtrim(end($tokens), '.'));
            if (in_array($last_token, ['JR', 'SR', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'])) {
                $ext = array_pop($tokens);
            }
        }

        $num_tokens = count($tokens);
        if ($num_tokens === 1) {
            $fn = $tokens[0];
            $ln = $tokens[0];
        } elseif ($num_tokens === 2) {
            $fn = $tokens[0];
            $ln = $tokens[1];
        } elseif ($num_tokens === 3) {
            $fn = $tokens[0];
            $mn = $tokens[1];
            $ln = $tokens[2];
        } else {
            $ln = array_pop($tokens);
            $mn = array_pop($tokens);
            $fn = implode(' ', $tokens);
        }

        $upd->execute([$fn, $mn, $ln, $ext, $m['id']]);
        $count++;
    }

    echo "  [+] Backfilled {$count} member record(s).\n";
    echo "\n[SUCCESS] Migration completed successfully.\n";

} catch (Exception $e) {
    echo "[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
