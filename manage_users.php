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

// Handle mass deletion of users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $user_ids = $_POST['user_ids'] ?? [];
    
    if (!empty($user_ids) && is_array($user_ids)) {
        try {
            $deleted_names = [];
            $db->beginTransaction();
            
            foreach ($user_ids as $user_id) {
                $user_id = intval($user_id);
                if ($user_id <= 0) continue;
                
                // Get user name for confirmation message
                $name_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                $name_stmt->execute([$user_id]);
                $user_name = $name_stmt->fetchColumn();
                
                if ($user_name) {
                    // Delete associated records first
                    $db->prepare("DELETE FROM availabilities WHERE user_id = ?")->execute([$user_id]);
                    $db->prepare("DELETE FROM interview_participants WHERE user_id = ?")->execute([$user_id]);
                    $db->prepare("DELETE FROM completed_interviews WHERE active_id = ? OR pledge_id = ?")->execute([$user_id, $user_id]);
                    
                    // Delete the user
                    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
                    $deleted_names[] = $user_name;
                }
            }
            
            $db->commit();
            
            $deleted_count = count($deleted_names);
            $deleted_list = implode(', ', $deleted_names);
            header("Location: manage_users.php?mass_deleted=1&count={$deleted_count}&names=" . urlencode($deleted_list));
            exit;
        } catch (PDOException $e) {
            $db->rollback();
            $error = "Error deleting users: " . $e->getMessage();
        }
    } else {
        $error = "No users selected for deletion.";
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

// Handle adding a new admin account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $username = trim($_POST['admin_username'] ?? '');
    $password = $_POST['admin_password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            // Check if username already exists
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
            $check_stmt->execute([$username]);
            if ($check_stmt->fetchColumn() > 0) {
                $error = "Admin username already exists.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
                $stmt->execute([$username, $password_hash]);
                header("Location: manage_users.php?admin_added=1&admin_name=" . urlencode($username));
                exit;
            }
        } catch (PDOException $e) {
            $error = "Error adding admin: " . $e->getMessage();
        }
    } else {
        $error = "Please provide both username and password for the admin account.";
    }
}

// Handle deleting an admin account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    $admin_id = intval($_POST['admin_id'] ?? 0);
    
    if ($admin_id > 0) {
        try {
            // Get admin username for confirmation message
            $name_stmt = $db->prepare("SELECT username FROM admin_users WHERE id = ?");
            $name_stmt->execute([$admin_id]);
            $admin_username = $name_stmt->fetchColumn();
            
            // Check if this is the last admin account
            $count_stmt = $db->query("SELECT COUNT(*) FROM admin_users");
            $admin_count = $count_stmt->fetchColumn();
            
            if ($admin_count <= 1) {
                $error = "Cannot delete the last admin account. At least one admin account must exist.";
            } else {
                // Delete the admin
                $stmt = $db->prepare("DELETE FROM admin_users WHERE id = ?");
                $stmt->execute([$admin_id]);
                
                header("Location: manage_users.php?admin_deleted=1&admin_name=" . urlencode($admin_username));
                exit;
            }
        } catch (PDOException $e) {
            $error = "Error deleting admin: " . $e->getMessage();
        }
    }
}

$users_stmt = $db->query("SELECT id, name, role, COALESCE(exclude_from_pairings,0) AS exclude_from_pairings FROM users ORDER BY role, name");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$actives = array_filter($users, fn($u) => $u['role'] === 'active');
$pledges = array_filter($users, fn($u) => $u['role'] === 'pledge');

// Fetch admin accounts
$admin_stmt = $db->query("SELECT id, username FROM admin_users ORDER BY username");
$admins = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
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
      <span class="text-white me-2">
        <i class="fas fa-user-shield"></i> <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>
      </span>
      <a class="btn btn-danger btn-sm" href="logout.php">
        <i class="fas fa-sign-out-alt"></i> Logout
      </a>
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

  <?php if (isset($_GET['admin_added'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <strong><i class="fas fa-check-circle"></i> Admin Added!</strong><br>
      Successfully added admin: <?= htmlspecialchars($_GET['admin_name']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['admin_deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <strong><i class="fas fa-check-circle"></i> Admin Deleted!</strong><br>
      Successfully deleted admin: <?= htmlspecialchars($_GET['admin_name']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['mass_deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <strong><i class="fas fa-check-circle"></i> Users Deleted!</strong><br>
      Successfully deleted <?= intval($_GET['count']) ?> user(s): <?= htmlspecialchars($_GET['names']) ?>
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

  <!-- Admin Accounts Management -->
  <div class="card mb-4">
    <div class="card-header bg-dark text-white">
      <i class="fas fa-user-shield"></i> Admin Accounts (<?= count($admins) ?>)
    </div>
    <div class="card-body">
      <div class="row">
        <!-- Add New Admin Form -->
        <div class="col-md-6 mb-3">
          <h5><i class="fas fa-user-plus"></i> Add New Admin</h5>
          <form method="post">
            <input type="hidden" name="add_admin" value="1">
            <div class="mb-3">
              <label class="form-label">Username</label>
              <input type="text" class="form-control" name="admin_username" required placeholder="Enter admin username">
            </div>
            <div class="mb-3">
              <label class="form-label">Password</label>
              <input type="password" class="form-control" name="admin_password" required placeholder="Enter password">
            </div>
            <button type="submit" class="btn btn-dark">
              <i class="fas fa-plus"></i> Add Admin
            </button>
          </form>
        </div>

        <!-- List of Admin Accounts -->
        <div class="col-md-6">
          <h5><i class="fas fa-list"></i> Current Admins</h5>
          <?php if (count($admins) > 0): ?>
            <div class="list-group">
              <?php foreach ($admins as $admin): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <i class="fas fa-user-shield text-dark"></i>
                    <strong><?= htmlspecialchars($admin['username']) ?></strong>
                  </div>
                  <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this admin account: <?= htmlspecialchars($admin['username']) ?>?');">
                    <input type="hidden" name="delete_admin" value="1">
                    <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Admin" <?= count($admins) <= 1 ? 'disabled' : '' ?>>
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($admins) <= 1): ?>
              <small class="text-muted d-block mt-2">
                <i class="fas fa-info-circle"></i> Cannot delete the last admin account
              </small>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-muted mb-0">No admin accounts found.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

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
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
      <div>
        <i class="fas fa-user-tie"></i> Actives (<?= count($actives) ?>)
      </div>
      <button type="button" class="btn btn-danger btn-sm" onclick="deleteSelectedUsers('actives')" id="deleteActivesBtn" disabled>
        <i class="fas fa-trash"></i> Delete Selected
      </button>
    </div>
    <div class="card-body">
      <?php if (count($actives) > 0): ?>
        <form method="post" id="deleteActivesForm">
          <input type="hidden" name="delete_selected" value="1">
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th style="width: 40px;">
                    <input type="checkbox" class="form-check-input" id="selectAllActives" onchange="toggleSelectAll('actives')">
                  </th>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($actives as $user): ?>
                  <tr>
                    <td>
                      <input type="checkbox" class="form-check-input user-checkbox-actives" name="user_ids[]" value="<?= $user['id'] ?>" onchange="updateDeleteButton('actives')">
                    </td>
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
        </form>
      <?php else: ?>
        <p class="text-muted mb-0">No actives found.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pledges List -->
  <div class="card mb-4">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
      <div>
        <i class="fas fa-user"></i> Pledges (<?= count($pledges) ?>)
      </div>
      <button type="button" class="btn btn-danger btn-sm" onclick="deleteSelectedUsers('pledges')" id="deletePledgesBtn" disabled>
        <i class="fas fa-trash"></i> Delete Selected
      </button>
    </div>
    <div class="card-body">
      <?php if (count($pledges) > 0): ?>
        <form method="post" id="deletePledgesForm">
          <input type="hidden" name="delete_selected" value="1">
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th style="width: 40px;">
                    <input type="checkbox" class="form-check-input" id="selectAllPledges" onchange="toggleSelectAll('pledges')">
                  </th>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pledges as $user): ?>
                  <tr>
                    <td>
                      <input type="checkbox" class="form-check-input user-checkbox-pledges" name="user_ids[]" value="<?= $user['id'] ?>" onchange="updateDeleteButton('pledges')">
                    </td>
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
        </form>
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
<script>
// Toggle select all checkboxes
function toggleSelectAll(type) {
  const selectAllCheckbox = document.getElementById('selectAll' + type.charAt(0).toUpperCase() + type.slice(1));
  const checkboxes = document.querySelectorAll('.user-checkbox-' + type);
  
  checkboxes.forEach(checkbox => {
    checkbox.checked = selectAllCheckbox.checked;
  });
  
  updateDeleteButton(type);
}

// Update delete button state
function updateDeleteButton(type) {
  const checkboxes = document.querySelectorAll('.user-checkbox-' + type + ':checked');
  const deleteBtn = document.getElementById('delete' + type.charAt(0).toUpperCase() + type.slice(1) + 'Btn');
  const selectAllCheckbox = document.getElementById('selectAll' + type.charAt(0).toUpperCase() + type.slice(1));
  const allCheckboxes = document.querySelectorAll('.user-checkbox-' + type);
  
  // Enable/disable delete button based on selection
  deleteBtn.disabled = checkboxes.length === 0;
  
  // Update select all checkbox state
  if (checkboxes.length === allCheckboxes.length && allCheckboxes.length > 0) {
    selectAllCheckbox.checked = true;
    selectAllCheckbox.indeterminate = false;
  } else if (checkboxes.length === 0) {
    selectAllCheckbox.checked = false;
    selectAllCheckbox.indeterminate = false;
  } else {
    selectAllCheckbox.checked = false;
    selectAllCheckbox.indeterminate = true;
  }
}

// Delete selected users
function deleteSelectedUsers(type) {
  const checkboxes = document.querySelectorAll('.user-checkbox-' + type + ':checked');
  
  if (checkboxes.length === 0) {
    alert('Please select at least one user to delete.');
    return;
  }
  
  const userNames = [];
  checkboxes.forEach(checkbox => {
    const row = checkbox.closest('tr');
    const nameCell = row.querySelector('td:nth-child(3)');
    userNames.push(nameCell.textContent.trim());
  });
  
  const confirmMessage = `Are you sure you want to delete ${checkboxes.length} user(s)?\n\n` +
                        `Users to be deleted:\n${userNames.join('\n')}\n\n` +
                        `This will also delete all their availability and interview records.`;
  
  if (confirm(confirmMessage)) {
    const formId = 'delete' + type.charAt(0).toUpperCase() + type.slice(1) + 'Form';
    document.getElementById(formId).submit();
  }
}
</script>
</body>
</html>
