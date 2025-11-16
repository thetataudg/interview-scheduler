<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';

// Check if admin is logged in (matching admin.php's session variable)
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

// Ensure users table has `exclude_from_pairings` column. Add if missing (safe migration).
try {
  $col_stmt = $db->query("PRAGMA table_info(users)");
  $cols = $col_stmt->fetchAll(PDO::FETCH_ASSOC);
  $has_exclude = false;
  foreach ($cols as $c) {
    if (isset($c['name']) && $c['name'] === 'exclude_from_pairings') { $has_exclude = true; break; }
  }
  if (!$has_exclude) {
    $db->exec("ALTER TABLE users ADD COLUMN exclude_from_pairings INTEGER DEFAULT 0");
  }
} catch (Exception $e) {
  // ignore migration errors; page will still work but toggle feature may error
}

// Handle adding a new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';
    
    if (!empty($name) && in_array($role, ['active', 'pledge'])) {
        try {
            $stmt = $db->prepare("INSERT INTO users (name, role) VALUES (?, ?)");
            $stmt->execute([$name, $role]);
            header("Location: manage_users.php?added=1&name=" . urlencode($name));
            exit;
        } catch (PDOException $e) {
            $error = "Error adding user: " . $e->getMessage();
        }
    } else {
        $error = "Please provide a valid name and role.";
    }
}

// Handle deleting a user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    
    if ($user_id > 0) {
        try {
            // Get user name for confirmation message
            $name_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $name_stmt->execute([$user_id]);
            $user_name = $name_stmt->fetchColumn();
            
            // Delete associated records first
            $db->prepare("DELETE FROM availabilities WHERE user_id = ?")->execute([$user_id]);
            $db->prepare("DELETE FROM interview_participants WHERE user_id = ?")->execute([$user_id]);
            $db->prepare("DELETE FROM completed_interviews WHERE active_id = ? OR pledge_id = ?")->execute([$user_id, $user_id]);
            
            // Delete the user
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            
            header("Location: manage_users.php?deleted=1&name=" . urlencode($user_name));
            exit;
        } catch (PDOException $e) {
            $error = "Error deleting user: " . $e->getMessage();
        }
    }
}

// Handle toggling exclude_from_pairings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exclude'])) {
  $user_id = intval($_POST['user_id'] ?? 0);
  if ($user_id > 0) {
    try {
      // flip the flag
      $cur = $db->prepare("SELECT COALESCE(exclude_from_pairings,0) FROM users WHERE id = ?");
      $cur->execute([$user_id]);
      $val = (int)$cur->fetchColumn();
      $new = $val ? 0 : 1;
      $upd = $db->prepare("UPDATE users SET exclude_from_pairings = ? WHERE id = ?");
      $upd->execute([$new, $user_id]);
      header("Location: manage_users.php?toggle=1&user_id={$user_id}&new={$new}");
      exit;
    } catch (Exception $e) {
      $error = "Error toggling exclude flag: " . $e->getMessage();
    }
  }
}

$users_stmt = $db->query("SELECT id, name, role, COALESCE(exclude_from_pairings,0) AS exclude_from_pairings FROM users ORDER BY role, name");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$actives = array_filter($users, fn($u) => $u['role'] === 'active');
$pledges = array_filter($users, fn($u) => $u['role'] === 'pledge');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>User Management - Interview Scheduler</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
      <a class="btn btn-outline-light btn-sm" href="manage_users.php">Manage Users</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-users"></i> User Management</h2>
    <a href="admin.php" class="btn btn-secondary">
      <i class="fas fa-arrow-left"></i> Back to Admin
    </a>
  </div>

  <?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <strong><i class="fas fa-check-circle"></i> User Added!</strong><br>
      Successfully added: <?= htmlspecialchars($_GET['name']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <strong><i class="fas fa-check-circle"></i> User Deleted!</strong><br>
      Successfully deleted: <?= htmlspecialchars($_GET['name']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <strong><i class="fas fa-exclamation-triangle"></i> Error!</strong><br>
      <?= htmlspecialchars($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Add New User -->
  <div class="card mb-4">
    <div class="card-header bg-primary text-white">
      <i class="fas fa-user-plus"></i> Add New User
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="add_user" value="1">
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" required placeholder="Enter full name">
          </div>
          <div class="col-md-4 mb-3">
            <label class="form-label">Role</label>
            <select class="form-select" name="role" required>
              <option value="">-- Select Role --</option>
              <option value="active">Active</option>
              <option value="pledge">Pledge</option>
            </select>
          </div>
          <div class="col-md-2 mb-3 d-flex align-items-end">
            <button type="submit" class="btn btn-success w-100">
              <i class="fas fa-plus"></i> Add User
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Actives List -->
  <div class="card mb-4">
    <div class="card-header bg-success text-white">
      <i class="fas fa-user-tie"></i> Actives (<?= count($actives) ?>)
    </div>
    <div class="card-body">
      <?php if (count($actives) > 0): ?>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($actives as $user): ?>
                <tr>
                  <td><?= $user['id'] ?></td>
                  <td>
                    <?php if (!empty($user['exclude_from_pairings'])): ?>
                      <span class="text-muted fst-italic"><?= htmlspecialchars($user['name']) ?></span>
                    <?php else: ?>
                      <?= htmlspecialchars($user['name']) ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="view_availability.php?user_id=<?= $user['id'] ?>" class="btn btn-sm btn-info" title="View Availability">
                      <i class="fas fa-calendar"></i>
                    </a>
                    <form method="post" style="display: inline; margin-left:6px;">
                      <input type="hidden" name="toggle_exclude" value="1">
                      <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                      <?php if (!empty($user['exclude_from_pairings'])): ?>
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Include in pairings">
                          <i class="fas fa-play"></i>
                        </button>
                      <?php else: ?>
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Exclude from pairings">
                          <i class="fas fa-stop"></i>
                        </button>
                      <?php endif; ?>
                    </form>
                    <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete <?= htmlspecialchars($user['name']) ?>? This will also delete all their availability and interview records.');">
                      <input type="hidden" name="delete_user" value="1">
                      <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger" title="Delete User">
                        <i class="fas fa-trash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-muted mb-0">No actives found.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pledges List -->
  <div class="card mb-4">
    <div class="card-header bg-warning text-dark">
      <i class="fas fa-user"></i> Pledges (<?= count($pledges) ?>)
    </div>
    <div class="card-body">
      <?php if (count($pledges) > 0): ?>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pledges as $user): ?>
                <tr>
                  <td><?= $user['id'] ?></td>
                  <td>
                    <?php if (!empty($user['exclude_from_pairings'])): ?>
                      <span class="text-muted fst-italic"><?= htmlspecialchars($user['name']) ?></span>
                    <?php else: ?>
                      <?= htmlspecialchars($user['name']) ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="view_availability.php?user_id=<?= $user['id'] ?>" class="btn btn-sm btn-info" title="View Availability">
                      <i class="fas fa-calendar"></i>
                    </a>
                    <form method="post" style="display: inline; margin-left:6px;">
                      <input type="hidden" name="toggle_exclude" value="1">
                      <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                      <?php if (!empty($user['exclude_from_pairings'])): ?>
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Include in pairings">
                          <i class="fas fa-play"></i>
                        </button>
                      <?php else: ?>
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Exclude from pairings">
                          <i class="fas fa-stop"></i>
                        </button>
                      <?php endif; ?>
                    </form>
                    <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete <?= htmlspecialchars($user['name']) ?>? This will also delete all their availability and interview records.');">
                      <input type="hidden" name="delete_user" value="1">
                      <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger" title="Delete User">
                        <i class="fas fa-trash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-muted mb-0">No pledges found.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="alert alert-info">
    <strong><i class="fas fa-info-circle"></i> Note:</strong> 
    Deleting a user will also remove all their availability slots, interview participants records, and completed interview records.
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
