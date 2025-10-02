<?php
require 'config.php';
require_admin();

$iid = intval($_GET['id'] ?? 0);
if (!$iid) { header('Location: admin.php'); exit; }

$stmt = $db->prepare("SELECT user_id, role FROM interview_participants WHERE interview_id = ?");
$stmt->execute([$iid]);
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$actives = []; $pledges = [];
foreach ($parts as $p) if ($p['role']=='active') $actives[]=$p['user_id']; else $pledges[]=$p['user_id'];

if ($actives && $pledges) {
    $iRow = $db->prepare("SELECT slot_start, slot_end FROM interviews WHERE id = ?");
    $iRow->execute([$iid]);
    $slot = $iRow->fetch(PDO::FETCH_ASSOC);
    $ins = $db->prepare("INSERT INTO completed_interviews (interview_id, active_id, pledge_id, slot_start, slot_end) VALUES (?, ?, ?, ?, ?)");
    foreach ($actives as $a) foreach ($pledges as $p) $ins->execute([$iid, $a, $p, $slot['slot_start'], $slot['slot_end']]);
    $db->prepare("UPDATE interviews SET completed=1 WHERE id=?")->execute([$iid]);
}

header('Location: admin.php');
exit;
