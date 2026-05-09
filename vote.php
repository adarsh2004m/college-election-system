<?php
require_once '../includes/auth.php';
require_once '../config/db_config.php';
require_once '../includes/functions.php';
requireRole('student');

$student_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get student details
$stmt = $pdo->prepare("SELECT is_verified, face_embedding FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student['is_verified']) {
    $error = "Your account is pending admin approval. Please wait for verification before voting.";
}

if (!$student['face_embedding']) {
    $error = "No face registered. Please contact administrator to register your face.";
}

// Get ALL active elections (including ones where student is candidate)
$active_elections = getActiveElections($pdo);
$upcoming_elections = getUpcomingElections($pdo);

// Check if student is a candidate (just for display, not for restriction)
$stmt = $pdo->prepare("
    SELECT c.election_id, e.election_name 
    FROM candidates c
    JOIN elections e ON c.election_id = e.id
    WHERE c.student_id = ? AND e.status = 'active'
");
$stmt->execute([$student_id]);
$candidate_in_elections = $stmt->fetchAll();

$is_candidate_list = [];
foreach ($candidate_in_elections as $candidate) {
    $is_candidate_list[] = $candidate['election_id'];
}

// Handle face verification and voting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_vote'])) {
    $election_id = $_POST['election_id'];
    $candidate_id = $_POST['candidate_id'];
    $face_data = $_POST['face_data'] ?? null;
    
    // Check if already voted
    if (hasVoted($pdo, $student_id, $election_id)) {
        $error = "You have already voted in this election!";
    } 
    elseif (!$student['is_verified']) {
        $error = "Your account is not verified. Please contact admin.";
    }
    elseif (!$student['face_embedding']) {
        $error = "No face registered. Please contact administrator.";
    }
    elseif ($face_data) {
        // Verify face
        $stored_face = json_decode($student['face_embedding'], true);
        $input_face = json_decode($face_data, true);
        
        if ($stored_face && $input_face) {
            $similarity = calculateCosineSimilarity($stored_face, $input_face);
            
            if ($similarity > 0.95) {
                // Record vote
                $stmt = $pdo->prepare("
                    INSERT INTO votes (election_id, candidate_id, student_id, face_verified, similarity_score, ip_address)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ip = $_SERVER['REMOTE_ADDR'];
                $stmt->execute([$election_id, $candidate_id, $student_id, true, $similarity, $ip]);
                
                // Update candidate vote count
                $stmt = $pdo->prepare("UPDATE candidates SET vote_count = vote_count + 1 WHERE id = ?");
                $stmt->execute([$candidate_id]);
                
                $message = "✅ Vote cast successfully! Your vote has been recorded.";
                header("Refresh:2");
            } else {
                $error = "Face verification failed. Similarity score: " . round($similarity * 100, 1) . "%. Please try again.";
            }
        } else {
            $error = "Face data error. Please contact administrator.";
        }
    } else {
        $error = "Please complete face verification.";
    }
}

// Get candidates for active elections (INCLUDING self)
$election_candidates = [];
foreach ($active_elections as $election) {
    if (!hasVoted($pdo, $student_id, $election['id'])) {
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name, u.username 
            FROM candidates c
            JOIN users u ON c.student_id = u.id
            WHERE c.election_id = ? AND c.status = 'approved'
            ORDER BY c.id
        ");
        $stmt->execute([$election['id']]);
        $election_candidates[$election['id']] = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cast Your Vote - College Election System</title>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .message { background: #c6f6d5; color: #22543d; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .error { background: #fed7d7; color: #c53030; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .info { background: #e3f2fd; color: #1976d2; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .election-card {
            background: white; border-radius: 10px; padding: 25px; margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            clear: both;
            overflow: hidden;
        }
        .election-card.candidate-election {
            border: 2px solid #ed8936;
            background: #fffaf5;
        }
        .election-title { font-size: 24px; font-weight: bold; color: #333; margin-bottom: 5px; }
        .election-date { color: #666; margin-bottom: 20px; }
        .candidates-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px; }
        .candidate-card {
            border: 2px solid #e0e0e0; border-radius: 10px; padding: 20px; text-align: center;
            transition: all 0.3s; cursor: pointer;
            position: relative;
        }
        .candidate-card:hover { border-color: #667eea; transform: translateY(-3px); }
        .candidate-card.selected { border-color: #48bb78; background: #f0fff4; }
        .candidate-card.self-candidate {
            border-color: #ed8936;
            background: #fff8f0;
        }
        .candidate-card.self-candidate.selected {
            border-color: #48bb78;
            background: #f0fff4;
        }
        .self-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #ed8936;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
        }
        .candidate-name { font-size: 18px; font-weight: bold; margin: 10px 0; }
        .candidate-symbol { font-size: 48px; margin: 10px 0; }
        .candidate-manifesto { color: #666; font-size: 12px; margin-top: 10px; }
        .vote-btn {
            background: #48bb78; color: white; border: none; padding: 12px 30px;
            border-radius: 5px; font-size: 16px; cursor: pointer; margin-top: 20px;
            transition: all 0.3s;
        }
        .vote-btn:hover { background: #38a169; transform: translateY(-2px); }
        .vote-btn:disabled { background: #ccc; cursor: not-allowed; transform: none; }
        .camera-modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center;
        }
        .modal-content {
            background: white; padding: 30px; border-radius: 10px; max-width: 500px; width: 90%;
        }
        #verifyVideo { width: 100%; margin: 15px 0; border-radius: 5px; }
        .upcoming-card {
            background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin-bottom: 15px;
            border-radius: 5px;
        }
        .candidate-badge {
            background: #ed8936; color: white; padding: 4px 10px; border-radius: 20px;
            font-size: 11px; display: inline-block; margin-left: 10px;
        }
        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; }
            .election-title { font-size: 20px; }
            .candidates-grid { grid-template-columns: 1fr; }
            .candidate-card { padding: 15px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🗳️ Cast Your Vote</h1>
        <a href="../dashboard.php" style="color: white; text-decoration: none;">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Info about self-voting -->
        <div class="info">
            ℹ️ <strong>Note:</strong> Candidates can vote in all elections, including the ones they are participating in.
        </div>
        
        <!-- Show if student is a candidate (info only) -->
        <?php if (!empty($candidate_in_elections)): ?>
            <div class="info">
                <strong>🗳️ You are a candidate in:</strong><br>
                <?php foreach ($candidate_in_elections as $candidate): ?>
                    • <?php echo htmlspecialchars($candidate['election_name']); ?><br>
                <?php endforeach; ?>
                <small>You can still vote in these elections, including voting for yourself!</small>
            </div>
        <?php endif; ?>
        
        <!-- Account Status Checks -->
        <?php if (!$student['is_verified']): ?>
            <div class="error">
                <strong>⚠️ Account Not Verified</strong><br>
                Your account is pending admin approval. Please wait for verification before voting.
            </div>
        <?php elseif (!$student['face_embedding']): ?>
            <div class="error">
                <strong>⚠️ Face Not Registered</strong><br>
                Your face has not been registered. Please contact the administrator.
            </div>
        <?php elseif (empty($active_elections)): ?>
            <div class="message">
                No active elections at the moment. Please check back later.
            </div>
        <?php else: ?>
            <!-- Active Elections (INCLUDING ones where student is candidate) -->
            <?php foreach ($active_elections as $election): ?>
                <?php 
                $is_candidate_election = in_array($election['id'], $is_candidate_list);
                $has_voted = hasVoted($pdo, $student_id, $election['id']);
                ?>
                
                <?php if ($has_voted): ?>
                    <div class="election-card">
                        <div class="election-title"><?php echo htmlspecialchars($election['election_name']); ?></div>
                        <div class="message">✅ You have already voted in this election. Thank you for participating!</div>
                    </div>
                <?php elseif (isset($election_candidates[$election['id']]) && count($election_candidates[$election['id']]) > 0): ?>
                    <div class="election-card <?php echo $is_candidate_election ? 'candidate-election' : ''; ?>">
                        <div class="election-title">
                            <?php echo htmlspecialchars($election['election_name']); ?>
                            <?php if ($is_candidate_election): ?>
                                <span class="candidate-badge">You are a candidate in this election</span>
                            <?php endif; ?>
                        </div>
                        <div class="election-date">
                            Voting Period: <?php echo date('F j, Y', strtotime($election['start_date'])); ?> - 
                            <?php echo date('F j, Y', strtotime($election['end_date'])); ?>
                        </div>
                        <p><?php echo htmlspecialchars($election['description']); ?></p>
                        
                        <div class="candidates-grid" id="candidates_<?php echo $election['id']; ?>">
                            <?php foreach ($election_candidates[$election['id']] as $candidate): ?>
                                <?php $is_self = ($candidate['student_id'] == $student_id); ?>
                                <div class="candidate-card <?php echo $is_self ? 'self-candidate' : ''; ?>" 
                                     onclick="selectCandidate(<?php echo $election['id']; ?>, <?php echo $candidate['id']; ?>)">
                                    <?php if ($is_self): ?>
                                        <div class="self-badge">You</div>
                                    <?php endif; ?>
                                    <div class="candidate-symbol"><?php echo htmlspecialchars($candidate['symbol'] ?: '🗳️'); ?></div>
                                    <div class="candidate-name">
                                        <?php echo htmlspecialchars($candidate['full_name']); ?>
                                        <?php if ($is_self): ?>
                                            <span style="color: #ed8936;"> (Yourself)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="candidate-manifesto"><?php echo htmlspecialchars(substr($candidate['manifesto'], 0, 100)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" id="selected_candidate_<?php echo $election['id']; ?>" value="">
                        <button class="vote-btn" onclick="openVerification(<?php echo $election['id']; ?>)" id="voteBtn_<?php echo $election['id']; ?>" disabled>
                            🔐 Verify Face & Vote
                        </button>
                    </div>
                <?php elseif (isset($election_candidates[$election['id']]) && count($election_candidates[$election['id']]) == 0): ?>
                    <div class="election-card">
                        <div class="election-title"><?php echo htmlspecialchars($election['election_name']); ?></div>
                        <div class="error">No candidates available for this election yet.</div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Upcoming Elections -->
        <?php if ($upcoming_elections): ?>
            <div class="election-card" style="background: #f0f7ff;">
                <div class="election-title">📅 Upcoming Elections</div>
                <?php foreach ($upcoming_elections as $election): ?>
                    <div class="upcoming-card">
                        <strong><?php echo htmlspecialchars($election['election_name']); ?></strong><br>
                        Starts: <?php echo date('F j, Y g:i A', strtotime($election['start_date'])); ?><br>
                        <?php echo htmlspecialchars($election['description']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Face Verification Modal -->
    <div id="verifyModal" class="camera-modal">
        <div class="modal-content">
            <h3>🔐 Face Verification Required</h3>
            <p>Please look at the camera to verify your identity before voting.</p>
            <video id="verifyVideo" autoplay muted playsinline></video>
            <canvas id="verifyCanvas" style="display: none;"></canvas>
            <button onclick="verifyAndVote()" class="vote-btn" style="width: 100%;">Verify Identity & Cast Vote</button>
            <button onclick="closeModal()" style="margin-top: 10px; width: 100%; padding: 10px; background: #ccc; border: none; border-radius: 5px; cursor: pointer;">Cancel</button>
        </div>
    </div>
    
    <script>
        let currentElectionId = null;
        let currentCandidateId = null;
        let stream = null;
        let modelsLoaded = false;
        
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
                alert('Face verification system unavailable. Please contact administrator.');
            }
        }
        
        function selectCandidate(electionId, candidateId) {
            // Remove selection from all candidates in this election
            document.querySelectorAll(`#candidates_${electionId} .candidate-card`).forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked candidate
            event.currentTarget.classList.add('selected');
            document.getElementById(`selected_candidate_${electionId}`).value = candidateId;
            document.getElementById(`voteBtn_${electionId}`).disabled = false;
            currentCandidateId = candidateId;
        }
        
        function openVerification(electionId) {
            if (!modelsLoaded) {
                alert('Face recognition system is loading. Please wait a moment.');
                return;
            }
            
            currentElectionId = electionId;
            currentCandidateId = document.getElementById(`selected_candidate_${electionId}`).value;
            
            if (!currentCandidateId) {
                alert('Please select a candidate first');
                return;
            }
            
            document.getElementById('verifyModal').style.display = 'flex';
            startVerificationCamera();
        }
        
        async function startVerificationCamera() {
            try {
                if (stream) stream.getTracks().forEach(track => track.stop());
                stream = await navigator.mediaDevices.getUserMedia({ video: true });
                document.getElementById('verifyVideo').srcObject = stream;
            } catch (error) {
                alert('Could not access camera. Please ensure you have granted camera permissions.');
            }
        }
        
        async function verifyAndVote() {
            const video = document.getElementById('verifyVideo');
            
            try {
                const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                if (detections.length === 0) {
                    alert('No face detected. Please position your face clearly in the frame.');
                    return;
                }
                
                if (detections.length > 1) {
                    alert('Multiple faces detected. Please ensure only your face is visible.');
                    return;
                }
                
                const faceData = JSON.stringify(detections[0].descriptor);
                
                // Submit vote
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="verify_vote" value="1">
                    <input type="hidden" name="election_id" value="${currentElectionId}">
                    <input type="hidden" name="candidate_id" value="${currentCandidateId}">
                    <input type="hidden" name="face_data" value='${faceData}'>
                `;
                document.body.appendChild(form);
                form.submit();
                
                closeModal();
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error during face verification. Please try again.');
            }
        }
        
        function closeModal() {
            document.getElementById('verifyModal').style.display = 'none';
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
        }
        
        document.addEventListener('DOMContentLoaded', loadModels);
        
        window.addEventListener('beforeunload', function() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>