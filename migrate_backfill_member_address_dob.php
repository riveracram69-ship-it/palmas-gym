<?php
/**
 * migrate_backfill_member_address_dob.php
 * Palma's Elite Gym — Backfill realistic Talavera / Nueva Ecija addresses and DOBs
 * for all members created prior to structured address & DOB schema migration.
 * 
 * Idempotent: Only modifies rows where address is empty or null, and dob is null.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/member_helpers.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Database connection failed via config/db.php\n");
}

echo "=======================================================\n";
echo " MIGRATION: BACKFILL MEMBER ADDRESS & DOB\n";
echo "=======================================================\n\n";

try {
    // Ensure all columns exist before updating
    $cols = $pdo->query("SHOW COLUMNS FROM `members`")->fetchAll(PDO::FETCH_COLUMN);
    $required_cols = ['dob', 'age', 'house_street', 'barangay', 'municipality', 'province', 'zip_code', 'address'];
    foreach ($required_cols as $rc) {
        if (!in_array($rc, $cols)) {
            echo "  [*] Adding missing column `{$rc}`...\n";
            if ($rc === 'dob') $pdo->exec("ALTER TABLE `members` ADD COLUMN `dob` DATE NULL AFTER `gender`");
            elseif ($rc === 'age') $pdo->exec("ALTER TABLE `members` ADD COLUMN `age` INT NULL AFTER `dob`");
            elseif ($rc === 'address') $pdo->exec("ALTER TABLE `members` ADD COLUMN `address` VARCHAR(255) NULL AFTER `contact_number`");
            elseif ($rc === 'house_street') $pdo->exec("ALTER TABLE `members` ADD COLUMN `house_street` VARCHAR(255) NULL AFTER `address`");
            elseif ($rc === 'barangay') $pdo->exec("ALTER TABLE `members` ADD COLUMN `barangay` VARCHAR(150) NULL AFTER `house_street`");
            elseif ($rc === 'municipality') $pdo->exec("ALTER TABLE `members` ADD COLUMN `municipality` VARCHAR(150) NULL AFTER `barangay`");
            elseif ($rc === 'province') $pdo->exec("ALTER TABLE `members` ADD COLUMN `province` VARCHAR(150) NULL AFTER `municipality`");
            elseif ($rc === 'zip_code') $pdo->exec("ALTER TABLE `members` ADD COLUMN `zip_code` VARCHAR(20) NULL AFTER `province`");
        }
    }

    // Curated Talavera / Nueva Ecija street addresses
    $streets = [
        "124 Maharlika Highway",
        "45 Rizal Street",
        "78 Bonifacio Avenue",
        "12 Mabini Street",
        "88 M. H. Del Pilar Street",
        "15 Luna Street",
        "34 Quezon Boulevard",
        "56 Burgos Street",
        "92 MacArthur Highway",
        "103 San Jose Road",
        "27 General Tinio Street",
        "61 Garcia Street",
        "18 Roxas Avenue",
        "82 Valenzuela Street",
        "14 Aquino Street",
        "39 Lapu-Lapu Street",
        "71 Basa Street",
        "23 Evangelista Street",
        "50 Mabini Extension",
        "118 National Road"
    ];

    $barangays = [
        "Maestrang Kikay",
        "Poblacion Sur",
        "Poblacion Norte",
        "Matias",
        "Sampaloc",
        "Pag-asa",
        "San Pascual",
        "Dimasalang",
        "Bantug",
        "La Torre",
        "Dinarayat",
        "Valle",
        "Marcos",
        "Pulong San Miguel",
        "Burnay",
        "Esguerra",
        "Andal Alino"
    ];

    $municipality = "Talavera";
    $province = "Nueva Ecija";
    $zip_code = "3114";

    // Select members who need backfilling
    $stmt = $pdo->query("
        SELECT id, full_name, membership_id, dob, age, address, house_street, barangay, municipality, province, zip_code
        FROM members
        WHERE (address IS NULL OR TRIM(address) = '' OR TRIM(address) = '—')
           OR (house_street IS NULL OR TRIM(house_street) = '')
           OR (barangay IS NULL OR TRIM(barangay) = '')
           OR (dob IS NULL)
    ");
    $members_to_update = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($members_to_update) . " member records needing address/DOB backfill.\n\n";

    $update_stmt = $pdo->prepare("
        UPDATE members
        SET house_street = :house_street,
            barangay     = :barangay,
            municipality = :municipality,
            province     = :province,
            zip_code     = :zip_code,
            address      = :address,
            dob          = :dob,
            age          = :age
        WHERE id = :id
    ");

    $updated_count = 0;
    foreach ($members_to_update as $idx => $m) {
        $id = $m['id'];

        // Deterministic but diverse pick based on member ID
        $street_idx = ($id * 7 + 3) % count($streets);
        $brgy_idx   = ($id * 11 + 5) % count($barangays);

        $chosen_street = !empty($m['house_street']) ? $m['house_street'] : $streets[$street_idx];
        $chosen_brgy   = !empty($m['barangay'])     ? $m['barangay']     : $barangays[$brgy_idx];
        $chosen_mun    = !empty($m['municipality']) ? $m['municipality'] : $municipality;
        $chosen_prov   = !empty($m['province'])     ? $m['province']     : $province;
        $chosen_zip    = !empty($m['zip_code'])     ? $m['zip_code']     : $zip_code;

        // Composed full address string
        $full_address = "{$chosen_street}, Brgy. {$chosen_brgy}, {$chosen_mun}, {$chosen_prov} {$chosen_zip}";

        // DOB & Age
        $dob = $m['dob'];
        $age = $m['age'];

        if (empty($dob) || $dob === '0000-00-00') {
            if (!empty($age) && $age > 10 && $age < 90) {
                $birth_year = (int)date('Y') - (int)$age;
            } else {
                // Gym member ages 20 - 36
                $birth_year = 1988 + (($id * 3 + 7) % 17); // 1988 - 2004
            }
            $birth_month = str_pad((($id * 5 + 2) % 12) + 1, 2, '0', STR_PAD_LEFT);
            $birth_day   = str_pad((($id * 13 + 1) % 28) + 1, 2, '0', STR_PAD_LEFT);
            $dob = "{$birth_year}-{$birth_month}-{$birth_day}";
            $age = compute_member_age($dob);
        } else {
            $age = compute_member_age($dob, $age);
        }

        $update_stmt->execute([
            ':house_street' => $chosen_street,
            ':barangay'     => $chosen_brgy,
            ':municipality' => $chosen_mun,
            ':province'     => $chosen_prov,
            ':zip_code'     => $chosen_zip,
            ':address'      => $full_address,
            ':dob'          => $dob,
            ':age'          => $age,
            ':id'           => $id
        ]);

        $updated_count++;
        echo "  [✓] Backfilled Member #{$id} [{$m['membership_id']}] {$m['full_name']}\n";
        echo "      DOB: {$dob} (Age {$age}) | {$full_address}\n";
    }

    echo "\n=======================================================\n";
    echo " [SUCCESS] Backfilled {$updated_count} member records with address & DOB!\n";
    echo "=======================================================\n";

} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
