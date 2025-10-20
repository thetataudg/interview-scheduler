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

// Get availability for both current and next week
$current_base = strtotime('monday this week');
$next_base = strtotime('next Monday');

$current_week_start = date('Y-m-d', $current_base);
$current_week_end = date('Y-m-d', strtotime('+6 days', $current_base));

$next_week_start = date('Y-m-d', $next_base);
$next_week_end = date('Y-m-d', strtotime('+6 days', $next_base));

// Get current week availability
$current_avail_stmt = $db->prepare("SELECT DISTINCT user_id FROM availabilities WHERE date(slot_start) BETWEEN ? AND ?");
$current_avail_stmt->execute([$current_week_start, $current_week_end]);
$current_avail_users = array_flip($current_avail_stmt->fetchAll(PDO::FETCH_COLUMN));

// Get next week availability
$next_avail_stmt = $db->prepare("SELECT DISTINCT user_id FROM availabilities WHERE date(slot_start) BETWEEN ? AND ?");
$next_avail_stmt->execute([$next_week_start, $next_week_end]);
$next_avail_users = array_flip($next_avail_stmt->fetchAll(PDO::FETCH_COLUMN));

// Helper function to determine availability badge
function getAvailabilityBadge($user_id, $current_avail, $next_avail) {
    $has_current = isset($current_avail[$user_id]);
    $has_next = isset($next_avail[$user_id]);
    
    if ($has_current && $has_next) {
        return '<span class="badge bg-success ms-2"><i class="fas fa-check"></i> Both Weeks</span>';
    } elseif ($has_current && !$has_next) {
        return '<span class="badge bg-success ms-2"><i class="fas fa-check"></i> Current Only</span>';
    } elseif (!$has_current && $has_next) {
        return '<span class="badge bg-info ms-2"><i class="fas fa-exclamation-triangle"></i> Next Only</span>';
    } else {
        return '<span class="badge bg-warning text-dark ms-2"><i class="fas fa-times"></i> Missing Both</span>';
    }
}

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

// Handle CSV upload of completed interviews
if (isset($_POST['upload_csv']) && isset($_FILES['csv_file'])) {
    $csv_date = $_POST['csv_date'] ?? date('Y-m-d');
    $csv_notes = $_POST['csv_notes'] ?? '';
    
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $upload_results = processCsvUpload($_FILES['csv_file'], $csv_date, $csv_notes, $db);
        
        // Redirect with results
        $params = http_build_query($upload_results);
        header("Location: admin.php?csv_uploaded=1&{$params}");
        exit;
    }
}

// Handle cleanup duplicates
if (isset($_POST['cleanup_duplicates'])) {
    // First, get the records that will be removed (duplicates)
    $duplicates_stmt = $db->query("
        SELECT ci.id, ci.interview_date, a.name as active_name, p.name as pledge_name
        FROM completed_interviews ci
        JOIN users a ON ci.active_id = a.id
        JOIN users p ON ci.pledge_id = p.id
        WHERE ci.id NOT IN (
            SELECT MIN(id) 
            FROM completed_interviews 
            GROUP BY active_id, pledge_id
        )
        ORDER BY active_name, pledge_name, interview_date
    ");
    $duplicates = $duplicates_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get the IDs to keep (minimum ID for each unique active/pledge pair)
    $keep_ids_stmt = $db->query("
        SELECT MIN(id) as keep_id 
        FROM completed_interviews 
        GROUP BY active_id, pledge_id
    ");
    $keep_ids = $keep_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $removed_count = 0;
    $removed_details = [];
    
    if (count($keep_ids) > 0 && count($duplicates) > 0) {
        // Store details of what's being removed
        foreach ($duplicates as $dup) {
            $removed_details[] = $dup['active_name'] . ' & ' . $dup['pledge_name'] . ' (' . $dup['interview_date'] . ')';
        }
        
        $keep_ids_str = implode(',', $keep_ids);
        $cleanup_result = $db->prepare("DELETE FROM completed_interviews WHERE id NOT IN ({$keep_ids_str})");
        $cleanup_result->execute();
        $removed_count = $cleanup_result->rowCount();
    }
    
    // Encode the removed details for URL
    $removed_details_encoded = base64_encode(json_encode($removed_details));
    header("Location: admin.php?cleanup_done=1&removed={$removed_count}&details={$removed_details_encoded}");
    exit;
}

function processCsvUpload($file, $date, $notes, $db) {
    $results = ['added' => 0, 'skipped' => 0, 'errors' => 0, 'error_details' => []];
    
    // Read CSV file
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $results['errors']++;
        $results['error_details'][] = "Could not read CSV file";
        return $results;
    }
    
    // Skip header row
    $header = fgetcsv($handle);
    
    // Get all users for name lookup
    $users_stmt = $db->query("SELECT id, name, role FROM users");
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create lookup arrays
    $user_lookup = [];
    foreach ($users as $user) {
        $user_lookup[trim(strtolower($user['name']))] = $user;
    }
    
    // Get existing completed interviews to check for duplicates (ignoring date)
    $existing_stmt = $db->query("SELECT active_id, pledge_id FROM completed_interviews");
    $existing_pairs = [];
    foreach ($existing_stmt->fetchAll(PDO::FETCH_ASSOC) as $pair) {
        // Create a standardized key to check for duplicates (both directions)
        $key1 = $pair['active_id'] . '_' . $pair['pledge_id'];
        $key2 = $pair['pledge_id'] . '_' . $pair['active_id']; // Check reverse too
        $existing_pairs[$key1] = true;
        $existing_pairs[$key2] = true;
    }
    
    $line_number = 1; // Start at 1 (after header)
    while (($row = fgetcsv($handle)) !== FALSE) {
        $line_number++;
        
        if (count($row) < 2) {
            $results['errors']++;
            $results['error_details'][] = "Line {$line_number}: Not enough columns";
            continue;
        }
        
        $pledge_name = trim($row[0]);
        $active_name = trim($row[1]);
        
        if (empty($pledge_name) || empty($active_name)) {
            $results['errors']++;
            $results['error_details'][] = "Line {$line_number}: Empty pledge or active name";
            continue;
        }
        
        // Look up user IDs
        $pledge_key = strtolower($pledge_name);
        $active_key = strtolower($active_name);
        
        $pledge_user = $user_lookup[$pledge_key] ?? null;
        $active_user = $user_lookup[$active_key] ?? null;
        
        if (!$pledge_user) {
            $results['errors']++;
            $results['error_details'][] = "Line {$line_number}: Pledge '{$pledge_name}' not found";
            continue;
        }
        
        if (!$active_user) {
            $results['errors']++;
            $results['error_details'][] = "Line {$line_number}: Active '{$active_name}' not found";
            continue;
        }
        
        // Verify roles
        if ($pledge_user['role'] !== 'pledge') {
            $results['errors']++;
            $results['error_details'][] = "Line {$line_number}: '{$pledge_name}' is not a pledge";
            continue;
        }
        
        if ($active_user['role'] !== 'active') {
            $results['errors']++;
            $results['error_details'][] = "Line {$line_number}: '{$active_name}' is not an active";
            continue;
        }
        
        // Check for duplicate (check both possible key combinations)
        $pair_key1 = $active_user['id'] . '_' . $pledge_user['id'];
        $pair_key2 = $pledge_user['id'] . '_' . $active_user['id'];
        if (isset($existing_pairs[$pair_key1]) || isset($existing_pairs[$pair_key2])) {
            $results['skipped']++;
            continue;
        }
        
        // Double-check for duplicates with a direct database query (ignoring date)
        $duplicate_check = $db->prepare("
            SELECT COUNT(*) FROM completed_interviews 
            WHERE active_id = ? AND pledge_id = ?
        ");
        $duplicate_check->execute([$active_user['id'], $pledge_user['id']]);
        
        if ($duplicate_check->fetchColumn() > 0) {
            $results['skipped']++;
            continue;
        }
        
        // Insert new interview record
        try {
            $insert_stmt = $db->prepare("
                INSERT INTO completed_interviews (active_id, pledge_id, interview_date, notes)
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->execute([$active_user['id'], $pledge_user['id'], $date, $notes]);
            
            // Mark as existing to prevent duplicates within the same CSV
            $existing_pairs[$pair_key1] = true;
            $existing_pairs[$pair_key2] = true;
            $results['added']++;

        } catch (Exception $e) {
            $results['errors']++;
            $results['error_details'][] = "Line {$line_number}: Database error - " . $e->getMessage();
        }
    }
    
    fclose($handle);
    return $results;
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
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    .navbar-dark { background-color: #000 !important; }
    
    /* Material Design Progress Bar */
    .material-progress {
      width: 300px;
      height: 4px;
      background: #e0e0e0;
      border-radius: 2px;
      overflow: hidden;
      position: relative;
      margin: 0 auto;
    }
    
    .material-progress-bar {
      height: 100%;
      background: linear-gradient(90deg, #2196F3, #21CBF3);
      border-radius: 2px;
      position: absolute;
      left: -100%;
      animation: material-indeterminate 0.8375s infinite;
    }
    
    @keyframes material-indeterminate {
      0% { left: -100%; width: 100%; }
      50% { left: 107%; width: 100%; }
      100% { left: 107%; width: 0%; }
    }
    
    .loading-text {
      margin-top: 20px;
      font-size: 16px;
      color: #666;
      text-align: center;
    }
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
  <?php if (isset($_GET['csv_uploaded'])): ?>
    <div class="alert alert-success">
      <strong>CSV Upload Complete!</strong><br>
      Added: <?= intval($_GET['added']) ?> new interviews |
      Skipped: <?= intval($_GET['skipped']) ?> duplicates |
      Errors: <?= intval($_GET['errors']) ?> invalid entries
    </div>
  <?php endif; ?>
  <?php if (isset($csv_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($csv_error) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['cleanup_done'])): ?>
    <div class="alert alert-success">
      <strong><i class="fas fa-broom"></i> Cleanup Complete!</strong><br>
      Removed <?= intval($_GET['removed']) ?> duplicate interview records.
      <?php if (isset($_GET['details']) && $_GET['removed'] > 0): ?>
        <?php 
        $removed_details = json_decode(base64_decode($_GET['details']), true);
        if ($removed_details && count($removed_details) > 0): 
        ?>
        <details class="mt-2">
          <summary><strong>Duplicates Removed (<?= count($removed_details) ?>)</strong></summary>
          <ul class="mb-0 mt-2">
            <?php foreach ($removed_details as $detail): ?>
              <li><?= htmlspecialchars($detail) ?></li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Full roster with availability highlighting -->
  <div class="card mb-4">
    <div class="card-header">
      Roster & Weekly Availability 
      <small class="text-muted">
        (Current: <?= date('M j', $current_base) ?>-<?= date('j', strtotime('+6 days', $current_base)) ?> | 
         Next: <?= date('M j', $next_base) ?>-<?= date('j', strtotime('+6 days', $next_base)) ?>)
      </small>
    </div>
    <div class="card-body">
      <div class="mb-3">
        <small class="text-muted">
          <span class="badge bg-success"><i class="fas fa-check"></i> Both Weeks</span> Has availability for both weeks |
          <span class="badge bg-success"><i class="fas fa-check"></i> Current Only</span> Ready for current week |
          <span class="badge bg-info"><i class="fas fa-exclamation-triangle"></i> Next Only</span> Missing current week |
          <span class="badge bg-warning text-dark"><i class="fas fa-times"></i> Missing Both</span> No availability set
        </small>
      </div>
      
      <h5>Actives</h5>
      <ul class="list-unstyled">
        <?php foreach ($actives as $a): ?>
          <li class="py-2 border-bottom">
            <a href="view_availability.php?user_id=<?=$a['id']?>" class="text-decoration-none">
              <?=$a['name']?>
            </a>
            <?= getAvailabilityBadge($a['id'], $current_avail_users, $next_avail_users) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      
      <h5 class="mt-4">Pledges</h5>
      <ul class="list-unstyled">
        <?php foreach ($pledges as $p): ?>
          <li class="py-2 border-bottom">
            <a href="view_availability.php?user_id=<?=$p['id']?>" class="text-decoration-none">
              <?=$p['name']?>
            </a>
            <?= getAvailabilityBadge($p['id'], $current_avail_users, $next_avail_users) ?>
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

  <!-- Bulk Upload Completed Interviews -->
  <div class="card mb-4">
    <div class="card-header">
      <i class="fas fa-upload"></i> Bulk Upload Completed Interviews
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="upload_csv" value="1">
        
        <div class="alert alert-info">
          <strong><i class="fas fa-info-circle"></i> CSV Format Requirements:</strong><br>
          • First row should be headers: <code>Pledge,Active</code><br>
          • Each row should contain the pledge name and active name<br>
          • Names must match exactly with database entries<br>
          • Duplicate active/pledge pairs will be skipped automatically (regardless of date)
        </div>
        
        <div class="row mb-3">
          <div class="col-md-6">
            <label class="form-label">CSV File</label>
            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
            <small class="text-muted">Select a CSV file with Pledge and Active columns</small>
          </div>
          <div class="col-md-6">
            <label class="form-label">Interview Date</label>
            <input type="date" class="form-control" name="csv_date" required>
            <small class="text-muted">Date to assign to all interviews in the CSV</small>
          </div>
        </div>
        
        <div class="mb-3">
          <label class="form-label">Notes (Optional)</label>
          <textarea class="form-control" name="csv_notes" rows="2" placeholder="Optional notes to add to all uploaded interviews"></textarea>
        </div>
        
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-upload"></i> Upload CSV
        </button>
        
        <button type="button" class="btn btn-outline-secondary ms-2" onclick="downloadSampleCsv()">
          <i class="fas fa-download"></i> Download Sample CSV
        </button>
      </form>
    </div>
  </div>

  <!-- Suggested weekly pairings -->
  <div class="card mb-4">
    <div class="card-header">Suggested Weekly Pairings</div>
    <div class="card-body">
      <!-- Pairing Generation Settings -->
      <div class="row mb-3">
        <div class="col-md-3">
          <label class="form-label">Week to Schedule</label>
          <select id="weekSelect" class="form-select">
            <?php 
            // Calculate date ranges for display
            $current_base = strtotime('monday this week');
            $next_base = strtotime('next Monday');
            ?>
            <option value="current">Current Week (<?= date('M j', $current_base) ?> - <?= date('M j', strtotime('+6 days', $current_base)) ?>)</option>
            <option value="next" selected>Next Week (<?= date('M j', $next_base) ?> - <?= date('M j', strtotime('+6 days', $next_base)) ?>)</option>
          </select>
          <small class="text-muted">Which week to generate interviews for</small>
        </div>
        <div class="col-md-3">
          <label class="form-label">Global Max Interviews</label>
          <input type="number" id="globalMax" class="form-control" value="50" min="1" max="100">
          <small class="text-muted">Total interviews to generate per week</small>
        </div>
        <div class="col-md-3">
          <label class="form-label">Max per Active</label>
          <input type="number" id="activeMax" class="form-control" value="5" min="1" max="10">
          <small class="text-muted">Max interviews per active per week</small>
        </div>
        <div class="col-md-3">
          <label class="form-label">Max per Pledge</label>
          <input type="number" id="pledgeMax" class="form-control" value="5" min="1" max="10">
          <small class="text-muted">Max interviews per pledge per week</small>
        </div>
      </div>
      
      <!-- Day Exclusion Settings -->
      <div class="row mb-3">
        <div class="col-12">
          <label class="form-label">Exclude Days from Scheduling</label>
          <div class="d-flex flex-wrap gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="excludeMonday" value="1">
              <label class="form-check-label" for="excludeMonday">Monday</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="excludeTuesday" value="2">
              <label class="form-check-label" for="excludeTuesday">Tuesday</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="excludeWednesday" value="3">
              <label class="form-check-label" for="excludeWednesday">Wednesday</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="excludeThursday" value="4">
              <label class="form-check-label" for="excludeThursday">Thursday</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="excludeFriday" value="5">
              <label class="form-check-label" for="excludeFriday">Friday</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="excludeSaturday" value="6">
              <label class="form-check-label" for="excludeSaturday">Saturday</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="excludeSunday" value="0">
              <label class="form-check-label" for="excludeSunday">Sunday</label>
            </div>
          </div>
          <small class="text-muted">Select days to exclude from interview scheduling (e.g., check Monday to prevent scheduling interviews on Mondays)</small>
        </div>
      </div>
      
      <button id="generatePairings" class="btn btn-primary">Generate Suggested Weekly Pairings</button>
      
      <!-- Preloader (hidden by default) -->
      <div id="pairingLoader" class="text-center mt-3" style="display: none;">
        <div class="material-progress">
          <div class="material-progress-bar"></div>
        </div>
        <div class="loading-text">
          <strong>Generating Pairings</strong><br>
          <small>Finding optimal combinations... This may take up to 30 seconds.</small>
        </div>
      </div>
      
      <!-- Results container -->
      <div id="pairingResults" class="mt-3"></div>
    </div>
  </div>

  <!-- Completed interviews history -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Completed Interviews</span>
      <form method="post" style="display: inline;" onsubmit="return confirm('This will remove all duplicate interview records. Are you sure?');">
        <input type="hidden" name="cleanup_duplicates" value="1">
        <button type="submit" class="btn btn-warning btn-sm">
          <i class="fas fa-broom"></i> Remove Duplicates
        </button>
      </form>
    </div>
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

<script>
document.getElementById('generatePairings').addEventListener('click', function() {
    const button = this;
    const loader = document.getElementById('pairingLoader');
    const results = document.getElementById('pairingResults');
    
    // Show loader immediately
    button.disabled = true;
    button.textContent = 'Generating...';
    loader.style.display = 'block';
    results.innerHTML = '';
    
    // Get the input values
    const weekSelect = document.getElementById('weekSelect').value;
    const globalMax = document.getElementById('globalMax').value;
    const activeMax = document.getElementById('activeMax').value;
    const pledgeMax = document.getElementById('pledgeMax').value;
    
    // Get excluded days
    const excludedDays = [];
    if (document.getElementById('excludeMonday').checked) excludedDays.push('1');
    if (document.getElementById('excludeTuesday').checked) excludedDays.push('2');
    if (document.getElementById('excludeWednesday').checked) excludedDays.push('3');
    if (document.getElementById('excludeThursday').checked) excludedDays.push('4');
    if (document.getElementById('excludeFriday').checked) excludedDays.push('5');
    if (document.getElementById('excludeSaturday').checked) excludedDays.push('6');
    if (document.getElementById('excludeSunday').checked) excludedDays.push('0');
    
    // Make AJAX request with parameters
    fetch(`generate_pairings.php?week=${weekSelect}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'global_max': globalMax,
            'active_max': activeMax,
            'pledge_max': pledgeMax,
            'excluded_days': excludedDays.join(',')
        })
    })
    .then(response => response.text())
    .then(html => {
        // Hide loader
        loader.style.display = 'none';
        button.disabled = false;
        button.textContent = 'Generate Suggested Weekly Pairings';
        
        // Show results
        results.innerHTML = html;
    })
    .catch(error => {
        // Hide loader
        loader.style.display = 'none';
        button.disabled = false;
        button.textContent = 'Generate Suggested Weekly Pairings';
        
        // Show error
        results.innerHTML = '<div class="alert alert-danger">Error generating pairings: ' + error.message + '</div>';
    });
});

function downloadSampleCsv() {
    // Create sample CSV content
    const csvContent = "Pledge,Active\n" +
                      "John Smith,Jane Doe\n" +
                      "Mike Johnson,Sarah Wilson\n" +
                      "Emily Davis,Robert Brown";
    
    // Create and download file
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', 'sample_interviews.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>
</body>
</html>
