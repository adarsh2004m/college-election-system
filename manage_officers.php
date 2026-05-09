<?php
require_once '../includes/auth.php';
require_once '../config/db_config.php';
requireRole('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_officer'])) {
        $username = $_POST['username'];
        $email = $_POST['email'];
        $mobile = $_POST['mobile'];
        $full_name = $_POST['full_name'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt1 = $pdo->prepare("
        SELECT * FROM users
        WHERE username = ? 
        
    ");
    $stmt1->execute([$username]);
    if ($stmt1->fetch()) {
        $error = "Username already exists";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, mobile, full_name, password_hash, role, is_verified) VALUES (?, ?, ?, ?, ?, 'presiding_officer', 1)");
        $stmt->execute([$username, $email, $mobile, $full_name, $password]);
        $success = "Presiding Officer added successfully";
    }
    } elseif (isset($_POST['delete_officer'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role='presiding_officer'");
        $stmt->execute([$_POST['officer_id']]);
        $success = "Officer deleted successfully";
    }
}

$officers = $pdo->query("SELECT * FROM users WHERE role = 'presiding_officer' ORDER BY full_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Presiding Officers - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-primary { background: #667eea; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; max-width: 500px; width: 90%; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        table { width: 100%; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .add-btn { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>👮 Manage Presiding Officers</h1>
        <a href="../dashboard.php" style="color: white; text-decoration: none;">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if (isset($success)): ?>
     
            <div style="background: #c6f6d5; padding: 10px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success; ?></div>
        <?php endif; ?>
        
         <?php if (isset($error)): ?>
            <div style="background: #fed7d7; padding: 10px; border-radius: 5px; margin-bottom: 20px;"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <button class="btn btn-primary add-btn" onclick="openModal()">+ Add Presiding Officer</button>
        
        <table>
            <thead>
                <tr><th>ID</th><th>Full Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($officers as $officer): ?>
                <tr>
                    <td><?php echo $officer['id']; ?></td>
                    <td><?php echo htmlspecialchars($officer['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($officer['username']); ?></td>
                    <td><?php echo htmlspecialchars($officer['email']); ?></td>
                    <td><?php echo htmlspecialchars($officer['mobile']); ?></td>
                    <td>
                        <button class="btn btn-danger" onclick="deleteOfficer(<?php echo $officer['id']; ?>)">Delete</button>
                    </td>
                    </tr>
                
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div id="officerModal" class="modal">
        <div class="modal-content">
            <h2>Add Presiding Officer</h2>
            <form method="POST">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                <div class="form-group"><label>Mobile</label><input type="text" name="mobile"></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                <button type="submit" name="add_officer" class="btn btn-primary">Add Officer</button>
                <button type="button" onclick="closeModal()" class="btn">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function openModal() { document.getElementById('officerModal').style.display = 'flex'; }
        function closeModal() { document.getElementById('officerModal').style.display = 'none'; }
        function deleteOfficer(id) {
            if (confirm('Are you sure?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="delete_officer" value="1"><input type="hidden" name="officer_id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>