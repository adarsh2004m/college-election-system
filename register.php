<?php
session_start();
require_once 'config/db_config.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $full_name = trim($_POST['full_name']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $face_data = $_POST['face_data'] ?? null;
    
    // Validation
    if (empty($username)) $errors[] = "Username is required";
    if (empty($email)) $errors[] = "Email is required";
    if (empty($full_name)) $errors[] = "Full name is required";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match";
    if (!$face_data) $errors[] = "Please capture your face for verification";
    
    // Check if username exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        $errors[] = "Username or email already exists";
    }
    
    if (empty($errors)) {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $face_embedding = json_encode(json_decode($face_data));
            
            // Insert with is_verified = 0 (pending admin approval)
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, mobile, full_name, password_hash, face_embedding, role, is_verified) 
                VALUES (?, ?, ?, ?, ?, ?, 'student', 0)
            ");
            $stmt->execute([$username, $email, $mobile, $full_name, $password_hash, $face_embedding]);
            
            $success = "Registration successful! Your account is pending admin approval. You will be notified once verified.";
            
            // Clear form data
            $_POST = [];
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - Election System</title>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .register-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 { text-align: center; color: #333; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 30px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
        .btn {
            width: 100%; padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; border: none; border-radius: 5px;
            font-size: 16px; cursor: pointer;
            margin-top: 10px;
        }
        .btn-camera { background: #4299e1; margin-bottom: 10px; }
        .video-container { position: relative; margin: 15px 0; border: 2px dashed #ccc; border-radius: 5px; overflow: hidden; }
        #video { width: 100%; display: block; }
        #canvas { position: absolute; top: 0; left: 0; }
        .error { background: #fed7d7; color: #c53030; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .success { background: #c6f6d5; color: #22543d; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .face-status { margin-top: 10px; padding: 10px; border-radius: 5px; text-align: center; display: none; }
        .face-status.success { background: #c6f6d5; color: #22543d; display: block; }
        .face-status.error { background: #fed7d7; color: #c53030; display: block; }
        .info-box {
            background: #e3f2fd; padding: 15px; border-radius: 5px; margin-bottom: 20px;
            border-left: 4px solid #2196f3;
        }
        .info-box p { margin: 5px 0; color: #1976d2; }
        .login-link { text-align: center; margin-top: 20px; }
        .login-link a { color: #667eea; text-decoration: none; }
    </style>
</head>
<body>
    <div class="register-container">
        <h1>🗳️ Student Registration</h1>
        <div class="subtitle">Register to vote in college elections</div>
        
        <div class="info-box">
            <p>📋 Registration Process:</p>
            <p>1. Fill in your details below</p>
            <p>2. Capture your face for verification</p>
            <p>3. Submit for admin approval</p>
            <p>4. Wait for admin to verify your account</p>
        </div>
        
        <?php if ($success): ?>
            <div class="success">
                <?php echo $success; ?>
                <p style="margin-top: 10px;">
                    <a href="login.php" style="color: #22543d; font-weight: bold;">Click here to login</a>
                </p>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <p>❌ <?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
        <form method="POST" id="registerForm">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Mobile Number</label>
                <input type="text" name="mobile" value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Password * (min 6 characters)</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" required>
            </div>
            
            <div class="form-group">
                <button type="button" id="startCamera" class="btn btn-camera">📸 Start Camera for Face Registration</button>
            </div>
            
            <div class="video-container" id="videoContainer" style="display: none;">
                <video id="video" autoplay muted></video>
                <canvas id="canvas"></canvas>
            </div>
            
            <div class="form-group">
                <button type="button" id="captureFace" class="btn btn-camera" disabled>Capture Face</button>
            </div>
            
            <div id="faceStatus" class="face-status"></div>
            <input type="hidden" name="face_data" id="faceData">
            
            <button type="submit" class="btn">Register & Submit for Approval</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        let stream = null;
        let modelsLoaded = false;
        
        async function loadModels() {
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri('/face/models');
                await faceapi.nets.faceLandmark68Net.loadFromUri('/face/models');
                await faceapi.nets.faceRecognitionNet.loadFromUri('/face/models');
                modelsLoaded = true;
                document.getElementById('startCamera').disabled = false;
                console.log('Face models loaded');
            } catch (error) {
                console.error('Error loading models:', error);
                document.getElementById('faceStatus').innerHTML = '⚠️ Face recognition not available. Please contact administrator.';
                document.getElementById('faceStatus').className = 'face-status error';
            }
        }
        
        async function startVideo() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true });
                const video = document.getElementById('video');
                video.srcObject = stream;
                document.getElementById('videoContainer').style.display = 'block';
                document.getElementById('startCamera').disabled = true;
                document.getElementById('captureFace').disabled = false;
            } catch (error) {
                alert('Could not access camera. Please ensure you have granted camera permissions.');
            }
        }
        
        async function captureFace() {
            const video = document.getElementById('video');
            const canvas = document.getElementById('canvas');
            const faceStatus = document.getElementById('faceStatus');
            
            try {
                const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                if (detections.length === 0) {
                    faceStatus.innerHTML = '❌ No face detected. Please position your face clearly in frame.';
                    faceStatus.className = 'face-status error';
                    return;
                }
                
                if (detections.length > 1) {
                    faceStatus.innerHTML = '❌ Multiple faces detected. Please ensure only your face is visible.';
                    faceStatus.className = 'face-status error';
                    return;
                }
                
                // Store the face descriptor
                const faceData = JSON.stringify(detections[0].descriptor);
                document.getElementById('faceData').value = faceData;
                
                // Draw detection on canvas for visual feedback
                faceapi.matchDimensions(canvas, video);
                faceapi.draw.drawDetections(canvas, detections);
                faceapi.draw.drawFaceLandmarks(canvas, detections);
                
                faceStatus.innerHTML = '✅ Face captured successfully! You can now submit your registration.';
                faceStatus.className = 'face-status success';
                
                // Stop camera after capture
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                }
                
            } catch (error) {
                console.error('Error capturing face:', error);
                faceStatus.innerHTML = '❌ Error capturing face. Please try again.';
                faceStatus.className = 'face-status error';
            }
        }
        
        // Validate form before submit
        document.getElementById('registerForm')?.addEventListener('submit', function(e) {
            const faceData = document.getElementById('faceData').value;
            if (!faceData) {
                e.preventDefault();
                alert('Please capture your face before registering.');
            }
        });
        
        document.addEventListener('DOMContentLoaded', () => {
            loadModels();
            document.getElementById('startCamera')?.addEventListener('click', startVideo);
            document.getElementById('captureFace')?.addEventListener('click', captureFace);
        });
        
        window.addEventListener('beforeunload', () => {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>