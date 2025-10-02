<?php
require 'config.php';

$user_id = $_GET['user_id'] ?? 1; // stub user
$week = $_GET['week'] ?? 'next'; // 'current' or 'next'

// Fetch the user name
$stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_name = $stmt->fetchColumn();
if (!$user_name) $user_name = "Unknown User";

// Generate slots based on selected week
if ($week === 'current') {
    $base = strtotime('monday this week');
    $week_label = "Current Week";
} else {
    $base = strtotime('next Monday');
    $week_label = "Next Week";
}

// Load existing availability for the selected week
$week_start = date('Y-m-d', $base);
$week_end = date('Y-m-d', strtotime('+6 days', $base));
$avail_stmt = $db->prepare("SELECT slot_start FROM availabilities WHERE user_id=? AND date(slot_start) BETWEEN ? AND ?");
$avail_stmt->execute([$user_id, $week_start, $week_end]);
$existing = $avail_stmt->fetchAll(PDO::FETCH_COLUMN);
$existing = array_map('strtotime', $existing);

// Handle form post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $week_param = $_POST['week'] ?? 'next';
    // Delete only the availability for the selected week
    $delete_stmt = $db->prepare("DELETE FROM availabilities WHERE user_id=? AND date(slot_start) BETWEEN ? AND ?");
    $delete_stmt->execute([$user_id, $week_start, $week_end]);
    
    if (!empty($_POST['slots'])) {
        $stmt = $db->prepare("INSERT INTO availabilities(user_id, slot_start, slot_end) VALUES (?,?,?)");
        foreach ($_POST['slots'] as $s) {
            [$sstart,$send] = explode('|',$s);
            $stmt->execute([$user_id, date('c',$sstart), date('c',$send)]);
        }
    }
    header("Location: availability.php?user_id=$user_id&week=$week_param&saved=1");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Availability</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .navbar-dark { background-color: #000 !important; }

    .slot {
      cursor: pointer;
      padding: 0.5rem;
      border-radius: 4px;
    }
    .available {
      background-color: #198754 !important; /* Bootstrap green */
      color: white;
    }
    .unavailable {
      background-color: #6c757d !important; /* Bootstrap gray */
      color: white;
    }
    .blocked {
      background-color: #6c757d !important;
      opacity: 0.5;
      cursor: not-allowed;
      color: white;
    }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand text-white" href="index.php">Interview Scheduler</a>
    <div>
      <a class="btn btn-outline-light btn-sm" href="index.php">Home</a>
      <a class="btn btn-outline-light btn-sm" href="admin.php">Admin</a>
    </div>
  </div>
</nav>

<div class="container py-2">
<h2>Set Availability for <?= htmlspecialchars($user_name) ?> - <?= $week_label ?></h2>
<br />
<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-success">Availability saved for <?= $week_label ?>!</div>
<?php endif; ?>

<!-- Week Selector -->
<div class="mb-3">
  <div class="btn-group" role="group" aria-label="Week selector">
    <a href="availability.php?user_id=<?= $user_id ?>&week=current" 
       class="btn <?= $week === 'current' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Current Week (<?= date('M j', strtotime('monday this week')) ?> - <?= date('M j', strtotime('sunday this week')) ?>)
    </a>
    <a href="availability.php?user_id=<?= $user_id ?>&week=next" 
       class="btn <?= $week === 'next' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Next Week (<?= date('M j', strtotime('next monday')) ?> - <?= date('M j', strtotime('next sunday')) ?>)
    </a>
  </div>
</div>

<form method="post" id="availabilityForm">
  <input type="hidden" name="week" value="<?= htmlspecialchars($week) ?>">
  <table class="table table-bordered text-center align-middle">
    <tr>
      <th>Time</th>
      <?php for ($d=0; $d<7; $d++): ?>
        <th><?=date('D', strtotime("+$d day", $base))?></th>
      <?php endfor; ?>
    </tr>

    <?php for ($h=8; $h<19; $h++): ?>
      <tr>
        <th><?=$h?>:00 - <?=($h+1)?>:00</th>
        <?php for ($d=0; $d<7; $d++):
          $start = strtotime("+$d day $h:00", $base);
          $end = $start + 3600;
          $val = $start."|".$end;

          $dayOfWeek = date('D', $start);
          $hour = (int)date('H', $start);

          $isAvailable = in_array($start, $existing);

          // Block Tuesday/Thursday 6pm+
          $isBlocked = (($dayOfWeek === 'Tue' || $dayOfWeek === 'Thu') && $hour >= 18);

          if ($isBlocked): ?>
            <td><div class="slot blocked">Unavailable</div></td>
          <?php else: ?>
            <td>
              <div class="slot <?= $isAvailable ? 'available' : 'unavailable' ?>" data-value="<?=$val?>">
                <?= $isAvailable ? 'Available' : 'Not Available' ?>
              </div>
              <input type="checkbox" name="slots[]" value="<?=$val?>" <?= $isAvailable ? 'checked' : '' ?> hidden>
            </td>
          <?php endif; ?>
        <?php endfor; ?>
      </tr>
    <?php endfor; ?>
  </table>
  <button class="btn btn-primary">Save Availability</button>
</form>

<script>
document.querySelectorAll('.slot').forEach(slot => {
  if (!slot.classList.contains('blocked')) { // only allow toggle if not blocked
    slot.addEventListener('click', () => {
      const checkbox = slot.parentElement.querySelector('input[type="checkbox"]');
      checkbox.checked = !checkbox.checked;
      if (checkbox.checked) {
        slot.classList.remove('unavailable');
        slot.classList.add('available');
        slot.textContent = 'Available';
      } else {
        slot.classList.remove('available');
        slot.classList.add('unavailable');
        slot.textContent = 'Not Available';
      }
    });
  }
});
</script>
</div>
</body>
</html>
