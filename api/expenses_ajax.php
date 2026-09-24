<?php
/**
 * Expenses AJAX Controller
 * Palma's Elite Gym Management System
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/logger.php';

// Access Control: Admin only
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated session. Please log in.']);
    exit;
}

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Expense management is restricted to Administrators only.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'get_expense') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid expense ID.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
        $stmt->execute([$id]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$expense) {
            echo json_encode(['success' => false, 'message' => 'Expense record not found.']);
            exit;
        }

        echo json_encode(['success' => true, 'expense' => $expense]);
        exit;
    }

    if ($action === 'save_expense') {
        $id = isset($_POST['id']) && !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $expense_date = trim($_POST['expense_date'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if (empty($expense_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_date)) {
            echo json_encode(['success' => false, 'message' => 'Please provide a valid expense date (YYYY-MM-DD).']);
            exit;
        }

        if (empty($category)) {
            echo json_encode(['success' => false, 'message' => 'Expense category is required.']);
            exit;
        }

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Expense title/description is required.']);
            exit;
        }

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0.00.']);
            exit;
        }

        $valid_methods = ['Cash', 'GCash', 'Bank Transfer', 'Credit Card', 'Cheque', 'Debit Card', 'Other'];
        if (!in_array($payment_method, $valid_methods, true)) {
            $payment_method = 'Cash';
        }

        $receipt_filename = null;
        $existing_receipt = null;

        if ($id) {
            $stmt_old = $pdo->prepare("SELECT receipt_photo FROM expenses WHERE id = ?");
            $stmt_old->execute([$id]);
            $existing_receipt = $stmt_old->fetchColumn();
            $receipt_filename = $existing_receipt;
        }

        // Handle file upload if provided
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['receipt'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Receipt upload failed with error code: ' . $file['error']]);
                exit;
            }

            // Limit file size to 5MB
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Receipt file size exceeds maximum limit of 5MB.']);
                exit;
            }

            // Validate MIME type securely using finfo
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed_mimes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf'
            ];

            if (!array_key_exists($mime, $allowed_mimes)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file format. Allowed formats: JPG, PNG, WEBP, and PDF.']);
                exit;
            }

            $ext = $allowed_mimes[$mime];
            $upload_dir = __DIR__ . '/../uploads/receipts';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $unique_name = 'rcpt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $target_path = $upload_dir . '/' . $unique_name;

            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                echo json_encode(['success' => false, 'message' => 'Failed to save uploaded receipt file.']);
                exit;
            }

            $receipt_filename = 'uploads/receipts/' . $unique_name;

            // Remove old receipt file if replacing
            if ($existing_receipt && file_exists(__DIR__ . '/../' . $existing_receipt)) {
                @unlink(__DIR__ . '/../' . $existing_receipt);
            }
        }

        $user_id = $_SESSION['user_id'] ?? null;

        if ($id) {
            // Update
            $sql = "UPDATE expenses SET 
                        expense_date = :expense_date,
                        category = :category,
                        title = :title,
                        amount = :amount,
                        payment_method = :payment_method,
                        reference_number = :reference_number,
                        receipt_photo = :receipt_photo,
                        notes = :notes
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':expense_date'     => $expense_date,
                ':category'         => $category,
                ':title'            => $title,
                ':amount'           => $amount,
                ':payment_method'   => $payment_method,
                ':reference_number' => $reference_number ?: null,
                ':receipt_photo'    => $receipt_filename,
                ':notes'            => $notes ?: null,
                ':id'               => $id
            ]);

            log_activity($pdo, 'Updated Expense', "Updated expense #{$id}: '{$title}' (PHP " . number_format($amount, 2) . ")", 'Expenses');

            echo json_encode([
                'success' => true,
                'message' => 'Expense record updated successfully.',
                'expense_id' => $id
            ]);
            exit;
        } else {
            // Insert
            $sql = "INSERT INTO expenses (expense_date, category, title, amount, payment_method, reference_number, receipt_photo, notes, recorded_by)
                    VALUES (:expense_date, :category, :title, :amount, :payment_method, :reference_number, :receipt_photo, :notes, :recorded_by)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':expense_date'     => $expense_date,
                ':category'         => $category,
                ':title'            => $title,
                ':amount'           => $amount,
                ':payment_method'   => $payment_method,
                ':reference_number' => $reference_number ?: null,
                ':receipt_photo'    => $receipt_filename,
                ':notes'            => $notes ?: null,
                ':recorded_by'      => $user_id
            ]);

            $new_id = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Recorded Expense', "Created expense #{$new_id}: '{$title}' (PHP " . number_format($amount, 2) . ") in category '{$category}'", 'Expenses');

            echo json_encode([
                'success' => true,
                'message' => 'New expense recorded successfully.',
                'expense_id' => $new_id
            ]);
            exit;
        }
    }

    if ($action === 'delete_expense') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid expense ID.']);
            exit;
        }

        // Fetch to check existence and receipt
        $stmt = $pdo->prepare("SELECT title, amount, receipt_photo FROM expenses WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Expense not found or already deleted.']);
            exit;
        }

        if (!empty($row['receipt_photo']) && file_exists(__DIR__ . '/../' . $row['receipt_photo'])) {
            @unlink(__DIR__ . '/../' . $row['receipt_photo']);
        }

        $stmt_del = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt_del->execute([$id]);

        log_activity($pdo, 'Deleted Expense', "Deleted expense #{$id}: '{$row['title']}' (PHP " . number_format((float)$row['amount'], 2) . ")", 'Expenses');

        echo json_encode(['success' => true, 'message' => 'Expense record successfully removed.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request action.']);
    exit;

} catch (Exception $e) {
    error_log("Expenses AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error occurred: ' . $e->getMessage()]);
    exit;
}
