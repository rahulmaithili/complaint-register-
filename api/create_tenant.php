<?php
// api/create_tenant.php
// This API receives webhook calls from your Subscription Management System

header('Content-Type: application/json');

// Secret key to verify the request comes from your Subscription System
$API_SECRET = 'GAS_AGENCY_SECRET_9988'; 

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST requests allowed']);
    exit();
}

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit();
}

// Verify Secret
if (!isset($data['secret']) || $data['secret'] !== $API_SECRET) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid Secret']);
    exit();
}

// Extract Required Fields
$agency_name = trim($data['agency_name'] ?? '');
$owner_name = trim($data['owner_name'] ?? '');
$email = trim($data['email'] ?? '');
$mobile = trim($data['mobile'] ?? '');
$password = trim($data['password'] ?? ''); // Plaintext password

if (empty($agency_name) || empty($owner_name) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    // Connect to Master DB
    $dbPath = __DIR__ . '/../master.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM agencies WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Agency with this email already exists']);
        exit();
    }

    // Generate unique database filename
    $db_filename = 'db_agency_' . time() . '_' . rand(1000, 9999) . '.sqlite';
    $templatePath = __DIR__ . '/../template.sqlite';
    $newDbPath = __DIR__ . '/../' . $db_filename;

    // Clone Template Database
    if (!copy($templatePath, $newDbPath)) {
        throw new Exception("Failed to clone database from template.");
    }

    // Hash Password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Insert into Master Database
    $insertStmt = $db->prepare("INSERT INTO agencies (agency_name, owner_name, email, mobile, password_hash, db_filename, subscription_status, subscription_start, subscription_end) VALUES (?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, date('now', '+30 days'))");
    $insertStmt->execute([
        $agency_name,
        $owner_name,
        $email,
        $mobile,
        $password_hash,
        $db_filename
    ]);

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Tenant successfully created.',
        'agency_id' => $db->lastInsertId(),
        'db_filename' => $db_filename
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
