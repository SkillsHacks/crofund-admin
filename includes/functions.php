<?php
function logAdminActivity($action, $details = '') {
    $db = new PDO(/* your connection details */);
    $stmt = $db->prepare("INSERT INTO admin_activity_log (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_SESSION['admin_id'], $action, $details, $_SERVER['REMOTE_ADDR']]);
}