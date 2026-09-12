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

    // ── Photo Column Migration & Base64 Persistence ────────────────────────────
    echo "\n  [*] Ensuring `photo` column supports persistent Base64 Data URIs...\n";
    try {
        $pdo->exec("ALTER TABLE `members` MODIFY COLUMN `photo` MEDIUMTEXT NULL");
        echo "  [+] `photo` column updated to MEDIUMTEXT.\n";
    } catch (\Throwable $photoColErr) {
        echo "  [*] Note on `photo` column: " . $photoColErr->getMessage() . "\n";
    }

    // Convert any existing local image files on disk into persistent Base64 Data URIs
    $photo_stmt = $pdo->query("SELECT id, photo FROM members WHERE photo IS NOT NULL AND photo != '' AND photo NOT LIKE 'data:%' AND photo NOT LIKE 'http%'");
    $local_photos = $photo_stmt->fetchAll(PDO::FETCH_ASSOC);
    $photo_migrated = 0;

    $photo_upd = $pdo->prepare("UPDATE members SET photo = ? WHERE id = ?");

    foreach ($local_photos as $lp) {
        $clean_path = ltrim($lp['photo'], '/');
        $full_paths = [
            __DIR__ . '/' . $clean_path,
            __DIR__ . '/uploads/members/' . basename($clean_path),
            dirname(__DIR__) . '/' . $clean_path
        ];

        $found_file = null;
        foreach ($full_paths as $fp) {
            if (file_exists($fp) && is_file($fp)) {
                $found_file = $fp;
                break;
            }
        }

        if ($found_file) {
            $raw_data = @file_get_contents($found_file);
            if ($raw_data) {
                $b64 = null;
                if (extension_loaded('gd')) {
                    $src = @imagecreatefromstring($raw_data);
                    if ($src !== false) {
                        $sw = imagesx($src);
                        $sh = imagesy($src);
                        $tw = $sw;
                        $th = $sh;
                        if ($sw > 400 || $sh > 400) {
                            $ratio = min(400 / $sw, 400 / $sh);
                            $tw = max(1, (int)round($sw * $ratio));
                            $th = max(1, (int)round($sh * $ratio));
                        }
                        $dst = imagecreatetruecolor($tw, $th);
                        $white = imagecolorallocate($dst, 255, 255, 255);
                        imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
                        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

                        ob_start();
                        imagejpeg($dst, null, 82);
                        $jpg = ob_get_clean();
                        imagedestroy($src);
                        imagedestroy($dst);

                        if (!empty($jpg)) {
                            $b64 = 'data:image/jpeg;base64,' . base64_encode($jpg);
                        }
                    }
                }

                if (!$b64) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $found_file) ?: 'image/jpeg';
                    finfo_close($finfo);
                    $b64 = 'data:' . $mime . ';base64,' . base64_encode($raw_data);
                }

                if ($b64) {
                    $photo_upd->execute([$b64, $lp['id']]);
                    $photo_migrated++;
                }
            }
        }
    }

    if ($photo_migrated > 0) {
        echo "  [+] Converted {$photo_migrated} existing local member photo(s) to persistent Base64 Data URIs.\n";
    }

    echo "\n[SUCCESS] Migration completed successfully.\n";

} catch (Exception $e) {
    echo "[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
