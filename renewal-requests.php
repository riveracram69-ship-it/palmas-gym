<?php
// Backward compatibility alias for Renewal Requests -> Pending Approvals Hub
$_GET['tab'] = $_GET['tab'] ?? 'renewals';
require __DIR__ . '/pending-approvals.php';
