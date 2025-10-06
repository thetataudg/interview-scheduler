<?php
require 'config.php';

session_start();

// Require admin authentication
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$user_id = $_GET['user_id'] ?? null;
if (!$user_id) die("User not specified.");

$user = $db->prepare("SELECT * FROM users WHERE id=?");
$user->execute([$user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User not found.");

// Get current week availability (consistent with other files)
$current_base = strtotime('monday this week');
$current_week_start = date('Y-m-d', $current_base);
$current_week_end = date('Y-m-d', strtotime('+6 days', $current_base));
$current_avail = $db->prepare("SELECT slot_start, slot_end FROM availabilities WHERE user_id=? AND date(slot_start) BETWEEN ? AND ? ORDER BY slot_start");
$current_avail->execute([$user_id, $current_week_start, $current_week_end]);
$current_slots = $current_avail->fetchAll(PDO::FETCH_ASSOC);

// Get next week availability (consistent with other files)
$next_base = strtotime('next Monday');
$next_week_start = date('Y-m-d', $next_base);
$next_week_end = date('Y-m-d', strtotime('+6 days', $next_base));
$next_avail = $db->prepare("SELECT slot_start, slot_end FROM availabilities WHERE user_id=? AND date(slot_start) BETWEEN ? AND ? ORDER BY slot_start");
$next_avail->execute([$user_id, $next_week_start, $next_week_end]);
$next_slots = $next_avail->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Availability - <?=$user['name']?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .navbar-dark { background-color: #000 !important; }
  </style>
</head>
<body>
	<nav class="navbar navbar-dark bg-dark">
	<div class="container">
		<a class="navbar-brand text-white" href="index.php">Interview Scheduler</a>
		<div>
			<a class="btn btn-outline-light btn-sm" href="index.php">Home</a>
			<a class="btn btn-outline-light btn-sm" href="admin.php">Admin</a>
		</div>
	</div>
</nav>
<div class="container py-4">
  <h2>Availability for <?=$user['name']?></h2>
  
  <!-- Action buttons -->
  <div class="mb-4">
    <a href="availability.php?user_id=<?=$user_id?>&week=current" class="btn btn-primary btn-sm">Edit Current Week</a>
    <a href="availability.php?user_id=<?=$user_id?>&week=next" class="btn btn-primary btn-sm">Edit Next Week</a>
  </div>

  <div class="row">
    <!-- Current Week -->
    <div class="col-md-6">
      <h4>Current Week (<?=date('M j', $current_base)?> - <?=date('M j', strtotime('+6 days', $current_base))?>)</h4>
      <?php if ($current_slots): ?>
        <ul class="list-group mb-3">
          <?php foreach ($current_slots as $s): ?>
            <li class="list-group-item">
              <?=date('D M j, g A', strtotime($s['slot_start']))?> – <?=date('g A', strtotime($s['slot_end']))?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="alert alert-warning">No availability entered for current week.</div>
      <?php endif; ?>
    </div>

    <!-- Next Week -->
    <div class="col-md-6">
      <h4>Next Week (<?=date('M j', $next_base)?> - <?=date('M j', strtotime('+6 days', $next_base))?>)</h4>
      <?php if ($next_slots): ?>
        <ul class="list-group mb-3">
          <?php foreach ($next_slots as $s): ?>
            <li class="list-group-item">
              <?=date('D M j, g A', strtotime($s['slot_start']))?> – <?=date('g A', strtotime($s['slot_end']))?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="alert alert-warning">No availability entered for next week.</div>
      <?php endif; ?>
    </div>
  </div>

  <a href="admin.php" class="btn btn-secondary mt-3">Back</a>
</div>
</body>
</html>
