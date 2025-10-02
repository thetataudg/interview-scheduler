<?php
require 'config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        // Create table if not exists
        $db->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL
        )");

        // Hash password
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Insert admin user
        $stmt = $db->prepare("INSERT OR IGNORE INTO admin_users (username, password_hash) VALUES (?, ?)");
        if ($stmt->execute([$username, $hash])) {
            $message = "Admin user '$username' created successfully!";
        } else {
            $message = "Failed to create admin user.";
        }
    } else {
        $message = "Username and password are required.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Initialize Admin Users</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">
  <nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
      <a class="navbar-brand text-white" href="index.php">Interview Scheduler</a>
      <div></div>
    </div>
  </nav>

  <h2>Initialize Admin Users</h2>

  <?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="mb-3">
      <label class="form-label">Username</label>
      <input type="text" class="form-control" name="username" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Password</label>
      <input type="password" class="form-control" name="password" required>
    </div>
    <button class="btn btn-primary">Create Admin User</button>
  </form>

  <hr>
  <p>Note: After creating admin users, login at <a href="admin.php">Admin Page</a> using the password only.</p>
</body>
</html>
