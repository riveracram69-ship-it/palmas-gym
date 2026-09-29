<?php
/**
 * Philippine Standard Geographic Code (PSGC) Address API
 * Palma's Elite Gym Management System
 * 
 * Provides local, authoritative cascading address data:
 * Region -> Province -> City/Municipality -> Barangay
 * 
 * Powered by self-hosted SQLite dataset in protected database/psgc/psgc.db.
 * Self-hosted PSGC dataset with no third-party API dependency and manual-entry
 * fallback when the address service is unavailable.
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours in client browser
}

$dbPath = __DIR__ . '/../database/psgc/psgc.db';

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PSGC address database is not initialized.'
    ]);
    exit;
}

try {
    $sqlite = new PDO("sqlite:$dbPath");
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to connect to PSGC database.'
    ]);
    exit;
}

$action = trim($_GET['action'] ?? '');

switch ($action) {
    case 'regions':
        $stmt = $sqlite->query("SELECT code, name, region_name FROM regions ORDER BY code ASC");
        $regions = $stmt->fetchAll();
        echo json_encode(['success' => true, 'regions' => $regions]);
        break;

    case 'provinces':
        $regionCode = trim($_GET['region_code'] ?? '');
        if (empty($regionCode)) {
            echo json_encode(['success' => false, 'message' => 'region_code is required.']);
            exit;
        }

        $stmt = $sqlite->prepare("SELECT code, name, region_code FROM provinces WHERE region_code = ? ORDER BY name ASC");
        $stmt->execute([$regionCode]);
        $provinces = $stmt->fetchAll();

        // Special handling for NCR (130000000)
        if ($regionCode === '130000000' && empty($provinces)) {
            $provinces = [
                ['code' => '130000000', 'name' => 'Metro Manila', 'region_code' => '130000000']
            ];
        }

        echo json_encode(['success' => true, 'provinces' => $provinces]);
        break;

    case 'cities':
        $provinceCode = trim($_GET['province_code'] ?? '');
        $regionCode   = trim($_GET['region_code'] ?? '');

        if (!empty($provinceCode)) {
            $stmt = $sqlite->prepare("
                SELECT code, name, province_code, region_code, is_city, is_municipality
                FROM cities
                WHERE province_code = ?
                ORDER BY name ASC
            ");
            $stmt->execute([$provinceCode]);
            $cities = $stmt->fetchAll();
        } elseif (!empty($regionCode)) {
            $stmt = $sqlite->prepare("
                SELECT code, name, province_code, region_code, is_city, is_municipality
                FROM cities
                WHERE region_code = ?
                ORDER BY name ASC
            ");
            $stmt->execute([$regionCode]);
            $cities = $stmt->fetchAll();
        } else {
            echo json_encode(['success' => false, 'message' => 'province_code or region_code is required.']);
            exit;
        }

        echo json_encode(['success' => true, 'cities' => $cities]);
        break;

    case 'barangays':
        $cityCode = trim($_GET['city_code'] ?? '');
        if (empty($cityCode)) {
            echo json_encode(['success' => false, 'message' => 'city_code is required.']);
            exit;
        }

        $stmt = $sqlite->prepare("
            SELECT code, name, city_code
            FROM barangays
            WHERE city_code = ?
            ORDER BY name ASC
        ");
        $stmt->execute([$cityCode]);
        $barangays = $stmt->fetchAll();

        echo json_encode(['success' => true, 'barangays' => $barangays]);
        break;

    case 'reverse':
        $provinceName = trim($_GET['province'] ?? '');
        $cityName     = trim($_GET['city'] ?? '');
        $barangayName = trim($_GET['barangay'] ?? '');

        if (empty($provinceName) && empty($cityName)) {
            echo json_encode(['success' => false, 'message' => 'Province or city name required.']);
            exit;
        }

        // Clean names (remove "City of ", "Brgy. ", etc.)
        $cleanMuni = preg_replace('/^(city\s+of\s+|municipality\s+of\s+)/i', '', $cityName);
        $cleanBrgy = preg_replace('/^(brgy\.?|barangay)\s+/i', '', $barangayName);

        $sql = "
            SELECT r.code as region_code, r.name as region_name, r.region_name as region_designation,
                   p.code as province_code, p.name as province_name,
                   c.code as city_code, c.name as city_name,
                   b.code as barangay_code, b.name as barangay_name
            FROM provinces p
            JOIN regions r ON p.region_code = r.code
            LEFT JOIN cities c ON (c.province_code = p.code OR c.region_code = p.region_code) 
                               AND (c.name LIKE ? OR c.name LIKE ?)
            LEFT JOIN barangays b ON b.city_code = c.code 
                                 AND (b.name LIKE ? OR b.name LIKE ?)
            WHERE p.name LIKE ? OR p.name LIKE ?
            LIMIT 1
        ";

        $stmt = $sqlite->prepare($sql);
        $stmt->execute([
            $cleanMuni,
            '%' . $cleanMuni . '%',
            $cleanBrgy . '%',
            '%' . $cleanBrgy . '%',
            $provinceName,
            '%' . $provinceName . '%'
        ]);
        $match = $stmt->fetch();

        if ($match && !empty($match['region_code'])) {
            echo json_encode([
                'success' => true,
                'match'   => $match
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No exact geographic match found.']);
        }
        break;

    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action. Supported: regions, provinces, cities, barangays, reverse.'
        ]);
        break;
}
