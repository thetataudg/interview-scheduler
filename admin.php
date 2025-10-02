<?php
require 'config.php';

session_start();

// Multi-admin password check
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $password = $_POST['password'];

        // Fetch all admin password hashes
        $stmt = $db->query("SELECT password_hash FROM admin_users");
        $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $authenticated = false;
        foreach ($hashes as $hash) {
            if (password_verify($password, $hash)) {
                $authenticated = true;
                break;
            }
        }

        if ($authenticated) {
            $_SESSION['admin_logged_in'] = true;
        } else {
            $error = "Incorrect password.";
        }
    }

    // Show login form if not authenticated
    if (!isset($_SESSION['admin_logged_in'])) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Admin Login</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="container py-4">
            <h2>Admin Login</h2>
            <?php if (!empty($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
            <form method="post">
                <div class="mb-3">
                    <label>Password</label>
                    <input type="password" class="form-control" name="password" required>
                </div>
                <button class="btn btn-primary">Login</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
}

// Get all users
$users_stmt = $db->query("SELECT id, name, role FROM users ORDER BY role, name");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$actives = array_filter($users, fn($u) => $u['role'] === 'active');
$pledges = array_filter($users, fn($u) => $u['role'] === 'pledge');

// Get this week's availability
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));

$avail_stmt = $db->prepare("SELECT DISTINCT user_id FROM availabilities WHERE date(slot_start) BETWEEN ? AND ?");
$avail_stmt->execute([$weekStart, $weekEnd]);
$avail_users = array_flip($avail_stmt->fetchAll(PDO::FETCH_COLUMN));

// Handle logging completed interviews
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_interview'])) {
    $active_id = $_POST['active_id'] ?? null;
    $pledge_id = $_POST['pledge_id'] ?? null;
    $date      = $_POST['date'] ?? null;
    $notes     = $_POST['notes'] ?? null;

    if ($active_id && $pledge_id && $date) {
        $stmt = $db->prepare("
            INSERT INTO completed_interviews (active_id, pledge_id, interview_date, notes)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$active_id, $pledge_id, $date, $notes]);
        header("Location: admin.php?saved=1");
        exit;
    }
}

// Handle deleting completed interviews
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_interview'])) {
    $interview_id = $_POST['interview_id'] ?? null;
    
    if ($interview_id) {
        $stmt = $db->prepare("DELETE FROM completed_interviews WHERE id = ?");
        $stmt->execute([$interview_id]);
        header("Location: admin.php?deleted=1");
        exit;
    }
}

// Fetch completed interviews
$completed_stmt = $db->query("
    SELECT ci.id, ci.interview_date, ci.notes,
           a.name AS active_name, p.name AS pledge_name
    FROM completed_interviews ci
    JOIN users a ON ci.active_id = a.id
    JOIN users p ON ci.pledge_id = p.id
    ORDER BY ci.interview_date DESC
");
$completed = $completed_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin - Interview Scheduler</title>
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
  <h2>Admin Dashboard</h2>
  <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Interview logged successfully!</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Interview deleted successfully!</div>
  <?php endif; ?>

  <!-- Full roster with availability highlighting -->
  <div class="card mb-4">
    <div class="card-header">Roster & Weekly Availability</div>
    <div class="card-body">
      <h5>Actives</h5>
      <ul>
        <?php foreach ($actives as $a): ?>
          <li style="background-color: <?=isset($avail_users[$a['id']]) ? 'transparent' : '#fff3cd'?>; padding: 4px;">
            <a href="view_availability.php?user_id=<?=$a['id']?>"><?=$a['name']?></a>
          </li>
        <?php endforeach; ?>
      </ul>
      <h5>Pledges</h5>
      <ul>
        <?php foreach ($pledges as $p): ?>
          <li style="background-color: <?=isset($avail_users[$p['id']]) ? 'transparent' : '#fff3cd'?>; padding: 4px;">
            <a href="view_availability.php?user_id=<?=$p['id']?>"><?=$p['name']?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <!-- Log a completed interview -->
  <div class="card mb-4">
    <div class="card-header">Log Completed Interview</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="log_interview" value="1">
        <div class="row mb-3">
          <div class="col">
            <label class="form-label">Active</label>
            <select class="form-select" name="active_id" required>
              <option value="">-- Select Active --</option>
              <?php foreach ($actives as $a): ?>
                <option value="<?=$a['id']?>"><?=$a['name']?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col">
            <label class="form-label">Pledge</label>
            <select class="form-select" name="pledge_id" required>
              <option value="">-- Select Pledge --</option>
              <?php foreach ($pledges as $p): ?>
                <option value="<?=$p['id']?>"><?=$p['name']?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Date</label>
          <input type="date" class="form-control" name="date" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="2"></textarea>
        </div>
        <button class="btn btn-success">Save Interview</button>
      </form>
    </div>
  </div>

  <!-- Suggested weekly pairings -->
  <div class="card mb-4">
    <div class="card-header">Suggested Weekly Pairings</div>
    <div class="card-body">
      <form method="post" action="generate_pairings.php">
        <button class="btn btn-primary" type="submit">Generate Suggested Weekly Pairings</button>
      </form>
    </div>
  </div>

  <!-- Completed interviews history -->
  <div class="card">
    <div class="card-header">Completed Interviews</div>
    <div class="card-body">
      <?php if ($completed): ?>
        <table class="table table-bordered">
          <thead>
            <tr>
              <th>Date</th>
              <th>Active</th>
              <th>Pledge</th>
              <th>Notes</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($completed as $c): ?>
              <tr>
                <td><?=htmlspecialchars($c['interview_date'])?></td>
                <td><?=htmlspecialchars($c['active_name'])?></td>
                <td><?=htmlspecialchars($c['pledge_name'])?></td>
                <td><?=htmlspecialchars($c['notes'])?></td>
                <td>
                  <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this interview record?');">
                    <input type="hidden" name="delete_interview" value="1">
                    <input type="hidden" name="interview_id" value="<?=$c['id']?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Delete Interview">
                      <i class="bi bi-trash"></i> Trash
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p>No completed interviews yet.</p>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
