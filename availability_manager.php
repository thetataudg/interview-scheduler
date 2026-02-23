<?php
// filepath: /Applications/AMPPS/www/availability_manager.php
require 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'copy_availability') {
        $source_week = $_POST['source_week'] ?? '';
        $target_week = $_POST['target_week'] ?? '';
        
        if (!$source_week || !$target_week) {
            echo json_encode(['success' => false, 'message' => 'Missing week parameters']);
            exit;
        }
        
        $result = copyAvailability($source_week, $target_week, $db);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'get_availability_summary') {
        $result = getAvailabilitySummary($db);
        echo json_encode($result);
        exit;
    }
}

function copyAvailability($source_week, $target_week, $db) {
    try {
        $db->beginTransaction();
        
        // Get source and target week dates
        $source_start = date('Y-m-d', strtotime($source_week));
        $source_end = date('Y-m-d', strtotime($source_week . ' +6 days'));
        $target_start = date('Y-m-d', strtotime($target_week));
        $target_end = date('Y-m-d', strtotime($target_week . ' +6 days'));
        
        // Step 1: Find users who have availability in the source week
        $source_users_stmt = $db->prepare("
            SELECT DISTINCT user_id, u.name
            FROM availabilities a
            JOIN users u ON a.user_id = u.id
            WHERE DATE(slot_start) BETWEEN ? AND ?
        ");
        $source_users_stmt->execute([$source_start, $source_end]);
        $users_with_source_availability = $source_users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($users_with_source_availability)) {
            return ['success' => false, 'message' => 'No users have availability in the source week'];
        }
        
        $source_user_ids = array_column($users_with_source_availability, 'user_id');
        
        // Step 2: Find users who already have availability in the target week
        $target_user_ids_placeholders = str_repeat('?,', count($source_user_ids) - 1) . '?';
        $target_users_stmt = $db->prepare("
            SELECT DISTINCT user_id
            FROM availabilities
            WHERE user_id IN ($target_user_ids_placeholders)
            AND DATE(slot_start) BETWEEN ? AND ?
        ");
        $target_params = array_merge($source_user_ids, [$target_start, $target_end]);
        $target_users_stmt->execute($target_params);
        $users_with_target_availability = $target_users_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Step 3: Determine which users to copy for (those without target week availability)
        $users_to_copy_for = array_diff($source_user_ids, $users_with_target_availability);
        
        if (empty($users_to_copy_for)) {
            return [
                'success' => false, 
                'message' => 'All users who have source week availability already have availability in the target week. No copying needed.'
            ];
        }
        
        // Step 4: Get source availability only for users we're copying for
        $copy_user_placeholders = str_repeat('?,', count($users_to_copy_for) - 1) . '?';
        $source_stmt = $db->prepare("
            SELECT user_id, slot_start, slot_end, u.name
            FROM availabilities a
            JOIN users u ON a.user_id = u.id
            WHERE user_id IN ($copy_user_placeholders)
            AND DATE(slot_start) BETWEEN ? AND ?
            ORDER BY u.name, slot_start
        ");
        $source_params = array_merge($users_to_copy_for, [$source_start, $source_end]);
        $source_stmt->execute($source_params);
        $source_availability = $source_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Step 5: Calculate day offset and copy availability
        $source_timestamp = strtotime($source_start);
        $target_timestamp = strtotime($target_start);
        $day_offset = ($target_timestamp - $source_timestamp) / (24 * 60 * 60);
        
        $insert_stmt = $db->prepare("
            INSERT INTO availabilities (user_id, slot_start, slot_end) 
            VALUES (?, ?, ?)
        ");
        
        $copied_count = 0;
        $users_copied_for = [];
        
        foreach ($source_availability as $record) {
            $new_start = date('Y-m-d H:i:s', strtotime($record['slot_start'] . " +{$day_offset} days"));
            $new_end = date('Y-m-d H:i:s', strtotime($record['slot_end'] . " +{$day_offset} days"));
            
            $insert_stmt->execute([$record['user_id'], $new_start, $new_end]);
            $copied_count++;
            
            if (!isset($users_copied_for[$record['user_id']])) {
                $users_copied_for[$record['user_id']] = $record['name'];
            }
        }
        
        // Get detailed information about all users and their slot counts
        $user_details = [];
        
        // Get slot counts for copied users
        foreach ($users_copied_for as $user_id => $user_name) {
            $slot_count = 0;
            foreach ($source_availability as $record) {
                if ($record['user_id'] == $user_id) {
                    $slot_count++;
                }
            }
            $user_details[] = [
                'name' => $user_name,
                'status' => 'copied',
                'slots_copied' => $slot_count,
                'existing_slots' => 0
            ];
        }
        
        // Get information about skipped users (including their existing slot counts)
        foreach ($users_with_source_availability as $user) {
            if (in_array($user['user_id'], $users_with_target_availability)) {
                // Count existing slots in target week for this user
                $existing_count_stmt = $db->prepare("
                    SELECT COUNT(*) as existing_count
                    FROM availabilities
                    WHERE user_id = ? AND DATE(slot_start) BETWEEN ? AND ?
                ");
                $existing_count_stmt->execute([$user['user_id'], $target_start, $target_end]);
                $existing_count = $existing_count_stmt->fetch(PDO::FETCH_ASSOC)['existing_count'];
                
                // Count source slots for this user
                $source_count_stmt = $db->prepare("
                    SELECT COUNT(*) as source_count
                    FROM availabilities
                    WHERE user_id = ? AND DATE(slot_start) BETWEEN ? AND ?
                ");
                $source_count_stmt->execute([$user['user_id'], $source_start, $source_end]);
                $source_count = $source_count_stmt->fetch(PDO::FETCH_ASSOC)['source_count'];
                
                $user_details[] = [
                    'name' => $user['name'],
                    'status' => 'skipped',
                    'slots_copied' => 0,
                    'existing_slots' => $existing_count,
                    'source_slots' => $source_count
                ];
            }
        }
        
        // Sort by name for better readability
        usort($user_details, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        $db->commit();
        
        $message = "Successfully copied {$copied_count} availability slots from " . 
                  date('M j', strtotime($source_start)) . " to " . 
                  date('M j, Y', strtotime($target_start));
        
        $skipped_count = count(array_filter($user_details, function($user) {
            return $user['status'] === 'skipped';
        }));
        
        return [
            'success' => true, 
            'message' => $message,
            'copied_count' => $copied_count,
            'users_copied_count' => count($users_copied_for),
            'skipped_count' => $skipped_count,
            'user_details' => $user_details
        ];
        
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function getAvailabilitySummary($db) {
    try {
        // Get availability summary by week for ALL users
        $stmt = $db->prepare("
            SELECT
                DATE(slot_start) as day_date,
                COUNT(*) as slot_count,
                COUNT(DISTINCT user_id) as user_count,
                MIN(slot_start) as first_slot,
                MAX(slot_start) as last_slot
            FROM availabilities a
            JOIN users u ON a.user_id = u.id
            GROUP BY DATE(slot_start)
            ORDER BY day_date
        ");
        
        $stmt->execute();
        $days = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($days)) {
            return [
                'success' => false, 
                'message' => "No availability data found in database"
            ];
        }
        
        // Process days into weeks and get accurate user counts per week
        $weeks_data = [];
        foreach ($days as $day) {
            // Get the Monday of the week for this date
            $date = new DateTime($day['day_date']);
            $monday = clone $date;
            
            // If it's not Monday, go back to Monday
            $dayOfWeek = $date->format('N'); // 1 = Monday, 7 = Sunday
            if ($dayOfWeek != 1) {
                $monday->modify('-' . ($dayOfWeek - 1) . ' days');
            }
            
            $mondayStr = $monday->format('Y-m-d');
            
            if (!isset($weeks_data[$mondayStr])) {
                $weeks_data[$mondayStr] = [
                    'week_start' => $mondayStr,
                    'slot_count' => 0,
                    'days' => []
                ];
            }
            
            $weeks_data[$mondayStr]['slot_count'] += $day['slot_count'];
            $weeks_data[$mondayStr]['days'][] = $day['day_date'];
        }
        
        // Now get accurate user counts per week
        $processed_weeks = [];
        foreach ($weeks_data as $mondayStr => $week_info) {
            // Get unique user count for this entire week
            $week_start = $mondayStr;
            $week_end = date('Y-m-d', strtotime($mondayStr . ' +6 days'));
            
            $user_count_stmt = $db->prepare("
                SELECT COUNT(DISTINCT user_id) as unique_users
                FROM availabilities 
                WHERE DATE(slot_start) BETWEEN ? AND ?
            ");
            $user_count_stmt->execute([$week_start, $week_end]);
            $user_count_result = $user_count_stmt->fetch(PDO::FETCH_ASSOC);
            
            $processed_weeks[$mondayStr] = [
                'week_start' => $mondayStr,
                'slot_count' => $week_info['slot_count'],
                'user_count' => $user_count_result['unique_users']
            ];
        }
        
        // Sort by week start date
        ksort($processed_weeks);
        
        return ['success' => true, 'weeks' => array_values($processed_weeks)];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// Calculate current and next week
$current_monday = date('Y-m-d', strtotime('monday this week'));
$next_monday = date('Y-m-d', strtotime('monday next week'));
$current_sunday = date('Y-m-d', strtotime('sunday this week'));
$next_sunday = date('Y-m-d', strtotime('sunday next week'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability Manager - Interview Scheduler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .navbar-dark { background-color: #000 !important; }
        .week-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .week-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .week-card.selected {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13,110,253,.25);
        }
        .target-week {
            background: linear-gradient(135deg, #e3f2fd 0%, #f8f9fa 100%);
        }
        .loading {
            display: none;
        }
        .week-info {
            font-size: 0.9em;
            color: #6c757d;
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
            <div>
                <h2><i class="fas fa-calendar-alt"></i> Availability Manager</h2>
                <p class="text-muted mb-0">Copy availability data between weeks for interviews</p>
            </div>
            <a href="admin.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Admin
            </a>
        </div>

        <!-- Current/Next Week Info -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card target-week">
                    <div class="card-body text-center">
                        <h5 class="card-title"><i class="fas fa-clock text-primary"></i> Current Week</h5>
                        <p class="card-text">
                            <strong><?= date('M j', strtotime($current_monday)) ?> - <?= date('M j, Y', strtotime($current_sunday)) ?></strong>
                        </p>
                        <button class="btn btn-outline-primary btn-sm" onclick="selectTargetWeek('<?= $current_monday ?>')">
                            <i class="fas fa-target"></i> Set as Target
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card target-week">
                    <div class="card-body text-center">
                        <h5 class="card-title"><i class="fas fa-forward text-success"></i> Next Week</h5>
                        <p class="card-text">
                            <strong><?= date('M j', strtotime($next_monday)) ?> - <?= date('M j, Y', strtotime($next_sunday)) ?></strong>
                        </p>
                        <button class="btn btn-outline-success btn-sm" onclick="selectTargetWeek('<?= $next_monday ?>')">
                            <i class="fas fa-target"></i> Set as Target
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Copy Controls -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-copy"></i> Copy Availability</h5>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Source Week (Copy From)</label>
                                <input type="date" id="sourceWeek" class="form-control" placeholder="Select source week">
                                <div class="week-info mt-1">
                                    <span id="sourceWeekInfo"></span>
                                </div>
                            </div>
                            <div class="col-md-1 text-center">
                                <i class="fas fa-arrow-right fa-2x text-muted mt-4"></i>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Target Week (Copy To)</label>
                                <input type="date" id="targetWeek" class="form-control" placeholder="Select target week">
                                <div class="week-info mt-1">
                                    <span id="targetWeekInfo"></span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary w-100" onclick="copyAvailability()" id="copyBtn">
                                    <i class="fas fa-copy"></i> Copy Availability
                                </button>
                                <div class="spinner-border spinner-border-sm loading ms-2" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available Weeks -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-list"></i> Weeks with Availability Data</h5>
                        <button class="btn btn-sm btn-outline-secondary" onclick="refreshWeeks()">
                            <i class="fas fa-refresh"></i> Refresh
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="weeksContainer">
                            <div class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">Loading weeks...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div id="results" class="row mt-4" style="display: none;">
            <div class="col-12">
                <div class="alert" id="resultAlert" role="alert"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedSourceWeek = null;
        let selectedTargetWeek = null;

        // Load weeks on page load
        document.addEventListener('DOMContentLoaded', function() {
            refreshWeeks();
            
            // Update week info when dates change
            document.getElementById('sourceWeek').addEventListener('change', updateSourceWeekInfo);
            document.getElementById('targetWeek').addEventListener('change', updateTargetWeekInfo);
        });

        function updateSourceWeekInfo() {
            const date = document.getElementById('sourceWeek').value;
            if (date) {
                const inputDate = new Date(date + 'T00:00:00'); // Add time to avoid timezone issues
                const monday = getMonday(inputDate);
                const sunday = new Date(monday);
                sunday.setDate(sunday.getDate() + 6);
                
                document.getElementById('sourceWeekInfo').textContent = 
                    `Week of ${formatDate(monday)} - ${formatDate(sunday)}`;
                selectedSourceWeek = formatDateForAPI(monday);
            }
        }

        function updateTargetWeekInfo() {
            const date = document.getElementById('targetWeek').value;
            if (date) {
                const inputDate = new Date(date + 'T00:00:00'); // Add time to avoid timezone issues
                const monday = getMonday(inputDate);
                const sunday = new Date(monday);
                sunday.setDate(sunday.getDate() + 6);
                
                document.getElementById('targetWeekInfo').textContent = 
                    `Week of ${formatDate(monday)} - ${formatDate(sunday)}`;
                selectedTargetWeek = formatDateForAPI(monday);
            }
        }

        function getMonday(date) {
            const d = new Date(date);
            const day = d.getDay(); // 0 = Sunday, 1 = Monday, ... 6 = Saturday
            const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Adjust when day is Sunday
            const monday = new Date(d);
            monday.setDate(diff);
            return monday;
        }

        function formatDate(date) {
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        function formatDateForAPI(date) {
            return date.toISOString().split('T')[0];
        }

        function selectSourceWeek(weekStart) {
            selectedSourceWeek = weekStart;
            document.getElementById('sourceWeek').value = weekStart;
            updateSourceWeekInfo();
            
            // Update visual selection
            document.querySelectorAll('.week-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`[data-week="${weekStart}"]`)?.classList.add('selected');
        }

        function selectTargetWeek(weekStart) {
            selectedTargetWeek = weekStart;
            document.getElementById('targetWeek').value = weekStart;
            updateTargetWeekInfo();
        }

        function refreshWeeks() {
            fetch('availability_manager.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_availability_summary'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayWeeks(data.weeks);
                } else {
                    showResult('error', data.message);
                }
            })
            .catch(error => {
                showResult('error', 'Failed to load weeks: ' + error.message);
            });
        }

        function displayWeeks(weeks) {
            const container = document.getElementById('weeksContainer');
            
            if (weeks.length === 0) {
                container.innerHTML = '<div class="text-muted text-center">No availability data found</div>';
                return;
            }
            
            let html = '<div class="row">';
            weeks.forEach(week => {
                const weekStart = new Date(week.week_start);
                const weekEnd = new Date(weekStart);
                weekEnd.setDate(weekEnd.getDate() + 6);
                
                const isCurrentWeek = week.week_start === '<?= $current_monday ?>';
                const isNextWeek = week.week_start === '<?= $next_monday ?>';
                
                let badgeClass = 'bg-secondary';
                let badgeText = '';
                if (isCurrentWeek) {
                    badgeClass = 'bg-primary';
                    badgeText = ' (Current)';
                } else if (isNextWeek) {
                    badgeClass = 'bg-success';
                    badgeText = ' (Next)';
                }
                
                html += `
                    <div class="col-md-4 col-lg-3 mb-3">
                        <div class="card week-card h-100" data-week="${week.week_start}" onclick="selectSourceWeek('${week.week_start}')">
                            <div class="card-body text-center">
                                <h6 class="card-title">
                                    ${formatDate(weekStart)} - ${formatDate(weekEnd)}
                                    ${badgeText ? `<span class="badge ${badgeClass}">${badgeText.substring(2, badgeText.length - 1)}</span>` : ''}
                                </h6>
                                <p class="card-text">
                                    <i class="fas fa-users text-primary"></i> ${week.user_count} users<br>
                                    <i class="fas fa-clock text-success"></i> ${week.slot_count} slots
                                </p>
                                <button class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-hand-pointer"></i> Select as Source
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        }

        function copyAvailability() {
            const sourceWeek = selectedSourceWeek || document.getElementById('sourceWeek').value;
            const targetWeek = selectedTargetWeek || document.getElementById('targetWeek').value;
            
            if (!sourceWeek || !targetWeek) {
                showResult('warning', 'Please select both source and target weeks');
                return;
            }
            
            if (sourceWeek === targetWeek) {
                showResult('warning', 'Source and target weeks cannot be the same');
                return;
            }
            
            // Show loading
            document.getElementById('copyBtn').disabled = true;
            document.querySelector('.loading').style.display = 'inline-block';
            
            fetch('availability_manager.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=copy_availability&source_week=${sourceWeek}&target_week=${targetWeek}`
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('copyBtn').disabled = false;
                document.querySelector('.loading').style.display = 'none';
                
                if (data.success) {
                    let detailsText = `Copied: ${data.copied_count} slots for ${data.users_copied_count} users`;
                    if (data.skipped_count > 0) {
                        detailsText += ` | Skipped: ${data.skipped_count} users (already have availability)`;
                    }
                    
                    // Create detailed table
                    let tableHtml = createUserDetailsTable(data.user_details);
                    
                    showResult('success', `✅ ${data.message}<br><small>${detailsText}</small>${tableHtml}`);
                    refreshWeeks(); // Refresh the weeks display
                } else {
                    showResult('danger', `❌ ${data.message}`);
                }
            })
            .catch(error => {
                document.getElementById('copyBtn').disabled = false;
                document.querySelector('.loading').style.display = 'none';
                showResult('danger', 'Failed to copy availability: ' + error.message);
            });
        }

        function createUserDetailsTable(userDetails) {
            if (!userDetails || userDetails.length === 0) {
                return '';
            }
            
            const copiedUsers = userDetails.filter(user => user.status === 'copied');
            const skippedUsers = userDetails.filter(user => user.status === 'skipped');
            
            let tableHtml = '<div class="mt-3"><h6>Copy Details:</h6>';
            
            if (copiedUsers.length > 0) {
                tableHtml += `
                    <div class="mb-3">
                        <h6 class="text-success"><i class="fas fa-check-circle"></i> Copied Users (${copiedUsers.length})</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead class="table-success">
                                    <tr>
                                        <th>Name</th>
                                        <th>Slots Copied</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                copiedUsers.forEach(user => {
                    tableHtml += `
                        <tr>
                            <td>${user.name}</td>
                            <td><span class="badge bg-success">${user.slots_copied}</span></td>
                        </tr>
                    `;
                });
                
                tableHtml += '</tbody></table></div></div>';
            }
            
            if (skippedUsers.length > 0) {
                tableHtml += `
                    <div class="mb-3">
                        <h6 class="text-warning"><i class="fas fa-exclamation-triangle"></i> Skipped Users (${skippedUsers.length})</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead class="table-warning">
                                    <tr>
                                        <th>Name</th>
                                        <th>Current Slots in Target Week</th>
                                        <th>Available Slots in Source Week</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                skippedUsers.forEach(user => {
                    tableHtml += `
                        <tr>
                            <td>${user.name}</td>
                            <td><span class="badge bg-info">${user.existing_slots}</span></td>
                            <td><span class="badge bg-secondary">${user.source_slots || 0}</span></td>
                        </tr>
                    `;
                });
                
                tableHtml += '</tbody></table></div></div>';
            }
            
            tableHtml += '</div>';
            return tableHtml;
        }

        function showResult(type, message) {
            const results = document.getElementById('results');
            const alert = document.getElementById('resultAlert');
            
            alert.className = `alert alert-${type}`;
            alert.innerHTML = message;
            results.style.display = 'block';
            
            // Don't auto-hide - let user dismiss manually
        }
    </script>
</body>
</html>