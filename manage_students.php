<?php
require_once '../includes/auth.php';
require_once '../config/db_config.php';
requireRole('admin');

// Helper function for cosine similarity
function calculateCosineSimilarity($vecA, $vecB) {
    if (!$vecA || !$vecB) return 0;
    
    $dotProduct = 0.0;
    $magnitudeA = 0.0;
    $magnitudeB = 0.0;
    
    // Convert associative arrays to indexed if needed
    $vecA = array_values($vecA);
    $vecB = array_values($vecB);
    
    for ($i = 0; $i < count($vecA); $i++) {
        $dotProduct += $vecA[$i] * $vecB[$i];
        $magnitudeA += $vecA[$i] * $vecA[$i];
        $magnitudeB += $vecB[$i] * $vecB[$i];
    }
    
    $magnitudeA = sqrt($magnitudeA);
    $magnitudeB = sqrt($magnitudeB);
    
    if ($magnitudeA == 0 || $magnitudeB == 0) return 0.0;
    
    return $dotProduct / ($magnitudeA * $magnitudeB);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_student'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $mobile = trim($_POST['mobile']);
        $full_name = trim($_POST['full_name']);
        $password = $_POST['password'];
        $face_data = $_POST['face_data'] ?? null;
        
        $errors = [];
        
        if (empty($username)) $errors[] = "Username is required";
        if (empty($email)) $errors[] = "Email is required";
        if (empty($full_name)) $errors[] = "Full name is required";
        if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters";
        if (!$face_data) $errors[] = "Please capture student's face";
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = "Username or email already exists";
        }
        
        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $face_embedding = json_encode(json_decode($face_data));
            
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, mobile, full_name, password_hash, face_embedding, role, is_verified) 
                VALUES (?, ?, ?, ?, ?, ?, 'student', 1)
            ");
            $stmt->execute([$username, $email, $mobile, $full_name, $password_hash, $face_embedding]);
            $success = "Student added successfully! They can now vote.";
        } else {
            $error_msg = implode("<br>", $errors);
        }
    } elseif (isset($_POST['verify_face'])) {
        // Face verification endpoint
        $student_id = $_POST['student_id'];
        $new_face_data = $_POST['face_data'];
        
        if ($new_face_data && $student_id) {
            $stmt = $pdo->prepare("SELECT face_embedding, full_name FROM users WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            if ($student && $student['face_embedding']) {
                $existing_face = json_decode($student['face_embedding'], true);
                $new_face = json_decode($new_face_data, true);
                
                if ($existing_face && $new_face) {
                    $similarity = calculateCosineSimilarity($existing_face, $new_face);
                    
                    if ($similarity > 0.95) {
                        echo json_encode([
                            'success' => true, 
                            'similarity' => $similarity,
                            'message' => 'Face verified! Same person.'
                        ]);
                    } else {
                        echo json_encode([
                            'success' => false, 
                            'similarity' => $similarity,
                            'message' => 'SECURITY ALERT: Face does NOT match! Similarity: ' . round($similarity * 100, 1) . '%'
                        ]);
                    }
                    exit;
                }
            } else {
                echo json_encode([
                    'success' => true, 
                    'message' => 'No existing face found. First time registration allowed.',
                    'is_first_time' => true
                ]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    } elseif (isset($_POST['update_face'])) {
        $student_id = $_POST['student_id'];
        $face_data = $_POST['face_data'];
        $verification_token = $_POST['verification_token'] ?? '';
        
        if ($verification_token !== 'verified_' . $student_id) {
            echo json_encode(['success' => false, 'message' => 'Security verification failed. Please verify face first.']);
            exit;
        }
        
        if ($face_data && $student_id) {
            $face_embedding = json_encode(json_decode($face_data));
            $stmt = $pdo->prepare("UPDATE users SET face_embedding = ? WHERE id = ?");
            $stmt->execute([$face_embedding, $student_id]);
            echo json_encode(['success' => true, 'message' => 'Face updated successfully!']);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Update failed']);
        exit;
    } elseif (isset($_POST['verify_student'])) {
        $stmt = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->execute([$_POST['student_id']]);
        $success = "Student verified successfully!";
    } elseif (isset($_POST['delete_student'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$_POST['student_id']]);
        $success = "Student deleted successfully";
    }
}

$all_students = $pdo->query("SELECT * FROM users WHERE role = 'student' ORDER BY is_verified DESC, created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 2px; transition: all 0.3s; }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }
        .btn-success { background: #48bb78; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .btn-primary { background: #667eea; color: white; }
        .btn-info { background: #4299e1; color: white; }
        .btn-warning { background: #ed8936; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .table-wrapper { overflow-x: auto; margin-bottom: 30px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); background: white; }
        table { width: 100%; background: white; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; vertical-align: middle; }
        th { background: #f8f9fa; font-weight: bold; color: #555; position: sticky; top: 0; }
        tr:hover { background: #f9f9f9; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .status-pending { background: #feebc8; color: #975a16; }
        .status-verified { background: #c6f6d5; color: #22543d; }
        .status-face { background: #c6f6d5; color: #22543d; }
        .status-no-face { background: #fed7d7; color: #c53030; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 18px; margin: 0 auto; }
        .avatar-cell { width: 50px; text-align: center; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .action-buttons .btn { padding: 5px 12px; font-size: 11px; white-space: nowrap; }
        .video-container { position: relative; margin: 20px 0; border: 2px dashed #ccc; border-radius: 4px; overflow: hidden; }
        #video, #faceVideo { width: 100%; display: block; }
        #canvas, #faceCanvas { position: absolute; top: 0; left: 0; }
        .face-status { margin-top: 10px; padding: 10px; border-radius: 5px; text-align: center; display: none; }
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #ddd; }
        .tab { padding: 10px 20px; cursor: pointer; transition: all 0.3s; }
        .tab:hover { background: #f0f0f0; }
        .tab.active { border-bottom: 2px solid #667eea; color: #667eea; font-weight: bold; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .alert-box { background: #fed7d7; border-left: 4px solid #f56565; padding: 15px; margin: 15px 0; border-radius: 5px; color: #c53030; }
        .success-box { background: #c6f6d5; border-left: 4px solid #48bb78; padding: 15px; margin: 15px 0; border-radius: 5px; color: #22543d; }
        .warning-box { background: #feebc8; border-left: 4px solid #ed8936; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .loading { display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; margin-left: 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    </style>
</head>
<body>
    <div class="header">
        <h1>👨‍🎓 Manage Students</h1>
        <a href="../dashboard.php" style="color: white; text-decoration: none;">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if (isset($success)): ?>
            <div style="background: #c6f6d5; padding: 10px; border-radius: 5px; margin-bottom: 20px;">✅ <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error_msg)): ?>
            <div style="background: #fed7d7; padding: 10px; border-radius: 5px; margin-bottom: 20px;">❌ <?php echo $error_msg; ?></div>
        <?php endif; ?>
        
        <div class="tabs">
            <div class="tab active" onclick="showTab('add')">➕ Add New Student</div>
            <div class="tab" onclick="showTab('list')">📋 Student List</div>
        </div>
        
        <!-- Tab 1: Add New Student -->
        <div id="addTab" class="tab-content active">
            <div class="form-container" style="background: white; padding: 25px; border-radius: 10px;">
                <h3>Add New Student</h3>
                <form method="POST" id="addStudentForm" onsubmit="return validateAddForm()">
                    <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" required></div>
                    <div class="form-group"><label>Username *</label><input type="text" name="username" required></div>
                    <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
                    <div class="form-group"><label>Mobile Number</label><input type="text" name="mobile"></div>
                    <div class="form-group"><label>Password * (min 6 characters)</label><input type="password" name="password" required></div>
                    
                    <div class="form-group">
                        <button type="button" id="startCameraBtn" class="btn btn-primary">📸 Start Camera for Face Registration</button>
                    </div>
                    <div class="video-container" id="videoContainer" style="display: none;">
                        <video id="video" autoplay muted></video>
                        <canvas id="canvas"></canvas>
                    </div>
                    <div class="form-group">
                        <button type="button" id="captureFaceBtn" class="btn btn-info" disabled>Capture Face</button>
                    </div>
                    <div id="faceStatus" class="face-status"></div>
                    <input type="hidden" name="face_data" id="faceData">
                    <button type="submit" name="add_student" class="btn btn-success" style="width: 100%; margin-top: 10px;">Add Student</button>
                </form>
            </div>
        </div>
        
        <!-- Tab 2: Student List -->
        <div id="listTab" class="tab-content">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>Avatar</th><th>Full Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Face Status</th><th>Verification</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_students as $student): ?>
                        <tr>
                            <td class="avatar-cell"><div class="avatar"><?php echo strtoupper(substr($student['full_name'], 0, 1)); ?></div></td>
                            <td><?php echo htmlspecialchars($student['full_name']); ?> </br>
                            <td><?php echo htmlspecialchars($student['username']); ?> </br>
                            <td><?php echo htmlspecialchars($student['email']); ?> </br>
                            <td><?php echo $student['mobile'] ?: '-'; ?> </br>
                            <td><?php echo $student['face_embedding'] ? '<span class="status-badge status-face">✓ Face Registered</span>' : '<span class="status-badge status-no-face">✗ No Face</span>'; ?> </br>
                            <td>
                                <?php if ($student['is_verified']): ?>
                                    <span class="status-badge status-verified">✓ Verified</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">⏳ Pending</span>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                        <button type="submit" name="verify_student" class="btn btn-success" style="padding: 3px 8px; font-size: 11px;">Verify</button>
                                    </form>
                                <?php endif; ?>
                             </br>
                            <td class="action-buttons">
                                <button class="btn btn-warning" onclick="openFaceModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['full_name']); ?>', <?php echo $student['face_embedding'] ? 'true' : 'false'; ?>)">
                                    <?php echo $student['face_embedding'] ? '🔄 Update Face' : '📸 Register Face'; ?>
                                </button>
                                <button class="btn btn-danger" onclick="deleteStudent(<?php echo $student['id']; ?>)">🗑️ Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Face Modal with Verification -->
    <div id="faceModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle">Face Verification</h3>
            <div id="verificationResult" style="display: none;"></div>
            <div class="video-container">
                <video id="faceVideo" autoplay muted></video>
                <canvas id="faceCanvas"></canvas>
            </div>
            <button id="captureVerifyBtn" class="btn btn-primary" style="width: 100%;">📸 Capture & Verify Face</button>
            <button onclick="closeFaceModal()" class="btn" style="margin-top: 10px;">Cancel</button>
            <div id="faceModalStatus" class="face-status"></div>
        </div>
    </div>
    
    <script>
        let stream = null;
        let modelsLoaded = false;
        let currentStudentId = null;
        let currentStudentName = null;
        let currentHasFace = false;
        let verifiedFaceData = null;
        let verificationToken = null;
        let verificationPassed = false;
        
        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            if (tab === 'add') {
                document.querySelector('.tab:first-child').classList.add('active');
                document.getElementById('addTab').classList.add('active');
            } else {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('listTab').classList.add('active');
            }
        }
        
        async function loadModels() {
            try {
                let modelsPath = '/face/models';
                if (window.location.pathname.includes('/college_election_system/')) {
                    modelsPath = '/college_election_system/face/models';
                }
                await faceapi.nets.tinyFaceDetector.loadFromUri(modelsPath);
                await faceapi.nets.faceLandmark68Net.loadFromUri(modelsPath);
                await faceapi.nets.faceRecognitionNet.loadFromUri(modelsPath);
                modelsLoaded = true;
                console.log('Models loaded');
            } catch (error) {
                console.error('Error loading models:', error);
                alert('Face recognition models failed to load');
            }
        }
        
        function validateAddForm() {
            const faceData = document.getElementById('faceData').value;
            if (!faceData) {
                alert('Please capture student face before adding!');
                return false;
            }
            return true;
        }
        
        async function startAddCamera() {
            try {
                if (stream) stream.getTracks().forEach(track => track.stop());
                stream = await navigator.mediaDevices.getUserMedia({ video: true });
                document.getElementById('video').srcObject = stream;
                document.getElementById('videoContainer').style.display = 'block';
                document.getElementById('startCameraBtn').disabled = true;
                document.getElementById('captureFaceBtn').disabled = false;
            } catch (error) {
                alert('Could not access camera');
            }
        }
        
        async function captureFaceForAdd() {
            const video = document.getElementById('video');
            const canvas = document.getElementById('canvas');
            const faceStatus = document.getElementById('faceStatus');
            
            try {
                const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                if (detections.length === 0) {
                    faceStatus.innerHTML = '❌ No face detected. Please position face clearly.';
                    faceStatus.style.display = 'block';
                    return;
                }
                if (detections.length > 1) {
                    faceStatus.innerHTML = '❌ Multiple faces detected. Please ensure only one face.';
                    faceStatus.style.display = 'block';
                    return;
                }
                
                const faceData = JSON.stringify(detections[0].descriptor);
                document.getElementById('faceData').value = faceData;
                
                faceapi.matchDimensions(canvas, video);
                faceapi.draw.drawDetections(canvas, detections);
                faceapi.draw.drawFaceLandmarks(canvas, detections);
                
                faceStatus.innerHTML = '✅ Face captured successfully! You can now add the student.';
                faceStatus.style.background = '#c6f6d5';
                faceStatus.style.color = '#22543d';
                faceStatus.style.display = 'block';
                
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    stream = null;
                }
            } catch (error) {
                faceStatus.innerHTML = '❌ Error capturing face. Please try again.';
                faceStatus.style.display = 'block';
            }
        }
        
        function openFaceModal(studentId, studentName, hasFace) {
            currentStudentId = studentId;
            currentStudentName = studentName;
            currentHasFace = hasFace;
            verificationPassed = false;
            verifiedFaceData = null;
            verificationToken = null;
            
            document.getElementById('modalTitle').innerHTML = hasFace ? 
                `🔐 Verify Identity - ${studentName}` : 
                `📸 Register Face - ${studentName}`;
            document.getElementById('verificationResult').style.display = 'none';
            document.getElementById('verificationResult').innerHTML = '';
            document.getElementById('faceModalStatus').style.display = 'none';
            
            const btn = document.getElementById('captureVerifyBtn');
            btn.innerHTML = '📸 Capture & Verify Face';
            btn.onclick = () => captureAndVerify();
            btn.disabled = false;
            
            document.getElementById('faceModal').style.display = 'flex';
            startFaceCamera();
        }
        
        async function startFaceCamera() {
            try {
                if (stream) stream.getTracks().forEach(track => track.stop());
                stream = await navigator.mediaDevices.getUserMedia({ video: true });
                document.getElementById('faceVideo').srcObject = stream;
            } catch (error) {
                alert('Could not access camera');
            }
        }
        
        async function captureAndVerify() {
            const video = document.getElementById('faceVideo');
            const faceStatus = document.getElementById('faceModalStatus');
            const btn = document.getElementById('captureVerifyBtn');
            const verificationResult = document.getElementById('verificationResult');
            
            try {
                btn.disabled = true;
                btn.innerHTML = 'Verifying... <span class="loading"></span>';
                faceStatus.style.display = 'none';
                verificationResult.style.display = 'none';
                
                const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                if (detections.length === 0) {
                    faceStatus.innerHTML = '❌ No face detected. Please position face clearly.';
                    faceStatus.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '📸 Capture & Verify Face';
                    return;
                }
                
                if (detections.length > 1) {
                    faceStatus.innerHTML = '❌ Multiple faces detected. Please ensure only one face.';
                    faceStatus.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '📸 Capture & Verify Face';
                    return;
                }
                
                const faceData = JSON.stringify(detections[0].descriptor);
                
                const formData = new FormData();
                formData.append('verify_face', '1');
                formData.append('student_id', currentStudentId);
                formData.append('face_data', faceData);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    verificationPassed = true;
                    verifiedFaceData = faceData;
                    verificationToken = 'verified_' + currentStudentId;
                    
                    let message = '';
                    if (result.is_first_time) {
                        message = '✅ No existing face found. You can register this face.';
                    } else {
                        let percentage = (result.similarity * 100).toFixed(1);
                        message = `✅ Face Verified! Similarity: ${percentage}% - Same person confirmed.`;
                    }
                    
                    verificationResult.innerHTML = `<div class="success-box">${message}<br>Click "Confirm Update" to save.</div>`;
                    verificationResult.style.display = 'block';
                    btn.innerHTML = '✅ Confirm Update';
                    btn.onclick = () => finalizeUpdate();
                } else {
                    let percentage = result.similarity ? (result.similarity * 100).toFixed(1) : '0';
                    verificationResult.innerHTML = `
                        <div class="alert-box">
                            🚨 <strong>SECURITY ALERT!</strong><br>
                            ${result.message}<br>
                            <strong>Similarity: ${percentage}% - This appears to be a DIFFERENT PERSON!</strong><br>
                            Update blocked for security.
                        </div>
                    `;
                    verificationResult.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '📸 Try Again';
                    btn.onclick = () => captureAndVerify();
                }
                
            } catch (error) {
                console.error('Error:', error);
                faceStatus.innerHTML = '❌ Error processing face. Please try again.';
                faceStatus.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '📸 Capture & Verify Face';
            }
        }
        
        async function finalizeUpdate() {
            if (!verificationPassed) {
                alert('Please verify face first!');
                return;
            }
            
            const btn = document.getElementById('captureVerifyBtn');
            
            try {
                btn.disabled = true;
                btn.innerHTML = 'Updating... <span class="loading"></span>';
                
                const formData = new FormData();
                formData.append('update_face', '1');
                formData.append('student_id', currentStudentId);
                formData.append('face_data', verifiedFaceData);
                formData.append('verification_token', verificationToken);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Face ' + (currentHasFace ? 'updated' : 'registered') + ' successfully!');
                    location.reload();
                } else {
                    alert('❌ Error: ' + result.message);
                    btn.disabled = false;
                    btn.innerHTML = '✅ Try Again';
                }
                
            } catch (error) {
                alert('Error updating face. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '✅ Try Again';
            }
        }
        
        function closeFaceModal() {
            document.getElementById('faceModal').style.display = 'none';
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
        }
        
        function deleteStudent(studentId) {
            if (confirm('Are you sure you want to delete this student?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="delete_student" value="1"><input type="hidden" name="student_id" value="${studentId}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            loadModels();
            const startBtn = document.getElementById('startCameraBtn');
            const captureBtn = document.getElementById('captureFaceBtn');
            if (startBtn) startBtn.addEventListener('click', startAddCamera);
            if (captureBtn) captureBtn.addEventListener('click', captureFaceForAdd);
        });
    </script>
</body>
</html>