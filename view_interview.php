<?php
require 'config.php';
require_admin();

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: admin.php'); exit; }

// fetch interview
$stmt = $db->prepare("SELECT * FROM interviews WHERE id = ?");
$stmt->execute([$id]);
$iv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$iv) { echo "Interview not found"; exit; }

// participants
$stmt = $db->prepare("SELECT p.*, u.name FROM interview_participants p JOIN users u ON u.id=p.user_id WHERE p.interview_id = ?");
$stmt->execute([$id]);
$parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// if admin clicks mark completed on this page
if (isset($_POST['mark_completed'])) {
    // record completed_interviews rows for each active-pledge pair
    $actives = array_filter($parts, function($r){ return $r['role']=='active';});
    $pledges = array_filter($parts, function($r){ return $r['role']=='pledge';});
    $ins = $db->prepare("INSERT INTO completed_interviews (interview_id, active_id, pledge_id, slot_start, slot_end) VALUES (?, ?, ?, ?, ?)");
    foreach ($actives as $a) foreach ($pledges as $p) {
        $ins->execute([$id, $a['user_id'], $p['user_id'], $iv['slot_start'], $iv['slot_end']]);
    }
    $db->prepare("UPDATE interviews SET completed = 1 WHERE id = ?")->execute([$id]);
    header('Location: view_interview.php?id='.$id);
    exit;
}

// mark as no-show / did not occur
if (isset($_POST['mark_no'])) {
    $db->prepare("UPDATE interviews SET completed = 0 WHERE id = ?")->execute([$id]);
    // optionally you might add a different flag; for now we just mark not completed and delete any completed_interviews rows associated with this interview
    $db->prepare("DELETE FROM completed_interviews WHERE interview_id = ?")->execute([$id]);
    header('Location: view_interview.php?id='.$id);
    exit;
}

// delete interview
if (isset($_POST['delete_interview'])) {
    $db->prepare("DELETE FROM interviews WHERE id = ?")->execute([$id]);
    header('Location: admin.php');
    exit;
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>View Interview #<?= $iv['id'] ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.navbar-dark { background:#000; }</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <div class="container">
    <a class="navbar-brand" href="admin.php">Interview Scheduler</a>
    <div>
      <a class="btn btn-outline-light btn-sm" href="admin.php">Back</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <h1>Interview #<?= $iv['id'] ?></h1>
  <p><strong>Slot:</strong> <?= h($iv['slot_start']) ?> — <?= h($iv['slot_end']) ?></p>
  <p><strong>Group size:</strong> <?= (int)$iv['group_size'] ?> | <strong>Completed?</strong> <?= $iv['completed'] ? 'Yes' : 'No' ?></p>

  <h3>Participants</h3>
  <ul>
    <?php foreach ($parts as $p): ?>
      <li><?= h($p['role']) ?>: <?= h($p['name']) ?> (user id: <?= $p['user_id'] ?>)</li>
    <?php endforeach; ?>
  </ul>

  <form method="post" class="d-inline">
    <?php if (!$iv['completed']): ?>
      <button name="mark_completed" class="btn btn-primary">Mark Completed</button>
    <?php else: ?>
      <button name="mark_no" class="btn btn-warning">Mark as Not Occurred / Undo</button>
    <?php endif; ?>
  </form>

  <form method="post" class="d-inline" onsubmit="return confirm('Delete this interview?');">
    <button name="delete_interview" class="btn btn-danger">Delete Interview</button>
  </form>

  <hr>
  <h4>Historical pairings for these participants</h4>
  <div>
    <?php
      // show counts for active-pledge pairs among participants
      $actives = array_filter($parts, fn($r)=>$r['role']=='active');
      $pledges  = array_filter($parts, fn($r)=>$r['role']=='pledge');
      foreach ($actives as $a) {
          foreach ($pledges as $p) {
              $cnt = (int)$db->query("SELECT COUNT(*) FROM completed_interviews WHERE active_id=".intval($a['user_id'])." AND pledge_id=".intval($p['user_id']))->fetchColumn();
              echo h($a['name'])." — ".h($p['name']).": ".$cnt."<br>";
          }
      }
    ?>
  </div>

</div>
</body>
</html>
