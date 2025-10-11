<?php
// filepath: /Applications/AMPPS/www/remove_pledges_fixed.php
require 'config.php';

// Names to remove
$names_to_remove = [
];

try {
    $db->beginTransaction();
    
    // Get user IDs
    $placeholders = str_repeat('?,', count($names_to_remove) - 1) . '?';
    $stmt = $db->prepare("SELECT id, name FROM users WHERE name IN ($placeholders)");
    $stmt->execute($names_to_remove);
    $users_to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($users_to_delete) . " users to delete:\n";
    foreach ($users_to_delete as $user) {
        echo "- {$user['name']} (ID: {$user['id']})\n";
    }
    
    if (empty($users_to_delete)) {
        echo "No users found with those names.\n";
        $db->rollback();
        exit;
    }
    
    $user_ids = array_column($users_to_delete, 'id');
    $id_placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
    
    // Delete availability records
    $stmt = $db->prepare("DELETE FROM availabilities WHERE user_id IN ($id_placeholders)");
    $stmt->execute($user_ids);
    echo "Deleted availability records.\n";
    
    // Delete interview participants
    $stmt = $db->prepare("DELETE FROM interview_participants WHERE user_id IN ($id_placeholders)");
    $stmt->execute($user_ids);
    echo "Deleted interview participant records.\n";
    
    // Delete completed interviews where they were pledges
    $stmt = $db->prepare("DELETE FROM completed_interviews WHERE pledge_id IN ($id_placeholders)");
    $stmt->execute($user_ids);
    echo "Deleted completed interview records (as pledges).\n";
    
    // Check if there's an actives table or if actives are stored differently
    // Let's first check what columns exist in completed_interviews
    $stmt = $db->prepare("PRAGMA table_info(completed_interviews)");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Completed interviews table structure:\n";
    foreach ($columns as $col) {
        echo "- {$col['name']} ({$col['type']})\n";
    }
    
    // If there are active-related columns, delete those records too
    $active_columns = array_filter($columns, function($col) {
        return strpos(strtolower($col['name']), 'active') !== false;
    });
    
    if (!empty($active_columns)) {
        foreach ($active_columns as $col) {
            $col_name = $col['name'];
            $stmt = $db->prepare("DELETE FROM completed_interviews WHERE $col_name IN ($id_placeholders)");
            $stmt->execute($user_ids);
            echo "Deleted completed interview records (as $col_name).\n";
        }
    }
    
    // Delete users
    $stmt = $db->prepare("DELETE FROM users WHERE id IN ($id_placeholders)");
    $stmt->execute($user_ids);
    echo "Deleted user records.\n";
    
    $db->commit();
    echo "\n✅ Successfully removed all specified pledges and their associated data!\n";
    
} catch (Exception $e) {
    $db->rollback();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>