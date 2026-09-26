<?php
// Backward compatibility alias for Pending Registrations -> Pending Approvals Hub
$_GET['tab'] = $_GET['tab'] ?? 'registrations';
require __DIR__ . '/pending-approvals.php';
