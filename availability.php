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
      user-select: none; /* Prevent text selection during drag */
      transition: all 0.15s ease; /* Smooth transitions */
      border: 2px solid transparent;
      position: relative;
    }
    .available {
      background-color: #198754 !important; /* Bootstrap green */
      color: white;
      border-color: #198754;
    }
    .unavailable {
      background-color: #6c757d !important; /* Bootstrap gray */
      color: white;
      border-color: #6c757d;
    }
    .slot:hover:not(.blocked) {
      transform: scale(1.02); /* Slight scale on hover */
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .blocked {
      background-color: #dc3545 !important;
      opacity: 0.5;
      cursor: not-allowed;
      color: white;
    }
    
    /* Drag selection states */
    .slot.drag-selecting {
      border-color: #0d6efd !important; /* Bootstrap blue */
      border-width: 3px;
      box-shadow: 0 0 10px rgba(13, 110, 253, 0.5);
      transform: scale(1.03);
    }
    
    .slot.drag-preview {
      opacity: 0.8;
      border-color: #ffc107 !important; /* Bootstrap yellow */
      border-width: 2px;
      animation: pulse 0.8s infinite;
    }
    
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.02); }
      100% { transform: scale(1); }
    }
    
    /* Visual feedback for drag direction */
    body.dragging {
      cursor: grab;
    }
    body.dragging .slot:not(.blocked) {
      cursor: grab;
    }
    
    /* Table styling improvements */
    .table td {
      padding: 0.25rem;
      vertical-align: middle;
    }
    
    .slot-container {
      position: relative;
      min-height: 45px;
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
    <?php 
    // Calculate correct date ranges for display
    $current_base = strtotime('monday this week');
    $next_base = strtotime('next Monday');
    ?>
    <a href="availability.php?user_id=<?= $user_id ?>&week=current" 
       class="btn <?= $week === 'current' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Current Week (<?= date('M j', $current_base) ?> - <?= date('M j', strtotime('+6 days', $current_base)) ?>)
    </a>
    <a href="availability.php?user_id=<?= $user_id ?>&week=next" 
       class="btn <?= $week === 'next' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Next Week (<?= date('M j', $next_base) ?> - <?= date('M j', strtotime('+6 days', $next_base)) ?>)
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

    <?php 
    // Generate 30-minute slots from 8:00 AM to 7:00 PM
    for ($minutes = 0; $minutes < 660; $minutes += 30): 
      $hour = floor($minutes / 60) + 8;
      $minute = $minutes % 60;
    ?>
      <tr>
        <th><?=sprintf("%d:%02d - %d:%02d", $hour, $minute, $hour + ($minute == 30 ? 1 : 0), ($minute + 30) % 60)?></th>
        <?php for ($d=0; $d<7; $d++):
          $start = strtotime(sprintf("+%d day %d:%02d", $d, $hour, $minute), $base);
          $end = $start + 1800; // 30 minutes = 1800 seconds
          $val = $start."|".$end;

          $dayOfWeek = date('D', $start);
          $hour = (int)date('H', $start);

          $isAvailable = in_array($start, $existing);

          // Block Tuesday/Thursday 6pm+
          $isBlocked = (($dayOfWeek === 'Tue' || $dayOfWeek === 'Thu') && $hour >= 18);

          if ($isBlocked): ?>
            <td><div class="slot blocked">Blocked</div></td>
          <?php else: ?>
            <td>
              <div class="slot-container">
                <div class="slot <?= $isAvailable ? 'available' : 'unavailable' ?>" data-value="<?=$val?>">
                  <?= $isAvailable ? '✓ Available' : 'Not Available' ?>
                </div>
                <input type="checkbox" name="slots[]" value="<?=$val?>" <?= $isAvailable ? 'checked' : '' ?> hidden>
              </div>
            </td>
          <?php endif; ?>
        <?php endfor; ?>
      </tr>
    <?php endfor; ?>
  </table>
  <button class="btn btn-primary">Save Availability</button>
</form>

<script>
// Enhanced drag selection functionality
let isDragging = false;
let dragStartState = null;
let draggedSlots = new Set();
let hasMoved = false; // Track if mouse has moved during drag

document.querySelectorAll('.slot').forEach(slot => {
  if (!slot.classList.contains('blocked')) {
    // Mouse events for desktop
    slot.addEventListener('mousedown', (e) => {
      e.preventDefault();
      isDragging = true;
      hasMoved = false;
      draggedSlots.clear();
      
      // Add visual feedback for drag start
      document.body.classList.add('dragging');
      slot.classList.add('drag-selecting');
      
      const checkbox = slot.closest('.slot-container').querySelector('input[type="checkbox"]');
      dragStartState = !checkbox.checked; // Toggle state
      
      // Immediately toggle the starting slot
      toggleSlot(slot, dragStartState);
      draggedSlots.add(slot);
    });
    
    slot.addEventListener('mouseenter', () => {
      if (isDragging) {
        hasMoved = true; // Mark that we've moved during drag
        
        // Remove drag-preview from all slots first
        document.querySelectorAll('.slot').forEach(s => s.classList.remove('drag-preview'));
        
        if (!draggedSlots.has(slot)) {
          slot.classList.add('drag-preview');
          toggleSlot(slot, dragStartState);
          draggedSlots.add(slot);
        }
      }
    });
    
    slot.addEventListener('mouseleave', () => {
      if (isDragging) {
        slot.classList.remove('drag-preview');
      }
    });
    
    // Touch events for mobile
    slot.addEventListener('touchstart', (e) => {
      e.preventDefault();
      isDragging = true;
      hasMoved = false;
      draggedSlots.clear();
      
      document.body.classList.add('dragging');
      slot.classList.add('drag-selecting');
      
      const checkbox = slot.closest('.slot-container').querySelector('input[type="checkbox"]');
      dragStartState = !checkbox.checked;
      
      // Immediately toggle the starting slot
      toggleSlot(slot, dragStartState);
      draggedSlots.add(slot);
    });
    
    slot.addEventListener('touchmove', (e) => {
      e.preventDefault();
      if (isDragging) {
        hasMoved = true;
        const touch = e.touches[0];
        const element = document.elementFromPoint(touch.clientX, touch.clientY);
        const touchedSlot = element?.closest('.slot');
        
        // Remove drag-preview from all slots first
        document.querySelectorAll('.slot').forEach(s => s.classList.remove('drag-preview'));
        
        if (touchedSlot && !touchedSlot.classList.contains('blocked') && !draggedSlots.has(touchedSlot)) {
          touchedSlot.classList.add('drag-preview');
          toggleSlot(touchedSlot, dragStartState);
          draggedSlots.add(touchedSlot);
        }
      }
    });
    
    // Simple click functionality - no longer needed since mousedown handles it
    // The click event is now handled entirely by the mousedown/mouseup pattern
  }
});

// Global mouse/touch up events
document.addEventListener('mouseup', (e) => {
  if (isDragging) {
    // If we didn't move, this was just a click - add some visual feedback
    if (!hasMoved) {
      const target = e.target.closest('.slot');
      if (target) {
        target.style.transform = 'scale(0.95)';
        setTimeout(() => { target.style.transform = ''; }, 100);
      }
    }
    
    // Clean up drag state
    document.body.classList.remove('dragging');
    document.querySelectorAll('.slot').forEach(slot => {
      slot.classList.remove('drag-selecting', 'drag-preview');
    });
    
    isDragging = false;
    draggedSlots.clear();
    hasMoved = false;
  }
});

document.addEventListener('touchend', (e) => {
  if (isDragging) {
    // Clean up drag state
    document.body.classList.remove('dragging');
    document.querySelectorAll('.slot').forEach(slot => {
      slot.classList.remove('drag-selecting', 'drag-preview');
    });
    
    isDragging = false;
    draggedSlots.clear();
    hasMoved = false;
  }
});

// Prevent text selection during drag
document.addEventListener('selectstart', (e) => {
  if (isDragging) e.preventDefault();
});

// Enhanced toggle function with better visual feedback
function toggleSlot(slot, isAvailable) {
  const checkbox = slot.closest('.slot-container').querySelector('input[type="checkbox"]');
  checkbox.checked = isAvailable;
  
  if (isAvailable) {
    slot.classList.remove('unavailable');
    slot.classList.add('available');
    slot.textContent = '✓ Available';
  } else {
    slot.classList.remove('available');
    slot.classList.add('unavailable');
    slot.textContent = 'Not Available';
  }
}

// Add some visual polish - smooth animations on load
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.slot').forEach((slot, index) => {
    slot.style.opacity = '0';
    slot.style.transform = 'translateY(10px)';
    
    setTimeout(() => {
      slot.style.transition = 'all 0.3s ease';
      slot.style.opacity = '1';
      slot.style.transform = 'translateY(0)';
    }, index * 10); // Stagger the animations
  });
});
</script>
</div>
</body>
</html>
