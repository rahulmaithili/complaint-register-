<?php
/**
 * Gas Agency Standalone Complaint Register
 * A fully self-contained portable single-file PHP application
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_URL', ''); // Self-relative URLs

// SaaS Authentication Check
// Instead of redirecting, we stay on this page. If not authenticated, $db remains null.
$db = null;

if (!empty($_SESSION['agency_id']) && !empty($_SESSION['db_filename'])) {
    try {
        $db_filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $_SESSION['db_filename']);
        $db = new PDO("sqlite:" . __DIR__ . "/" . $db_filename);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Enable busy timeout (wait up to 15 seconds for locks to clear) and WAL mode for concurrent read/write stability
        $db->exec("PRAGMA busy_timeout = 15000;");
        $db->exec("PRAGMA journal_mode = WAL;");
    } catch (PDOException $e) {
        die("Database Connection Error: " . $e->getMessage());
    }
}

if ($db) {
    // Expand schema for new employee columns if not exist
    try {
        $db->exec("ALTER TABLE gas_users ADD COLUMN mobile TEXT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE gas_users ADD COLUMN profile_photo TEXT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE gas_consumers ADD COLUMN ekyc_status TEXT DEFAULT ''");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE gas_complaints ADD COLUMN fail_reason TEXT DEFAULT ''");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE gas_complaints ADD COLUMN fail_notes TEXT DEFAULT ''");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE gas_complaints ADD COLUMN photo_proof_url TEXT DEFAULT ''");
    } catch (Exception $e) {}

    // Multi-branch schema migrations
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS gas_branches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                code TEXT DEFAULT '',
                brand TEXT DEFAULT 'HP',
                address TEXT DEFAULT '',
                mobile TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } catch (Exception $e) {}

    try {
        $count = (int)$db->query("SELECT COUNT(*) FROM gas_branches")->fetchColumn();
        if ($count === 0) {
            $db->exec("INSERT INTO gas_branches (id, name, code, brand, address) VALUES (1, 'Main Branch', '00000', 'HP', 'Main Office')");
        }
    } catch (Exception $e) {}
    
    // Default branch ID logic...
    $default_branch_id = 1;
    try {
        $default_branch_id = (int)$db->query("SELECT id FROM gas_branches ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
    } catch (Exception $e) {}

    try {
        $db->exec("ALTER TABLE gas_users ADD COLUMN branch_id INTEGER DEFAULT $default_branch_id");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE gas_complaints ADD COLUMN branch_id INTEGER DEFAULT $default_branch_id");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE gas_consumers ADD COLUMN branch_id INTEGER DEFAULT $default_branch_id");
    } catch (Exception $e) {}
    try {
        $db->exec("UPDATE gas_users SET branch_id = $default_branch_id WHERE branch_id IS NULL OR branch_id = 0");
    } catch (Exception $e) {}
    try {
        $db->exec("UPDATE gas_complaints SET branch_id = $default_branch_id WHERE branch_id IS NULL OR branch_id = 0");
    } catch (Exception $e) {}
    try {
        $db->exec("UPDATE gas_consumers SET branch_id = $default_branch_id WHERE branch_id IS NULL OR branch_id = 0");
    } catch (Exception $e) {}

    // Auto-run schema setup if tables don't exist
    try {
        $db->query("SELECT 1 FROM gas_users LIMIT 1");
    } catch (Exception $e) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS gas_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                name TEXT NOT NULL,
                role TEXT DEFAULT 'Employee',
                permissions TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by TEXT DEFAULT 'system',
                active INTEGER DEFAULT 1,
                pending_password TEXT DEFAULT '',
                reset_requested_at DATETIME NULL
            );

            CREATE TABLE IF NOT EXISTS gas_vendors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                mobile TEXT NOT NULL,
                code TEXT DEFAULT '',
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS gas_complaints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                consumer_number TEXT NULL,
                consumer_name TEXT NOT NULL,
                mobile TEXT NOT NULL,
                address TEXT NOT NULL,
                source TEXT NOT NULL,
                complaint TEXT NOT NULL,
                status TEXT DEFAULT 'Pending',
                vendor_id INTEGER NULL,
                vendor TEXT NULL,
                signature_url TEXT DEFAULT '',
                resolved_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                deleted INTEGER DEFAULT 0,
                deleted_at DATETIME NULL,
                deleted_by TEXT DEFAULT '',
                FOREIGN KEY (vendor_id) REFERENCES gas_vendors(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS gas_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NULL
            );

            CREATE TABLE IF NOT EXISTS gas_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                record_id INTEGER NULL,
                username TEXT NOT NULL,
                details TEXT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            INSERT OR IGNORE INTO gas_users (id, username, password, name, role, permissions, active) 
            VALUES (1, 'admin', '21f25c8a583f2c993255e9008a9f332f486c6083ff113070490bb711003f259a', 'Administrator', 'Admin', '*', 1);

            INSERT OR IGNORE INTO gas_settings (setting_key, setting_value) VALUES 
            ('CompanyName', 'Shiv Shakti Hp Gas Agency'),
            ('ComplaintSources', 'Office Phone,Delivery,Leakage,District Office,MO'),
            ('AutoWhatsApp', 'false'),
            ('VendorMessageTemplate', 'Dear {vendor},\nNew complaint assigned:\nID: {id}\nName: {name}\nMobile: {mobile}\nAddress: {address}\nDetails: {complaint}\nPlease resolve ASAP.');
        ");
    }

    // Run migrations only once using settings flag to prevent database write locks on every request
    $schemaMigrated = false;
    try {
        $schemaMigrated = $db->query("SELECT setting_value FROM gas_settings WHERE setting_key = 'schema_migrated_v1'")->fetchColumn() === '1';
    } catch (Exception $e) {}

    if (!$schemaMigrated) {
        try {
            $db->exec("ALTER TABLE gas_complaints ADD COLUMN tag TEXT DEFAULT ''");
        } catch (Exception $e) {
            // Column might already exist
        }

        try {
            $db->exec("
                 CREATE TABLE IF NOT EXISTS gas_consumers (
                     id INTEGER PRIMARY KEY AUTOINCREMENT,
                     consumer_number TEXT DEFAULT '',
                     consumer_name TEXT NOT NULL,
                     mobile TEXT DEFAULT '',
                     address TEXT DEFAULT '',
                     area TEXT DEFAULT '',
                     connection_type TEXT DEFAULT '',
                     status TEXT DEFAULT '',
                     ekyc_status TEXT DEFAULT '',
                     imported_at DATETIME DEFAULT CURRENT_TIMESTAMP
                 );
            ");
            $db->exec("INSERT OR IGNORE INTO gas_settings (setting_key, setting_value) VALUES ('schema_migrated_v1', '1')");
        } catch (Exception $e) {
            // Handle table creation error silently or log
        }
    }

    // v2 migrations: add connection_type and status to gas_consumers if they do not exist
    $schemaMigratedV2 = false;
    try {
        $schemaMigratedV2 = $db->query("SELECT setting_value FROM gas_settings WHERE setting_key = 'schema_migrated_v2'")->fetchColumn() === '1';
    } catch (Exception $e) {}

    if (!$schemaMigratedV2) {
        try {
            $db->exec("ALTER TABLE gas_consumers ADD COLUMN connection_type TEXT DEFAULT ''");
        } catch (Exception $e) {}
        try {
            $db->exec("ALTER TABLE gas_consumers ADD COLUMN status TEXT DEFAULT ''");
        } catch (Exception $e) {}
        try {
            $db->exec("INSERT OR IGNORE INTO gas_settings (setting_key, setting_value) VALUES ('schema_migrated_v2', '1')");
        } catch (Exception $e) {}
    }
}


// Standalone Helper functions
function isLoggedIn() {
    return isset($_SESSION['gas_user_id']) && !empty($_SESSION['gas_user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        renderLoginPage();
        exit();
    }
}

function getLoggedInUser() {
    global $db;
    if (!isLoggedIn()) return null;
    $id = $_SESSION['gas_user_id'];
    try {
        $stmt = $db->prepare("SELECT id, username, name, role, permissions, active, mobile, profile_photo FROM gas_users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    } catch (Exception $e) {}
    return $_SESSION['gas_user'] ?? null;
}

function hasPermission($perm) {
    $user = getLoggedInUser();
    if (!$user) return false;
    if ($user['role'] === 'Admin') return true;
    
    $employeePermissions = [
        'complaints_view', 'complaints_add', 'complaints_edit', 'complaints_assign', 'complaints_deliver',
        'vendors_view', 'vendors_add', 'history_view'
    ];
    return in_array($perm, $employeePermissions);
}

function logAction($action, $recordId, $details = '') {
    global $db;
    $user = getLoggedInUser();
    $username = $user ? $user['username'] : 'system';
    try {
        $stmt = $db->prepare("INSERT INTO gas_logs (action, record_id, username, details) VALUES (:act, :rid, :uname, :det)");
        $stmt->execute([
            'act' => $action,
            'rid' => $recordId,
            'uname' => $username,
            'det' => $details
        ]);
    } catch(Exception $e) {}
}

function saveGasSetting(PDO $db, string $key, string $value): void {
    $stmt = $db->prepare("INSERT OR REPLACE INTO gas_settings (setting_key, setting_value) VALUES (:k, :v)");
    $stmt->execute(['k' => $key, 'v' => $value]);
}

function getGasSetting(PDO $db, string $key, string $default = ''): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM gas_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function getActiveBranchId(PDO $db): int {
    $enabled = getGasSetting($db, 'MultiBranchEnabled', '0') === '1';
    if (!$enabled) {
        return 0; // 0 means multi-branch is disabled
    }

    if (($_SESSION['gas_role'] ?? '') !== 'Admin') {
        return (int)($_SESSION['gas_user']['branch_id'] ?? 1);
    }

    return (int)($_SESSION['gas_active_branch_id'] ?? 0);
}

function hashPassword($pw) {
    return hash('sha256', $pw . '_GasDB_Salt_2024');
}

function fillVendorMessageTemplate($template, $complaint, $vendorName = '') {
  if (empty($template)) {
    $template = "Dear {vendor},\nNew complaint assigned:\nID: {id}\nConsumer No: {consumer_no}\nName: {name}\nMobile: {mobile}\nAddress: {address}\nDetails: {complaint}\nPlease resolve ASAP.";
  }

  // Auto-inject consumer number placeholder if missing from stored template
  if (
    strpos($template, '{consumer_no}') === false &&
    strpos($template, '{consumer_number}') === false &&
    strpos($template, '{account_no}') === false &&
    strpos($template, '{{ConsumerNumber}}') === false &&
    strpos($template, 'Consumer No:') === false
  ) {
    if (strpos($template, '{name}') !== false) {
      $template = str_replace('{name}', "Consumer No: {consumer_no}\nName: {name}", $template);
    } elseif (strpos($template, '{id}') !== false) {
      $template = str_replace('{id}', "{id}\nConsumer No: {consumer_no}", $template);
    }
  }

  $cNum = $complaint['consumer_number'] ?? $complaint['consumer_no'] ?? $complaint['account_no'] ?? 'N/A';

  $values = [
    '{vendor}' => $vendorName,
    '{id}' => $complaint['id'] ?? '',
    '{consumer_no}' => $cNum,
    '{consumer_number}' => $cNum,
    '{account_no}' => $cNum,
    '{name}' => $complaint['consumer_name'] ?? '',
    '{mobile}' => $complaint['mobile'] ?? '',
    '{address}' => $complaint['address'] ?? '',
    '{complaint}' => $complaint['complaint'] ?? '',
    '{{ConsumerNumber}}' => $cNum,
    '{{ConsumerName}}' => $complaint['consumer_name'] ?? '',
    '{{Mobile}}' => $complaint['mobile'] ?? '',
    '{{Address}}' => $complaint['address'] ?? '',
    '{{Complaint}}' => $complaint['complaint'] ?? ''
  ];

  return str_replace(array_keys($values), array_values($values), $template);
}

// Standalone Login HTML Renderer
function renderLoginPage() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Gas Agency Login</title>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
      <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body {
          background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
          min-height: 100vh;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 20px;
        }
        .login-card {
          background: #ffffff;
          border-radius: 16px;
          box-shadow: 0 10px 25px rgba(0,0,0,0.3);
          width: 100%;
          max-width: 400px;
          padding: 40px 32px;
          border: 1px solid rgba(255,255,255,0.1);
        }
        .brand {
          display: flex;
          align-items: center;
          gap: 12px;
          margin-bottom: 24px;
          justify-content: center;
        }
        .brand-icon {
          width: 44px;
          height: 44px;
          background: #dbeafe;
          color: #2563eb;
          border-radius: 12px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 1.4rem;
        }
        .brand-title {
          font-size: 1.25rem;
          font-weight: 800;
          color: #0f172a;
        }
        h2 { font-size: 1.5rem; font-weight: 800; color: #0f172a; margin-bottom: 8px; text-align: center; }
        p.subtitle { font-size: 0.85rem; color: #64748b; margin-bottom: 30px; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 6px; }
        .input-wrapper { position: relative; }
        .input-wrapper i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.95rem; }
        input {
          width: 100%;
          border: 2px solid #e2e8f0;
          border-radius: 10px;
          padding: 12px 14px 12px 42px;
          font-size: 0.9rem;
          font-weight: 600;
          color: #0f172a;
          outline: none;
          transition: all 0.2s;
        }
        input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .btn-submit {
          width: 100%;
          background: #2563eb;
          color: #ffffff;
          border: none;
          border-radius: 10px;
          padding: 14px;
          font-size: 0.9rem;
          font-weight: 700;
          cursor: pointer;
          transition: background 0.2s;
          margin-top: 10px;
        }
        .btn-submit:hover { background: #1d4ed8; }
        .error-msg {
          background: #fef2f2;
          border: 1px solid #fee2e2;
          color: #991b1b;
          border-radius: 8px;
          padding: 10px 12px;
          font-size: 0.82rem;
          font-weight: 600;
          margin-bottom: 20px;
          display: none;
          align-items: center;
          gap: 8px;
        }
        .info-box {
          margin-top: 24px;
          background: #f8fafc;
          border: 1px solid #e2e8f0;
          border-radius: 8px;
          padding: 12px;
          font-size: 0.78rem;
          color: #64748b;
          text-align: center;
        }
      </style>
    </head>
    <body>
      <div class="login-card" id="loginCard">
        <div class="brand">
          <div class="brand-icon"><i class="fas fa-gas-pump"></i></div>
          <div class="brand-title">Gas Agency CRM</div>
        </div>
        <h2>Welcome Back</h2>
        <p class="subtitle">Log in to manage complaints and vendors</p>
        
        <div class="error-msg" id="errorBlock"><i class="fas fa-exclamation-circle"></i> <span id="errorText"></span></div>
        
        <form id="loginForm" onsubmit="handleLoginSubmit(event)">
          <div class="form-group">
            <label>Email / Vendor Code / Mobile</label>
            <div class="input-wrapper">
              <i class="fas fa-user"></i>
              <input type="text" id="username" required placeholder="Email, Vendor Code or Mobile">
            </div>
          </div>
          <div class="form-group">
            <label>Password / Code</label>
            <div class="input-wrapper">
              <i class="fas fa-lock"></i>
              <input type="password" id="password" required placeholder="••••••••">
            </div>
          </div>
          <button type="submit" class="btn-submit" id="submitBtn">Log In</button>
        </form>
        
        <div style="text-align: center; margin-top: 15px;">
          <a href="#" onclick="showForgotPasswordForm()" style="color: #2563eb; font-size: 0.85rem; font-weight: 700; text-decoration: none;">Forgot Password?</a>
        </div>
        
        <div class="info-box">
          Agency Admin (Email) | Vendors (Vendor Code / Mobile)
        </div>
      </div>

      <!-- Forgot Password Card -->
      <div class="login-card" id="forgotCard" style="display: none;">
        <div class="brand">
          <div class="brand-icon"><i class="fas fa-key"></i></div>
          <div class="brand-title">Reset Password</div>
        </div>
        <h2>Reset Request</h2>
        <p class="subtitle" style="margin-bottom: 1.5rem;">Submit a password reset request to your administrator.</p>
        
        <div class="error-msg" id="forgotErrorBlock"><i class="fas fa-exclamation-circle"></i> <span id="forgotErrorText"></span></div>
        <div class="success-msg" id="forgotSuccessBlock" style="display: none; background: #d1fae5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 14px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; margin-bottom: 1.25rem;"><i class="fas fa-check-circle"></i> <span id="forgotSuccessText"></span></div>
        
        <form id="forgotForm" onsubmit="handleForgotSubmit(event)">
          <div class="form-group">
            <label>Gmail ID / Username *</label>
            <div class="input-wrapper">
              <i class="fas fa-user"></i>
              <input type="text" id="forgotUsername" required placeholder="user@gmail.com">
            </div>
          </div>
          <div class="form-group">
            <label>New Password Requested *</label>
            <div class="input-wrapper">
              <i class="fas fa-lock"></i>
              <input type="password" id="forgotPassword" required placeholder="••••••••">
            </div>
          </div>
          <button type="submit" class="btn-submit" id="forgotSubmitBtn">Request Reset</button>
        </form>
        
        <div style="text-align: center; margin-top: 15px;">
          <a href="#" onclick="showLoginForm()" style="color: #2563eb; font-size: 0.85rem; font-weight: 700; text-decoration: none;"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
      </div>

      <script>
        function handleLoginSubmit(e) {
          e.preventDefault();
          const u = document.getElementById('username').value.trim();
          const p = document.getElementById('password').value;
          const btn = document.getElementById('submitBtn');
          const errBlock = document.getElementById('errorBlock');
          const errText = document.getElementById('errorText');
          
          btn.disabled = true;
          btn.innerText = 'Logging in...';
          errBlock.style.display = 'none';

          const fd = new FormData();
          fd.append('action', 'login');
          fd.append('username', u);
          fd.append('password', p);

          fetch('?', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(res => {
              btn.disabled = false;
              btn.innerText = 'Log In';
              if (res.success) {
                if (res.redirect) window.location.href = res.redirect;
                else window.location.reload();
              } else {
                errText.innerText = res.message || 'Invalid credentials';
                errBlock.style.display = 'flex';
              }
            })
            .catch(err => {
              btn.disabled = false;
              btn.innerText = 'Log In';
              errText.innerText = 'Connection error occurred.';
              errBlock.style.display = 'flex';
            });
        }

        function showForgotPasswordForm() {
          document.getElementById('loginCard').style.display = 'none';
          document.getElementById('forgotCard').style.display = 'block';
          document.getElementById('forgotErrorBlock').style.display = 'none';
          document.getElementById('forgotSuccessBlock').style.display = 'none';
        }
        function showLoginForm() {
          document.getElementById('forgotCard').style.display = 'none';
          document.getElementById('loginCard').style.display = 'block';
          document.getElementById('errorBlock').style.display = 'none';
        }
        function handleForgotSubmit(e) {
          e.preventDefault();
          const u = document.getElementById('forgotUsername').value.trim();
          const p = document.getElementById('forgotPassword').value;
          const btn = document.getElementById('forgotSubmitBtn');
          const errBlock = document.getElementById('forgotErrorBlock');
          const errText = document.getElementById('forgotErrorText');
          const succBlock = document.getElementById('forgotSuccessBlock');
          const succText = document.getElementById('forgotSuccessText');
          
          btn.disabled = true;
          btn.innerText = 'Submitting...';
          errBlock.style.display = 'none';
          succBlock.style.display = 'none';

          const fd = new FormData();
          fd.append('action', 'request_reset');
          fd.append('username', u);
          fd.append('new_password', p);

          fetch('?', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(res => {
              btn.disabled = false;
              btn.innerText = 'Request Reset';
              if (res.success) {
                succText.innerText = res.message;
                succBlock.style.display = 'flex';
                document.getElementById('forgotForm').reset();
              } else {
                errText.innerText = res.message || 'Request failed';
                errBlock.style.display = 'flex';
              }
            })
            .catch(err => {
              btn.disabled = false;
              btn.innerText = 'Request Reset';
              errText.innerText = 'Connection error occurred.';
              errBlock.style.display = 'flex';
            });
        }
      </script>
    </body>
    </html>
    <?php
}

// -------------------------------------------------------------
// STANDALONE AJAX ROUTING AND API ENDPOINTS
// -------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action) {
    header('Content-Type: application/json');
    if ($action === 'login') {
        $u = trim($_POST['username'] ?? ''); // Email, Username, Vendor Code, or Mobile
        $p = trim($_POST['password'] ?? '');
        
        // 1. Try Master DB for SaaS Agency Owners
        try {
            $masterDb = new PDO("sqlite:" . __DIR__ . "/master.sqlite");
            $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $masterDb->prepare("SELECT * FROM agencies WHERE email = ? LIMIT 1");
            $stmt->execute([$u]);
            $agency = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($agency && password_verify($p, $agency['password_hash'])) {
                $_SESSION['agency_id'] = $agency['id'];
                $_SESSION['db_filename'] = $agency['db_filename'];
                $_SESSION['agency_name'] = $agency['agency_name'];
                
                $localDb = new PDO("sqlite:" . __DIR__ . "/" . $agency['db_filename']);
                $localDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                try {
                    $count = (int)$localDb->query("SELECT COUNT(*) FROM gas_users")->fetchColumn();
                    if ($count === 0) {
                        $localDb->exec("INSERT OR IGNORE INTO gas_users (id, username, password, name, role, permissions, active) VALUES (1, 'admin', '21f25c8a583f2c993255e9008a9f332f486c6083ff113070490bb711003f259a', 'Administrator', 'Admin', '*', 1)");
                    }
                } catch (Exception $e) {}

                $stmt = $localDb->prepare("SELECT * FROM gas_users WHERE username = 'admin' AND active = 1 LIMIT 1");
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row) {
                    $_SESSION['gas_user_id'] = $row['id'];
                    $_SESSION['gas_user'] = [
                        'id' => $row['id'],
                        'username' => $row['username'],
                        'name' => $row['name'],
                        'role' => $row['role'],
                        'active' => 1
                    ];
                    $_SESSION['gas_role'] = $row['role'];
                    echo json_encode(['success' => true]);
                    exit();
                }
            }
        } catch (Exception $e) {}

        // 2. If not SaaS agency owner email, check local agency DBs for Staff (gas_users) or Vendors (gas_vendors)
        $targetAgencies = [];
        if (!empty($_SESSION['db_filename']) && file_exists(__DIR__ . '/' . $_SESSION['db_filename'])) {
            $targetAgencies[] = [
                'id' => $_SESSION['agency_id'] ?? 1,
                'db_filename' => $_SESSION['db_filename'],
                'agency_name' => $_SESSION['agency_name'] ?? 'Gas Agency'
            ];
        }
        
        try {
            $masterDb = new PDO("sqlite:" . __DIR__ . "/master.sqlite");
            $agList = $masterDb->query("SELECT * FROM agencies")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($agList as $ag) {
                if (file_exists(__DIR__ . '/' . $ag['db_filename'])) {
                    // avoid duplicate if already added
                    if (!array_filter($targetAgencies, fn($x) => $x['db_filename'] === $ag['db_filename'])) {
                        $targetAgencies[] = $ag;
                    }
                }
            }
        } catch (Exception $e) {}

        foreach ($targetAgencies as $ag) {
            try {
                $tenantDb = new PDO("sqlite:" . __DIR__ . "/" . $ag['db_filename']);
                $tenantDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // A. Check gas_users (Employees / Staff)
                $stmtUser = $tenantDb->prepare("SELECT * FROM gas_users WHERE (LOWER(username) = LOWER(:u) OR mobile = :u) AND active = 1 LIMIT 1");
                $stmtUser->execute(['u' => $u]);
                $uRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

                if ($uRow) {
                    $hashedP = hashPassword($p);
                    if ($uRow['password'] === $hashedP || password_verify($p, $uRow['password']) || $uRow['password'] === $p) {
                        $_SESSION['agency_id'] = $ag['id'];
                        $_SESSION['db_filename'] = $ag['db_filename'];
                        $_SESSION['agency_name'] = $ag['agency_name'];
                        $_SESSION['gas_user_id'] = $uRow['id'];
                        $_SESSION['gas_user'] = [
                            'id' => $uRow['id'],
                            'username' => $uRow['username'],
                            'name' => $uRow['name'],
                            'role' => $uRow['role'],
                            'active' => 1
                        ];
                        $_SESSION['gas_role'] = $uRow['role'];
                        echo json_encode(['success' => true]);
                        exit();
                    }
                }

                // B. Check gas_vendors (Vendors)
                $stmtVendor = $tenantDb->prepare("SELECT * FROM gas_vendors WHERE LOWER(code) = LOWER(:u) OR mobile = :u OR LOWER(name) = LOWER(:u) LIMIT 1");
                $stmtVendor->execute(['u' => $u]);
                $vRow = $stmtVendor->fetch(PDO::FETCH_ASSOC);

                if ($vRow) {
                    $vCode = trim($vRow['code'] ?? '');
                    $vMob = trim($vRow['mobile'] ?? '');
                    $vName = trim($vRow['name'] ?? '');

                    // Vendor matches if password equals vendor code, mobile, name, or standard fallback '1234'/'admin'
                    if (
                        ($vCode && strcasecmp($p, $vCode) === 0) ||
                        ($vMob && $p === $vMob) ||
                        ($vName && strcasecmp($p, $vName) === 0) ||
                        $p === '1234' ||
                        $p === 'admin'
                    ) {
                        $_SESSION['agency_id'] = $ag['id'];
                        $_SESSION['db_filename'] = $ag['db_filename'];
                        $_SESSION['agency_name'] = $ag['agency_name'];
                        $_SESSION['gas_user_id'] = 'v_' . $vRow['id'];
                        $_SESSION['gas_user'] = [
                            'id' => 'v_' . $vRow['id'],
                            'username' => $vCode ?: ($vMob ?: $vName),
                            'name' => $vName,
                            'role' => 'Vendor',
                            'active' => 1
                        ];
                        $_SESSION['gas_role'] = 'Vendor';
                        $_SESSION['gas_vendor_id'] = $vRow['id'];
                        $_SESSION['gas_vendor_name'] = $vName;
                        echo json_encode(['success' => true]);
                        exit();
                    }
                }
            } catch (Exception $e) {}
        }

        echo json_encode(['success' => false, 'message' => 'Invalid login details. Check your Email / Vendor Code / Password.']);
        exit();
    }
    
    if ($action === 'logout') {
        unset($_SESSION['gas_user_id']);
        unset($_SESSION['gas_user']);
        unset($_SESSION['gas_role']);
        header("Location: ?");
        exit();
    }

    if ($action === 'request_reset') {
        $u = trim($_POST['username'] ?? '');
        $newPw = $_POST['new_password'] ?? '';
        
        if (empty($u) || empty($newPw)) {
            echo json_encode(['success' => false, 'message' => 'Username and New Password are required']);
            exit();
        }
        
        $stmt = $db->prepare("SELECT * FROM gas_users WHERE username = :u LIMIT 1");
        $stmt->execute(['u' => $u]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Username/Gmail ID not found']);
            exit();
        }
        
        if ($row['role'] === 'Admin') {
            echo json_encode(['success' => false, 'message' => 'Admin password cannot be reset externally. Please contact an Admin.']);
            exit();
        }
        
        $hashed = hashPassword($newPw);
        
        $stmtUpdate = $db->prepare("UPDATE gas_users SET pending_password = :pending, reset_requested_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmtUpdate->execute([
            'pending' => $hashed,
            'id' => $row['id']
        ]);
        
        try {
            $stmtLog = $db->prepare("INSERT INTO gas_logs (action, record_id, username, details) VALUES ('PWD_RESET_REQUEST', :rid, :uname, :det)");
            $stmtLog->execute([
                'rid' => $row['id'],
                'uname' => $u,
                'det' => 'User requested password reset'
            ]);
        } catch(Exception $e) {}
        
        echo json_encode(['success' => true, 'message' => 'Reset request submitted! Please ask an Admin to approve it.']);
        exit();
    }
    
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
        exit();
    }
    try {
    switch ($action) {

        
        case 'get_init_data':
            // Load configuration
            $config = [];
            $rows = $db->query("SELECT setting_key, setting_value FROM gas_settings")->fetchAll();
            foreach ($rows as $r) {
                $config[$r['setting_key']] = $r['setting_value'];
            }

            // Sources
            $sources = isset($config['ComplaintSources']) ? array_map('trim', explode(',', $config['ComplaintSources'])) : ['Office Phone','Delivery','Leakage','District Office','MO'];
            $sources = array_filter($sources);

            // Vendors
            $vendors = $db->query("SELECT * FROM gas_vendors ORDER BY name ASC")->fetchAll();

            $activeBranchId = getActiveBranchId($db);
            $branches = [];
            if (($config['MultiBranchEnabled'] ?? '0') === '1') {
                $branches = $db->query("SELECT * FROM gas_branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            }

            // Stats — count scoped complaints (all statuses)
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $todayCheck = ($driver === 'sqlite') ? "date('now', 'localtime')" : "CURDATE()";
            
            $statsWhere = "deleted = 0";
            $statsParams = [];
            if ($activeBranchId > 0) {
                $statsWhere .= " AND branch_id = :branch_id";
                $statsParams['branch_id'] = $activeBranchId;
            }
            if (($_SESSION['gas_role'] ?? '') === 'Vendor') {
                $statsWhere .= " AND (vendor_id = :v_id OR vendor = :v_name)";
                $statsParams['v_id'] = $_SESSION['gas_vendor_id'] ?? 0;
                $statsParams['v_name'] = $_SESSION['gas_vendor_name'] ?? '';
            }

            $statsStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as inProgress,
                    SUM(CASE WHEN status IN ('Delivered','Resolved') THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN DATE(created_at) = {$todayCheck} THEN 1 ELSE 0 END) as todayNew
                FROM gas_complaints
                WHERE $statsWhere
            ");
            $statsStmt->execute($statsParams);
            $stats = $statsStmt->fetch();

            // Complaints (First page — active only)
            $compWhere = "deleted = 0 AND status NOT IN ('Delivered', 'Resolved', 'Closed')";
            $compParams = [];
            if ($activeBranchId > 0) {
                $compWhere .= " AND branch_id = :branch_id";
                $compParams['branch_id'] = $activeBranchId;
            }
            if (($_SESSION['gas_role'] ?? '') === 'Vendor') {
                $compWhere .= " AND (vendor_id = :v_id OR vendor = :v_name)";
                $compParams['v_id'] = $_SESSION['gas_vendor_id'] ?? 0;
                $compParams['v_name'] = $_SESSION['gas_vendor_name'] ?? '';
            }

            $compStmt = $db->prepare("
                SELECT * FROM gas_complaints 
                WHERE $compWhere
                ORDER BY created_at DESC LIMIT 50
            ");
            $compStmt->execute($compParams);
            $complaints = $compStmt->fetchAll();

            // Check if vendor role
            $sessionUser = getLoggedInUser();

            $companyInfo = [
                'company_name' => $config['CompanyName'] ?? 'Gas Agency',
                'company_address' => $config['CompanyAddress'] ?? '',
                'company_mobile' => $config['CompanyMobile'] ?? '',
                'company_email' => $config['CompanyEmail'] ?? '',
                'company_logo' => $config['CompanyLogo'] ?? 'default-logo.png'
            ];

            echo json_encode([
                'success' => true,
                'config' => $config,
                'sources' => array_values($sources),
                'vendors' => $vendors,
                'branches' => $branches,
                'active_branch_id' => $activeBranchId,
                'company' => $companyInfo,
                'stats' => [
                    'total'      => (int)($stats['total']      ?? 0),
                    'pending'    => (int)($stats['pending']    ?? 0),
                    'inProgress' => (int)($stats['inProgress'] ?? 0),
                    'delivered'  => (int)($stats['delivered']  ?? 0),
                    'todayNew'   => (int)($stats['todayNew']   ?? 0),
                ],
                'complaints' => [
                    'rows'       => $complaints,
                    'total'      => (int)($stats['total'] ?? 0),
                    'page'       => 1,
                    'pageSize'   => 50,
                    'totalPages' => ceil(($stats['total'] ?? 0) / 50)
                ]
            ]);
            break;

        case 'get_complaints':
            $status = $_GET['status'] ?? '';
            $source = $_GET['source'] ?? '';
            $tag    = $_GET['tag'] ?? '';
            $search = $_GET['search'] ?? '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $pageSize = 50;
            $offset = ($page - 1) * $pageSize;

            $activeBranchId = getActiveBranchId($db);

            $whereClauses = ["deleted = 0"];
            $params = [];

            if ($activeBranchId > 0) {
                $whereClauses[] = "branch_id = :branch_id";
                $params['branch_id'] = $activeBranchId;
            }

            if (($_SESSION['gas_role'] ?? '') === 'Vendor') {
                $whereClauses[] = "(vendor_id = :v_id OR vendor = :v_name)";
                $params['v_id'] = $_SESSION['gas_vendor_id'] ?? 0;
                $params['v_name'] = $_SESSION['gas_vendor_name'] ?? '';
            }

            if ($status) {
                $whereClauses[] = "status = :status";
                $params['status'] = $status;
            } else {
                $whereClauses[] = "status NOT IN ('Delivered', 'Resolved', 'Closed')";
            }

            if ($source) {
                $whereClauses[] = "source = :source";
                $params['source'] = $source;
            }

            if ($tag) {
                $whereClauses[] = "tag = :tag";
                $params['tag'] = $tag;
            }

            if ($search) {
                $whereClauses[] = "(consumer_name LIKE :s OR mobile LIKE :s OR consumer_number LIKE :s OR address LIKE :s OR complaint LIKE :s)";
                $params['s'] = "%$search%";
            }

            $whereString = implode(" AND ", $whereClauses);

            // Fetch rows
            $stmt = $db->prepare("
                SELECT * FROM gas_complaints 
                WHERE $whereString 
                ORDER BY created_at DESC 
                LIMIT $pageSize OFFSET $offset
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Total count
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM gas_complaints WHERE $whereString");
            $stmtCount->execute($params);
            $total = $stmtCount->fetchColumn();

            // Stats — count ALL complaints
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $todayCheck = ($driver === 'sqlite') ? "date('now', 'localtime')" : "CURDATE()";
            
            $statsWhere = "deleted = 0";
            $statsParams = [];
            if ($activeBranchId > 0) {
                $statsWhere .= " AND branch_id = :branch_id";
                $statsParams['branch_id'] = $activeBranchId;
            }
            if (($_SESSION['gas_role'] ?? '') === 'Vendor') {
                $statsWhere .= " AND (vendor_id = :v_id OR vendor = :v_name)";
                $statsParams['v_id'] = $_SESSION['gas_vendor_id'] ?? 0;
                $statsParams['v_name'] = $_SESSION['gas_vendor_name'] ?? '';
            }
            
            $statsStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as inProgress,
                    SUM(CASE WHEN status IN ('Delivered','Resolved') THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN DATE(created_at) = {$todayCheck} THEN 1 ELSE 0 END) as todayNew
                FROM gas_complaints
                WHERE $statsWhere
            ");
            $statsStmt->execute($statsParams);
            $stats = $statsStmt->fetch();

            echo json_encode([
                'success'    => true,
                'rows'       => $rows,
                'total'      => (int)$total,
                'page'       => $page,
                'pageSize'   => $pageSize,
                'totalPages' => ceil($total / $pageSize),
                'stats'      => [
                    'total'      => (int)($stats['total']      ?? 0),
                    'pending'    => (int)($stats['pending']    ?? 0),
                    'inProgress' => (int)($stats['inProgress'] ?? 0),
                    'delivered'  => (int)($stats['delivered']  ?? 0),
                    'todayNew'   => (int)($stats['todayNew']   ?? 0),
                ]
            ]);
            break;

        case 'add_complaint':
            if (!hasPermission('complaints_add')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $no   = $_POST['consumer_number'] ?? '';
            $name = $_POST['consumer_name'] ?? '';
            $mob  = $_POST['mobile'] ?? '';
            $addr = $_POST['address'] ?? '';
            $src  = $_POST['source'] ?? '';
            $comp = $_POST['complaint'] ?? '';
            $tag  = $_POST['tag'] ?? '';

            $branchId = getActiveBranchId($db);
            if ($branchId <= 0) {
                $branchId = (int)($_SESSION['gas_user']['branch_id'] ?? 1);
            }

            $stmt = $db->prepare("
                INSERT INTO gas_complaints (consumer_number, consumer_name, mobile, address, source, complaint, status, tag, branch_id)
                VALUES (:no, :name, :mob, :addr, :src, :comp, 'Pending', :tag, :branch)
            ");
            $stmt->execute([
                'no' => $no, 'name' => $name, 'mob' => $mob,
                'addr' => $addr, 'src' => $src, 'comp' => $comp, 'tag' => $tag,
                'branch' => $branchId
            ]);
            $newId = $db->lastInsertId();
            logAction('CREATE', $newId, "Complaint registered by " . ($_SESSION['gas_user']['name'] ?? 'System'));
            echo json_encode(['success' => true, 'id' => $newId]);
            break;

        case 'update_complaint':
            if (!hasPermission('complaints_edit')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id     = $_POST['id'] ?? '';
            $no     = $_POST['consumer_number'] ?? '';
            $name   = $_POST['consumer_name'] ?? '';
            $mob    = $_POST['mobile'] ?? '';
            $addr   = $_POST['address'] ?? '';
            $src    = $_POST['source'] ?? '';
            $comp   = $_POST['complaint'] ?? '';
            $status = $_POST['status'] ?? 'Pending';
            $tag    = $_POST['tag'] ?? '';

            $stmt = $db->prepare("
                UPDATE gas_complaints 
                SET consumer_number=:no, consumer_name=:name, mobile=:mob, address=:addr,
                    source=:src, complaint=:comp, status=:status, tag=:tag
                WHERE id = :id
            ");
            $stmt->execute([
                'id'=>$id,'no'=>$no,'name'=>$name,'mob'=>$mob,
                'addr'=>$addr,'src'=>$src,'comp'=>$comp,'status'=>$status,'tag'=>$tag
            ]);
            logAction('UPDATE', $id, "Updated complaint by " . ($_SESSION['gas_user']['name'] ?? 'System'));
            echo json_encode(['success' => true]);
            break;

        case 'delete_complaint':
            if (!hasPermission('complaints_delete')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_GET['id'] ?? '';
            $stmt = $db->prepare("UPDATE gas_complaints SET deleted = 1, deleted_at = datetime('now', 'localtime'), deleted_by = :u WHERE id = :id");
            $stmt->execute(['id' => $id, 'u' => ($_SESSION['gas_user']['name'] ?? 'System')]);

            logAction('DELETE', $id, "Soft deleted complaint by " . ($_SESSION['gas_user']['name'] ?? 'System'));

            echo json_encode(['success' => true]);
            break;

        case 'get_complaint_details':
            $id = $_GET['id'] ?? '';
            $stmt = $db->prepare("SELECT * FROM gas_complaints WHERE id = :id AND deleted = 0");
            $stmt->execute(['id' => $id]);
            $c = $stmt->fetch();

            if (!$c) {
                echo json_encode(['success' => false, 'error' => 'Record not found']);
            } else {
                echo json_encode(['success' => true, 'complaint' => $c]);
            }
            break;

        case 'mark_delivery_failed':
            if (!hasPermission('complaints_deliver')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_POST['id'] ?? '';
            $reason = trim($_POST['fail_reason'] ?? '');
            $notes = trim($_POST['fail_notes'] ?? '');

            if (!$id || !$reason) {
                echo json_encode(['success' => false, 'error' => 'Complaint ID and Failure Reason are required']);
                exit();
            }

            $stmt = $db->prepare("
                UPDATE gas_complaints 
                SET status = 'Delivery Failed', fail_reason = :r, fail_notes = :n 
                WHERE id = :id AND deleted = 0
            ");
            $stmt->execute(['r' => $reason, 'n' => $notes, 'id' => $id]);

            logAction('DELIVERY_FAILED', $id, "Delivery Failed: " . $reason . ($notes ? " ($notes)" : ""));
            echo json_encode(['success' => true]);
            break;

        case 'resolve_complaint':
            if (!hasPermission('complaints_deliver')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_POST['id'] ?? '';
            $sigData = $_POST['signature_data'] ?? '';
            $sigUrl = '';

            if ($sigData && strpos($sigData, 'data:image/png;base64,') === 0) {
                $dir = __DIR__ . '/uploads/gas_signatures';
                if (!file_exists($dir)) {
                    mkdir($dir, 0777, true);
                }

                $data = base64_decode(substr($sigData, 22));
                $fileName = 'signature_' . $id . '_' . time() . '.png';
                file_put_contents($dir . '/' . $fileName, $data);
                $sigUrl = 'uploads/gas_signatures/' . $fileName;
            }

            // Photo proof upload
            $photoProofUrl = '';
            if (isset($_FILES['photo_proof']) && $_FILES['photo_proof']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/gas_delivery_photos';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = pathinfo($_FILES['photo_proof']['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $photoFileName = 'proof_' . $id . '_' . time() . '.' . $ext;
                $targetFile = $uploadDir . '/' . $photoFileName;
                if (move_uploaded_file($_FILES['photo_proof']['tmp_name'], $targetFile)) {
                    $photoProofUrl = 'uploads/gas_delivery_photos/' . $photoFileName;
                }
            }

            $stmt = $db->prepare("
                UPDATE gas_complaints 
                SET status = 'Resolved', 
                    signature_url = CASE WHEN :sig != '' THEN :sig ELSE signature_url END, 
                    photo_proof_url = CASE WHEN :photo != '' THEN :photo ELSE photo_proof_url END,
                    resolved_at = :resolved_at 
                WHERE id = :id AND deleted = 0
            ");
            $stmt->execute([
              'sig' => $sigUrl,
              'photo' => $photoProofUrl,
              'resolved_at' => date('Y-m-d H:i:s'),
              'id' => $id
            ]);

            if ($stmt->rowCount() === 0) {
              echo json_encode(['success' => false, 'error' => 'Complaint not found or already deleted']);
              exit();
            }

            logAction('DELIVER', $id, "Resolved case with resolution data");

            echo json_encode(['success' => true]);
            break;

        case 'assign_vendor':
            if (!hasPermission('complaints_assign')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_POST['complaint_ids'] ?? '';
            $vId = $_POST['vendor_id'] ?? '';
            $vName = $_POST['vendor_name'] ?? '';

            $stmt = $db->prepare("
                UPDATE gas_complaints 
                SET status = 'In Progress', vendor_id = :vid, vendor = :vname 
                WHERE id = :id
            ");
            $stmt->execute(['vid' => $vId, 'vname' => $vName, 'id' => $id]);

            logAction('ASSIGN', $id, "Assigned to vendor: " . $vName);

            // Fetch template to generate WhatsApp message
            $stmtTmpl = $db->prepare("SELECT setting_value FROM gas_settings WHERE setting_key = 'VendorMessageTemplate'");
            $stmtTmpl->execute();
            $tmpl = $stmtTmpl->fetchColumn() ?: '';

            $stmtC = $db->prepare("SELECT * FROM gas_complaints WHERE id = :id");
            $stmtC->execute(['id' => $id]);
            $c = $stmtC->fetch();

            $msg = fillVendorMessageTemplate($tmpl, $c, $vName);

            echo json_encode(['success' => true, 'whatsapp_message' => $msg]);
            break;

        case 'bulk_assign_vendor':
            if (!hasPermission('complaints_assign')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $ids = explode(',', $_POST['complaint_ids'] ?? '');
            $vId = $_POST['vendor_id'] ?? '';
            $vName = $_POST['vendor_name'] ?? '';

            $stmt = $db->prepare("
                UPDATE gas_complaints 
                SET status = 'In Progress', vendor_id = :vid, vendor = :vname 
                WHERE id = :id
            ");

            $msg = "*New Jobs Assigned:*\n\n";
            $count = 1;

            foreach ($ids as $id) {
                $id = trim($id);
                if (!$id) continue;
                $stmt->execute(['vid' => $vId, 'vname' => $vName, 'id' => $id]);
                logAction('ASSIGN', $id, "Bulk assigned to vendor: " . $vName);

                // Fetch details for SMS/WhatsApp alert aggregation
                $stmtC = $db->prepare("SELECT * FROM gas_complaints WHERE id = :id");
                $stmtC->execute(['id' => $id]);
                $c = $stmtC->fetch();

                $msg .= "$count) ID: #{$c['id']}\nName: {$c['consumer_name']}\nPh: {$c['mobile']}\nAddress: {$c['address']}\nComplaint: {$c['complaint']}\n\n";
                $count++;
            }

            echo json_encode(['success' => true, 'whatsapp_message' => trim($msg)]);
            break;

        case 'bulk_resolve':
            if (!hasPermission('complaints_deliver')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }
            $ids = explode(',', $_POST['complaint_ids'] ?? '');
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $nowExpr = ($driver === 'sqlite') ? "datetime('now','localtime')" : 'NOW()';
            $stmt = $db->prepare("UPDATE gas_complaints SET status = 'Resolved', resolved_at = $nowExpr WHERE id = :id");
            foreach ($ids as $id) {
                $id = trim($id); if (!$id) continue;
                $stmt->execute(['id' => $id]);
                logAction('DELIVER', $id, "Bulk marked as resolved");
            }
            echo json_encode(['success' => true]);
            break;

        case 'bulk_delete':
            if (!hasPermission('complaints_delete')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }
            $ids = explode(',', $_POST['complaint_ids'] ?? '');
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $nowExpr = ($driver === 'sqlite') ? "datetime('now','localtime')" : 'NOW()';
            $stmt = $db->prepare("UPDATE gas_complaints SET deleted=1, deleted_at=$nowExpr, deleted_by=:u WHERE id=:id");
            $uname = getLoggedInUser()['name'] ?? 'admin';
            foreach ($ids as $id) {
                $id = trim($id); if (!$id) continue;
                $stmt->execute(['id' => $id, 'u' => $uname]);
                logAction('DELETE', $id, "Bulk deleted");
            }
            echo json_encode(['success' => true]);
            break;

        case 'get_consumer_history':
            $mob = $_GET['mobile'] ?? '';
            if (!$mob) { echo json_encode(['success'=>true,'count'=>0,'complaints',[]]); break; }
            $stmt = $db->prepare("SELECT id, consumer_name, complaint, status, created_at FROM gas_complaints WHERE mobile=:mob AND deleted=0 ORDER BY created_at DESC LIMIT 5");
            $stmt->execute(['mob' => $mob]);
            $hist = $stmt->fetchAll();
            echo json_encode(['success'=>true,'count'=>count($hist),'complaints'=>$hist]);
            break;

        case 'get_tag_report':
            $rows = $db->query("
                SELECT tag, COUNT(*) as total
                FROM gas_complaints
                WHERE deleted=0 AND tag IS NOT NULL AND tag != ''
                GROUP BY tag ORDER BY total DESC
            ")->fetchAll();
            echo json_encode(['success'=>true,'rows'=>$rows]);
            break;

        case 'get_vendor_scorecard':
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lateExpr = ($driver === 'sqlite')
                ? "(julianday(COALESCE(resolved_at, datetime('now','localtime'))) - julianday(created_at)) * 24 > 48"
                : "TIMESTAMPDIFF(HOUR, created_at, COALESCE(resolved_at, NOW())) > 48";
            $rows = $db->query("
                SELECT
                    vendor,
                    COUNT(*) as total_assigned,
                    SUM(CASE WHEN status IN ('Resolved','Delivered','Closed') THEN 1 ELSE 0 END) as total_resolved,
                    SUM(CASE WHEN $lateExpr THEN 1 ELSE 0 END) as total_late
                FROM gas_complaints
                WHERE deleted=0 AND vendor IS NOT NULL AND vendor != ''
                GROUP BY vendor
                ORDER BY total_resolved DESC
            ")->fetchAll();
            $scored = array_map(function($r) {
                $assigned = (int)$r['total_assigned'];
                $resolved = (int)$r['total_resolved'];
                $late     = (int)$r['total_late'];
                $score = $assigned > 0 ? max(0, round(($resolved / $assigned) * 100 - ($late * 5))) : 0;
                return ['vendor'=>$r['vendor'],'assigned'=>$assigned,'resolved'=>$resolved,'late'=>$late,'score'=>$score];
            }, $rows);
            usort($scored, fn($a,$b) => $b['score'] - $a['score']);
            echo json_encode(['success'=>true,'rows'=>$scored]);
            break;

        case 'get_history':
            $status = $_GET['status'] ?? '';
            $from = $_GET['date_from'] ?? '';
            $to = $_GET['date_to'] ?? '';
            $search = $_GET['search'] ?? '';

            $activeBranchId = getActiveBranchId($db);

            $whereClauses = ["deleted = 0", "status IN ('Delivered', 'Resolved', 'Closed')"];
            $params = [];

            if ($activeBranchId > 0) {
                $whereClauses[] = "branch_id = :branch_id";
                $params['branch_id'] = $activeBranchId;
            }

            if (($_SESSION['gas_role'] ?? '') === 'Vendor') {
                $whereClauses[] = "(vendor_id = :v_id OR vendor = :v_name)";
                $params['v_id'] = $_SESSION['gas_vendor_id'] ?? 0;
                $params['v_name'] = $_SESSION['gas_vendor_name'] ?? '';
            }

            if ($status) {
                $whereClauses[] = "status = :status";
                $params['status'] = $status;
            }

            if ($from) {
                $whereClauses[] = "DATE(resolved_at) >= :from";
                $params['from'] = $from;
            }

            if ($to) {
                $whereClauses[] = "DATE(resolved_at) <= :to";
                $params['to'] = $to;
            }

            if ($search) {
                $whereClauses[] = "(consumer_name LIKE :s OR mobile LIKE :s OR consumer_number LIKE :s OR address LIKE :s OR complaint LIKE :s)";
                $params['s'] = "%$search%";
            }

            $whereString = implode(" AND ", $whereClauses);
            $stmt = $db->prepare("SELECT * FROM gas_complaints WHERE $whereString ORDER BY resolved_at DESC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            echo json_encode(['success' => true, 'rows' => $rows]);
            break;

        case 'get_vendors':
            $vendors = $db->query("SELECT * FROM gas_vendors ORDER BY name ASC")->fetchAll();
            echo json_encode(['success' => true, 'vendors' => $vendors]);
            break;

        case 'get_consumer_by_mobile_or_number':
            $query = $_GET['query'] ?? '';
            if (!$query) {
                echo json_encode(['success' => false, 'error' => 'No query provided']);
                exit();
            }
            $query = ltrim(trim($query), "'");

            $stmt = $db->prepare("SELECT * FROM gas_consumers WHERE consumer_number = :q LIMIT 1");
            $stmt->execute(['q' => $query]);
            $consumer = $stmt->fetch();
            if (!$consumer) {
                $stmt = $db->prepare("SELECT * FROM gas_consumers WHERE mobile = :q LIMIT 1");
                $stmt->execute(['q' => $query]);
                $consumer = $stmt->fetch();
            }
            if ($consumer) {
                echo json_encode(['success' => true, 'consumer' => $consumer]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Consumer not found']);
            }
            break;

        case 'import_consumers':
            if ($_SESSION['gas_role'] !== 'Admin') {
                echo json_encode(['success' => false, 'error' => 'Permission denied']); exit();
            }
            $rows = json_decode(file_get_contents('php://input'), true);
            if (!$rows || !is_array($rows)) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']); exit();
            }
            
            // Helper function for case-insensitive and space-insensitive column matching
            if (!function_exists('getRowValue')) {
                function getRowValue($row, $possibilities) {
                    foreach ($possibilities as $p) {
                        $pClean = preg_replace('/[^a-z0-9]/', '', strtolower($p));
                        if (empty($pClean)) continue;
                        foreach ($row as $key => $val) {
                            $keyClean = preg_replace('/[^a-z0-9]/', '', strtolower($key));
                            if ($keyClean === $pClean || strpos($keyClean, $pClean) !== false || strpos($pClean, $keyClean) !== false) {
                                return $val;
                            }
                        }
                    }
                    return null;
                }
            }

            // Set higher execution time and memory limits for large Excel uploads
            @set_time_limit(600);
            @ini_set('memory_limit', '512M');

            $branchId = getActiveBranchId($db);
            if ($branchId <= 0) {
                $branchId = (int)$db->query("SELECT id FROM gas_branches ORDER BY id ASC LIMIT 1")->fetchColumn();
            }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO gas_consumers (consumer_number, consumer_name, mobile, address, area, connection_type, status, ekyc_status, branch_id)
                    VALUES (:no, :name, :mob, :addr, :area, :conn_type, :status, :ekyc, :branch)
                ");
                $count = 0;
                foreach ($rows as $r) {
                    // Map columns using robust helper
                    $name = trim(getRowValue($r, ['Consumer Name', 'consumer_name', 'name', 'Name']) ?? '');
                    if (!$name) continue;

                    $no        = trim(getRowValue($r, ['Consumer No', 'Consumer Number', 'consumer_number', 'Account No', 'account_no', 'LPG ID', 'lpg_id']) ?? '');
                    $mob       = trim(getRowValue($r, ['Mobile No', 'mobile_no', 'mobile', 'Mobile', 'Phone', 'phone']) ?? '');
                    $addr      = trim(getRowValue($r, ['Consumer Address', 'address', 'Address']) ?? '');
                    $area      = trim(getRowValue($r, ['Delivery Area', 'area', 'Area']) ?? '');
                    $conn_type = trim(getRowValue($r, ['Connection Type', 'connection_type']) ?? '');
                    $status    = trim(getRowValue($r, ['Consumer Status', 'status', 'Status']) ?? '');
                    $ekyc      = trim(getRowValue($r, ['Is KYC Completed', 'EKYC Status', 'kyc_status', 'kyc', 'ekyc', 'is_kyc', 'is_kyc_completed', 'kyccompleted', 'ekyccompleted']) ?? '');

                    // Sanitize NaN / null string representations from raw uploads
                    $no        = (strcasecmp($no, 'nan') === 0 || strcasecmp($no, 'null') === 0) ? '' : $no;
                    $mob       = (strcasecmp($mob, 'nan') === 0 || strcasecmp($mob, 'null') === 0) ? '' : $mob;
                    $addr      = (strcasecmp($addr, 'nan') === 0 || strcasecmp($addr, 'null') === 0) ? '' : $addr;
                    $area      = (strcasecmp($area, 'nan') === 0 || strcasecmp($area, 'null') === 0) ? '' : $area;
                    $conn_type = (strcasecmp($conn_type, 'nan') === 0 || strcasecmp($conn_type, 'null') === 0) ? '' : $conn_type;
                    $status    = (strcasecmp($status, 'nan') === 0 || strcasecmp($status, 'null') === 0) ? '' : $status;
                    $ekyc      = (strcasecmp($ekyc, 'nan') === 0 || strcasecmp($ekyc, 'null') === 0) ? '' : $ekyc;

                    // Strip leading single quote which Excel uses to force numeric columns to strings
                    $mob = ltrim($mob, "'");

                    $stmt->execute([
                        'no'        => $no,
                        'name'      => $name,
                        'mob'       => $mob,
                        'addr'      => $addr,
                        'area'      => $area,
                        'conn_type' => $conn_type,
                        'status'    => $status,
                        'ekyc'      => $ekyc,
                        'branch'    => $branchId
                    ]);
                    $count++;
                }
                $db->commit();
                logAction('CONSUMER_IMPORT', 0, "Imported $count consumers");
                echo json_encode(['success' => true, 'imported' => $count]);
            } catch (Exception $ex) {
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $ex->getMessage()]);
            }
            break;

        case 'get_consumers':
            $search = $_GET['search'] ?? '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $pageSize = 100;
            $offset = ($page - 1) * $pageSize;
            
            $filterField = $_GET['filter_field'] ?? '';
            $filterVal = $_GET['filter_val'] ?? '';
            
            $activeBranchId = getActiveBranchId($db);
            $conditions = [];
            $params = [];

            if ($activeBranchId > 0) {
                $conditions[] = "branch_id = :branch_id";
                $params['branch_id'] = $activeBranchId;
            }
            
            if ($search) {
                $conditions[] = "(consumer_name LIKE :s OR mobile LIKE :s OR consumer_number LIKE :s OR area LIKE :s OR connection_type LIKE :s OR status LIKE :s)";
                $params['s'] = "%$search%";
            }
            
            if ($filterField && $filterVal) {
                if ($filterField === 'connection_type') {
                    $conditions[] = "connection_type LIKE :ff";
                    $params['ff'] = "%$filterVal%";
                } elseif ($filterField === 'ekyc_status') {
                    if ($filterVal === 'Completed') {
                        $conditions[] = "LOWER(ekyc_status) IN ('completed', 'y', 'yes', 'complete')";
                    } else {
                        $conditions[] = "(LOWER(ekyc_status) IN ('pending', 'n', 'no', '') OR ekyc_status IS NULL)";
                    }
                } elseif ($filterField === 'status') {
                    $conditions[] = "LOWER(status) NOT IN ('active', 'y', 'yes', '1') AND status != '' AND status IS NOT NULL";
                }
            }
            
            $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
            
            $stmt = $db->prepare("SELECT * FROM gas_consumers $where ORDER BY consumer_name ASC LIMIT $pageSize OFFSET $offset");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM gas_consumers $where");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();
            
            echo json_encode(['success' => true, 'rows' => $rows, 'total' => $total]);
            break;

        case 'get_consumer_stats':
            try {
                $activeBranchId = getActiveBranchId($db);
                $where = "1=1";
                $params = [];
                if ($activeBranchId > 0) {
                    $where = "branch_id = :branch_id";
                    $params['branch_id'] = $activeBranchId;
                }
                
                $totalStmt = $db->prepare("SELECT COUNT(*) FROM gas_consumers WHERE $where");
                $totalStmt->execute($params);
                $total = (int)$totalStmt->fetchColumn();

                $areasStmt = $db->prepare("SELECT COUNT(DISTINCT area) FROM gas_consumers WHERE $where");
                $areasStmt->execute($params);
                $areas = (int)$areasStmt->fetchColumn();

                $dbcStmt = $db->prepare("SELECT COUNT(*) FROM gas_consumers WHERE $where AND connection_type LIKE '%DBC%'");
                $dbcStmt->execute($params);
                $dbc = (int)$dbcStmt->fetchColumn();

                $sbcStmt = $db->prepare("SELECT COUNT(*) FROM gas_consumers WHERE $where AND connection_type LIKE '%SBC%'");
                $sbcStmt->execute($params);
                $sbc = (int)$sbcStmt->fetchColumn();
                
                $ekycCompletedStmt = $db->prepare("SELECT COUNT(*) FROM gas_consumers WHERE $where AND LOWER(ekyc_status) IN ('completed', 'y', 'yes', 'complete')");
                $ekycCompletedStmt->execute($params);
                $ekyc_completed = (int)$ekycCompletedStmt->fetchColumn();

                $ekycPendingStmt = $db->prepare("SELECT COUNT(*) FROM gas_consumers WHERE $where AND (LOWER(ekyc_status) IN ('pending', 'n', 'no', '') OR ekyc_status IS NULL)");
                $ekycPendingStmt->execute($params);
                $ekyc_pending = (int)$ekycPendingStmt->fetchColumn();

                $blockedStmt = $db->prepare("SELECT COUNT(*) FROM gas_consumers WHERE $where AND LOWER(status) NOT IN ('active', 'y', 'yes', '1') AND status != '' AND status IS NOT NULL");
                $blockedStmt->execute($params);
                $blocked = (int)$blockedStmt->fetchColumn();
                
                echo json_encode([
                    'success'        => true,
                    'total'          => $total,
                    'areas'          => $areas,
                    'dbc'            => $dbc,
                    'sbc'            => $sbc,
                    'ekyc_completed' => $ekyc_completed,
                    'ekyc_pending'   => $ekyc_pending,
                    'blocked'        => $blocked
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'delete_consumer':
            if (!hasPermission('complaints_delete')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']); exit();
            }
            $id = $_GET['id'] ?? '';
            $db->prepare("DELETE FROM gas_consumers WHERE id = :id")->execute(['id' => $id]);
            echo json_encode(['success' => true]);
            break;

        case 'clear_consumers':
            if (!hasPermission('complaints_delete')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']); exit();
            }
            $db->exec("DELETE FROM gas_consumers");
            logAction('CONSUMER_CLEAR', 0, "Cleared all consumers");
            echo json_encode(['success' => true]);
            break;

        case 'add_vendor':
            if (!hasPermission('vendors_add')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $name = $_POST['name'] ?? '';
            $mob = $_POST['mobile'] ?? '';
            $code = $_POST['code'] ?? '';
            $notes = $_POST['notes'] ?? '';

            $stmt = $db->prepare("INSERT INTO gas_vendors (name, mobile, code, notes) VALUES (:name, :mob, :code, :notes)");
            $stmt->execute(['name' => $name, 'mob' => $mob, 'code' => $code, 'notes' => $notes]);
            
            logAction('VENDOR_ADD', $db->lastInsertId(), "Added vendor profile: " . $name);
            echo json_encode(['success' => true]);
            break;

        case 'update_vendor':
            if (!hasPermission('vendors_add')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_POST['id'] ?? '';
            $name = $_POST['name'] ?? '';
            $mob = $_POST['mobile'] ?? '';
            $code = $_POST['code'] ?? '';
            $notes = $_POST['notes'] ?? '';

            $stmt = $db->prepare("UPDATE gas_vendors SET name = :name, mobile = :mob, code = :code, notes = :notes WHERE id = :id");
            $stmt->execute(['name' => $name, 'mob' => $mob, 'code' => $code, 'notes' => $notes, 'id' => $id]);
            
            logAction('VENDOR_UPDATE', $id, "Updated vendor profile: " . $name);
            echo json_encode(['success' => true]);
            break;

        case 'delete_vendor':
            if (!hasPermission('vendors_delete')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_GET['id'] ?? '';
            
            // Delete vendor
            $stmt = $db->prepare("DELETE FROM gas_vendors WHERE id = :id");
            $stmt->execute(['id' => $id]);

            // Unlink complaints mapped to this vendor
            $db->prepare("UPDATE gas_complaints SET vendor_id = NULL, vendor = NULL WHERE vendor_id = :id")->execute(['id' => $id]);

            logAction('VENDOR_DELETE', $id, "Deleted vendor record");
            echo json_encode(['success' => true]);
            break;

        case 'get_vendor_reports':
            if (($_SESSION['gas_role'] ?? '') === 'Vendor') {
                $vId = $_SESSION['gas_vendor_id'] ?? 0;
                $vName = $_SESSION['gas_vendor_name'] ?? '';
                $stmtV = $db->prepare("SELECT * FROM gas_vendors WHERE id = :vid OR name = :vname");
                $stmtV->execute(['vid' => $vId, 'vname' => $vName]);
                $vendors = $stmtV->fetchAll();
            } else {
                $vendors = $db->query("SELECT * FROM gas_vendors ORDER BY name ASC")->fetchAll();
            }
            
            // Company name
            $stmtName = $db->prepare("SELECT setting_value FROM gas_settings WHERE setting_key = 'CompanyName'");
            $stmtName->execute();
            $compName = $stmtName->fetchColumn() ?: 'Gas Agency';

            // Message template
            $stmtTmpl = $db->prepare("SELECT setting_value FROM gas_settings WHERE setting_key = 'VendorMessageTemplate'");
            $stmtTmpl->execute();
            $tmpl = $stmtTmpl->fetchColumn() ?: '';

            $report = [];

            // Add unassigned category only for Admin / Staff
            if (($_SESSION['gas_role'] ?? '') !== 'Vendor') {
                $unassignedOpen = $db->query("SELECT * FROM gas_complaints WHERE deleted = 0 AND (vendor_id IS NULL OR vendor_id = 0) AND status NOT IN ('Delivered', 'Resolved', 'Closed')")->fetchAll();
                $report[] = [
                    'vendor' => ['id' => '', 'name' => 'Unassigned Queue', 'mobile' => ''],
                    'openCount' => count($unassignedOpen),
                    'deliveredCount' => 0,
                    'totalAssigned' => count($unassignedOpen),
                    'openComplaints' => $unassignedOpen,
                    'whatsappMessage' => ''
                ];
            }

            foreach ($vendors as $v) {
                // Open jobs for this vendor
                $stmtOpen = $db->prepare("SELECT * FROM gas_complaints WHERE deleted = 0 AND vendor_id = :vid AND status NOT IN ('Delivered', 'Resolved', 'Closed')");
                $stmtOpen->execute(['vid' => $v['id']]);
                $open = $stmtOpen->fetchAll();

                // Delivered jobs
                $stmtDel = $db->prepare("SELECT COUNT(*) FROM gas_complaints WHERE deleted = 0 AND vendor_id = :vid AND status IN ('Delivered', 'Resolved', 'Closed')");
                $stmtDel->execute(['vid' => $v['id']]);
                $delivered = $stmtDel->fetchColumn();

                // Generate WhatsApp text report
                $waMsg = "*$compName - Jobs Dispatch Sheet*\n";
                $waMsg .= "*Vendor:* {$v['name']}\n";
                $waMsg .= "Date: " . date('d-M-Y') . "\n";
                $waMsg .= "==========================\n\n";

                foreach ($open as $idx => $c) {
                    $msgPart = fillVendorMessageTemplate($tmpl, $c, $v['name']);

                    $waMsg .= ($idx + 1) . ") " . trim($msgPart) . "\n\n";
                }

                if (count($open) === 0) {
                    $waMsg .= "No pending complaints assigned today.\n";
                }

                $waMsg .= "==========================\n";
                $waMsg .= "Total open: " . count($open) . " | Completed: " . $delivered;

                $report[] = [
                    'vendor' => $v,
                    'openCount' => count($open),
                    'deliveredCount' => (int)$delivered,
                    'totalAssigned' => count($open) + $delivered,
                    'openComplaints' => $open,
                    'whatsappMessage' => $waMsg
                ];
            }

            echo json_encode(['success' => true, 'report' => $report]);
            break;

        case 'get_clipboard_text':
            $id = $_GET['id'] ?? '';
            
            $stmtC = $db->prepare("SELECT * FROM gas_complaints WHERE id = :id");
            $stmtC->execute(['id' => $id]);
            $c = $stmtC->fetch();

            $stmtTmpl = $db->prepare("SELECT setting_value FROM gas_settings WHERE setting_key = 'VendorMessageTemplate'");
            $stmtTmpl->execute();
            $tmpl = $stmtTmpl->fetchColumn() ?: '';

            $msg = fillVendorMessageTemplate($tmpl, $c, $c['vendor'] ?? '');

            echo json_encode(['success' => true, 'text' => $msg]);
            break;

        case 'get_analytics':
            $activeBranchId = getActiveBranchId($db);
            $where = "deleted = 0";
            $params = [];
            if ($activeBranchId > 0) {
                $where .= " AND branch_id = :branch_id";
                $params['branch_id'] = $activeBranchId;
            }
            if (($_SESSION['gas_role'] ?? '') === 'Vendor') {
                $where .= " AND (vendor_id = :v_id OR vendor = :v_name)";
                $params['v_id'] = $_SESSION['gas_vendor_id'] ?? 0;
                $params['v_name'] = $_SESSION['gas_vendor_name'] ?? '';
            }

            // 1. Status distribution
            $stmtStatus = $db->prepare("
                SELECT status, COUNT(*) as count 
                FROM gas_complaints WHERE $where 
                GROUP BY status
            ");
            $stmtStatus->execute($params);
            $statusCounts = $stmtStatus->fetchAll();

            $statusLabels = [];
            $statusData = [];
            foreach ($statusCounts as $sc) {
                $statusLabels[] = $sc['status'];
                $statusData[] = (int)$sc['count'];
            }

            // 2. Sources breakdown
            $stmtSrc = $db->prepare("
                SELECT source, COUNT(*) as count 
                FROM gas_complaints WHERE $where 
                GROUP BY source 
                ORDER BY count DESC LIMIT 5
            ");
            $stmtSrc->execute($params);
            $sourceCounts = $stmtSrc->fetchAll();

            $sourceLabels = [];
            $sourceData = [];
            foreach ($sourceCounts as $src) {
                $sourceLabels[] = $src['source'];
                $sourceData[] = (int)$src['count'];
            }

            // 3. Last 7 Days trend
            $trendData = [];
            $trendLabels = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $label = date('d M', strtotime($date));
                
                $stmtTrend = $db->prepare("SELECT COUNT(*) FROM gas_complaints WHERE $where AND DATE(created_at) = :d");
                $stmtTrend->execute(array_merge($params, ['d' => $date]));
                $count = $stmtTrend->fetchColumn();

                $trendLabels[] = $label;
                $trendData[] = (int)$count;
            }

            // 4. Vendor rankings
            $vendors = $db->query("SELECT * FROM gas_vendors")->fetchAll();
            $vendorRankings = [];
            foreach ($vendors as $v) {
                // Completed deliveries
                $stmtDel = $db->prepare("SELECT COUNT(*) FROM gas_complaints WHERE $where AND vendor_id = :vid AND status IN ('Delivered', 'Resolved', 'Closed')");
                $stmtDel->execute(array_merge($params, ['vid' => $v['id']]));
                $delivered = $stmtDel->fetchColumn();

                // Total assigned
                $stmtTot = $db->prepare("SELECT COUNT(*) FROM gas_complaints WHERE $where AND vendor_id = :vid");
                $stmtTot->execute(array_merge($params, ['vid' => $v['id']]));
                $total = $stmtTot->fetchColumn();

                $efficiency = $total > 0 ? round(($delivered / $total) * 100) : 0;

                $vendorRankings[] = [
                    'name' => $v['name'],
                    'delivered' => (int)$delivered,
                    'efficiency' => (int)$efficiency
                ];
            }
            // Sort by efficiency
            usort($vendorRankings, fn($a, $b) => $b['delivered'] <=> $a['delivered']);

            echo json_encode([
                'success' => true,
                'analytics' => [
                    'status' => ['labels' => $statusLabels, 'data' => $statusData],
                    'source' => ['labels' => $sourceLabels, 'data' => $sourceData],
                    'trend' => ['labels' => $trendLabels, 'data' => $trendData],
                    'vendor_rankings' => $vendorRankings
                ]
            ]);
            break;

        case 'export_csv':
            $from = $_GET['date_from'] ?? '';
            $to = $_GET['date_to'] ?? '';
            $status = $_GET['status'] ?? '';

            $whereClauses = ["deleted = 0"];
            $params = [];

            if ($from) {
                $whereClauses[] = "DATE(created_at) >= :from";
                $params['from'] = $from;
            }
            if ($to) {
                $whereClauses[] = "DATE(created_at) <= :to";
                $params['to'] = $to;
            }
            if ($status) {
                $whereClauses[] = "status = :status";
                $params['status'] = $status;
            }

            $whereString = implode(" AND ", $whereClauses);
            $stmt = $db->prepare("SELECT * FROM gas_complaints WHERE $whereString ORDER BY created_at DESC");
            $stmt->execute($params);
            $complaints = $stmt->fetchAll();

            // Set browser download headers
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=gas_complaints_export_' . date('Y-m-d') . '.csv');
            
            $output = fopen('php://output', 'w');
            
            // CSV Headers
            fputcsv($output, ['ID', 'Created At', 'Resolved At', 'Consumer No', 'Consumer Name', 'Mobile', 'Address', 'Source', 'Complaint Details', 'Vendor', 'Status']);

            foreach ($complaints as $c) {
                fputcsv($output, [
                    $c['id'],
                    $c['created_at'],
                    $c['resolved_at'],
                    $c['consumer_number'],
                    $c['consumer_name'],
                    $c['mobile'],
                    $c['address'],
                    $c['source'],
                    $c['complaint'],
                    $c['vendor'],
                    $c['status']
                ]);
            }
            fclose($output);
            exit();

        case 'impersonate_user':
            if ($_SESSION['gas_role'] !== 'Admin') {
                echo json_encode(['success' => false, 'error' => 'Admin permissions required']);
                exit();
            }
            $id = $_GET['id'] ?? '';
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'No employee ID provided']);
                exit();
            }
            $stmt = $db->prepare("SELECT * FROM gas_users WHERE id = :id AND active = 1 LIMIT 1");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Active employee not found']);
                exit();
            }
            // Store original admin ID so they can switch back if needed
            if (!isset($_SESSION['original_admin_id'])) {
                $_SESSION['original_admin_id'] = $_SESSION['gas_user_id'];
            }
            $_SESSION['gas_user_id'] = $row['id'];
            $_SESSION['gas_user'] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'name' => $row['name'],
                'role' => $row['role']
            ];
            $_SESSION['gas_role'] = $row['role'];

            logAction('IMPERSONATE', $row['id'], "Impersonated user account: " . $row['username']);
            echo json_encode(['success' => true]);
            break;

        case 'switch_back_to_admin':
            $adminId = $_SESSION['original_admin_id'] ?? '';
            if (!$adminId) {
                echo json_encode(['success' => false, 'error' => 'No active impersonation session']);
                exit();
            }
            $stmt = $db->prepare("SELECT * FROM gas_users WHERE id = :id AND active = 1 LIMIT 1");
            $stmt->execute(['id' => $adminId]);
            $row = $stmt->fetch();
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Admin profile not found']);
                exit();
            }
            
            $_SESSION['gas_user_id'] = $row['id'];
            $_SESSION['gas_user'] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'name' => $row['name'],
                'role' => $row['role']
            ];
            $_SESSION['gas_role'] = $row['role'];
            unset($_SESSION['original_admin_id']);

            logAction('SWITCH_BACK', $row['id'], "Returned to original admin session");
            echo json_encode(['success' => true]);
            break;

        case 'get_employees':
            if (!hasPermission('users_manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $employees = $db->query("
                SELECT id, username, name, role, permissions, active, mobile, profile_photo 
                FROM gas_users 
                ORDER BY name ASC
            ")->fetchAll();

            foreach ($employees as &$emp) {
                $stmtLogs = $db->prepare("SELECT action, details, timestamp FROM gas_logs WHERE username = :u ORDER BY timestamp DESC LIMIT 20");
                $stmtLogs->execute(['u' => $emp['username']]);
                $emp['logs'] = $stmtLogs->fetchAll();
            }
            echo json_encode(['success' => true, 'employees' => $employees]);
            break;

        case 'add_employee':
            if (!hasPermission('users_manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $name = trim($_POST['name'] ?? '');
            $uname = trim($_POST['username'] ?? '');
            $pw = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'Employee';
            $permissions = $_POST['permissions'] ?? '';
            $mobile = trim($_POST['mobile'] ?? '');
            $photo = $_POST['profile_photo'] ?? 'default-photo.png';
            $branchId = (int)($_POST['branch_id'] ?? 1);

            // Check duplicate username
            $stmt = $db->prepare("SELECT COUNT(*) FROM gas_users WHERE LOWER(username) = LOWER(:u)");
            $stmt->execute(['u' => $uname]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Username already exists']);
                exit();
            }

            $hashed = hashPassword($pw);

            $stmtIns = $db->prepare("
                INSERT INTO gas_users (username, password, name, role, permissions, mobile, profile_photo, created_by, branch_id) 
                VALUES (:u, :p, :name, :role, :perms, :mobile, :photo, :created, :branch)
            ");
            $stmtIns->execute([
                'u' => $uname,
                'p' => $hashed,
                'name' => $name,
                'role' => $role,
                'perms' => $permissions,
                'mobile' => $mobile,
                'photo' => $photo,
                'created' => ($_SESSION['gas_user']['name'] ?? 'System'),
                'branch' => $branchId
            ]);

            logAction('USER_CREATE', $db->lastInsertId(), "Created user account: " . $uname);
            echo json_encode(['success' => true]);
            break;

        case 'update_employee':
            if (!hasPermission('users_manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $uname = trim($_POST['username'] ?? '');
            $pw = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'Employee';
            $permissions = $_POST['permissions'] ?? '';
            $mobile = trim($_POST['mobile'] ?? '');
            $photo = $_POST['profile_photo'] ?? 'default-photo.png';
            $branchId = (int)($_POST['branch_id'] ?? 1);

            // Update user details
            if ($pw) {
                $hashed = hashPassword($pw);
                $stmt = $db->prepare("
                    UPDATE gas_users 
                    SET username = :u, password = :p, name = :name, role = :role, permissions = :perms, mobile = :mobile, profile_photo = :photo, branch_id = :branch 
                    WHERE id = :id
                ");
                $stmt->execute(['u' => $uname, 'p' => $hashed, 'name' => $name, 'role' => $role, 'perms' => $permissions, 'mobile' => $mobile, 'photo' => $photo, 'branch' => $branchId, 'id' => $id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE gas_users 
                    SET username = :u, name = :name, role = :role, permissions = :perms, mobile = :mobile, profile_photo = :photo, branch_id = :branch 
                    WHERE id = :id
                ");
                $stmt->execute(['u' => $uname, 'name' => $name, 'role' => $role, 'perms' => $permissions, 'mobile' => $mobile, 'photo' => $photo, 'branch' => $branchId, 'id' => $id]);
            }

            logAction('USER_UPDATE', $id, "Updated user account: " . $uname);
            echo json_encode(['success' => true]);
            break;

        case 'update_my_profile':
            $id = $_SESSION['gas_user_id'];
            $name = trim($_POST['name'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');
            $pw = $_POST['password'] ?? '';
            $photo = $_POST['profile_photo'] ?? 'default-photo.png';

            if (!$name) {
                echo json_encode(['success' => false, 'error' => 'Full Name is required']);
                exit();
            }

            if ($pw) {
                $hashed = hashPassword($pw);
                $stmt = $db->prepare("
                    UPDATE gas_users 
                    SET name = :name, password = :p, mobile = :mobile, profile_photo = :photo 
                    WHERE id = :id
                ");
                $stmt->execute(['name' => $name, 'p' => $hashed, 'mobile' => $mobile, 'photo' => $photo, 'id' => $id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE gas_users 
                    SET name = :name, mobile = :mobile, profile_photo = :photo 
                    WHERE id = :id
                ");
                $stmt->execute(['name' => $name, 'mobile' => $mobile, 'photo' => $photo, 'id' => $id]);
            }

            $_SESSION['gas_user']['name'] = $name;

            logAction('PROFILE_UPDATE', $id, "Updated personal profile picture/details");
            echo json_encode(['success' => true]);
            break;

        case 'toggle_employee':
            if (!hasPermission('users_manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_GET['id'] ?? '';
            $active = $_GET['active'] ?? 1;

            if (String($id) === String($_SESSION['gas_user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Cannot deactivate yourself']);
                exit();
            }

            $stmt = $db->prepare("UPDATE gas_users SET active = :a WHERE id = :id");
            $stmt->execute(['a' => $active, 'id' => $id]);

            logAction($active ? 'USER_ENABLE' : 'USER_DISABLE', $id, "Toggled user active state");
            echo json_encode(['success' => true]);
            break;

        case 'delete_employee':
            if (!hasPermission('users_manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_GET['id'] ?? '';

            if (String($id) === String($_SESSION['gas_user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete yourself']);
                exit();
            }

            $stmt = $db->prepare("DELETE FROM gas_users WHERE id = :id");
            $stmt->execute(['id' => $id]);

            logAction('USER_DELETE', $id, "Permanently deleted user account");
            echo json_encode(['success' => true]);
            break;

        case 'approve_reset':
            if (!hasPermission('users_manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_GET['id'] ?? '';

            // Fetch pending password
            $stmtU = $db->prepare("SELECT pending_password FROM gas_users WHERE id = :id");
            $stmtU->execute(['id' => $id]);
            $pend = $stmtU->fetchColumn();

            if (!$pend) {
                echo json_encode(['success' => false, 'error' => 'No pending reset request for this user']);
                exit();
            }

            // Move pending to primary password field
            $stmtApp = $db->prepare("UPDATE gas_users SET password = :pw, pending_password = '', reset_requested_at = NULL WHERE id = :id");
            $stmtApp->execute(['pw' => $pend, 'id' => $id]);

            logAction('PWD_RESET_APPROVE', $id, "Approved user password reset");
            echo json_encode(['success' => true]);
            break;

        case 'reject_reset':
            if (!hasPermission('users_manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $id = $_GET['id'] ?? '';
            $stmt = $db->prepare("UPDATE gas_users SET pending_password = '', reset_requested_at = NULL WHERE id = :id");
            $stmt->execute(['id' => $id]);

            logAction('PWD_RESET_REJECT', $id, "Rejected user password reset");
            echo json_encode(['success' => true]);
            break;

        case 'get_settings':
            if (!hasPermission('settings_view')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            // Config settings
            $settings = [];
            $rows = $db->query("SELECT setting_key, setting_value FROM gas_settings")->fetchAll();
            foreach ($rows as $r) {
                $settings[$r['setting_key']] = $r['setting_value'];
            }

            // Sources
            $sources = isset($settings['ComplaintSources']) ? array_map('trim', explode(',', $settings['ComplaintSources'])) : ['Office Phone','Delivery','Leakage','District Office','MO'];
            $sources = array_values(array_filter($sources));

            // Network config (written by start.js)
            $network_config = [];
            $net_cfg_path = __DIR__ . '/network_config.json';
            if (file_exists($net_cfg_path)) {
                $nc = json_decode(file_get_contents($net_cfg_path), true);
                if ($nc) $network_config = $nc;
            }
            if (empty($network_config)) {
                // Fallback: detect server IP from PHP
                $server_ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
                $server_port = $_SERVER['SERVER_PORT'] ?? 8000;
                $network_config = [
                    'ip'   => $server_ip,
                    'port' => (int)$server_port,
                    'url'  => "http://{$server_ip}:{$server_port}"
                ];
            }

            echo json_encode(['success' => true, 'settings' => $settings, 'sources' => $sources, 'network' => $network_config]);
            break;

        case 'save_branding':
            if (!hasPermission('settings_edit')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $name = trim($_POST['company_name'] ?? '');
            $address = trim($_POST['company_address'] ?? '');
            $mobile = trim($_POST['company_mobile'] ?? '');
            $email = trim($_POST['company_email'] ?? '');
            $logo = $_POST['company_logo'] ?? 'default-logo.png';
            $autoWA = $_POST['auto_whatsapp'] ?? 'false';
            $multiBranch = $_POST['multi_branch_enabled'] ?? '0';

            saveGasSetting($db, 'CompanyName', $name);
            saveGasSetting($db, 'CompanyAddress', $address);
            saveGasSetting($db, 'CompanyMobile', $mobile);
            saveGasSetting($db, 'CompanyEmail', $email);
            saveGasSetting($db, 'CompanyLogo', $logo);
            saveGasSetting($db, 'AutoWhatsApp', $autoWA);
            saveGasSetting($db, 'MultiBranchEnabled', $multiBranch);

            logAction('CONFIG_UPDATE', 0, "Updated branding to: " . $name);
            echo json_encode(['success' => true]);
            break;

        case 'get_branches':
            $stmt = $db->query("SELECT * FROM gas_branches ORDER BY name ASC");
            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'branches' => $branches]);
            break;

        case 'add_branch':
            if (!hasPermission('settings_edit')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $brand = trim($_POST['brand'] ?? 'HP');
            $address = trim($_POST['address'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');

            if (empty($name) || empty($code)) {
                echo json_encode(['success' => false, 'error' => 'Name and Code are required']);
                exit();
            }

            if ($id > 0) {
                $stmt = $db->prepare("UPDATE gas_branches SET name = :name, code = :code, brand = :brand, address = :address, mobile = :mobile WHERE id = :id");
                $stmt->execute(['id' => $id, 'name' => $name, 'code' => $code, 'brand' => $brand, 'address' => $address, 'mobile' => $mobile]);
                logAction('BRANCH_UPDATE', $id, "Updated branch: $name");
            } else {
                $stmt = $db->prepare("INSERT INTO gas_branches (name, code, brand, address, mobile) VALUES (:name, :code, :brand, :address, :mobile)");
                $stmt->execute(['name' => $name, 'code' => $code, 'brand' => $brand, 'address' => $address, 'mobile' => $mobile]);
                $id = $db->lastInsertId();
                logAction('BRANCH_CREATE', $id, "Created branch: $name");
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete_branch':
            if (!hasPermission('settings_edit')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                exit();
            }

            // Prevent deleting the last branch
            $count = (int)$db->query("SELECT COUNT(*) FROM gas_branches")->fetchColumn();
            if ($count <= 1) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete the only remaining branch']);
                exit();
            }

            // Re-assign users, complaints, and consumers to default branch before deletion
            $default_id = (int)$db->query("SELECT id FROM gas_branches WHERE id != $id ORDER BY id ASC LIMIT 1")->fetchColumn();
            $db->prepare("UPDATE gas_users SET branch_id = :def WHERE branch_id = :id")->execute(['def' => $default_id, 'id' => $id]);
            $db->prepare("UPDATE gas_complaints SET branch_id = :def WHERE branch_id = :id")->execute(['def' => $default_id, 'id' => $id]);
            $db->prepare("UPDATE gas_consumers SET branch_id = :def WHERE branch_id = :id")->execute(['def' => $default_id, 'id' => $id]);

            $stmt = $db->prepare("DELETE FROM gas_branches WHERE id = :id");
            $stmt->execute(['id' => $id]);
            logAction('BRANCH_DELETE', $id, "Deleted branch ID: $id (migrated to ID: $default_id)");
            echo json_encode(['success' => true]);
            break;

        case 'set_active_branch':
            if (($_SESSION['gas_role'] ?? '') !== 'Admin') {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }
            $id = (int)($_POST['branch_id'] ?? 0);
            $_SESSION['gas_active_branch_id'] = $id;
            echo json_encode(['success' => true]);
            break;

        case 'save_sources':
            if (!hasPermission('settings_edit')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $sources = $_POST['sources'] ?? '';

            saveGasSetting($db, 'ComplaintSources', $sources);

            logAction('CONFIG_UPDATE', 0, "Updated complaint sources list");
            echo json_encode(['success' => true]);
            break;

        case 'save_template':
            if (!hasPermission('settings_edit')) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit();
            }

            $tmpl = $_POST['template'] ?? '';

            saveGasSetting($db, 'VendorMessageTemplate', $tmpl);

            logAction('CONFIG_UPDATE', 0, "Updated WhatsApp message template config");
            echo json_encode(['success' => true]);
            break;

        case 'clear_logs':
            if ($_SESSION['gas_role'] !== 'Admin') {
                echo json_encode(['success' => false, 'error' => 'Admin role required']);
                exit();
            }

            $count = $db->query("SELECT COUNT(*) FROM gas_logs")->fetchColumn();
            $db->exec("DELETE FROM gas_logs");

            logAction('CLEAR_LOGS', 0, "Wiped all audit logs");

            echo json_encode(['success' => true, 'count' => (int)$count]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Action path not supported']);
            break;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);

}
exit();
}
?>
<?php
requireLogin();
$user = getLoggedInUser();

// Fetch settings
$companyInfo = [
    'company_name' => 'Gas Agency',
    'company_logo' => 'default-logo.png',
    'company_address' => '',
    'company_mobile' => '',
    'company_email' => ''
];
$rows = $db->query("SELECT setting_key, setting_value FROM gas_settings")->fetchAll();
$settings = [];
foreach ($rows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
if (isset($settings['CompanyName'])) {
    $companyInfo['company_name'] = $settings['CompanyName'];
}
if (isset($settings['CompanyAddress'])) {
    $companyInfo['company_address'] = $settings['CompanyAddress'];
}
if (isset($settings['CompanyMobile'])) {
    $companyInfo['company_mobile'] = $settings['CompanyMobile'];
}
if (isset($settings['CompanyEmail'])) {
    $companyInfo['company_email'] = $settings['CompanyEmail'];
}
if (isset($settings['CompanyLogo'])) {
    $companyInfo['company_logo'] = $settings['CompanyLogo'];
}
$companyName = $companyInfo['company_name'];
$logoUrl = ($companyInfo['company_logo'] && $companyInfo['company_logo'] !== 'default-logo.png')
    ? $companyInfo['company_logo']
    : '';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?= htmlspecialchars($companyName) ?> - Management Dashboard</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <!-- SweetAlert2 & Chart.js & SheetJS -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

  <!-- PWA Service Worker Cleanup -->
  <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations().then(registrations => {
        for (let registration of registrations) {
          registration.unregister();
        }
      });
    }
  </script>

  <!-- SweetAlert Premium CSS Overrides -->
  <style>
    /* Premium Styling Overrides */
    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --bg-main: #f8fafc;
      --bg-card: #ffffff;
      --text-main: #1e293b;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --sidebar-width: 260px;
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 20px;
    }

    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background-color: var(--bg-main);
      color: var(--text-main);
      margin: 0;
      padding: 0;
      overflow-x: hidden;
    }

    .app-container {
      display: flex;
      min-height: 100vh;
      max-width: 100vw;
      overflow-x: hidden;
    }

    /* Sidebar Styling */
    .sidebar {
      width: var(--sidebar-width);
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%) !important;
      color: #ffffff;
      display: flex;
      flex-direction: column;
      padding: 1.5rem 1rem;
      box-sizing: border-box;
      position: fixed;
      height: 100vh;
      z-index: 100;
      transition: width 0.3s ease;
      box-shadow: 4px 0 25px rgba(15, 23, 42, 0.15);
    }

    .sidebar.collapsed {
      width: 78px;
    }

    .sidebar-header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding-bottom: 2rem;
      border-bottom: 1px solid #334155;
      margin-bottom: 1.5rem;
      overflow: hidden;
      white-space: nowrap;
    }

    .sidebar-brand {
      font-weight: 800;
      font-size: 1.25rem;
      color: #ffffff;
      letter-spacing: -0.5px;
      text-transform: capitalize;
      background: linear-gradient(135deg, #ffffff 0%, #94a3b8 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .sidebar-logo-container {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #eff6ff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      color: var(--primary);
      flex-shrink: 0;
      overflow: hidden;
    }

    .sidebar-logo-container img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .sidebar-nav {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }

    /* SPECIFIC ACCORDION STYLE IN SIDEBAR */
    .sidebar .sidebar-nav .nav-item {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 0.8rem 1.2rem;
      border-radius: var(--radius-sm);
      cursor: pointer;
      font-weight: 700 !important;
      font-size: 0.92rem;
      color: #e2e8f0 !important;
      text-decoration: none;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      white-space: nowrap;
      margin-bottom: 2px;
      border-left: 3px solid transparent;
    }

    .sidebar .sidebar-nav .nav-item:hover {
      color: #ffffff !important;
      background-color: rgba(255, 255, 255, 0.06) !important;
      border-left-color: rgba(255, 255, 255, 0.3);
      padding-left: 1.35rem;
    }

    .sidebar .sidebar-nav .nav-item.active {
      color: #ffffff !important;
      background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
      box-shadow: 0 4px 15px rgba(37, 99, 235, 0.35) !important;
      border-left-color: #3b82f6;
    }

    /* Icon color rules */
    .sidebar .sidebar-nav .nav-item[onclick*="active-registry"] i { color: #fbbf24 !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="history"] i { color: #10b981 !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="vendors"] i { color: #06b6d4 !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="reports"] i { color: #a78bfa !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="analytics"] i { color: #fb7185 !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="export"] i { color: #34d399 !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="employees"] i { color: #38bdf8 !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="settings"] i { color: #f472b6 !important; }
    .sidebar .sidebar-nav .nav-item[onclick*="consumers"] i { color: #f97316 !important; }

    /* Back to CRM Button Gradient */
    .sidebar .sidebar-nav a[href*="employee/dashboard.php"] {
      background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
      color: #ffffff !important;
      border-left: none !important;
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35) !important;
      margin-bottom: 1.25rem !important;
      padding: 0.85rem 1.2rem !important;
      border-radius: var(--radius-sm) !important;
      text-transform: uppercase;
      font-size: 0.8rem;
      letter-spacing: 0.5px;
    }

    .sidebar .sidebar-nav a[href*="employee/dashboard.php"] i {
      color: #ffffff !important;
    }

    .sidebar .sidebar-nav a[href*="employee/dashboard.php"]:hover {
      background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%) !important;
      box-shadow: 0 6px 16px rgba(59, 130, 246, 0.45) !important;
      padding-left: 1.2rem !important;
      transform: translateY(-1px);
    }

    .nav-item i {
      font-size: 1.1rem;
      width: 20px;
      text-align: center;
    }

    .sidebar.collapsed .sidebar-brand,
    .sidebar.collapsed .nav-item span {
      display: none;
    }

    .sidebar-user {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 1rem 0.5rem;
      border-top: 1px solid #334155;
      margin-top: auto;
    }

    .sidebar-user-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--primary);
      color: #ffffff;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .sidebar-user-info {
      overflow: hidden;
      white-space: nowrap;
    }

    .sidebar-user-name {
      font-weight: 700;
      font-size: 0.85rem;
      color: #ffffff;
    }

    .sidebar-user-role {
      font-size: 0.72rem;
      color: #cbd5e1;
    }

    /* Collapsed: hide name/role and side logout button, show avatar-logout instead */
    .sidebar.collapsed .sidebar-user-info,
    .sidebar.collapsed .sidebar-user button.logout-btn {
      display: none !important;
    }
    .sidebar.collapsed .sidebar-user {
      justify-content: center !important;
    }
    .sidebar-user-avatar {
      position: relative;
    }
    .sidebar.collapsed .avatar-logout-btn {
      display: flex !important;
    }
    .avatar-logout-btn {
      display: none;
      position: absolute;
      bottom: -6px;
      right: -6px;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: #ef4444;
      border: 2px solid #1e293b;
      color: #fff;
      font-size: 0.6rem;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: transform 0.2s;
    }
    .avatar-logout-btn:hover {
      transform: scale(1.15);
      background: #dc2626;
    }

    /* Premium Detail Cards */
    .premium-detail-card {
      background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
      border: 1px solid #e2e8f0;
      padding: 1.25rem;
      border-radius: 12px;
      margin-bottom: 1.25rem;
      font-size: 0.88rem;
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.02);
      transition: transform 0.2s ease;
    }

    .premium-detail-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.05);
    }

    /* Main Content Layout */
    .main-content {
      flex: 1;
      margin-left: var(--sidebar-width);
      padding: 2rem 1.5rem;
      box-sizing: border-box;
      transition: margin-left 0.3s ease;
      min-height: 100vh;
      max-width: calc(100vw - var(--sidebar-width));
      overflow-x: hidden;
      min-width: 0;
    }

    .sidebar.collapsed + .main-content {
      margin-left: 78px;
      max-width: calc(100vw - 78px);
    }

    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
      gap: 0.75rem;
      width: 100%;
      box-sizing: border-box;
    }

    .page-title h1 {
      font-size: 1.75rem;
      font-weight: 800;
      margin: 0;
      letter-spacing: -0.5px;
    }

    .page-title p {
      margin: 0.25rem 0 0 0;
      color: var(--text-muted);
      font-size: 0.9rem;
    }

    /* Cards */
    .content-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
      margin-bottom: 1.5rem;
      overflow: hidden;
      width: 100%;
      box-sizing: border-box;
      min-width: 0;
    }

    /* Statistics Widgets */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(180px, 100%), 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
      width: 100%;
      box-sizing: border-box;
    }

    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      padding: 1.25rem 1rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
      min-width: 0;
      box-sizing: border-box;
    }

    .stat-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.35rem;
    }

    .stat-info .value {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--text-main);
    }

    .stat-info .label {
      font-size: 0.78rem;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      margin-top: 0.2rem;
    }

    /* Table styles */
    .table-container {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      border-radius: var(--radius-sm);
      overflow: hidden;
    }

    th {
      background: #0f172a !important;
      padding: 1rem 1.25rem;
      font-size: 0.8rem;
      font-weight: 700;
      color: #ffffff !important;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: none;
    }

    td {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--border-color);
      font-size: 0.88rem;
    }

    tr:hover td {
      background-color: #f8fafc;
    }

    /* Badges */
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.3rem 0.6rem;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
    }

    .badge-pending { background-color: #fef3c7; color: #b45309; }
    .badge-warning { background-color: #ffedd5; color: #ea580c; }
    .badge-delivered { background-color: #dcfce7; color: #166534; }
    .badge-resolved { background-color: #dbeafe; color: #1e40af; }
    .badge-secondary { background-color: #f1f5f9; color: #475569; }
    .badge-danger { background-color: #fee2e2; color: #b91c1c; }

    /* Modals overlays */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(4px);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
    }

    .modal {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      width: 90%;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
      animation: modalShow 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
      display: flex;
      flex-direction: column;
      max-height: 90vh;
    }

    .modal-header {
      padding: 1.25rem 1.5rem;
      border-bottom: none;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #0f172a !important;
      color: #ffffff !important;
      border-top-left-radius: var(--radius-lg);
      border-top-right-radius: var(--radius-lg);
    }

    .modal-title {
      font-weight: 800;
      font-size: 1.25rem;
      letter-spacing: -0.5px;
      color: #ffffff !important;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 1.5rem;
      cursor: pointer;
      color: #cbd5e1 !important;
      opacity: 0.8;
      transition: all 0.2s ease;
    }

    .modal-close:hover {
      color: var(--text-main);
    }

    .modal-body {
      padding: 1.5rem;
      overflow-y: auto;
    }

    .modal-footer {
      padding: 1rem 1.5rem;
      border-top: 1px solid var(--border-color);
      display: flex;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 0.5rem;
      background: #f8fafc;
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1.25rem;
      border: 1px solid transparent;
      border-radius: 8px;
      font-family: inherit;
      font-weight: 700;
      font-size: 0.85rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-sm {
      padding: 0.35rem 0.75rem;
      font-size: 0.75rem;
      border-radius: 6px;
    }

    .btn-primary { background-color: var(--primary); color: white; }
    .btn-primary:hover { background-color: var(--primary-dark); }
    .btn-success { background-color: #10b981; color: white; }
    .btn-success:hover { background-color: #059669; }
    .btn-danger { background-color: #ef4444; color: white; }
    .btn-danger:hover { background-color: #dc2626; }
    .btn-warning { background-color: #f59e0b; color: white; }
    .btn-warning:hover { background-color: #d97706; }
    .btn-outline { background-color: transparent; border-color: var(--border-color); color: var(--text-main); }
    .btn-outline:hover { background-color: #f8fafc; border-color: var(--text-muted); }
    .btn-secondary { background-color: #e2e8f0; color: var(--text-main); }
    .btn-secondary:hover { background-color: #cbd5e1; }

    /* Forms */
    .form-group {
      margin-bottom: 1.25rem;
    }

    .form-group label {
      display: block;
      font-weight: 700;
      font-size: 0.8rem;
      margin-bottom: 0.4rem;
      color: var(--text-main);
    }

    .form-control {
      width: 100%;
      padding: 0.65rem 0.85rem;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.88rem;
      box-sizing: border-box;
      background: #f8fafc;
      transition: all 0.2s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      background: white;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }

    /* Toolbar filter group */
    .toolbar {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background-color: #ffffff;
      flex-wrap: wrap;
      gap: 1rem;
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    /* Views */
    .view-section {
      display: none;
    }

    .view-section.active {
      display: block;
    }

    /* Signature canvas styling */
    .sig-canvas-container {
      border: 2px dashed #cbd5e1;
      background: #ffffff;
      border-radius: 8px;
      position: relative;
      margin-top: 0.5rem;
    }

    #sigCanvas {
      width: 100%;
      height: 150px;
      cursor: crosshair;
      display: block;
    }

    /* Pagination */
    .pagination {
      padding: 1rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid var(--border-color);
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-muted);
    }

    /* Alert indicators for aging cases */
    .aging-alert {
      background-color: #fffbeb !important;
    }

    .aging-alert-critical {
      background-color: #fef2f2 !important;
    }

    .aging-badge {
      font-size: 9px;
      font-weight: 700;
      padding: 2px 4px;
      border-radius: 4px;
      background: #fee2e2;
      color: #991b1b !important;
      display: inline-block;
      margin-top: 4px;
    }

    .heartbeat {
      animation: beat 1s infinite alternate;
    }

    @keyframes beat {
      to { transform: scale(1.05); }
    }

    /* Loader */
    #loadingOverlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.7);
      z-index: 2000;
      display: none;
      align-items: center;
      justify-content: center;
      flex-direction: column;
    }

    #loadingOverlay .spinner {
      width: 48px;
      height: 48px;
      border: 4px solid #cbd5e1;
      border-radius: 50%;
      border-top-color: var(--primary);
      animation: spin 0.8s linear infinite;
    }

    /* ============================================
       MOBILE-FIRST RESPONSIVE DESIGN SYSTEM
       ============================================ */

    /* ---- Desktop: sidebar collapse ---- */
    @media (min-width: 992px) {
      .sidebar.collapsed { width: 78px; }
      .sidebar.collapsed .sidebar-brand,
      .sidebar.collapsed .nav-item span { opacity: 0; width: 0; overflow: hidden; }
      .sidebar.collapsed + .main-content { margin-left: 78px; max-width: calc(100vw - 78px); }
      .bottom-nav { display: none !important; }
      .mobile-fab { display: none !important; }
    }

    /* ---- Medium screens: compact layout to prevent overflow ---- */
    @media (min-width: 992px) and (max-width: 1280px) {
      :root { --sidebar-width: 200px; }
      .main-content { padding: 1.25rem 1rem; }
      .stats-grid { grid-template-columns: repeat(auto-fill, minmax(min(140px, 100%), 1fr)); gap: 0.75rem; }
      .stat-card { padding: 1rem 0.75rem; }
      .stat-icon { width: 38px; height: 38px; font-size: 1.1rem; flex-shrink: 0; }
      .stat-info .value { font-size: 1.25rem; }
      .toolbar { padding: 0.75rem 1rem; }
      header { margin-bottom: 1rem; }
    }

    /* ---- Tablet / Mobile: slide-in sidebar ---- */
    @media (max-width: 991px) {
      :root { --sidebar-width: 280px; }

      .app-container { display: block; }

      /* Sidebar becomes a full-height slide-in drawer */
      .sidebar {
        position: fixed;
        top: 0; left: 0;
        height: 100%;
        transform: translateX(-110%);
        transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);
        z-index: 500;
        padding-top: 1rem;
        width: var(--sidebar-width) !important;
      }
      .sidebar.open {
        transform: translateX(0);
        box-shadow: 8px 0 40px rgba(0,0,0,0.4);
      }
      .sidebar.open .modal-close { display: block !important; }

      /* Overlay behind drawer */
      .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 490;
        backdrop-filter: blur(2px);
      }
      .sidebar-overlay.show { display: block; }

      /* Main content full width */
      .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        padding: 0 !important;
        padding-bottom: 80px !important; /* room for bottom nav */
      }

      .main-content > *,
      .view-section,
      .content-card,
      .toolbar,
      .filter-card {
        max-width: 100% !important;
        min-width: 0 !important;
      }

      main > header {
        width: 100% !important;
        box-sizing: border-box !important;
        gap: 0.65rem !important;
      }
      main > header .page-title {
        min-width: 0 !important;
      }
      main > header #viewTitle,
      main > header #viewSubtitle {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      #liveClockWidget {
        margin-right: 0 !important;
        flex-shrink: 0;
      }
      #liveClockWidget #clockDate { display: none; }

      .form-control,
      input:not([type="checkbox"]):not([type="radio"]),
      select,
      textarea {
        max-width: 100% !important;
        box-sizing: border-box !important;
      }
      .btn-group { max-width: 100%; }
      .btn { white-space: nowrap; }

      /* Mobile App-style top header */
      main > header {
        background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%) !important;
        border-radius: 0 !important;
        padding: 0.75rem 1rem !important;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 12px rgba(0,0,0,0.3);
      }
      main > header #viewTitle { color: #fff !important; font-size: 1rem !important; }
      main > header #viewSubtitle { color: #94a3b8 !important; font-size: 0.72rem !important; }
      main > header #sidebarToggleBtn {
        background: rgba(255,255,255,0.1) !important;
        border-color: rgba(255,255,255,0.2) !important;
        color: #fff !important;
      }
      main > header .btn-group { flex-wrap: wrap; gap: 0.4rem; }
      main > header .btn-group .btn { font-size: 0.72rem; padding: 0.35rem 0.65rem; }

      /* View sections padding */
      .view-section { padding: 1rem !important; }
      .dashboard-welcome { align-items: flex-start; flex-direction: column; }
      .dashboard-welcome h2 { font-size: 1.25rem; }
      .dashboard-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; }
      .dashboard-kpi { padding: 0.8rem; }
      .dashboard-kpi strong { font-size: 1.2rem; }
      .dashboard-columns { grid-template-columns: 1fr; gap: 0.75rem; }
      .dashboard-chart-grid { grid-template-columns: 1fr; gap: 0.75rem; }
      .dashboard-activity { grid-template-columns: 1fr; }

      /* Stats grid — 2x3 grid on mobile */
      .stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.6rem !important;
      }
      .stat-card { padding: 0.9rem 0.75rem !important; }
      .stat-card .value { font-size: 1.6rem !important; }
      .stat-card .label { font-size: 0.65rem !important; }
      .stat-icon { width: 36px !important; height: 36px !important; font-size: 1rem !important; }

      /* 5th card full width on mobile */
      .stats-grid .stat-card:last-child { grid-column: span 2; }

      /* Pipeline filter — horizontal scroll */
      .pipeline-container {
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        padding-bottom: 0.5rem;
        -webkit-overflow-scrolling: touch;
        gap: 2px !important;
        scrollbar-width: none;
        margin-left: -1rem;
        margin-right: -1rem;
        padding-left: 1rem;
        padding-right: 1rem;
      }
      .pipeline-container::-webkit-scrollbar { display: none; }
      .pipeline-step {
        flex: 0 0 110px !important;
        font-size: 0.68rem !important;
        height: 36px !important;
      }

      /* Content cards */
      .content-card { border-radius: 12px !important; margin-bottom: 1rem !important; }
      .toolbar { padding: 0.85rem 1rem !important; }

      /* Tables: horizontal scroll */
      .table-container {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        margin: 0 !important;
        border-radius: 0 !important;
      }
      table { min-width: 500px; }

      /* Hide non-critical columns on mobile */
      .hide-mobile { display: none !important; }

      /* Modals: full-screen on mobile */
      .modal-overlay {
        padding: 0 !important;
        align-items: flex-end !important;
      }
      .modal {
        width: 100% !important;
        max-width: 100% !important;
        max-height: 92vh !important;
        border-radius: 20px 20px 0 0 !important;
        overflow-y: auto;
      }
      .modal-footer {
        flex-wrap: wrap !important;
        gap: 0.5rem !important;
        padding: 1rem !important;
      }
      .modal-footer .btn {
        flex: 1 1 calc(50% - 0.25rem) !important;
        justify-content: center !important;
        padding: 0.65rem 0.5rem !important;
        font-size: 0.78rem !important;
      }

      /* Filter section compact */
      .filter-grid {
        grid-template-columns: 1fr 1fr !important;
        gap: 0.5rem !important;
      }

      /* Batch toolbar */
      #batchActions { flex-wrap: wrap !important; }

      /* Layout grids overrides */
      #view-settings > div,
      #view-analytics > div {
        grid-template-columns: 1fr !important;
        gap: 1rem !important;
      }
      .modal-header {
        border-top-left-radius: 20px !important;
        border-top-right-radius: 20px !important;
      }
      .modal .modal-body div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
        gap: 0.5rem !important;
      }
    }

    /* ---- Small Mobile (<480px) ---- */
    @media (max-width: 480px) {
      .view-section { padding: 0.75rem !important; }
      .content-card { border-radius: 8px !important; }
      .toolbar,
      .filter-card { padding: 0.75rem !important; }
      .stats-grid .stat-card { min-width: 0 !important; }
      .dashboard-kpis { gap: 0.45rem; }
      .dashboard-kpi span, .dashboard-kpi small { font-size: 0.62rem; }
      .dashboard-panel { padding: 0.9rem !important; }
      .stat-card .label { white-space: normal !important; }
      .stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.5rem !important;
      }
      .filter-grid { grid-template-columns: 1fr !important; }
      main > header .btn-group { display: none; } /* hide action buttons from header on tiny screens */
    }

    /* ============================================
       BOTTOM NAVIGATION BAR (Mobile Only)
       ============================================ */
    .bottom-nav {
      display: none;
      position: fixed;
      bottom: 0; left: 0; right: 0;
      background: #0f172a;
      border-top: 1px solid #1e293b;
      z-index: 400;
      padding: 0.4rem 0 env(safe-area-inset-bottom, 0.4rem);
      box-shadow: 0 -4px 20px rgba(0,0,0,0.4);
    }
    .bottom-nav-inner {
      display: flex;
      justify-content: space-around;
      align-items: center;
    }
    .bnav-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      padding: 0.4rem 0.6rem;
      border-radius: 10px;
      cursor: pointer;
      color: #64748b;
      font-size: 0.6rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      transition: all 0.2s;
      min-width: 52px;
      border: none;
      background: none;
    }
    .bnav-item i { font-size: 1.2rem; margin-bottom: 2px; }
    .bnav-item.active { color: #3b82f6; }
    .bnav-item.active i { filter: drop-shadow(0 0 6px rgba(59,130,246,0.6)); }

    /* FAB — Floating Add Button (Mobile) */
    .mobile-fab {
      display: none;
      position: fixed;
      bottom: 70px;
      right: 16px;
      z-index: 450;
      width: 54px; height: 54px;
      border-radius: 50%;
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      color: #fff;
      border: none;
      font-size: 1.4rem;
      box-shadow: 0 6px 24px rgba(59,130,246,0.5);
      cursor: pointer;
      display: flex !important;
      align-items: center;
      justify-content: center;
      transition: transform 0.2s;
    }
    .mobile-fab:active { transform: scale(0.93); }

    @media (min-width: 992px) {
      .mobile-fab { display: none !important; }
      .bottom-nav { display: none !important; }
    }
    @media (max-width: 991px) {
      .bottom-nav { display: block; }
      .mobile-fab { display: flex !important; }
    }

    /* Chevron Pipeline flow styling */
    .pipeline-container {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      margin-bottom: 2rem;
      width: 100%;
    }
    .pipeline-step {
      flex: 1;
      min-width: 120px;
      height: 42px;
      background-color: #e2e8f0;
      color: #475569;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.8rem;
      cursor: pointer;
      position: relative;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      clip-path: polygon(0% 0%, 92% 0%, 100% 50%, 92% 100%, 0% 100%, 8% 50%);
      padding: 0 1rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border: none !important;
      outline: none !important;
    }
    .pipeline-step:first-child {
      clip-path: polygon(0% 0%, 92% 0%, 100% 50%, 92% 100%, 0% 100%);
      border-top-left-radius: 8px;
      border-bottom-left-radius: 8px;
    }
    .pipeline-step:last-child {
      clip-path: polygon(0% 0%, 100% 0%, 100% 100%, 0% 100%, 8% 50%);
      border-top-right-radius: 8px;
      border-bottom-right-radius: 8px;
    }
    .pipeline-step:hover { background-color: #cbd5e1; color: #1e293b; }
    .pipeline-step.active { color: #ffffff !important; }
    .pipeline-step.step-all.active      { background: linear-gradient(135deg, #64748b 0%, #475569 100%) !important; }
    .pipeline-step.step-pending.active  { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important; }
    .pipeline-step.step-progress.active { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important; }
    .pipeline-step.step-delivered.active{ background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important; }
    .pipeline-step.step-resolved.active { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%) !important; }

    /* Gradient KPI Cards */
    .premium-card-1 { background: linear-gradient(135deg, #311010 0%, #7f1d1d 100%) !important; color: #ffffff !important; border: none !important; }
    .premium-card-2 { background: linear-gradient(135deg, #064e3b 0%, #059669 100%) !important; color: #ffffff !important; border: none !important; }
    .premium-card-3 { background: linear-gradient(135deg, #78350f 0%, #d97706 100%) !important; color: #ffffff !important; border: none !important; }
    .premium-card-4 { background: linear-gradient(135deg, #581c87 0%, #a855f7 100%) !important; color: #ffffff !important; border: none !important; }
    .premium-card-1 .stat-icon,
    .premium-card-2 .stat-icon,
    .premium-card-3 .stat-icon,
    .premium-card-4 .stat-icon { background: rgba(255,255,255,0.15) !important; color: white !important; }
    .premium-card-1 .stat-info .value, .premium-card-1 .stat-info .label,
    .premium-card-2 .stat-info .value, .premium-card-2 .stat-info .label,
    .premium-card-3 .stat-info .value, .premium-card-3 .stat-info .label,
    .premium-card-4 .stat-info .value, .premium-card-4 .stat-info .label { color: #ffffff !important; }

    /* Employee Cards */
    .employee-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .employee-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.075);
    }

    .print-only-header {
      display: none !important;
    }

    /* PRINT SPECIFIC STYLING */
    @media print {
      body {
        background: #ffffff !important;
        color: #000000 !important;
      }
      header,
      main > header,
      .sidebar,
      .sidebar-overlay,
      #sidebarToggleBtn,
      #headerActions,
      .stats-grid,
      .toolbar,
      .filter-card,
      #view-settings,
      .modal,
      .swal2-container,
      .btn,
      .bottom-nav,
      .mobile-fab {
        display: none !important;
      }
      .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
      }
      .content-card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        background: transparent !important;
      }
      table {
        width: 100% !important;
        border-collapse: collapse !important;
      }
      th, td {
        border-bottom: 1px solid #e2e8f0 !important;
        padding: 8px !important;
      }
      .print-only-header {
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        border-bottom: 2px solid #2563eb;
        padding-bottom: 12px;
        margin-bottom: 20px;
      }
    }
    
    .stat-card-clickable {
      cursor: pointer;
      transition: all 0.2s ease;
      border: 2px solid transparent !important;
    }
    .stat-card-clickable:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.1) !important;
    }
    .stat-card-clickable.active-filter {
      border: 2px solid #2563eb !important;
      box-shadow: 0 0 10px rgba(37, 99, 235, 0.2) !important;
    }

    /* Premium Multi-Color Dashboard Styling */
    .dashboard-welcome {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.5rem;
      padding: 1.4rem 1.75rem;
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      border-radius: 16px;
      color: #ffffff;
      box-shadow: 0 10px 25px rgba(15, 23, 42, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.1);
    }
    .dashboard-welcome h2 { margin: 0.2rem 0 0.1rem 0; font-size: 1.6rem; font-weight: 800; color: #ffffff; }
    .dashboard-welcome p { margin: 0; color: #94a3b8; font-size: 0.85rem; font-weight: 500; }
    .dashboard-kicker { color: #38bdf8; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; }

    .dashboard-kpis { display: grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .dashboard-kpi {
      min-width: 0;
      padding: 1.15rem 1rem;
      border-radius: 14px;
      color: #ffffff;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
      border: none;
    }
    .dashboard-kpi:hover {
      transform: translateY(-4px);
      box-shadow: 0 14px 28px rgba(0, 0, 0, 0.15);
    }
    .dashboard-kpi i {
      display: inline-flex;
      width: 38px;
      height: 38px;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
      margin-bottom: 0.75rem;
      font-size: 1.1rem;
      background: rgba(255, 255, 255, 0.22);
      color: #ffffff;
      backdrop-filter: blur(4px);
    }
    .dashboard-kpi span { display: block; color: rgba(255, 255, 255, 0.9); font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .dashboard-kpi strong { display: block; margin: 0.2rem 0; color: #ffffff; font-size: 1.75rem; font-weight: 800; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .dashboard-kpi small { display: block; color: rgba(255, 255, 255, 0.85); font-size: 0.7rem; font-weight: 600; }

    /* Vibrant Multi-Color Gradients */
    .kpi-blue { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
    .kpi-amber { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .kpi-cyan { background: linear-gradient(135deg, #06b6d4 0%, #0e7490 100%); }
    .kpi-green { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
    .kpi-dark { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }

    .dashboard-columns { display: grid; grid-template-columns: 1.4fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
    .dashboard-chart-grid { display: grid; grid-template-columns: 1fr 1.4fr; gap: 1.25rem; margin-top: 1.25rem; }
    .dashboard-chart { position: relative; height: 220px; }
    .dashboard-panel { padding: 1.35rem; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color); box-shadow: 0 4px 15px rgba(15,23,42,0.04); }
    .dashboard-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; margin-bottom: 1.1rem; }
    .dashboard-panel h3 { margin: 0.2rem 0 0; color: var(--text-main); font-size: 1.05rem; font-weight: 800; }

    .dashboard-progress-row { display: flex; justify-content: space-between; margin-top: 0.9rem; color: var(--text-muted); font-size: 0.82rem; font-weight: 700; }
    .dashboard-progress { height: 10px; overflow: hidden; border-radius: 10px; background: #e2e8f0; margin-top: 4px; }
    .dashboard-progress i { display: block; width: 0; height: 100%; border-radius: inherit; transition: width .4s ease; }
    #dashPendingBar { background: linear-gradient(90deg, #f59e0b, #d97706); }
    #dashProgressBar { background: linear-gradient(90deg, #06b6d4, #0e7490); }
    #dashResolvedBar { background: linear-gradient(90deg, #10b981, #047857); }

    .dashboard-action {
      width: 100%; display: flex; align-items: center; gap: 0.85rem; padding: 0.85rem 0.75rem; border: 1px solid #e2e8f0;
      border-radius: 10px; background: #f8fafc; color: var(--text-main); text-align: left; cursor: pointer; margin-bottom: 0.65rem;
      transition: all 0.2s ease;
    }
    .dashboard-action:hover { background: #ffffff; box-shadow: 0 4px 12px rgba(0,0,0,0.06); transform: translateX(3px); }
    .dashboard-action > i:first-child { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 9px; color: #ffffff; background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
    .dashboard-action:nth-child(2) > i:first-child { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
    .dashboard-action:nth-child(3) > i:first-child { background: linear-gradient(135deg, #06b6d4, #0e7490); }
    .dashboard-action span { flex: 1; }
    .dashboard-action b, .dashboard-action small { display: block; }
    .dashboard-action b { font-size: 0.82rem; font-weight: 700; }
    .dashboard-action small { margin-top: 2px; color: var(--text-muted); font-size: 0.7rem; }

    .dashboard-activity { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.85rem; }
    .dashboard-activity > div { display: flex; align-items: center; gap: 0.85rem; padding: 1rem; border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0; }
    .dashboard-activity i { font-size: 1.3rem; color: #3b82f6; }
    .dashboard-activity > div:nth-child(2) i { color: #06b6d4; }
    .dashboard-activity > div:nth-child(3) i { color: #10b981; }
    .dashboard-activity span { flex: 1; color: var(--text-muted); font-size: 0.75rem; font-weight: 700; }
    .dashboard-activity strong { color: var(--text-main); font-size: 1.2rem; font-weight: 800; }

    /* Top-bar appearance controls */
    .appearance-control { position: relative; flex-shrink: 0; }
    .appearance-toggle {
      width: 38px; height: 38px; border: 1px solid var(--border-color);
      border-radius: 8px; background: var(--bg-card); color: var(--text-main);
      cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
    }
    .appearance-menu {
      display: none; position: absolute; right: 0; top: calc(100% + 8px); z-index: 700;
      width: 220px; padding: 0.75rem; border: 1px solid var(--border-color);
      border-radius: 10px; background: var(--bg-card); box-shadow: 0 12px 30px rgba(15,23,42,0.2);
    }
    .appearance-menu.open { display: block; }
    .appearance-menu-title { font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.6rem; }
    .theme-swatches { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.45rem; }
    .theme-swatch { height: 28px; border: 2px solid transparent; border-radius: 6px; cursor: pointer; }
    .theme-swatch.active { border-color: var(--text-main); box-shadow: 0 0 0 2px var(--bg-card); }
    .theme-swatch[data-theme="blue"] { background: linear-gradient(90deg,#172554 50%,#3b82f6 50%); }
    .theme-swatch[data-theme="cyan"] { background: linear-gradient(90deg,#164e63 50%,#06b6d4 50%); }
    .theme-swatch[data-theme="green"] { background: linear-gradient(90deg,#064e3b 50%,#10b981 50%); }
    .theme-swatch[data-theme="amber"] { background: linear-gradient(90deg,#422006 50%,#f59e0b 50%); }
    .theme-swatch[data-theme="rose"] { background: linear-gradient(90deg,#4c0519 50%,#f43f5e 50%); }
    .dark-mode-row { display:flex; align-items:center; justify-content:space-between; margin-top:0.75rem; padding-top:0.7rem; border-top:1px solid var(--border-color); font-size:0.78rem; font-weight:700; color:var(--text-main); }
    .dark-mode-row input { accent-color: var(--primary); }

    body.theme-dark { --bg-main:#111827; --bg-card:#1f2937; --text-main:#f8fafc; --text-muted:#94a3b8; --border-color:#374151; color:var(--text-main); }
    body.theme-dark .content-card, body.theme-dark .toolbar, body.theme-dark .filter-card { background:#1f2937 !important; border-color:#374151 !important; }
    body.theme-dark .form-control, body.theme-dark select, body.theme-dark textarea, body.theme-dark input { background:#111827 !important; color:#f8fafc !important; border-color:#4b5563 !important; }
    body.theme-dark table th { background:#0f172a !important; }
    body.theme-dark table td { border-color:#374151 !important; color:#e5e7eb; }

    body.theme-cyan { --primary:#0891b2; --primary-dark:#0e7490; }
    body.theme-green { --primary:#059669; --primary-dark:#047857; }
    body.theme-amber { --primary:#d97706; --primary-dark:#b45309; }
    body.theme-rose { --primary:#e11d48; --primary-dark:#be123c; }

    /* Dashboard rules come after the main mobile block, so repeat the mobile layout here. */
    @media (max-width: 991px) {
      html, body, .app-container, .main-content { width:100%; max-width:100%; }
      .main-content { overflow-x:hidden; }
      .dashboard-welcome { flex-direction:column; align-items:stretch; }
      .dashboard-welcome h2 { font-size:1.25rem; line-height:1.2; }
      .dashboard-welcome .btn { align-self:flex-start; }
      .dashboard-kpis { grid-template-columns:repeat(2, minmax(0, 1fr)); width:100%; }
      .dashboard-columns, .dashboard-chart-grid { grid-template-columns:minmax(0, 1fr); width:100%; }
      .dashboard-panel { min-width:0; overflow:hidden; }
      .dashboard-activity { grid-template-columns:minmax(0, 1fr); }
      .dashboard-chart { height:200px; min-width:0; }
      .dashboard-action { min-width:0; }
      #liveClockWidget { display:none !important; }
      #headerActions { display:none !important; }
      main > header { min-width:0; }
      main > header .page-title { overflow:hidden; }
      .appearance-menu { right:-4px; }
    }

    @media (max-width: 360px) {
      .view-section { padding:0.65rem !important; }
      .dashboard-kpis { gap:0.4rem; }
      .dashboard-kpi { padding:0.65rem; }
      .dashboard-kpi strong { font-size:1.05rem; }
      .dashboard-kpi span, .dashboard-kpi small { font-size:0.58rem; }
      .dashboard-panel { padding:0.75rem !important; }
      .dashboard-panel-head .btn { padding:0.3rem 0.45rem; font-size:0.65rem; }
    }
  </style>
</head>
<body>

  <!-- Loading Screen -->
  <div id="loadingOverlay">
    <div class="spinner"></div>
    <p style="margin-top: 1rem; font-weight: 700; color: var(--primary);">Loading System Data...</p>
  </div>

  <div class="app-container">

    <!-- Sidebar Backdrop Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    
    <!-- Sidebar Navigation -->
    <nav class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo-container">
          <?php if ($logoUrl): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
          <?php else: ?>
            <i class="fas fa-gas-pump"></i>
          <?php endif; ?>
        </div>
        <span class="sidebar-brand"><?= htmlspecialchars($companyName) ?></span>
        <button class="modal-close" style="color: white; display: none;" onclick="document.getElementById('sidebar').classList.remove('open')">&times;</button>
      </div>

      <div class="sidebar-nav">
        <div class="nav-item active" onclick="switchView('dashboard', this)">
          <i class="fas fa-chart-pie"></i> <span>Dashboard</span>
        </div>
        <div class="nav-item" onclick="switchView('active-registry', this)">
          <i class="fas fa-truck-loading"></i> <span><?= ($user['role'] === 'Vendor') ? 'My Active Deliveries' : 'Active Registry' ?></span>
        </div>
        <div class="nav-item" onclick="switchView('history', this)">
          <i class="fas fa-history"></i> <span><?= ($user['role'] === 'Vendor') ? 'Delivery History' : 'History Log' ?></span>
        </div>
        <div class="nav-item" onclick="switchView('reports', this)">
          <i class="fas fa-file-invoice"></i> <span><?= ($user['role'] === 'Vendor') ? 'Trip Manifest' : 'Dispatch Sheets' ?></span>
        </div>
        <?php if (($user['role'] ?? '') !== 'Vendor'): ?>
          <div class="nav-item" onclick="switchView('vendors', this)">
            <i class="fas fa-truck"></i> <span>Vendors Directory</span>
          </div>
          <div class="nav-item" onclick="switchView('analytics', this)">
            <i class="fas fa-chart-line"></i> <span>Performance Charts</span>
          </div>
          <div class="nav-item" onclick="switchView('export', this)">
            <i class="fas fa-file-excel"></i> <span>Data Export</span>
          </div>
          <div class="nav-item" onclick="switchView('consumers', this)">
            <i class="fas fa-users"></i> <span>Consumer Registry</span>
          </div>
        <?php endif; ?>
        <?php if (($user['role'] ?? '') === 'Admin'): ?>
          <div class="nav-item" onclick="switchView('employees', this)">
            <i class="fas fa-users-cog"></i> <span>Employee Management</span>
          </div>
          <div class="nav-item" onclick="switchView('settings', this)">
            <i class="fas fa-cog"></i> <span>Settings Console</span>
          </div>
        <?php endif; ?>
      </div>



      <div class="sidebar-user" style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
        <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
          <div class="sidebar-user-avatar" style="position: relative;">
            <?php if (!empty($user['profile_photo']) && $user['profile_photo'] !== 'default-photo.png'): ?>
              <img src="<?= htmlspecialchars($user['profile_photo']) ?>" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
            <?php else: ?>
              <?= strtoupper(substr($user['name'], 0, 1)) ?>
            <?php endif; ?>
            <!-- Logout button shown on avatar when sidebar is collapsed -->
            <button class="avatar-logout-btn" onclick="handleLogout()" title="Logout">
              <i class="fas fa-sign-out-alt"></i>
            </button>
          </div>
          <div class="sidebar-user-info" onclick="openMyProfileModal()" style="cursor: pointer;" title="Edit Profile">
            <div class="sidebar-user-name" style="display:flex; align-items:center; gap:4px; font-weight:800; color:#1e293b;"><?= htmlspecialchars($user['name']) ?> <i class="fas fa-cog" style="font-size:0.75rem; color:#94a3b8;"></i></div>
            <div class="sidebar-user-role" style="font-size:0.7rem; color:#64748b; font-weight:700;"><?= htmlspecialchars($user['role']) ?></div>
          </div>
        </div>
        <button class="logout-btn" onclick="handleLogout()" title="Logout" style="background: rgba(239, 68, 68, 0.15); border: none; color: #ef4444; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s, transform 0.2s; flex-shrink: 0;" onmouseover="this.style.background='rgba(239, 68, 68, 0.3)'; this.style.transform='scale(1.05)';" onmouseout="this.style.background='rgba(239, 68, 68, 0.15)'; this.style.transform='scale(1)';">
          <i class="fas fa-sign-out-alt"></i>
        </button>
      </div>
    </nav>

    <!-- Main Content Panel -->
    <main class="main-content">
      <?php if (isset($_SESSION['original_admin_id'])): ?>
        <div style="background:linear-gradient(135deg, #6b21a8 0%, #4c1d95 100%); color:#fff; padding:12px 1.5rem; display:flex; justify-content:space-between; align-items:center; font-size:0.85rem; font-weight:700; width:100%; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
          <div style="display:flex; align-items:center; gap:8px;">
            <i class="fas fa-user-secret" style="font-size:1.1rem; color:#d8b4fe;"></i>
            <span>Currently impersonating: <b style="color:#f3e8ff;"><?= htmlspecialchars($user['name']) ?></b> (<?= htmlspecialchars($user['role']) ?>)</span>
          </div>
          <button onclick="switchBackToAdmin()" style="background:#fff; color:#6b21a8; border:none; padding:6px 16px; border-radius:6px; font-weight:800; cursor:pointer; font-size:0.75rem; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)';" onmouseout="this.style.transform='scale(1)';">
            Switch Back to Admin
          </button>
        </div>
      <?php endif; ?>
      <!-- Print Only Header -->
      <div class="print-only-header">
        <div class="logo-container" id="printHeaderLogo" style="height: 50px; width: 50px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;"></div>
        <div class="company-details" style="flex: 1; margin-left: 15px;">
          <div class="company-name" id="printHeaderCompanyName" style="font-size: 1.35rem; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: -0.3px;"></div>
          <div class="company-info-text" id="printHeaderCompanyInfo" style="font-size: 0.75rem; color: #64748b; margin-top: 2px; line-height: 1.3;"></div>
        </div>
        <div style="text-align: right;">
          <div style="font-size: 0.75rem; font-weight: 800; color: #2563eb; text-transform: uppercase;" id="printHeaderReportTitle">REPORT</div>
          <div style="font-size: 0.7rem; color: #64748b; margin-top: 4px;">Printed on: <?= date('d-M-Y') ?></div>
        </div>
      </div>

      <header style="display: flex; align-items: center; gap: 1.5rem;">
        <button class="btn btn-outline" id="sidebarToggleBtn" title="Toggle Sidebar" style="width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; border-color: var(--border-color); padding: 0;">
          <i class="fas fa-bars"></i>
        </button>
        <div class="page-title" style="flex: 1; margin: 0; display:flex; flex-direction:column;">
          <h1 id="viewTitle" style="margin:0;">Active Registry</h1>
          <p id="viewSubtitle" style="margin:4px 0 0 0;">Track pending complaints</p>
        </div>
        <!-- Premium Live Date/Time & Greeting Widget -->
        <div id="liveClockWidget" style="text-align: right; margin-right: 1.5rem; display: flex; flex-direction: column; justify-content: center; font-family: inherit;">
          <div id="clockGreeting" style="font-size: 0.82rem; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 6px; justify-content: flex-end; text-transform: uppercase; letter-spacing: 0.3px;">
            <i class="fas fa-sun" id="greetingIcon" style="transition: color 0.3s ease;"></i> <span id="greetingText">Good Morning</span>
          </div>
          <div id="clockTime" style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-top: 2px; letter-spacing: -0.3px; font-family: monospace;">00:00:00 AM</div>
          <div id="clockDate" style="font-size: 0.7rem; font-weight: 700; color: #64748b; margin-top: 2px;">Saturday, 15 Aug 2026</div>
        </div>
        <div class="appearance-control">
          <button class="appearance-toggle" type="button" onclick="toggleAppearanceMenu(event)" title="Theme settings" aria-label="Theme settings"><i class="fas fa-palette"></i></button>
          <div class="appearance-menu" id="appearanceMenu">
            <div class="appearance-menu-title">Color palette</div>
            <div class="theme-swatches">
              <button class="theme-swatch" data-theme="blue" onclick="setAppearanceTheme('blue')" title="Blue theme" aria-label="Blue theme"></button>
              <button class="theme-swatch" data-theme="cyan" onclick="setAppearanceTheme('cyan')" title="Cyan theme" aria-label="Cyan theme"></button>
              <button class="theme-swatch" data-theme="green" onclick="setAppearanceTheme('green')" title="Green theme" aria-label="Green theme"></button>
              <button class="theme-swatch" data-theme="amber" onclick="setAppearanceTheme('amber')" title="Amber theme" aria-label="Amber theme"></button>
              <button class="theme-swatch" data-theme="rose" onclick="setAppearanceTheme('rose')" title="Rose theme" aria-label="Rose theme"></button>
            </div>
            <label class="dark-mode-row"><span><i class="fas fa-moon"></i> Dark mode</span><input type="checkbox" id="darkModeToggle" onchange="setDarkMode(this.checked)"></label>
          </div>
        </div>
        <!-- Top-Bar Branch Selector (visible to Admin when Multi-Branch is Enabled) -->
        <select id="headerBranchSelector" class="form-control" style="max-width: 180px; display: none; margin: 0; border: 2px solid #e2e8f0; font-weight: 700; color: var(--text-main); font-size: 0.85rem;" onchange="changeActiveBranch(this.value)">
          <!-- Loaded dynamically -->
        </select>
        <div class="btn-group" id="headerActions">
          <?php if (($user['role'] ?? '') !== 'Vendor'): ?>
            <button class="btn btn-primary" onclick="openAddComplaintModal()">
              <i class="fas fa-plus"></i> Add Complaint
            </button>
          <?php else: ?>
            <button class="btn btn-primary" onclick="optimizeVendorRoute()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border:none; box-shadow: 0 4px 12px rgba(16,185,129,0.3);">
              <i class="fas fa-route"></i> 🗺️ Route Optimizer
            </button>
          <?php endif; ?>
          <button class="btn btn-outline" onclick="triggerCsvExport()">
            <i class="fas fa-file-csv"></i> CSV
          </button>
          <button class="btn btn-outline" onclick="triggerPrint()">
            <i class="fas fa-print"></i> Print
          </button>
        </div>
      </header>

      <!-- VIEW 1: ACTIVE COMPLAINTS REGISTRY / VENDOR PORTAL -->
      <section id="view-dashboard" class="view-section active">
        <div class="dashboard-welcome">
          <?php if (($user['role'] ?? '') === 'Vendor'): ?>
            <div>
              <span class="dashboard-kicker">Delivery Boy Portal</span>
              <h2>Welcome back, <?= htmlspecialchars($user['name']) ?> 👋</h2>
              <p>Manage your assigned gas deliveries, optimized routes & completion history.</p>
            </div>
            <button class="btn btn-primary" onclick="optimizeVendorRoute()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border:none; box-shadow: 0 4px 12px rgba(16,185,129,0.3);">
              <i class="fas fa-route"></i> 🗺️ Route Optimizer
            </button>
          <?php else: ?>
            <div>
              <span class="dashboard-kicker">Operations overview</span>
              <h2>Good to see you, <?= htmlspecialchars($user['name']) ?></h2>
              <p>Track complaints, dispatch work and service performance.</p>
            </div>
            <button class="btn btn-primary" onclick="openAddComplaintModal()"><i class="fas fa-plus"></i> New Complaint</button>
          <?php endif; ?>
        </div>
        <div class="dashboard-kpis">
          <div class="dashboard-kpi kpi-blue">
            <i class="fas fa-layer-group"></i>
            <span><?= ($user['role'] ?? '') === 'Vendor' ? 'My Total Assigned' : 'Total Cases' ?></span>
            <strong id="dashTotal">0</strong>
            <small><?= ($user['role'] ?? '') === 'Vendor' ? 'Assigned to me' : 'All active records' ?></small>
          </div>
          <div class="dashboard-kpi kpi-amber">
            <i class="fas fa-hourglass-half"></i>
            <span>Pending</span>
            <strong id="dashPending">0</strong>
            <small><?= ($user['role'] ?? '') === 'Vendor' ? 'Need delivery' : 'Need attention' ?></small>
          </div>
          <div class="dashboard-kpi kpi-cyan">
            <i class="fas fa-truck"></i>
            <span>In Progress</span>
            <strong id="dashProgress">0</strong>
            <small><?= ($user['role'] ?? '') === 'Vendor' ? 'In-transit' : 'With technicians' ?></small>
          </div>
          <div class="dashboard-kpi kpi-green">
            <i class="fas fa-check-circle"></i>
            <span><?= ($user['role'] ?? '') === 'Vendor' ? 'Total Delivered' : 'Resolved' ?></span>
            <strong id="dashResolved">0</strong>
            <small><?= ($user['role'] ?? '') === 'Vendor' ? 'Completed by me' : 'Completed cases' ?></small>
          </div>
          <div class="dashboard-kpi kpi-dark">
            <i class="fas fa-calendar-day"></i>
            <span><?= ($user['role'] ?? '') === 'Vendor' ? 'Assigned Today' : 'Today New' ?></span>
            <strong id="dashToday">0</strong>
            <small><?= ($user['role'] ?? '') === 'Vendor' ? 'New today' : 'Registered today' ?></small>
          </div>
        </div>
        <div class="dashboard-columns">
          <div class="content-card dashboard-panel">
            <div class="dashboard-panel-head">
              <div>
                <span class="dashboard-kicker"><?= ($user['role'] ?? '') === 'Vendor' ? 'Delivery Progress' : 'Live workload' ?></span>
                <h3><?= ($user['role'] ?? '') === 'Vendor' ? 'My Delivery Workload' : 'Complaint collection' ?></h3>
              </div>
              <button class="btn btn-outline btn-sm" onclick="switchView('active-registry', null)"><?= ($user['role'] ?? '') === 'Vendor' ? 'My Deliveries ' : 'View all ' ?><i class="fas fa-arrow-right"></i></button>
            </div>
            <div class="dashboard-progress-row"><span><i class="fas fa-clock"></i> Pending</span><strong id="dashPendingLabel">0</strong></div><div class="dashboard-progress"><i id="dashPendingBar"></i></div>
            <div class="dashboard-progress-row"><span><i class="fas fa-route"></i> In progress</span><strong id="dashProgressLabel">0</strong></div><div class="dashboard-progress"><i id="dashProgressBar"></i></div>
            <div class="dashboard-progress-row"><span><i class="fas fa-check"></i> Delivered</span><strong id="dashResolvedLabel">0</strong></div><div class="dashboard-progress"><i id="dashResolvedBar"></i></div>
            <div class="dashboard-total-line"><span>Completion rate</span><strong id="dashRate">0%</strong></div>
          </div>
          <div class="content-card dashboard-panel">
            <div class="dashboard-panel-head">
              <div>
                <span class="dashboard-kicker">Shortcuts</span>
                <h3><?= ($user['role'] ?? '') === 'Vendor' ? 'Vendor Actions' : 'Operational actions' ?></h3>
              </div>
              <i class="fas fa-bolt dashboard-bolt"></i>
            </div>
            <?php if (($user['role'] ?? '') !== 'Vendor'): ?>
              <button class="dashboard-action" onclick="openAddComplaintModal()"><i class="fas fa-file-circle-plus"></i><span><b>Register complaint</b><small>Create a new service case</small></span><i class="fas fa-chevron-right"></i></button>
              <button class="dashboard-action" onclick="switchView('reports', null)"><i class="fas fa-print"></i><span><b>Dispatch sheets</b><small>Print technician trip lists</small></span><i class="fas fa-chevron-right"></i></button>
              <button class="dashboard-action" onclick="switchView('analytics', null)"><i class="fas fa-chart-line"></i><span><b>Performance charts</b><small>Review service trends</small></span><i class="fas fa-chevron-right"></i></button>
            <?php else: ?>
              <button class="dashboard-action" onclick="optimizeVendorRoute()"><i class="fas fa-route" style="color:#10b981;"></i><span><b>🗺️ GPS Route Optimizer</b><small>Get Google Maps directions for open deliveries</small></span><i class="fas fa-chevron-right"></i></button>
              <button class="dashboard-action" onclick="switchView('active-registry', null)"><i class="fas fa-truck-loading" style="color:#06b6d4;"></i><span><b>📋 My Active Deliveries</b><small>View pending delivery list & action buttons</small></span><i class="fas fa-chevron-right"></i></button>
              <button class="dashboard-action" onclick="switchView('reports', null)"><i class="fas fa-file-invoice" style="color:#3b82f6;"></i><span><b>📜 Trip Manifest Sheet</b><small>View & print today's trip dispatch sheet</small></span><i class="fas fa-chevron-right"></i></button>
              <button class="dashboard-action" onclick="switchView('history', null)"><i class="fas fa-history" style="color:#8b5cf6;"></i><span><b>✅ Delivery History</b><small>View all past completed deliveries</small></span><i class="fas fa-chevron-right"></i></button>
            <?php endif; ?>
          </div>
        </div>
        <div class="content-card dashboard-panel dashboard-recent">
          <div class="dashboard-panel-head">
            <div>
              <span class="dashboard-kicker"><?= ($user['role'] ?? '') === 'Vendor' ? 'My Delivery Summary' : 'Queue monitor' ?></span>
              <h3><?= ($user['role'] ?? '') === 'Vendor' ? 'Delivery Activity Overview' : 'Recent service activity' ?></h3>
            </div>
            <span class="dashboard-live"><i></i> LIVE</span>
          </div>
          <div class="dashboard-activity">
            <div><i class="fas fa-inbox"></i><span><?= ($user['role'] ?? '') === 'Vendor' ? 'Pending deliveries waiting' : 'Open cases waiting for action' ?></span><strong id="dashOpenCases">0</strong></div>
            <div><i class="fas fa-user-check"></i><span><?= ($user['role'] ?? '') === 'Vendor' ? 'Deliveries in-transit' : 'Cases currently assigned' ?></span><strong id="dashAssignedCases">0</strong></div>
            <div><i class="fas fa-calendar-check"></i><span><?= ($user['role'] ?? '') === 'Vendor' ? 'Total completed deliveries' : 'Resolved cases in records' ?></span><strong id="dashResolvedCases">0</strong></div>
          </div>
        </div>
        <div class="dashboard-chart-grid">
          <div class="content-card dashboard-panel"><div class="dashboard-panel-head"><div><span class="dashboard-kicker"><?= ($user['role'] ?? '') === 'Vendor' ? 'My Delivery Breakdown' : 'Distribution' ?></span><h3><?= ($user['role'] ?? '') === 'Vendor' ? 'Delivery Status' : 'Complaint status' ?></h3></div></div><div class="dashboard-chart"><canvas id="dashboardStatusChart"></canvas></div></div>
          <div class="content-card dashboard-panel"><div class="dashboard-panel-head"><div><span class="dashboard-kicker"><?= ($user['role'] ?? '') === 'Vendor' ? 'My 7-Day Trend' : 'Last 7 days' ?></span><h3><?= ($user['role'] ?? '') === 'Vendor' ? 'Daily Delivery Trend' : 'Registration trend' ?></h3></div></div><div class="dashboard-chart"><canvas id="dashboardTrendChart"></canvas></div></div>
        </div>
      </section>

      <section id="view-active-registry" class="view-section">
        <div class="stats-grid" id="statsGrid" style="grid-template-columns: repeat(5, 1fr);">
          <div class="stat-card premium-card-1">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stat-info">
              <div class="value" id="statTotal">0</div>
              <div class="label">Total Cases</div>
            </div>
          </div>
          <div class="stat-card premium-card-2">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
              <div class="value" id="statPending">0</div>
              <div class="label">Pending</div>
            </div>
          </div>
          <div class="stat-card premium-card-3">
            <div class="stat-icon"><i class="fas fa-running"></i></div>
            <div class="stat-info">
              <div class="value" id="statProgress">0</div>
              <div class="label">In Progress</div>
            </div>
          </div>
          <div class="stat-card premium-card-4">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
              <div class="value" id="statDelivered">0</div>
              <div class="label">Delivered</div>
            </div>
          </div>
          <div class="stat-card" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: white;">
            <div class="stat-icon" style="color: #38bdf8;"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-info">
              <div class="value" id="statToday" style="color: #38bdf8;">0</div>
              <div class="label" style="color: #94a3b8;">Today's New</div>
            </div>
          </div>
        </div>

        <!-- Chevron Status Pipeline Flow -->
        <div class="pipeline-container">
          <div class="pipeline-step step-all active" onclick="setPipelineFilter('')">All Active</div>
          <div class="pipeline-step step-pending" onclick="setPipelineFilter('Pending')">Pending</div>
          <div class="pipeline-step step-progress" onclick="setPipelineFilter('In Progress')">In Progress</div>
          <div class="pipeline-step step-delivered" onclick="setPipelineFilter('Delivered')">Delivered</div>
          <div class="pipeline-step step-resolved" onclick="setPipelineFilter('Resolved')">Resolved</div>
        </div>

        <div class="content-card">
          <div class="toolbar" style="display: flex; flex-direction: column; gap: 1rem; align-items: stretch; padding: 1.25rem 1.5rem; background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
              <div style="font-weight: 800; font-size: 0.9rem; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-filter text-primary"></i> Filters
              </div>
              <div id="batchActions" style="display: none; gap: 0.5rem; align-items: center;">
                <span id="batchCount" style="font-size: 0.8rem; font-weight: 700; margin-right: 0.5rem;">0 Selected</span>
                <button class="btn btn-warning btn-sm" onclick="openBulkAssign()"><i class="fas fa-truck"></i> Assign Vendor</button>
                <button class="btn btn-success btn-sm" onclick="bulkMarkDelivered()"><i class="fas fa-check"></i> Bulk Deliver</button>
                <button class="btn btn-sm" id="bulkDeleteBtn" style="background:#fee2e2;color:#991b1b;border:none;font-weight:700;" onclick="bulkDeleteSelected()"><i class="fas fa-trash"></i> Bulk Delete</button>
              </div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; width: 100%;">
              <div class="form-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 0.72rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 0.4rem;">
                  <i class="fas fa-search text-primary"></i> SEARCH CONSUMER
                </label>
                <input type="text" id="searchQuery" class="form-control" placeholder="Name, Mobile, Account No..." oninput="debouncedSearch()">
              </div>
              <div class="form-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 0.72rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 0.4rem;">
                  <i class="fas fa-tasks text-primary"></i> STATUS TYPE
                </label>
                <select id="filterStatus" class="form-control" onchange="syncPipelineFilterAndLoad()">
                  <option value="">All Statuses</option>
                  <option value="Pending" selected>Pending</option>
                  <option value="In Progress">In Progress</option>
                  <option value="Delivered">Delivered</option>
                  <option value="Resolved">Resolved</option>
                </select>
              </div>
              <div class="form-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 0.72rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 0.4rem;">
                  <i class="fas fa-tags text-primary"></i> COMPLAINT SOURCE
                </label>
                <select id="filterSource" class="form-control" onchange="loadComplaints()">
                  <option value="">All Sources</option>
                </select>
              </div>
              <div class="form-group" style="margin: 0;">
                <label style="font-weight: 700; font-size: 0.72rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 0.4rem;">
                  <i class="fas fa-tag text-primary"></i> COMPLAINT TAG
                </label>
                <select id="filterTag" class="form-control" onchange="loadComplaints()">
                  <option value="">All Tags</option>
                  <option value="Leakage">🔴 Leakage</option>
                  <option value="No Supply">⚫ No Supply</option>
                  <option value="Wrong Delivery">🟠 Wrong Delivery</option>
                  <option value="Duplicate">🔵 Duplicate</option>
                  <option value="Pressure Issue">🟡 Pressure Issue</option>
                  <option value="Other">⚪ Other</option>
                </select>
              </div>
              <div class="form-group" style="margin: 0; display: flex; align-items: flex-end;">
                <button class="btn btn-outline" style="width: 100%; height: 38px;" onclick="clearActiveFilters()">
                  <i class="fas fa-undo"></i> Clear Filters
                </button>
              </div>
            </div>
          </div>

          <div class="table-container">
            <table>
              <thead>
                <tr>
                  <th style="width: 40px;" class="hide-mobile"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                  <th style="width: 80px;">ID</th>
                  <th style="width: 120px;" class="hide-mobile">Created Date</th>
                  <th>Consumer Info</th>
                  <th style="width: 120px;">Mobile No</th>
                  <th class="hide-mobile">Address</th>
                  <th style="width: 120px;" class="hide-mobile">Source</th>
                  <th>Complaint details</th>
                  <th class="hide-mobile">Assigned Vendor</th>
                  <th style="width: 130px;">Status</th>
                  <th style="width: 60px;">Action</th>
                </tr>
              </thead>
              <tbody id="complaintsTableBody">
                <!-- Javascript will load records here -->
              </tbody>
            </table>
          </div>
          <!-- Pagination Block -->
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid var(--border-color); border-bottom-left-radius: var(--radius-md); border-bottom-right-radius: var(--radius-md);">
            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted);" id="pgInfo">0 of 0</div>
            <div style="display: flex; align-items: center; gap: 8px;" id="pgControls"></div>
          </div>
        </div>
      </section>

      <!-- VIEW 2: HISTORY LOG -->
      <section id="view-history" class="view-section">
        <div class="content-card">
          <div class="toolbar">
            <div class="filter-group">
              <select id="histFilterStatus" class="form-control" style="width: 140px;" onchange="loadHistory()">
                <option value="Delivered">Delivered</option>
                <option value="Resolved">Resolved</option>
                <option value="Closed">Closed</option>
                <option value="" selected>All Closed</option>
              </select>
              <input type="date" id="histDateFrom" class="form-control" style="width: 130px;" onchange="loadHistory()">
              <input type="date" id="histDateTo" class="form-control" style="width: 130px;" onchange="loadHistory()">
              <div class="input-wrapper" style="position: relative;">
                <input type="text" id="histSearch" class="form-control" placeholder="Search customer, code..." style="width: 220px; padding-left: 2.2rem;" oninput="debouncedHistSearch()">
                <i class="fas fa-search" style="position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
              </div>
            </div>
          </div>
          <div class="table-container">
            <table>
              <thead>
                <tr>
                  <th style="width: 80px;">ID</th>
                  <th style="width: 130px;" class="hide-mobile">Resolution Date</th>
                  <th>Consumer</th>
                  <th style="width: 120px;">Mobile No</th>
                  <th class="hide-mobile">Address</th>
                  <th>Complaint Details</th>
                  <th class="hide-mobile">Vendor</th>
                  <th style="width: 120px;">Status</th>
                  <th style="width: 60px;">Action</th>
                </tr>
              </thead>
              <tbody id="historyTableBody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- VIEW 3: VENDORS DIRECTORY -->
      <section id="view-vendors" class="view-section">
        <div class="content-card">
          <div class="toolbar">
            <div style="font-weight: 700; font-size: 1.05rem;">Active Vendors</div>
            <button class="btn btn-primary btn-sm" onclick="openAddVendorModal()">
              <i class="fas fa-plus"></i> Add Vendor
            </button>
          </div>
          <div class="table-container">
            <table>
              <thead>
                <tr>
                  <th style="width: 80px;">ID</th>
                  <th>Vendor Name</th>
                  <th>Mobile Number</th>
                  <th>Vendor Code</th>
                  <th>Notes</th>
                  <th style="width: 140px; text-align: center;">Actions</th>
                </tr>
              </thead>
              <tbody id="vendorsTableBody"></tbody>
            </table>
          </div>
        </div>

        <!-- Vendor Scorecard -->
        <div class="content-card" style="margin-top: 1.5rem;">
          <div class="toolbar" style="border:none; padding: 0 0 1rem 0; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-weight: 800; font-size: 1rem; color: var(--text-main);"><i class="fas fa-trophy" style="color:#f59e0b;"></i> Vendor Scorecard — Performance Ranking</div>
            <button class="btn btn-outline btn-sm" onclick="loadVendorScorecard()"><i class="fas fa-sync"></i> Load Scorecard</button>
          </div>
          <div id="vendorScorecardContainer" style="color:var(--text-muted); text-align:center; padding:1.5rem;">
            Click "Load Scorecard" to view vendor performance.
          </div>
        </div>
      </section>

      <!-- VIEW 4: DISPATCH SHEETS & REPORTS -->
      <section id="view-reports" class="view-section">
        <div class="content-card" style="padding: 1.5rem; margin-bottom: 2rem;">
          <div class="toolbar" style="border: none; padding: 0;">
            <div style="font-weight: 700; color: var(--text-main); font-size: 1.1rem;"><i class="fas fa-list-alt" style="color:var(--primary)"></i> Vendor Trip Manifests</div>
            <button class="btn btn-outline btn-sm" onclick="printAllManifests()"><i class="fas fa-print"></i> Print All Logs</button>
          </div>
        </div>
        <div id="vendorReportGrid" style="display: flex; flex-direction: column; gap: 1.5rem;"></div>
      </section>

      <!-- VIEW 5: ANALYTICS -->
      <section id="view-analytics" class="view-section">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
          <div class="content-card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem; font-weight: 800; font-size: 1.1rem;"><i class="fas fa-chart-pie" style="color:var(--primary)"></i> Complaint Status Distribution</h3>
            <div style="position: relative; height: 300px;"><canvas id="statusChart"></canvas></div>
          </div>
          <div class="content-card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem; font-weight: 800; font-size: 1.1rem;"><i class="fas fa-chart-bar" style="color:var(--primary)"></i> Top Complaint Sources</h3>
            <div style="position: relative; height: 300px;"><canvas id="sourceChart"></canvas></div>
          </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
          <div class="content-card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem; font-weight: 800; font-size: 1.1rem;"><i class="fas fa-calendar-alt" style="color:var(--primary)"></i> Complaints Registration Trend</h3>
            <div style="position: relative; height: 300px;"><canvas id="trendChart"></canvas></div>
          </div>
          <div class="content-card" style="padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem; font-weight: 800; font-size: 1.1rem;"><i class="fas fa-star" style="color:var(--primary)"></i> Vendor Delivery Stats</h3>
            <div class="table-container" style="max-height: 280px; overflow-y: auto;">
              <table>
                <thead>
                  <tr>
                    <th>Vendor Name</th>
                    <th style="width: 100px;">Deliveries</th>
                    <th style="width: 120px;">Efficiency</th>
                  </tr>
                </thead>
                <tbody id="analyticsVendorRows"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- VIEW 6: DATA EXPORT -->
      <section id="view-export" class="view-section">
        <div class="content-card" style="max-width: 700px; margin: 2rem auto;">
          <div class="toolbar"><div style="font-weight: 700;"><i class="fas fa-file-csv" style="color:#10b981"></i> Advanced CSV Export</div></div>
          <div style="padding: 1.5rem;">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">Select date ranges to filter and download the database as a CSV spreadsheet.</p>
            <div class="form-group">
              <label>From Date</label>
              <input type="date" id="exportFromDate" class="form-control">
            </div>
            <div class="form-group">
              <label>To Date</label>
              <input type="date" id="exportToDate" class="form-control">
            </div>
            <div class="form-group">
              <label>Filter Status</label>
              <select id="exportStatus" class="form-control">
                <option value="">All Statuses</option>
                <option value="Pending">Pending</option>
                <option value="In Progress">In Progress</option>
                <option value="Delivered">Delivered</option>
                <option value="Resolved">Resolved</option>
                <option value="Closed">Closed</option>
              </select>
            </div>
            <button class="btn btn-success" style="width: 100%; margin-top: 1rem;" onclick="triggerCsvExport()"><i class="fas fa-download"></i> Export Data</button>
          </div>
        </div>
      </section>

      <!-- VIEW: CONSUMER REGISTRY -->
      <section id="view-consumers" class="view-section">
        <!-- Consumer Dashboard Stats -->
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 1.5rem; gap: 1rem;">
          <div class="stat-card premium-card-1 stat-card-clickable active-filter" id="cardFilterTotal" onclick="setConsumerFilter('total', '')">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-info">
              <div class="value" id="consStatTotal">0</div>
              <div class="label">Total Consumers</div>
            </div>
          </div>
          <div class="stat-card premium-card-4 stat-card-clickable" id="cardFilterDBC" onclick="setConsumerFilter('connection_type', 'DBC')">
            <div class="stat-icon"><i class="fas fa-cubes"></i></div>
            <div class="stat-info">
              <div class="value" id="consStatDBC">0</div>
              <div class="label">DBC Connections</div>
            </div>
          </div>
          <div class="stat-card premium-card-3 stat-card-clickable" id="cardFilterSBC" onclick="setConsumerFilter('connection_type', 'SBC')">
            <div class="stat-icon"><i class="fas fa-cube"></i></div>
            <div class="stat-info">
              <div class="value" id="consStatSBC">0</div>
              <div class="label">SBC Connections</div>
            </div>
          </div>
          <div class="stat-card premium-card-2 stat-card-clickable" id="cardFilterKYCComplete" onclick="setConsumerFilter('ekyc_status', 'Completed')" style="background: linear-gradient(135deg, #dcfce7 0%, #b9f6ca 100%); color: #15803d;">
            <div class="stat-icon" style="background: rgba(21, 128, 61, 0.1); color: #15803d;"><i class="fas fa-user-check"></i></div>
            <div class="stat-info">
              <div class="value" id="consStatKYCComplete" style="color: #15803d;">0</div>
              <div class="label" style="color: #166534;">E-KYC Complete</div>
            </div>
          </div>
          <div class="stat-card premium-card-3 stat-card-clickable" id="cardFilterKYCPending" onclick="setConsumerFilter('ekyc_status', 'Pending')" style="background: linear-gradient(135deg, #fef9c3 0%, #fef08a 100%); color: #a16207;">
            <div class="stat-icon" style="background: rgba(161, 98, 7, 0.1); color: #a16207;"><i class="fas fa-user-clock"></i></div>
            <div class="stat-info">
              <div class="value" id="consStatKYCPending" style="color: #a16207;">0</div>
              <div class="label" style="color: #854d0e;">E-KYC Pending</div>
            </div>
          </div>
          <div class="stat-card premium-card-1 stat-card-clickable" id="cardFilterBlocked" onclick="setConsumerFilter('status', 'Blocked')" style="background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%); color: #991b1b;">
            <div class="stat-icon" style="background: rgba(153, 27, 27, 0.1); color: #991b1b;"><i class="fas fa-user-slash"></i></div>
            <div class="stat-info">
              <div class="value" id="consStatBlocked" style="color: #991b1b;">0</div>
              <div class="label" style="color: #7f1d1d;">Blocked / Suspended</div>
            </div>
          </div>
        </div>

        <!-- Upload Card -->
        <div class="content-card" id="consumerUploadCard" style="margin-bottom: 1.5rem; padding: 1.5rem;">
          <div class="toolbar" style="border:none; padding: 0 0 1rem 0;">
            <div style="font-weight:800; font-size:1rem; color:var(--text-main);">
              <i class="fas fa-upload" style="color:#f97316;"></i> Excel / CSV File Upload
            </div>
            <div style="display:flex; gap:8px;">
              <button class="btn btn-outline btn-sm" onclick="loadConsumers()"><i class="fas fa-sync"></i> Refresh</button>
              <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;font-weight:700;" onclick="clearAllConsumers()"><i class="fas fa-trash"></i> Clear Database</button>
            </div>
          </div>

          <!-- Drag & Drop Upload Zone -->
          <div id="consumerDropZone" style="
            border: 2px dashed #e2e8f0;
            border-radius: 14px;
            padding: 2.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            background: #fafafa;
          "
            ondragover="event.preventDefault(); this.style.borderColor='#f97316'; this.style.background='#fff7ed';"
            ondragleave="this.style.borderColor='#e2e8f0'; this.style.background='#fafafa';"
            ondrop="handleConsumerFileDrop(event)"
            onclick="document.getElementById('consumerFileInput').click()"
          >
            <i class="fas fa-file-excel" style="font-size:2.5rem; color:#10b981; margin-bottom:0.75rem; display:block;"></i>
            <div style="font-weight:800; font-size:1rem; color:#0f172a; margin-bottom:4px;">Drag & drop Excel (.xlsx) or CSV (.csv) file here</div>
            <div style="font-size:0.8rem; color:#64748b; margin-bottom:0.75rem;">or click to select file</div>
            <div style="font-size:0.72rem; color:#94a3b8; background:#f1f5f9; padding:6px 14px; border-radius:20px; display:inline-block;">
              Supported columns: <b>Consumer Name, Mobile, Address, Area, Consumer Number / Account No</b>
            </div>
            <input type="file" id="consumerFileInput" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleConsumerFileSelect(event)">
          </div>

          <!-- Preview Table (before import) -->
          <div id="consumerPreviewSection" style="display:none; margin-top:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
              <div style="font-weight:700; color:#0f172a;"><i class="fas fa-eye" style="color:#f97316;"></i> Preview — <span id="previewCountLabel"></span> records found</div>
              <div style="display:flex; gap:8px;">
                <button class="btn btn-outline btn-sm" onclick="cancelConsumerImport()"><i class="fas fa-times"></i> Cancel</button>
                <button class="btn btn-primary btn-sm" onclick="confirmConsumerImport()" style="background:#f97316;border-color:#f97316;"><i class="fas fa-upload"></i> Upload & Save</button>
              </div>
            </div>
            <div class="table-container" style="max-height:300px; overflow-y:auto;">
              <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
                <thead id="previewTableHead"></thead>
                <tbody id="previewTableBody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Consumer List Card -->
        <div class="content-card" style="padding: 1.5rem;">
          <div class="toolbar" style="border:none; padding: 0 0 1rem 0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="font-weight:800; font-size:1rem; color:var(--text-main); display:flex; align-items:center; gap:8px;">
              <i class="fas fa-users" style="color:#f97316;"></i> Consumer List <span id="consumerTotalBadge" style="font-size:0.75rem; background:#fff7ed; color:#c2410c; padding:2px 10px; border-radius:20px; font-weight:700;"></span>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
              <input type="text" id="consumerSearch" class="form-control" placeholder="Search name, mobile, area..." style="max-width:220px; margin:0;" oninput="debouncedConsumerSearch()">
              <button class="btn btn-outline btn-sm" onclick="triggerPrint()"><i class="fas fa-print"></i> Print List</button>
            </div>
          </div>
          <div class="table-container">
            <table style="width:100%; border-collapse:collapse;">
              <thead>
                <tr style="background:#f8fafc; font-size:0.75rem; text-transform:uppercase; color:#64748b;">
                  <th style="padding:10px;">#</th>
                  <th style="padding:10px;">Account No</th>
                  <th style="padding:10px;">Consumer Name</th>
                  <th style="padding:10px;">Mobile</th>
                  <th style="padding:10px; text-align:center;">Connection</th>
                  <th style="padding:10px; text-align:center;">Status</th>
                  <th style="padding:10px; text-align:center;">E-KYC</th>
                  <th style="padding:10px;">Area</th>
                  <th style="padding:10px;">Address</th>
                  <th style="padding:10px; text-align:center;">Action</th>
                </tr>
              </thead>
              <tbody id="consumerListBody">
                <tr><td colspan="9" style="text-align:center; padding:3rem; color:var(--text-muted);">Please upload an Excel/CSV file to load consumer directory.</td></tr>
              </tbody>
            </table>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; font-size:0.8rem; color:var(--text-muted);">
            <span id="consumerPgInfo"></span>
            <div id="consumerPgControls" style="display:flex; gap:6px;"></div>
          </div>
        </div>
      </section>

      <!-- VIEW 7: EMPLOYEE MANAGEMENT -->
      <section id="view-employees" class="view-section">
        <div class="toolbar" style="margin-bottom: 1.5rem;">
          <div style="font-weight: 700; font-size: 1.15rem; color: var(--text-main);"><i class="fas fa-users-cog" style="color:var(--primary)"></i> Employee Directory</div>
          <div style="display: flex; gap: 8px; align-items: center;">
            <div class="btn-group" style="margin-right: 8px;">
              <button class="btn btn-outline btn-sm active" id="btnEmpGrid" onclick="setEmployeeViewMode('grid')" title="Grid View" style="padding: 6px 12px; font-weight: 700;">
                <i class="fas fa-th-large"></i> Grid
              </button>
              <button class="btn btn-outline btn-sm" id="btnEmpList" onclick="setEmployeeViewMode('list')" title="List View" style="padding: 6px 12px; font-weight: 700;">
                <i class="fas fa-list"></i> List
              </button>
            </div>
            <button class="btn btn-primary btn-sm" onclick="openAddEmployeeModal()">
              <i class="fas fa-plus"></i> Add Employee
            </button>
          </div>
        </div>
        
        <!-- Employee Cards/List Grid -->
        <div id="employeesCardGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem;">
          <!-- Rendered dynamically -->
        </div>
      </section>

      <!-- VIEW 8: SETTINGS CONSOLE -->
      <section id="view-settings" class="view-section">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
          
          <div class="content-card">
            <div class="toolbar"><div style="font-weight: 700;"><i class="fas fa-building" style="color:var(--primary)"></i> Agency Settings</div></div>
            <div style="padding: 1.5rem;">
              <div style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                <label style="font-weight:700; display:block; margin-bottom:10px;">Company Profile</label>
                
                <div class="form-group" style="margin-bottom:12px;">
                  <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:#475569; margin-bottom:4px; display:block;">Company Name</label>
                  <input type="text" id="editCompanyName" style="padding:10px; font-size:0.9rem; font-weight:600; border:2px solid #e2e8f0; border-radius:8px; width:100%;">
                </div>
                
                <div class="form-group" style="margin-bottom:12px;">
                  <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:#475569; margin-bottom:4px; display:block;">Company Address</label>
                  <input type="text" id="editCompanyAddr" style="padding:10px; font-size:0.9rem; font-weight:600; border:2px solid #e2e8f0; border-radius:8px; width:100%;">
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                  <div class="form-group">
                    <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:#475569; margin-bottom:4px; display:block;">Contact Number</label>
                    <input type="text" id="editCompanyMobile" style="padding:10px; font-size:0.9rem; font-weight:600; border:2px solid #e2e8f0; border-radius:8px; width:100%;">
                  </div>
                  <div class="form-group">
                    <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:#475569; margin-bottom:4px; display:block;">Email Address</label>
                    <input type="email" id="editCompanyEmail" style="padding:10px; font-size:0.9rem; font-weight:600; border:2px solid #e2e8f0; border-radius:8px; width:100%;">
                  </div>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                  <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:#475569; margin-bottom:4px; display:block;">Company Logo</label>
                  <div style="display:flex; align-items:center; gap:14px;">
                    <div id="editLogoPreview" style="width:50px; height:50px; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0;">
                      <i class="fas fa-image" style="color:#94a3b8; font-size:1.4rem;"></i>
                    </div>
                    <div style="flex:1;">
                      <input type="file" id="uploadCompanyLogo" accept="image/*" style="display:none;" onchange="previewLogo(event)">
                      <button type="button" class="btn" style="background:#e2e8f0; color:#0f172a; padding:6px 12px; font-size:0.8rem; font-weight:700; border-radius:6px; border:none; cursor:pointer;" onclick="document.getElementById('uploadCompanyLogo').click()"><i class="fas fa-upload"></i> Upload Logo</button>
                      <button type="button" class="btn" style="background:#fee2e2; color:#991b1b; padding:6px 12px; font-size:0.8rem; font-weight:700; border-radius:6px; border:none; cursor:pointer; margin-left:6px;" onclick="clearLogoPreview()"><i class="fas fa-trash"></i> Remove</button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="form-group" style="margin-bottom: 1.25rem; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                <label style="font-weight: 800; font-size: 0.75rem; text-transform: uppercase; color: #475569; display: block; margin-bottom: 8px;">Multi-Agency / Multi-Branch Mode</label>
                <div style="display: flex; gap: 20px;">
                  <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; cursor: pointer; color: var(--text-main); font-size: 0.85rem;">
                    <input type="radio" name="multiBranchMode" value="1" id="multiBranchEnabled" style="width:16px; height:16px; cursor:pointer;" onchange="toggleMultiBranchSettings(true)"> Enabled
                  </label>
                  <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; cursor: pointer; color: var(--text-main); font-size: 0.85rem;">
                    <input type="radio" name="multiBranchMode" value="0" id="multiBranchDisabled" style="width:16px; height:16px; cursor:pointer;" onchange="toggleMultiBranchSettings(false)"> Disabled
                  </label>
                </div>
                <span style="font-size: 0.68rem; color: var(--text-muted); display: block; margin-top: 6px; line-height: 1.3;">Manage multiple gas agency brands (HP, Indane, Bharat) or branches under one portal.</span>
              </div>
              <div class="form-group" style="display: flex; align-items: center; gap: 10px; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1.25rem;">
                <input type="checkbox" id="setAutoWA" style="width: 18px; height: 18px; cursor: pointer;">
                <label for="setAutoWA" style="margin: 0; cursor: pointer; font-weight: 700; color: var(--text-main);">Enable Auto WhatsApp Alerts</label>
              </div>
              <button class="btn btn-primary" style="width: 100%;" onclick="saveBrandingSettings()"><i class="fas fa-save"></i> Save Settings</button>
            </div>
          </div>

          <!-- Network Info Card -->
          <div class="content-card" id="networkInfoCard" style="grid-column: 1 / -1;">
            <div class="toolbar"><div style="font-weight: 700;"><i class="fas fa-wifi" style="color:var(--primary)"></i> Network Access Info</div></div>
            <div style="padding: 1.5rem;">
              <p style="margin:0 0 1rem; font-size:0.85rem; color:var(--text-muted); line-height:1.5;">
                Apne WiFi network par kisi bhi PC ya Mobile se is portal ko neeche diye URL se open karein. Sabhi devices ka data ek hi jagah se aayega.
              </p>
              <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.25rem;">
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px;">
                  <div style="font-size:0.68rem; font-weight:800; text-transform:uppercase; color:#166534; margin-bottom:6px;"><i class="fas fa-desktop"></i> Server Host IP</div>
                  <div id="netInfoIP" style="font-size:1.2rem; font-weight:900; color:#15803d; font-family:monospace;">—</div>
                </div>
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:14px;">
                  <div style="font-size:0.68rem; font-weight:800; text-transform:uppercase; color:#1e40af; margin-bottom:6px;"><i class="fas fa-plug"></i> Port</div>
                  <div id="netInfoPort" style="font-size:1.2rem; font-weight:900; color:#1d4ed8; font-family:monospace;">—</div>
                </div>
              </div>
              <div style="background:#1e293b; border-radius:10px; padding:14px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <div style="flex:1; min-width:0;">
                  <div style="font-size:0.65rem; font-weight:700; text-transform:uppercase; color:#94a3b8; margin-bottom:4px;"><i class="fas fa-link"></i> Network URL — Share with other devices</div>
                  <div id="netInfoURL" style="font-size:0.95rem; font-weight:700; color:#38bdf8; font-family:monospace; word-break:break-all;">—</div>
                </div>
                <button onclick="copyNetworkURL()" style="background:#0ea5e9; color:#fff; border:none; border-radius:8px; padding:10px 18px; font-weight:700; font-size:0.8rem; cursor:pointer; white-space:nowrap; flex-shrink:0;">
                  <i class="fas fa-copy"></i> Copy URL
                </button>
              </div>
              <div style="margin-top:1rem; background:#fefce8; border:1px solid #fde047; border-radius:8px; padding:12px; font-size:0.8rem; color:#713f12; line-height:1.5;">
                <i class="fas fa-info-circle"></i> <strong>Note:</strong> Server host PC aur baaki devices ek hi WiFi/Router se connected hone chahiye. Server host PC band karne par dusre devices access nahi kar paayenge.
              </div>
            </div>
          </div>

          <!-- Branch Management Card -->

          <div class="content-card" id="branchManagementCard" style="grid-column: 1 / -1; display: none;">
            <div class="toolbar" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
              <div style="font-weight: 700;"><i class="fas fa-network-wired" style="color:var(--primary)"></i> Manage Agency Branches</div>
              <button class="btn btn-primary btn-sm" onclick="openAddBranchModal()"><i class="fas fa-plus-circle"></i> Add Branch</button>
            </div>
            <div style="padding: 1.5rem;">
              <div class="table-container" style="max-height: 250px; overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                  <thead>
                    <tr style="background:#f8fafc; font-size:0.75rem; text-transform:uppercase; color:#64748b;">
                      <th style="padding:10px; width: 50px;">ID</th>
                      <th style="padding:10px;">Branch Name</th>
                      <th style="padding:10px; width: 80px;">Code</th>
                      <th style="padding:10px; width: 90px; text-align:center;">Brand</th>
                      <th style="padding:10px;">Address</th>
                      <th style="padding:10px; width: 110px;">Mobile</th>
                      <th style="padding:10px; width: 80px; text-align:center;">Action</th>
                    </tr>
                  </thead>
                  <tbody id="branchListBody">
                    <!-- Dynamic rows -->
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="content-card">
            <div class="toolbar"><div style="font-weight: 700;"><i class="fas fa-tags" style="color:var(--primary)"></i> Complaint Sources</div></div>
            <div style="padding: 1.5rem;">
              <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.8rem;">Add new sources or remove existing ones. Changes apply to the complaint forms.</p>
              
              <!-- Tag Container for visual list -->
              <div id="sourcesTagContainer" style="
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 15px;
                min-height: 48px;
                padding: 10px;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                background: #fafafa;
                align-content: flex-start;
              "></div>
              
              <!-- Input field & Add button -->
              <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                <input type="text" id="newSourceInput" class="form-control" placeholder="Type new source... (e.g. Website)" style="flex: 1;" onkeypress="if(event.key === 'Enter') { event.preventDefault(); addNewSourceItem(); }">
                <button type="button" class="btn btn-primary" onclick="addNewSourceItem()" style="white-space: nowrap;"><i class="fas fa-plus"></i> Add Source</button>
              </div>
              
              <button class="btn btn-success" style="width: 100%; margin-top: 0.5rem;" onclick="saveSourcesSettings()"><i class="fas fa-save"></i> Save Sources</button>
              
              <!-- Hidden textarea for backward compatibility with form submit -->
              <textarea id="setSources" style="display: none;"></textarea>
            </div>
          </div>

          <div class="content-card" style="grid-column: 1 / -1;">
            <div class="toolbar"><div style="font-weight: 700;"><i class="fab fa-whatsapp" style="color:#25d366"></i> WhatsApp Message Template</div></div>
            <div style="padding: 1.5rem;">
              <textarea id="setTemplate" class="form-control" style="height: 120px; font-family: monospace; font-size: 0.85rem;"></textarea>
              <button class="btn btn-primary" style="margin-top: 1rem;" onclick="saveTemplateSettings()"><i class="fas fa-save"></i> Save Template</button>
            </div>
          </div>

          <div class="content-card" style="grid-column: 1 / -1; border-color: #fca5a5;">
            <div class="toolbar" style="background-color: #fff5f5;"><div style="font-weight: 700; color: #dc2626;"><i class="fas fa-trash-alt"></i> System Cleanup</div></div>
            <div style="padding: 1.5rem;">
              <button class="btn btn-danger" onclick="triggerLogsCleanup()"><i class="fas fa-eraser"></i> Delete All Audit Logs</button>
            </div>
          </div>
        </div>
      </section>

    </main>

    <!-- ⚡ FAB Button (Mobile) -->
    <?php if (($user['role'] ?? '') !== 'Vendor'): ?>
      <button class="mobile-fab" onclick="openAddComplaintModal()" title="Add Complaint">
        <i class="fas fa-plus"></i>
      </button>
    <?php endif; ?>

    <!-- 📱 Bottom Navigation Bar (Mobile Only) -->
    <nav class="bottom-nav" id="bottomNav">
      <div class="bottom-nav-inner">
        <button class="bnav-item active" id="bnav-active-registry" onclick="switchView('active-registry', null); setBottomNav('active-registry')">
          <i class="fas fa-clipboard-list"></i>
          <span>Active</span>
        </button>
        <button class="bnav-item" id="bnav-history" onclick="switchView('history', null); setBottomNav('history')">
          <i class="fas fa-history"></i>
          <span>History</span>
        </button>
        <button class="bnav-item" id="bnav-reports" onclick="switchView('reports', null); setBottomNav('reports')">
          <i class="fas fa-print"></i>
          <span>Dispatch</span>
        </button>
        <button class="bnav-item" id="bnav-vendors" onclick="switchView('vendors', null); setBottomNav('vendors')">
          <i class="fas fa-truck"></i>
          <span>Vendors</span>
        </button>
        <button class="bnav-item" id="bnav-more" onclick="openSidebar()">
          <i class="fas fa-bars"></i>
          <span>More</span>
        </button>
      </div>
    </nav>

  </div>

  <!-- MODALS SECTION -->

  <!-- Modal 1: Complaint Form (Add/Edit) -->
  <div class="modal-overlay" id="complaintModal">
    <div class="modal" style="max-width: 550px;">
      <div class="modal-header">
        <div class="modal-title" id="complaintModalTitle">New Complaint</div>
        <button class="modal-close" onclick="closeModal('complaintModal')">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="c_id">
        <div class="form-group">
          <label>Consumer Number</label>
          <input type="text" id="c_no" class="form-control" placeholder="Consumer unique account code" onblur="lookupConsumer('no')">
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div class="form-group">
            <label>Consumer Name *</label>
            <input type="text" id="c_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Mobile Number *</label>
            <input type="text" id="c_mob" class="form-control" required maxlength="10" onblur="lookupConsumer('mob')">
          </div>
        </div>
        <div class="form-group">
          <label>Address *</label>
          <textarea id="c_addr" class="form-control" required style="height: 60px;"></textarea>
        </div>
        <div class="form-group">
          <label>Source</label>
          <select id="c_src" class="form-control"></select>
        </div>
        <div class="form-group">
          <label>Complaint Tag / Label</label>
          <select id="c_tag" class="form-control">
            <option value="">-- Select Tag --</option>
            <option value="Leakage">🔴 Leakage</option>
            <option value="No Supply">⚫ No Supply</option>
            <option value="Wrong Delivery">🟠 Wrong Delivery</option>
            <option value="Duplicate">🔵 Duplicate</option>
            <option value="Pressure Issue">🟡 Pressure Issue</option>
            <option value="Other">⚪ Other</option>
          </select>
        </div>
        <!-- Consumer History Alert Banner -->
        <div id="consumerHistoryBanner" style="display:none; background:#fffbeb; border:1px solid #fef3c7; border-radius:10px; padding:10px 14px; margin-bottom:12px;">
          <div style="font-size:0.8rem; font-weight:700; color:#b45309; margin-bottom:6px;"><i class="fas fa-exclamation-triangle"></i> <span id="consumerHistoryMsg"></span></div>
          <div id="consumerHistoryList" style="font-size:0.75rem; color:#475569;"></div>
        </div>
        <div class="form-group">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <label style="margin: 0; font-weight: 700;">Complaint Details *</label>
            <!-- AI Assist Button Badge -->
            <div id="aiAssistBadge" style="
              font-size: 0.65rem;
              font-weight: 800;
              background: linear-gradient(135deg, #a855f7 0%, #3b82f6 100%);
              color: #fff;
              padding: 3px 10px;
              border-radius: 20px;
              display: flex;
              align-items: center;
              gap: 4px;
              box-shadow: 0 2px 4px rgba(168,85,247,0.15);
              text-transform: uppercase;
              letter-spacing: 0.5px;
            ">
              <i class="fas fa-wand-magic-sparkles"></i> AI Active
            </div>
          </div>
          <textarea id="c_comp" class="form-control" required style="height: 90px; margin-bottom: 8px;" placeholder="Describe the issue... (e.g. cylinder valve is leaking / सिलेंडर लीक हो रहा है)"></textarea>
          
          <!-- AI Action Buttons -->
          <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <button type="button" class="btn" onclick="aiTranslateText('en', 'hi')" style="
              font-size: 0.72rem; padding: 4px 10px; background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; height: auto; box-shadow: none; cursor: pointer;
            ">
              <i class="fas fa-language"></i> English &rarr; Hindi
            </button>
            <button type="button" class="btn" onclick="aiTranslateText('hi', 'en')" style="
              font-size: 0.72rem; padding: 4px 10px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; height: auto; box-shadow: none; cursor: pointer;
            ">
              <i class="fas fa-language"></i> Hindi &rarr; English
            </button>
            <button type="button" class="btn" onclick="aiImproveText()" style="
              font-size: 0.72rem; padding: 4px 10px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; height: auto; box-shadow: none; cursor: pointer;
            ">
              <i class="fas fa-wand-magic"></i> AI Polish
            </button>
          </div>
        </div>
        <div class="form-group" id="cStatusBlock" style="display: none;">
          <label>Status</label>
          <select id="c_status" class="form-control">
            <option value="Pending">Pending</option>
            <option value="In Progress">In Progress</option>
            <option value="Delivered">Delivered</option>
            <option value="Resolved">Resolved</option>
            <option value="Closed">Closed</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline" onclick="closeModal('complaintModal')">Cancel</button>
        <button class="btn btn-primary" onclick="submitComplaintForm()">Save Complaint</button>
      </div>
    </div>
  </div>

  <!-- Modal 2: Assign Vendor Dialog -->
  <div class="modal-overlay" id="assignModal">
    <div class="modal" style="max-width: 440px;">
      <div class="modal-header">
        <div class="modal-title" id="assignModalTitle">Assign Vendor</div>
        <button class="modal-close" onclick="closeModal('assignModal')">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="assign_cid">
        <input type="hidden" id="assign_mode">
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">Select vendor to assign cases:</p>
        <div id="vendorSelectGrid" style="display: grid; grid-template-columns: 1fr; gap: 0.75rem; max-height: 250px; overflow-y: auto;">
          <!-- Loaded dynamically -->
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline" onclick="closeModal('assignModal')">Cancel</button>
        <button class="btn btn-primary" onclick="submitVendorAssignment()">Confirm Assignment</button>
      </div>
    </div>
  </div>

  <!-- Modal 3: Complaint Detail & Resolution Dialog -->
  <div class="modal-overlay" id="detailsModal">
    <div class="modal" style="max-width: 600px;">
      <div class="modal-header">
        <div class="modal-title" id="detailsModalTitle">Complaint Details</div>
        <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
      </div>
      <div class="modal-body" id="detailsModalBody" style="padding: 1.5rem;">
        <!-- Dynamic detail block -->
      </div>
      <div class="modal-footer" id="detailsModalFooter">
        <!-- Action buttons -->
      </div>
    </div>
  </div>

  <!-- Modal 4: Vendor Form (Add/Edit) -->
  <div class="modal-overlay" id="vendorModal">
    <div class="modal" style="max-width: 440px;">
      <div class="modal-header">
        <div class="modal-title" id="vendorModalTitle">Add Vendor</div>
        <button class="modal-close" onclick="closeModal('vendorModal')">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="v_id">
        <div class="form-group">
          <label>Vendor Name *</label>
          <input type="text" id="v_name" class="form-control" required>
        </div>
        <div class="form-group">
          <label>Mobile Number *</label>
          <input type="text" id="v_mob" class="form-control" required maxlength="10">
        </div>
        <div class="form-group">
          <label>Vendor Code</label>
          <input type="text" id="v_code" class="form-control">
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea id="v_notes" class="form-control" style="height: 60px;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline" onclick="closeModal('vendorModal')">Cancel</button>
        <button class="btn btn-primary" onclick="submitVendorForm()">Save Vendor</button>
      </div>
    </div>
  </div>

  <!-- Modal 5: Employee User Form -->
  <div class="modal-overlay" id="employeeModal">
    <div class="modal" style="max-width: 500px;">
      <div class="modal-header">
        <div class="modal-title" id="employeeModalTitle">Add Employee Account</div>
        <button class="modal-close" onclick="closeModal('employeeModal')">&times;</button>
      </div>
      <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
        <input type="hidden" id="u_id">
        <div class="form-group">
          <label>Full Display Name *</label>
          <input type="text" id="u_name" class="form-control" required>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div class="form-group">
            <label>Gmail ID / Username *</label>
            <input type="text" id="u_uname" class="form-control" required>
          </div>
          <div class="form-group">
            <label id="uPwLabel">Password *</label>
            <input type="password" id="u_pw" class="form-control">
          </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div class="form-group">
            <label>Mobile Number</label>
            <input type="text" id="u_mobile" class="form-control">
          </div>
          <div class="form-group">
            <label>Role</label>
            <select id="u_role" class="form-control" onchange="togglePermissionsBlock()">
              <option value="Employee">Employee (Restricted permissions)</option>
              <option value="Vendor">Vendor / Delivery Boy</option>
              <option value="Admin">Admin (Full privileges)</option>
            </select>
          </div>
        </div>
        
        <div class="form-group" id="empBranchSelectBlock" style="display: none; margin-bottom: 1rem;">
          <label>Assigned Branch / Agency *</label>
          <select id="u_branch_id" class="form-control"></select>
        </div>
        
        <!-- Profile Photo -->
        <div class="form-group" style="margin-bottom:12px;">
          <label>Profile Photo</label>
          <div style="display:flex; align-items:center; gap:14px;">
            <div id="uLogoPreview" style="width:50px; height:50px; border-radius:50%; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0;">
              <i class="fas fa-user" style="color:#94a3b8; font-size:1.4rem;"></i>
            </div>
            <div style="flex:1;">
              <input type="file" id="uploadEmployeeLogo" accept="image/*" style="display:none;" onchange="previewEmployeeLogo(event)">
              <button type="button" class="btn" style="background:#e2e8f0; color:#0f172a; padding:6px 12px; font-size:0.8rem; font-weight:700; border-radius:6px; border:none; cursor:pointer;" onclick="document.getElementById('uploadEmployeeLogo').click()"><i class="fas fa-upload"></i> Upload Photo</button>
              <button type="button" class="btn" style="background:#fee2e2; color:#991b1b; padding:6px 12px; font-size:0.8rem; font-weight:700; border-radius:6px; border:none; cursor:pointer; margin-left:6px;" onclick="clearEmployeeLogoPreview()"><i class="fas fa-trash"></i> Remove</button>
            </div>
          </div>
        </div>

        <div class="form-group" id="permissionsBlock">
          <label>Custom Permissions</label>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);" id="permChecklist">
            <!-- Dynamic checkboxes -->
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline" onclick="closeModal('employeeModal')">Cancel</button>
        <button class="btn btn-primary" onclick="submitEmployeeForm()">Save Employee</button>
      </div>
    </div>
  </div>

  <!-- Modal 5b: My Profile Form -->
  <div class="modal-overlay" id="myProfileModal">
    <div class="modal" style="max-width: 450px;">
      <div class="modal-header">
        <div class="modal-title"><i class="fas fa-user-cog" style="color:var(--primary);"></i> Edit My Profile</div>
        <button class="modal-close" onclick="closeModal('myProfileModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Full Display Name *</label>
          <input type="text" id="my_profile_name" class="form-control" required value="<?= htmlspecialchars($user['name']) ?>">
        </div>
        <div class="form-group">
          <label>Mobile Number</label>
          <input type="text" id="my_profile_mobile" class="form-control" value="<?= htmlspecialchars($user['mobile'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>New Password (leave blank to keep current)</label>
          <input type="password" id="my_profile_pw" class="form-control" placeholder="Optional new password">
        </div>
        
        <!-- Profile Photo -->
        <div class="form-group" style="margin-bottom:12px;">
          <label>Profile Picture</label>
          <div style="display:flex; align-items:center; gap:14px;">
            <div id="myProfileLogoPreview" style="width:60px; height:60px; border-radius:50%; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0;">
              <?php if (!empty($user['profile_photo']) && $user['profile_photo'] !== 'default-photo.png'): ?>
                <img src="<?= htmlspecialchars($user['profile_photo']) ?>" style="width:100%; height:100%; object-fit:cover;">
              <?php else: ?>
                <i class="fas fa-user" style="color:#94a3b8; font-size:1.6rem;"></i>
              <?php endif; ?>
            </div>
            <div style="flex:1;">
              <input type="file" id="uploadMyProfilePhoto" accept="image/*" style="display:none;" onchange="previewMyProfilePhoto(event)">
              <button type="button" class="btn" style="background:#e2e8f0; color:#0f172a; padding:6px 12px; font-size:0.8rem; font-weight:700; border-radius:6px; border:none; cursor:pointer;" onclick="document.getElementById('uploadMyProfilePhoto').click()"><i class="fas fa-upload"></i> Upload Photo</button>
              <button type="button" class="btn" style="background:#fee2e2; color:#991b1b; padding:6px 12px; font-size:0.8rem; font-weight:700; border-radius:6px; border:none; cursor:pointer; margin-left:6px;" onclick="clearMyProfilePhoto()"><i class="fas fa-trash"></i> Remove</button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline" onclick="closeModal('myProfileModal')">Cancel</button>
        <button class="btn btn-primary" onclick="submitMyProfileForm()">Save Changes</button>
      </div>
    </div>
  </div>

  <!-- Modal 6: Employee Details View -->
  <div class="modal-overlay" id="employeeDetailsModal">
    <div class="modal" style="max-width: 600px;">
      <div class="modal-header" style="background: #0f172a; color: #fff; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;">
        <div class="modal-title" id="empDetailsTitle" style="display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 1.1rem; color: #fff; margin: 0;">
          <i class="fas fa-id-card"></i> <span id="empDetailsName">Employee Name</span>
        </div>
        <button class="modal-close" onclick="closeModal('employeeDetailsModal')" style="color: #fff; opacity: 0.8; background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
      </div>
      
      <!-- Tab Headers -->
      <div style="display: flex; border-bottom: 1px solid #e2e8f0; background: #f8fafc; padding: 0 20px;">
        <button id="tabEmpProfile" onclick="switchEmpDetailTab('profile')" style="background: none; border: none; border-bottom: 2px solid #2563eb; padding: 12px 16px; font-size: 0.85rem; font-weight: 700; color: #2563eb; cursor: pointer; display: flex; align-items: center; gap: 6px; outline: none;">
          <i class="fas fa-info-circle"></i> Profile
        </button>
        <button id="tabEmpActivity" onclick="switchEmpDetailTab('activity')" style="background: none; border: none; border-bottom: 2px solid transparent; padding: 12px 16px; font-size: 0.85rem; font-weight: 700; color: #64748b; cursor: pointer; display: flex; align-items: center; gap: 6px; outline: none;">
          <i class="fas fa-history"></i> Activity <span id="empDetailsLogCount" style="font-size: 0.65rem; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 9999px; font-weight: 800;">0</span>
        </button>
      </div>
      
      <div class="modal-body" style="padding: 20px; max-height: 60vh; overflow-y: auto;">
        <!-- Tab Content: Profile -->
        <div id="empContentProfile">
          <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 20px;">
            <div id="empDetailsPhoto" style="width: 80px; height: 80px; border-radius: 50%; overflow: hidden; border: 3px solid var(--primary); display: flex; align-items: center; justify-content: center; background: #f1f5f9; flex-shrink: 0;"></div>
            <div>
              <h3 id="empDetailsDisplayName" style="font-weight: 800; font-size: 1.25rem; color: #0f172a; margin-bottom: 4px;">Name</h3>
              <div>
                <span id="empDetailsRole" style="font-size: 0.72rem; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 4px; display: inline-block;">Employee</span>
                <span id="empDetailsStatus" style="font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; display: inline-block; margin-left: 6px;">Active</span>
              </div>
            </div>
          </div>
          
          <div style="display: grid; grid-template-columns: 1fr; gap: 12px; background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 0.85rem; color: #334155;">
            <div><i class="fas fa-envelope" style="width: 20px; color: #64748b;"></i> <b>Gmail / Username:</b> <span id="empDetailsGmail" style="font-weight: 600;">user@gmail.com</span></div>
            <div><i class="fas fa-phone" style="width: 20px; color: #64748b;"></i> <b>Mobile Number:</b> <span id="empDetailsMobile" style="font-weight: 600;">N/A</span></div>
            <div style="border-top: 1px dashed #e2e8f0; padding-top: 10px; margin-top: 4px;">
              <div style="font-weight: 700; color: #64748b; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px; margin-bottom: 6px;">Access Privileges</div>
              <div id="empDetailsPermissions" style="display: flex; flex-wrap: wrap; gap: 4px;"></div>
            </div>
          </div>
        </div>
        
        <!-- Tab Content: Activity -->
        <div id="empContentActivity" style="display: none;">
          <div id="empDetailsLogsList" style="display: flex; flex-direction: column; gap: 10px;">
            <!-- Dynamically populated logs -->
          </div>
        </div>
      </div>
      
      <div class="modal-footer" style="padding: 14px 20px; border-top: 1px solid #e2e8f0;">
        <button class="btn btn-outline" onclick="closeModal('employeeDetailsModal')" style="width: 100%;">Close</button>
      </div>
    </div>
  </div>

  <!-- Modal 7: Branch Form (Add/Edit) -->
  <div class="modal-overlay" id="branchModal">
    <div class="modal" style="max-width: 440px;">
      <div class="modal-header">
        <div class="modal-title" id="branchModalTitle">Add Branch</div>
        <button class="modal-close" onclick="closeModal('branchModal')">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="br_id">
        
        <div class="form-group">
          <label>Branch / Agency Name *</label>
          <input type="text" id="br_name" class="form-control" required placeholder="e.g. HP Gas - City Center">
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>Branch Code *</label>
            <input type="text" id="br_code" class="form-control" required placeholder="e.g. CITY">
          </div>
          <div class="form-group">
            <label>Agency Brand *</label>
            <select id="br_brand" class="form-control">
              <option value="HP">HP Gas</option>
              <option value="Indane">Indane Gas</option>
              <option value="Bharat">Bharat Gas</option>
              <option value="Other">Other Brand</option>
            </select>
          </div>
        </div>
        
        <div class="form-group">
          <label>Mobile Number</label>
          <input type="text" id="br_mobile" class="form-control" placeholder="Contact number">
        </div>
        
        <div class="form-group">
          <label>Address</label>
          <input type="text" id="br_address" class="form-control" placeholder="Complete address">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline" onclick="closeModal('branchModal')">Cancel</button>
        <button class="btn btn-primary" onclick="submitBranchForm()">Save Branch</button>
      </div>
    </div>
  </div>



  <!-- APP CONTROLLER CLIENT LOGIC -->
  <script>
    // System Memory Cache
    const State = {
      user: <?= json_encode($user) ?>,
      company: <?= json_encode($companyInfo) ?>,
      config: {},
      sources: [],
      vendors: [],
      activeRows: [],
      histRows: [],
      selectedVendorId: null,
      currentView: 'dashboard',
      activePage: 1,
      employeeViewMode: 'grid'
    };

    const PERMISSION_LABELS = {
      complaints_view: 'View complaints list',
      complaints_add: 'Add new complaints',
      complaints_edit: 'Edit details',
      complaints_delete: 'Delete records',
      complaints_assign: 'Map technicians/vendors',
      complaints_deliver: 'Close/Deliver cases',
      vendors_view: 'View vendor records',
      vendors_add: 'Modify vendor lists',
      vendors_delete: 'Delete vendors',
      settings_view: 'View preferences',
      settings_edit: 'Edit configs & settings',
      users_manage: 'Manage employee logins',
      history_view: 'View closed history'
    };

    // Helper functions
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    function showLoading(show) { document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none'; }

    // Sidebar helpers
    function openSidebar() {
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar) sidebar.classList.add('open');
      if (overlay) overlay.classList.add('show');
      document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar) sidebar.classList.remove('open');
      if (overlay) overlay.classList.remove('show');
      document.body.style.overflow = '';
    }

    // Bottom Nav active state sync
    function setBottomNav(viewName) {
      document.querySelectorAll('.bnav-item').forEach(b => b.classList.remove('active'));
      const btn = document.getElementById('bnav-' + viewName);
      if (btn) btn.classList.add('active');
    }
    
    function showToast(msg, type = 'success') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        icon: type,
        title: msg
      });
    }

    function toggleAppearanceMenu(event) {
      event.stopPropagation();
      const menu = document.getElementById('appearanceMenu');
      if (menu) menu.classList.toggle('open');
    }

    function setAppearanceTheme(theme) {
      const themes = ['blue', 'cyan', 'green', 'amber', 'rose'];
      document.body.classList.remove(...themes.map(name => 'theme-' + name));
      document.body.classList.add('theme-' + (themes.includes(theme) ? theme : 'blue'));
      localStorage.setItem('gas_theme', theme);
      document.querySelectorAll('.theme-swatch').forEach(swatch => {
        swatch.classList.toggle('active', swatch.dataset.theme === theme);
      });
    }

    function setDarkMode(enabled) {
      document.body.classList.toggle('theme-dark', enabled);
      localStorage.setItem('gas_dark_mode', enabled ? 'true' : 'false');
      const toggle = document.getElementById('darkModeToggle');
      if (toggle) toggle.checked = enabled;
    }

    function restoreAppearance() {
      setAppearanceTheme(localStorage.getItem('gas_theme') || 'blue');
      setDarkMode(localStorage.getItem('gas_dark_mode') === 'true');
      document.addEventListener('click', event => {
        const control = document.querySelector('.appearance-control');
        if (control && !control.contains(event.target)) {
          document.getElementById('appearanceMenu')?.classList.remove('open');
        }
      });
    }

    // Debounce triggers
    function debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }

    const debouncedSearch = debounce(() => loadComplaints(1), 400);
    const debouncedHistSearch = debounce(() => loadHistory(), 400);

    // Initial system load
    window.addEventListener('DOMContentLoaded', () => {
      restoreAppearance();
      // Sidebar Toggle Logic
      const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
      const sidebar = document.getElementById('sidebar');
      
      const isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
      if (isCollapsed && sidebar) {
        sidebar.classList.add('collapsed');
      }

      if (sidebarToggleBtn && sidebar) {
        sidebarToggleBtn.addEventListener('click', () => {
          if (window.innerWidth > 991) {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
          } else {
            if (sidebar.classList.contains('open')) {
              closeSidebar();
            } else {
              openSidebar();
            }
          }
        });
      }

      State.tempMyProfilePhotoData = "<?= htmlspecialchars($user['profile_photo'] ?? 'default-photo.png') ?>";
      updateLiveClock();
      setInterval(updateLiveClock, 1000);
      loadSystemData();
      renderPermissionsChecklist();

      // Restore active view from URL hash on reload
      const hash = window.location.hash.substring(1);
      if (hash && ['dashboard', 'active-registry', 'history', 'vendors', 'reports', 'analytics', 'export', 'employees', 'settings', 'consumers'].includes(hash)) {
        switchView(hash, null);
      } else {
        switchView('dashboard', null);
      }
    });

    function switchView(viewName, element) {
      document.querySelectorAll('.view-section').forEach(sec => sec.classList.remove('active'));
      document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
      
      const targetSec = document.getElementById('view-' + viewName);
      if (targetSec) targetSec.classList.add('active');
      
      // Update sidebar active selection visually
      let activeNav = element;
      if (!activeNav) {
        activeNav = document.querySelector(`.nav-item[onclick*="'${viewName}'"]`);
      }
      if (activeNav) activeNav.classList.add('active');

      State.currentView = viewName;
      
      // Update URL hash without breaking history flow
      if (window.location.hash.substring(1) !== viewName) {
        window.location.hash = viewName;
      }
      
      closeSidebar();
      setBottomNav(viewName);

      // Update titles
      const isVendor = State.user && State.user.role === 'Vendor';
      const viewTitleMap = {
        'dashboard': isVendor ? ['Vendor Delivery Portal', 'My assigned delivery workload & status'] : ['Dashboard', 'Overview of complaints and operations'],
        'active-registry': isVendor ? ['My Active Deliveries', 'Pending delivery cases assigned to me'] : ['Active Registry', 'Track pending complaints'],
        'history': isVendor ? ['Delivery History', 'My completed & resolved deliveries'] : ['History Log', 'Audit closed complaints'],
        'vendors': ['Vendors Directory', 'Manage service providers'],
        'reports': isVendor ? ['Trip Manifest', 'My daily trip sheet & dispatch list'] : ['Trip Sheets & Dispatch', 'Print checklists & update technicians'],
        'analytics': ['Performance Charts', 'Inspect metrics and distribution'],
        'export': ['CSV Export Utility', 'Extract records as CSV file'],
        'employees': ['Employee Accounts', 'Manage login profiles'],
        'settings': ['Settings Console', 'Configure application configurations'],
        'consumers': ['Consumer Registry', 'Import and manage consumer profiles']
      };

      const title = viewTitleMap[viewName];
      document.getElementById('viewTitle').innerText = title[0];
      document.getElementById('viewSubtitle').innerText = title[1];

      // Refresh corresponding sections
      if (viewName === 'dashboard') loadDashboardCharts();
      else if (viewName === 'active-registry') loadComplaints(1);
      else if (viewName === 'history') loadHistory();
      else if (viewName === 'vendors') loadVendors();
      else if (viewName === 'reports') loadVendorReports();
      else if (viewName === 'analytics') loadAnalytics();
      else if (viewName === 'employees') loadEmployees();
      else if (viewName === 'settings') loadSettings();
      else if (viewName === 'consumers') loadConsumers(1);
    }

    function updatePrintHeader() {
      const nameEl = document.getElementById('printHeaderCompanyName');
      const infoEl = document.getElementById('printHeaderCompanyInfo');
      const logoEl = document.getElementById('printHeaderLogo');
      
      const co = State.company;
      if (nameEl) nameEl.innerText = co.company_name || 'Gas Agency';
      if (infoEl) {
        infoEl.innerHTML = `${escapeHtml(co.company_address || '')}<br>Ph: ${escapeHtml(co.company_mobile || '')} | Email: ${escapeHtml(co.company_email || '')}`;
      }
      if (logoEl) {
        logoEl.innerHTML = co.company_logo && co.company_logo !== 'default-logo.png'
          ? `<img src="${co.company_logo}" style="max-height:50px; max-width:50px; object-fit:contain;">`
          : `<div style="width:50px;height:50px;border-radius:6px;background:#2563eb;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:1.2rem;">${(co.company_name||'G').charAt(0).toUpperCase()}</div>`;
      }
    }

    function triggerPrint() {
      const viewTitle = document.getElementById('viewTitle').innerText;
      const titleEl = document.getElementById('printHeaderReportTitle');
      if (titleEl) titleEl.innerText = viewTitle.toUpperCase();
      window.print();
    }

    function loadSystemData() {
      showLoading(true);
      fetch('?action=get_init_data')
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            State.config = res.config;
            State.sources = res.sources;
            State.vendors = res.vendors;
            State.branches = res.branches || [];
            State.active_branch_id = res.active_branch_id || 0;
            if (res.company) {
              State.company = res.company;
            }
            updatePrintHeader();
            
            // Apply branding
            if (State.company.company_name) {
              document.querySelectorAll('.sidebar-brand').forEach(el => el.innerText = State.company.company_name);
            }

            // Setup branch selector dropdowns
            setupBranchSelectors();

            // Render KPI cards immediately from init data
            if (res.stats) {
              renderStats(res.stats);
            }
            
            fillDropdowns();
            if (State.currentView === 'active-registry') {
              loadComplaints(1);
            } else if (State.currentView === 'dashboard') {
              loadDashboardCharts();
            }
          }
        })
        .catch(err => {
          showLoading(false);
          showToast('Failed to contact server', 'error');
        });
    }

    function fillDropdowns() {
      // Source dropdowns
      const filter = document.getElementById('filterSource');
      const input = document.getElementById('c_src');
      
      filter.innerHTML = '<option value="">All Sources</option>';
      input.innerHTML = '';

      State.sources.forEach(src => {
        filter.innerHTML += `<option value="${src}">${src}</option>`;
        input.innerHTML += `<option value="${src}">${src}</option>`;
      });
    }

    function setPipelineFilter(status) {
      document.getElementById('filterStatus').value = status;
      
      document.querySelectorAll('.pipeline-step').forEach(step => {
        step.classList.remove('active');
      });
      
      const stepClass = status === '' ? 'step-all' : 'step-' + status.toLowerCase().replace(' ', '-');
      const activeStep = document.querySelector('.pipeline-step.' + stepClass);
      if (activeStep) {
        activeStep.classList.add('active');
      }

      loadComplaints(1);
    }

    function syncPipelineFilterAndLoad() {
      const status = document.getElementById('filterStatus').value;
      
      document.querySelectorAll('.pipeline-step').forEach(step => {
        step.classList.remove('active');
      });
      
      const stepClass = status === '' ? 'step-all' : 'step-' + status.toLowerCase().replace(' ', '-');
      const activeStep = document.querySelector('.pipeline-step.' + stepClass);
      if (activeStep) {
        activeStep.classList.add('active');
      }

      loadComplaints(1);
    }

    function clearActiveFilters() {
      document.getElementById('searchQuery').value = '';
      document.getElementById('filterSource').value = '';
      const tagEl = document.getElementById('filterTag');
      if (tagEl) tagEl.value = '';
      setPipelineFilter('');
    }

    function renderPermissionsChecklist() {
      const block = document.getElementById('permChecklist');
      block.innerHTML = '';
      Object.keys(PERMISSION_LABELS).forEach(key => {
        block.innerHTML += `
          <div style="display:flex;align-items:center;gap:6px;font-size:0.78rem;">
            <input type="checkbox" value="${key}" class="u-perm" id="p_${key}" style="cursor:pointer;width:14px;height:14px;">
            <label for="p_${key}" style="margin:0;cursor:pointer;font-weight:600;">${PERMISSION_LABELS[key]}</label>
          </div>
        `;
      });
    }

    // AUTH ACTIONS
    function handleLogout() {
      Swal.fire({
        title: 'Sign Out?',
        text: 'Are you sure you want to log out?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Logout',
        cancelButtonText: 'Stay'
      }).then(r => {
        if (r.isConfirmed) {
          window.location.href = '?action=logout';
        }
      });
    }

    function openPasswordModal() {
      document.getElementById('old_pw').value = '';
      document.getElementById('new_pw').value = '';
      document.getElementById('confirm_pw').value = '';
      openModal('passwordModal');
    }

    function submitPasswordChange() {
      const oldPw = document.getElementById('old_pw').value;
      const newPw = document.getElementById('new_pw').value;
      const confirmPw = document.getElementById('confirm_pw').value;

      if (!oldPw || !newPw || !confirmPw) {
        showToast('All fields required', 'warning');
        return;
      }

      if (newPw !== confirmPw) {
        showToast('Passwords do not match', 'error');
        return;
      }

      const fd = new FormData();
      fd.append('action', 'change_password');
      fd.append('old_password', oldPw);
      fd.append('new_password', newPw);

      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          if (res.success) {
            showToast('Password changed successfully');
            closeModal('passwordModal');
          } else {
            showToast(res.error || 'Failed to update password', 'error');
          }
        });
    }

    // COMPLAINTS READ/LOAD
    function loadComplaints(page = 1) {
      State.activePage = page;
      const status = document.getElementById('filterStatus').value;
      const source = document.getElementById('filterSource').value;
      const tag    = document.getElementById('filterTag') ? document.getElementById('filterTag').value : '';
      const query  = document.getElementById('searchQuery').value.trim();

      fetch(`?action=get_complaints&status=${status}&source=${source}&tag=${encodeURIComponent(tag)}&search=${query}&page=${page}`)
        .then(res => res.json())
        .then(res => {
          if (res.success) {
            State.activeRows = res.rows;
            renderComplaintsTable(res);
            renderStats(res.stats);
          }
        });
    }

    function renderStats(stats) {
      if (!stats) return;
      const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val || 0; };
      setEl('statTotal',     stats.total      || 0);
      setEl('statPending',   stats.pending    || 0);
      setEl('statProgress',  stats.inProgress || stats.in_progress || 0);
      setEl('statDelivered', stats.delivered  || 0);
      setEl('statToday',     stats.todayNew   || stats.today || 0);
      const pending = Number(stats.pending || 0);
      const inProgress = Number(stats.inProgress || stats.in_progress || 0);
      const resolved = Number(stats.delivered || 0);
      const total = Number(stats.total || 0);
      setEl('dashTotal', total);
      setEl('dashPending', pending);
      setEl('dashProgress', inProgress);
      setEl('dashResolved', resolved);
      setEl('dashToday', stats.todayNew || stats.today || 0);
      setEl('dashPendingLabel', pending);
      setEl('dashProgressLabel', inProgress);
      setEl('dashResolvedLabel', resolved);
      setEl('dashOpenCases', pending + inProgress);
      setEl('dashAssignedCases', inProgress);
      setEl('dashResolvedCases', resolved);
      setEl('dashRate', total ? Math.round((resolved / total) * 100) + '%' : '0%');
      [['dashPendingBar', pending], ['dashProgressBar', inProgress], ['dashResolvedBar', resolved]].forEach(([id, value]) => {
        const bar = document.getElementById(id);
        if (bar) bar.style.width = (total ? Math.min(100, value / total * 100) : 0) + '%';
      });
    }

    function loadDashboardCharts() {
      fetch('?action=get_analytics')
        .then(res => res.json())
        .then(res => {
          if (!res.success || !res.analytics || typeof Chart === 'undefined') return;
          const data = res.analytics;
          window.dashboardCharts = window.dashboardCharts || {};
          ['status', 'trend'].forEach(key => {
            if (window.dashboardCharts[key]) window.dashboardCharts[key].destroy();
          });
          const statusCanvas = document.getElementById('dashboardStatusChart');
          const trendCanvas = document.getElementById('dashboardTrendChart');
          if (statusCanvas) {
            window.dashboardCharts.status = new Chart(statusCanvas, {
              type: 'doughnut',
              data: { labels: data.status.labels, datasets: [{ data: data.status.data, backgroundColor: ['#f59e0b','#06b6d4','#10b981','#8b5cf6','#64748b'], borderWidth: 0 }] },
              options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } } } }
            });
          }
          if (trendCanvas) {
            window.dashboardCharts.trend = new Chart(trendCanvas, {
              type: 'line',
              data: { labels: data.trend.labels, datasets: [{ label: 'Complaints', data: data.trend.data, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.12)', fill: true, tension: 0.35, pointRadius: 4, pointBackgroundColor: '#2563eb' }] },
              options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
            });
          }
        })
        .catch(err => console.error('Dashboard charts failed:', err));
    }

    function renderComplaintsTable(res) {
      const tbody = document.getElementById('complaintsTableBody');
      tbody.innerHTML = '';

      if (!res.rows || res.rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:3rem;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:2rem;margin-bottom:1rem;display:block;"></i>No active complaints found.</td></tr>';
        document.getElementById('pgInfo').innerText = '0 of 0';
        return;
      }

      res.rows.forEach(r => {
        const tr = document.createElement('tr');
        tr.id = 'row-' + r.id;

        // Apply SLA warnings if case has been open > 24 hours
        const createdDate = new Date(r.created_at);
        const hoursOld = (new Date() - createdDate) / (1000 * 60 * 60);
        const isPending = r.status === 'Pending' || r.status === 'In Progress';
        
        let agingBadge = '';
        if (isPending) {
          if (hoursOld > 48) {
            tr.className = 'aging-alert-critical';
            agingBadge = '<br><span class="aging-badge" style="background:#fecaca;"><i class="fas fa-exclamation-triangle"></i> 48h SLA breached</span>';
          } else if (hoursOld > 24) {
            tr.className = 'aging-alert';
            agingBadge = '<br><span class="aging-badge"><i class="fas fa-clock"></i> 24h SLA breached</span>';
          }
        }

        const dateFormatted = new Date(r.created_at).toLocaleString('en-IN', {
          day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });

        const TAG_COLORS = {
          'Leakage':         {bg:'#fee2e2',color:'#991b1b'},
          'No Supply':       {bg:'#f1f5f9',color:'#334155'},
          'Wrong Delivery':  {bg:'#fff7ed',color:'#9a3412'},
          'Duplicate':       {bg:'#eff6ff',color:'#1d4ed8'},
          'Pressure Issue':  {bg:'#fefce8',color:'#854d0e'},
          'Other':           {bg:'#f8fafc',color:'#475569'},
        };
        const tagStyle = TAG_COLORS[r.tag] || null;
        const tagBadge = (r.tag && r.tag !== '') && tagStyle
          ? `<span style="font-size:0.65rem;font-weight:700;background:${tagStyle.bg};color:${tagStyle.color};padding:2px 6px;border-radius:4px;margin-left:4px;">${escapeHtml(r.tag)}</span>`
          : '';
        const mobileCountOnPage = State.activeRows ? State.activeRows.filter(x => x.mobile === r.mobile).length : 1;
        const repeatBadge = mobileCountOnPage > 1
          ? `<span style="font-size:0.65rem;font-weight:800;background:#fff7ed;color:#c2410c;padding:2px 6px;border-radius:4px;margin-left:4px;">🔁 Repeat</span>`
          : '';
        
        const isUnresolved = r.status !== 'Resolved' && r.status !== 'Delivered' && r.status !== 'Closed';
        const delBtnHtml = isUnresolved ? `
          <button class="btn btn-success btn-sm" onclick="quickMarkDelivered(${r.id}, '${escapeHtml(r.consumer_name)}')" style="font-weight:700; margin-right:4px;" title="Mark Delivered">
            <i class="fas fa-check-circle"></i> Delivered
          </button>
        ` : '';

        const cleanMobile = (r.mobile || '').replace(/\D/g, '');
        const callBtn = cleanMobile ? `<a href="tel:${cleanMobile}" class="btn btn-outline btn-sm" style="color:#2563eb; font-weight:700; padding:2px 8px; margin-right:4px;" title="Call Consumer"><i class="fas fa-phone-alt"></i></a>` : '';
        const waBtn = cleanMobile ? `<a href="https://wa.me/91${cleanMobile}?text=${encodeURIComponent('Hello ' + (r.consumer_name||'') + ', regarding your HP Gas complaint #' + r.id)}" target="_blank" class="btn btn-outline btn-sm" style="color:#16a34a; font-weight:700; padding:2px 8px; margin-right:4px;" title="WhatsApp Consumer"><i class="fab fa-whatsapp"></i></a>` : '';
        const mapBtn = r.address ? `<a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(r.address)}" target="_blank" class="btn btn-outline btn-sm" style="color:#ea580c; font-weight:700; padding:2px 8px; margin-right:4px;" title="Google Maps Navigation"><i class="fas fa-map-marker-alt"></i></a>` : '';

        tr.innerHTML = `
          <td>
            <input type="checkbox" class="c-sel" value="${r.id}" onchange="updateBatchToolbar()">
          </td>
          <td style="font-weight:700;color:var(--primary);">
            #${r.id}
            <div style="font-size:0.7rem;color:var(--text-muted);font-weight:normal;">${dateFormatted}${agingBadge}</div>
          </td>
          <td>
            <div style="font-weight:700;font-size:0.9rem;">${escapeHtml(r.consumer_name)}${repeatBadge}</div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
              <b>Acc No:</b> ${escapeHtml(r.consumer_number || 'N/A')}
            </div>
          </td>
          <td style="font-weight:700;color:var(--primary);font-size:0.9rem;">
            ${escapeHtml(r.mobile)}
          </td>
          <td style="max-width:200px;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;" title="${escapeHtml(r.address)}" class="hide-mobile">${escapeHtml(r.address)}</td>
          <td class="hide-mobile"><span class="badge badge-secondary">${escapeHtml(r.source)}</span></td>
          <td style="max-width:180px;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">${escapeHtml(r.complaint)}</td>
          <td style="font-weight:600;color:#1e293b;" class="hide-mobile">${escapeHtml(r.vendor || 'Unassigned')}</td>
          <td><span class="badge badge-${r.status.toLowerCase().replace(' ', '-')}">${r.status}</span></td>
          <td style="white-space:nowrap;">
            ${callBtn}${waBtn}${mapBtn}${delBtnHtml}
            <button class="btn btn-primary btn-sm" onclick="viewComplaintDetails(${r.id})" style="background:#0f172a !important; color:#ffffff !important; border:none !important; font-weight:700;">
              <i class="fas fa-eye"></i> View
            </button>
          </td>
        `;
        tbody.appendChild(tr);
      });

      // Update Pagination Indicators
      document.getElementById('pgInfo').innerText = `${res.rows.length} of ${res.total}`;
      const pc = document.getElementById('pgControls');
      pc.innerHTML = '';
      if (res.totalPages > 1) {
        if (res.page > 1) {
          pc.innerHTML += `<button class="btn btn-outline btn-sm" onclick="loadComplaints(${res.page - 1})"><i class="fas fa-chevron-left"></i></button>`;
        }
        pc.innerHTML += `<span style="font-size:0.8rem;margin:0 10px;">${res.page}/${res.totalPages}</span>`;
        if (res.page < res.totalPages) {
          pc.innerHTML += `<button class="btn btn-outline btn-sm" onclick="loadComplaints(${res.page + 1})"><i class="fas fa-chevron-right"></i></button>`;
        }
      }
    }

    // MULTI SELECT TOOLBARS
    function toggleSelectAll(master) {
      document.querySelectorAll('.c-sel').forEach(el => el.checked = master.checked);
      updateBatchToolbar();
    }

    function updateBatchToolbar() {
      const checked = document.querySelectorAll('.c-sel:checked');
      const bar = document.getElementById('batchActions');
      const label = document.getElementById('batchCount');

      if (checked.length > 0) {
        bar.style.display = 'flex';
        label.innerText = `${checked.length} Selected`;
        
        // Hide bulk delete button for non-admins
        const bulkDel = document.getElementById('bulkDeleteBtn');
        if (bulkDel) {
          bulkDel.style.display = (State.user.role === 'Admin') ? 'inline-block' : 'none';
        }
      } else {
        bar.style.display = 'none';
        document.getElementById('selectAll').checked = false;
      }
    }

    // CRUD ACTIONS: COMPLAINTS
    function openAddComplaintModal() {
      if (State.user && State.user.role === 'Vendor') {
        showToast('Vendors are not permitted to add complaints.', 'error');
        return;
      }
      document.getElementById('c_id').value = '';
      document.getElementById('c_no').value = '';
      document.getElementById('c_name').value = '';
      document.getElementById('c_mob').value = '';
      document.getElementById('c_addr').value = '';
      document.getElementById('c_comp').value = '';
      document.getElementById('cStatusBlock').style.display = 'none';
      const tagEl = document.getElementById('c_tag');
      if (tagEl) tagEl.value = '';
      const banner = document.getElementById('consumerHistoryBanner');
      if (banner) banner.style.display = 'none';
      document.getElementById('complaintModalTitle').innerText = 'New Complaint';
      openModal('complaintModal');
    }

    function submitComplaintForm() {
      const id   = document.getElementById('c_id').value;
      const no   = document.getElementById('c_no').value.trim();
      const name = document.getElementById('c_name').value.trim();
      const mob  = document.getElementById('c_mob').value.trim();
      const addr = document.getElementById('c_addr').value.trim();
      const src  = document.getElementById('c_src').value;
      const comp = document.getElementById('c_comp').value.trim();
      const status = document.getElementById('c_status').value;
      const tag  = document.getElementById('c_tag') ? document.getElementById('c_tag').value : '';

      if (!name || !mob || !addr || !comp) {
        showToast('Required fields missing', 'warning');
        return;
      }

      const fd = new FormData();
      fd.append('action', id ? 'update_complaint' : 'add_complaint');
      if (id) fd.append('id', id);
      fd.append('consumer_number', no);
      fd.append('consumer_name', name);
      fd.append('mobile', mob);
      fd.append('address', addr);
      fd.append('source', src);
      fd.append('complaint', comp);
      fd.append('tag', tag);
      if (id) fd.append('status', status);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast(id ? 'Complaint updated' : 'Complaint registered successfully');
            closeModal('complaintModal');
            if (!id) {
              clearActiveFilters();
              switchView('active-registry', null);
              loadComplaints(1);
            } else {
              loadComplaints(State.activePage);
            }
          } else {
            showToast(res.error || 'Operation failed', 'error');
          }
        })
        .catch(err => {
          showLoading(false);
          console.error(err);
          showToast('Failed to save: connection issue or database lock', 'error');
        });
    }

    // Consumer history lookup on mobile blur
    function checkConsumerHistory() {
      const mob = document.getElementById('c_mob').value.trim();
      const banner = document.getElementById('consumerHistoryBanner');
      if (!mob || mob.length < 8) { banner.style.display = 'none'; return; }
      // Skip if editing existing complaint
      if (document.getElementById('c_id').value) { banner.style.display = 'none'; return; }
      fetch(`?action=get_consumer_history&mobile=${encodeURIComponent(mob)}`)
        .then(r => r.json())
        .then(res => {
          if (res.success && res.count > 0) {
            document.getElementById('consumerHistoryMsg').innerText =
              `This consumer has filed ${res.count} prior complaints!`;
            let listHtml = '';
            res.complaints.forEach(c => {
              const d = new Date(c.created_at).toLocaleDateString('en-IN');
              listHtml += `<div style="padding:3px 0;border-bottom:1px dashed #fef3c7;">#${c.id} — ${escapeHtml(c.complaint.substring(0,50))}... <span style="color:#b45309;font-weight:700;">[${c.status}]</span> <span style="color:#94a3b8;">${d}</span></div>`;
            });
            document.getElementById('consumerHistoryList').innerHTML = listHtml;
            banner.style.display = 'block';
          } else {
            banner.style.display = 'none';
          }
        });
    }

    // Bulk Delete
    function bulkDeleteSelected() {
      const checked = Array.from(document.querySelectorAll('.c-sel:checked')).map(el => el.value);
      if (checked.length === 0) return;
      Swal.fire({
        title: 'Bulk Delete?',
        text: `Are you sure you want to permanently delete these ${checked.length} complaint(s)?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Yes, Delete'
      }).then(r => {
        if (r.isConfirmed) {
          const fd = new FormData();
          fd.append('action', 'bulk_delete');
          fd.append('complaint_ids', checked.join(','));
          showLoading(true);
          fetch('?', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast(`${checked.length} complaints deleted`);
                loadComplaints(State.activePage);
                document.getElementById('selectAll').checked = false;
                updateBatchToolbar();
              }
            });
        }
      });
    }

    function deleteComplaint(id) {
      Swal.fire({
        title: 'Delete Complaint?',
        text: 'This will permanently remove the record.',
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel'
      }).then(r => {
        if (r.isConfirmed) {
          showLoading(true);
          fetch(`?action=delete_complaint&id=${id}`)
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast('Complaint record deleted');
                closeModal('detailsModal');
                loadComplaints(State.activePage);
              } else {
                showToast(res.error || 'Failed to delete record', 'error');
              }
            });
        }
      });
    }

    // DETAILED COMPLAINT EXPAND VIEW
    function viewComplaintDetails(id) {
      showLoading(true);
      fetch(`?action=get_complaint_details&id=${id}`)
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            renderDetailModal(res.complaint);
          }
        });
    }

    function renderDetailModal(c) {
      const isPending = c.status === 'Pending' || c.status === 'In Progress';
      const createdDate = new Date(c.created_at).toLocaleString();
      const resolvedDate = c.resolved_at ? new Date(c.resolved_at).toLocaleString() : 'N/A';

      document.getElementById('detailsModalTitle').innerHTML = `<i class="fas fa-file-alt text-danger"></i> Complaint Details &mdash; Case #${c.id}`;

      const sigBlock = c.signature_url ? `
        <div style="grid-column: 1 / -1; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
          <div style="font-weight:700;margin-bottom:0.5rem;">Customer Digital Signature:</div>
          <img src="${c.signature_url}" style="max-height:80px;border:1px solid var(--border-color);border-radius:8px;background:white;padding:4px;" />
        </div>
      ` : '';

      const failBanner = c.fail_reason ? `
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 15px; margin-bottom:1.25rem;">
          <div style="font-weight:800; color:#dc2626; font-size:0.9rem; display:flex; align-items:center; gap:6px;">
            <i class="fas fa-exclamation-triangle"></i> Delivery Failed: ${escapeHtml(c.fail_reason)}
          </div>
          ${c.fail_notes ? `<div style="font-size:0.8rem; color:#7f1d1d; margin-top:4px;"><b>Notes:</b> ${escapeHtml(c.fail_notes)}</div>` : ''}
        </div>
      ` : '';

      const photoBlock = c.photo_proof_url ? `
        <div style="grid-column: 1 / -1; margin-top: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
          <div style="font-weight:700;margin-bottom:0.4rem;font-size:0.82rem;color:var(--text-muted);"><i class="fas fa-camera"></i> Delivery Photo Proof:</div>
          <a href="${c.photo_proof_url}" target="_blank">
            <img src="${c.photo_proof_url}" style="max-height:120px; border:1px solid var(--border-color); border-radius:8px; background:white; padding:4px; object-fit:cover;" />
          </a>
        </div>
      ` : '';

      const detailGrid = `
        ${failBanner}
        <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 15px; margin-bottom:1.5rem; font-size:0.8rem;">
          <span style="color:#64748b; font-weight:700;"><i class="fas fa-info-circle text-primary"></i> Created: ${createdDate}</span>
          <span class="badge badge-${c.status.toLowerCase().replace(' ', '-')}">${c.status}</span>
        </div>

        <div class="premium-detail-card" style="display:grid;grid-template-columns:120px 1fr;gap:0.75rem;">
          <div style="font-weight:700;color:var(--text-muted);">Consumer Name:</div>
          <div style="font-weight:800;color:var(--text-main);">${escapeHtml(c.consumer_name)}</div>
          
          <div style="font-weight:700;color:var(--text-muted);">Account No:</div>
          <div>${escapeHtml(c.consumer_number || 'N/A')}</div>

          <div style="font-weight:700;color:var(--text-muted);">Mobile:</div>
          <div style="font-weight:700;color:var(--primary);">${escapeHtml(c.mobile)}</div>

          <div style="font-weight:700;color:var(--text-muted);">Address:</div>
          <div>${escapeHtml(c.address)}</div>
        </div>

        <div class="premium-detail-card" style="display:grid;grid-template-columns:120px 1fr;gap:0.75rem;">
          <div style="font-weight:700;color:var(--text-muted);">Source Type:</div>
          <div><span class="badge badge-secondary">${escapeHtml(c.source)}</span></div>

          <div style="font-weight:700;color:var(--text-muted);">Issue Details:</div>
          <div style="font-style:italic;font-weight:600;color:#334155;">"${escapeHtml(c.complaint)}"</div>
        </div>

        <div class="premium-detail-card" style="display:grid;grid-template-columns:120px 1fr;gap:0.75rem;">
          <div style="font-weight:700;color:var(--text-muted);">Assigned Tech:</div>
          <div style="font-weight:700;color:var(--primary-dark);">${escapeHtml(c.vendor || 'Unassigned Queue')}</div>

          <div style="font-weight:700;color:var(--text-muted);">Resolved At:</div>
          <div>${resolvedDate}</div>
          
          ${sigBlock}
          ${photoBlock}
        </div>
      `;

      document.getElementById('detailsModalBody').innerHTML = detailGrid;

      // Render Footer Buttons
      let footerHtml = '';
      const isUnresolved = c.status !== 'Resolved' && c.status !== 'Delivered' && c.status !== 'Closed';
      if (isUnresolved) {
        if (State.user.role !== 'Vendor') {
          footerHtml += `<button class="btn btn-warning" onclick="openAssignSingle(${c.id})"><i class="fas fa-user-tag"></i> Assign Vendor</button>`;
        }
        footerHtml += `<button class="btn btn-success" onclick="openMarkDeliveredModal(${c.id})"><i class="fas fa-check-circle"></i> Mark Resolved</button>`;
        footerHtml += `<button class="btn btn-outline" onclick="openReportIssueModal(${c.id}, '${escapeHtml(c.consumer_name)}')" style="color:#ef4444; border-color:#fca5a5;"><i class="fas fa-exclamation-triangle"></i> Report Issue</button>`;
      }
      
      footerHtml += `<button class="btn btn-outline" onclick="copyComplaintText(${c.id})"><i class="fab fa-whatsapp"></i> Copy Text</button>`;
      footerHtml += `<button class="btn btn-outline" onclick="printComplaintSlip(${c.id})"><i class="fas fa-print"></i> Slip</button>`;
      
      if (State.user.role === 'Admin') {
        footerHtml += `<button class="btn btn-outline" onclick="openEditComplaintModal(${c.id})"><i class="fas fa-edit"></i> Edit</button>`;
        footerHtml += `<button class="btn btn-danger" onclick="deleteComplaint(${c.id})"><i class="fas fa-trash-alt"></i> Delete</button>`;
      }

      footerHtml += `<button class="btn btn-secondary" onclick="closeModal('detailsModal')">Close</button>`;

      document.getElementById('detailsModalFooter').innerHTML = footerHtml;
      openModal('detailsModal');
    }

    function quickMarkDelivered(id, consumerName) {
      Swal.fire({
        title: 'Mark Case #' + id + ' Delivered?',
        text: `Confirm resolution for ${consumerName || 'Consumer'}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Mark Resolved',
        confirmButtonColor: '#10b981',
        cancelButtonText: 'Cancel'
      }).then((result) => {
        if (result.isConfirmed) {
          submitResolutionNoSign(id);
        }
      });
    }

    function optimizeVendorRoute() {
      if (!State.activeRows || State.activeRows.length === 0) {
        Swal.fire('No Active Deliveries', 'No open complaint addresses to route.', 'info');
        return;
      }
      const addrs = State.activeRows
        .map(r => r.address ? r.address.trim() : '')
        .filter(a => a.length > 0);
      if (addrs.length === 0) {
        Swal.fire('No Valid Addresses', 'Active complaints do not have valid addresses.', 'warning');
        return;
      }
      const dest = encodeURIComponent(addrs[addrs.length - 1]);
      const waypoints = addrs.slice(0, addrs.length - 1).map(a => encodeURIComponent(a)).join('|');
      const mapsUrl = `https://www.google.com/maps/dir/?api=1&origin=My+Location&destination=${dest}&waypoints=${waypoints}`;
      window.open(mapsUrl, '_blank');
    }

    function openReportIssueModal(id, consumerName) {
      Swal.fire({
        title: `Report Issue — Case #${id}`,
        html: `
          <p style="font-size:0.85rem;color:#64748b;margin-bottom:1rem;">Select reason why delivery could not be completed for <b>${escapeHtml(consumerName || 'Consumer')}</b>:</p>
          <div style="text-align:left;">
            <label style="font-size:0.8rem;font-weight:700;">Issue Reason *</label>
            <select id="swal_fail_reason" class="swal2-input" style="width:100%;margin:0.4rem 0 1rem 0;height:42px;font-size:0.88rem;">
              <option value="🚪 Ghar Band (House Locked)">🚪 Ghar Band (House Locked)</option>
              <option value="📞 Phone Nahi Utha Raha (Not Answering)">📞 Phone Nahi Utha Raha (Not Answering)</option>
              <option value="📍 Galat Pata (Wrong Address)">📍 Galat Pata (Wrong Address)</option>
              <option value="❌ Customer Ne Mana Kiya (Refused)">❌ Customer Ne Mana Kiya (Refused)</option>
              <option value="⏳ Customer Out of Station">⏳ Customer Out of Station</option>
              <option value="⚪ Other Issue">⚪ Other Issue</option>
            </select>
            <label style="font-size:0.8rem;font-weight:700;">Additional Notes (Optional)</label>
            <input type="text" id="swal_fail_notes" class="swal2-input" style="width:100%;margin:0.4rem 0 0 0;height:42px;font-size:0.88rem;" placeholder="e.g. Called 3 times at 11:30 AM">
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Submit Issue',
        confirmButtonColor: '#ef4444',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
          const reason = document.getElementById('swal_fail_reason').value;
          const notes = document.getElementById('swal_fail_notes').value;
          if (!reason) {
            Swal.showValidationMessage('Please select an issue reason');
            return false;
          }
          return { reason, notes };
        }
      }).then((result) => {
        if (result.isConfirmed) {
          submitDeliveryFailed(id, result.value.reason, result.value.notes);
        }
      });
    }

    function submitDeliveryFailed(id, reason, notes) {
      const fd = new FormData();
      fd.append('action', 'mark_delivery_failed');
      fd.append('id', id);
      fd.append('fail_reason', reason);
      fd.append('fail_notes', notes);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Delivery issue logged');
            closeModal('detailsModal');
            loadComplaints(State.activePage);
          } else {
            showToast(res.error || 'Failed to log issue', 'error');
          }
        })
        .catch(() => { showLoading(false); showToast('Network error', 'error'); });
    }

    // SIGNATURE PAD & RESOLUTION SYSTEM
    let sigDrawing = false;
    let canvasContext = null;

    function openMarkDeliveredModal(id) {
      closeModal('detailsModal');
      
      // Load resolution modal dialog
      const markup = `
        <div style="margin-bottom:1.25rem;">
          <h2 style="margin:0;font-weight:800;color:#10b981;">Mark Case #${id} Resolved</h2>
          <p style="margin:0.25rem 0 0 0;color:var(--text-muted);font-size:0.85rem;">Capture signature or attach photo proof to complete resolution.</p>
        </div>
        <div class="form-group">
          <label><i class="fas fa-signature"></i> Customer Digital Signature</label>
          <div class="sig-canvas-container">
            <canvas id="sigCanvas"></canvas>
          </div>
          <div style="text-align:right;margin-top:0.35rem;">
            <button class="btn btn-outline btn-sm" onclick="clearSignatureCanvas()"><i class="fas fa-undo"></i> Clear Sign</button>
          </div>
        </div>
        <div class="form-group" style="margin-top:1rem;">
          <label><i class="fas fa-camera"></i> Upload Delivery Photo / Receipt Proof (Optional)</label>
          <input type="file" id="deliveryPhotoFile" accept="image/*" capture="environment" class="form-control" style="padding:6px;">
        </div>
      `;

      document.getElementById('detailsModalBody').innerHTML = markup;
      document.getElementById('detailsModalFooter').innerHTML = `
        <button class="btn btn-success" onclick="submitResolution(${id})"><i class="fas fa-check"></i> Submit Resolution</button>
        <button class="btn btn-outline" onclick="submitResolutionNoSign(${id})" style="background:#f1f5f9;color:#475569;"><i class="fas fa-check-circle"></i> Resolve Without Sign</button>
        <button class="btn btn-secondary" onclick="viewComplaintDetails(${id})">Back</button>
      `;

      // Setup drawing event handlers on HTML5 canvas
      setTimeout(() => {
        const canvas = document.getElementById('sigCanvas');
        canvasContext = canvas.getContext('2d');
        
        // Match canvas dimensions to offsetWidth
        canvas.width = canvas.offsetWidth;
        canvas.height = 130;

        canvasContext.strokeStyle = '#0f172a';
        canvasContext.lineWidth = 3;
        canvasContext.lineCap = 'round';

        // Mouse events
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);

        // Touch events
        canvas.addEventListener('touchstart', startDrawingTouch);
        canvas.addEventListener('touchmove', drawTouch);
        canvas.addEventListener('touchend', stopDrawing);
      }, 200);
    }

    function startDrawing(e) {
      sigDrawing = true;
      const canvas = document.getElementById('sigCanvas');
      canvasContext.beginPath();
      canvasContext.moveTo(e.pageX - canvas.getBoundingClientRect().left - window.scrollX, e.pageY - canvas.getBoundingClientRect().top - window.scrollY);
    }

    function draw(e) {
      if (!sigDrawing) return;
      const canvas = document.getElementById('sigCanvas');
      canvasContext.lineTo(e.pageX - canvas.getBoundingClientRect().left - window.scrollX, e.pageY - canvas.getBoundingClientRect().top - window.scrollY);
      canvasContext.stroke();
    }

    function startDrawingTouch(e) {
      sigDrawing = true;
      const canvas = document.getElementById('sigCanvas');
      const touch = e.touches[0];
      canvasContext.beginPath();
      canvasContext.moveTo(touch.clientX - canvas.getBoundingClientRect().left, touch.clientY - canvas.getBoundingClientRect().top);
    }

    function drawTouch(e) {
      if (!sigDrawing) return;
      e.preventDefault();
      const canvas = document.getElementById('sigCanvas');
      const touch = e.touches[0];
      canvasContext.lineTo(touch.clientX - canvas.getBoundingClientRect().left, touch.clientY - canvas.getBoundingClientRect().top);
      canvasContext.stroke();
    }

    function stopDrawing() {
      sigDrawing = false;
    }

    function clearSignatureCanvas() {
      const canvas = document.getElementById('sigCanvas');
      canvasContext.clearRect(0, 0, canvas.width, canvas.height);
    }

    function submitResolution(id) {
      const canvas = document.getElementById('sigCanvas');
      const base64Data = canvas ? canvas.toDataURL() : '';

      const fd = new FormData();
      fd.append('action', 'resolve_complaint');
      fd.append('id', id);
      fd.append('signature_data', base64Data);

      const fileInput = document.getElementById('deliveryPhotoFile');
      if (fileInput && fileInput.files && fileInput.files[0]) {
        fd.append('photo_proof', fileInput.files[0]);
      }

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Complaint resolved successfully!');
            closeModal('detailsModal');
            loadComplaints(State.activePage);
          } else {
            showToast(res.error || 'Failed to resolve complaint', 'error');
          }
        })
        .catch(() => { showLoading(false); showToast('Network error', 'error'); });
    }

    function submitResolutionNoSign(id) {
      const fd = new FormData();
      fd.append('action', 'resolve_complaint');
      fd.append('id', id);
      fd.append('signature_data', '');

      const fileInput = document.getElementById('deliveryPhotoFile');
      if (fileInput && fileInput.files && fileInput.files[0]) {
        fd.append('photo_proof', fileInput.files[0]);
      }

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Complaint resolved!');
            closeModal('detailsModal');
            loadComplaints(State.activePage);
          } else {
            showToast(res.error || 'Failed to resolve', 'error');
          }
        })
        .catch(() => { showLoading(false); showToast('Network error', 'error'); });
    }

    // VENDOR ASSIGNMENT METHODS
    function openAssignSingle(id) {
      document.getElementById('assign_cid').value = id;
      document.getElementById('assign_mode').value = 'single';
      renderVendorSelectList();
      closeModal('detailsModal');
      openModal('assignModal');
    }

    function openBulkAssign() {
      const checked = Array.from(document.querySelectorAll('.c-sel:checked')).map(el => el.value);
      if (checked.length === 0) return;

      document.getElementById('assign_cid').value = checked.join(',');
      document.getElementById('assign_mode').value = 'bulk';
      renderVendorSelectList();
      openModal('assignModal');
    }

    function renderVendorSelectList() {
      const block = document.getElementById('vendorSelectGrid');
      block.innerHTML = '';
      State.selectedVendorId = null;

      State.vendors.forEach(v => {
        const item = document.createElement('div');
        item.style.padding = '0.75rem 1rem';
        item.style.border = '1px solid var(--border-color)';
        item.style.borderRadius = '8px';
        item.style.cursor = 'pointer';
        item.style.display = 'flex';
        item.style.justifyContent = 'space-between';
        item.style.fontWeight = '700';
        item.style.fontSize = '0.85rem';
        item.id = 'vsel-' + v.id;
        
        item.innerHTML = `<div>${escapeHtml(v.name)}</div><div style="color:var(--text-muted)">${escapeHtml(v.code || 'Code')}</div>`;
        item.onclick = () => {
          document.querySelectorAll('[id^="vsel-"]').forEach(el => el.style.borderColor = 'var(--border-color)');
          item.style.borderColor = 'var(--primary)';
          State.selectedVendorId = v.id;
        };
        block.appendChild(item);
      });
    }

    function submitVendorAssignment() {
      if (!State.selectedVendorId) {
        showToast('Please select a vendor', 'warning');
        return;
      }

      const cids = document.getElementById('assign_cid').value;
      const mode = document.getElementById('assign_mode').value;
      const vendor = State.vendors.find(v => String(v.id) === String(State.selectedVendorId));

      const fd = new FormData();
      fd.append('action', mode === 'bulk' ? 'bulk_assign_vendor' : 'assign_vendor');
      fd.append('complaint_ids', cids);
      fd.append('vendor_id', vendor.id);
      fd.append('vendor_name', vendor.name);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Vendor assigned successfully');
            closeModal('assignModal');
            loadComplaints(State.activePage);
            
            // Clean selection
            document.getElementById('selectAll').checked = false;
            updateBatchToolbar();
          }
        });
    }

    function bulkMarkDelivered() {
      const checked = Array.from(document.querySelectorAll('.c-sel:checked')).map(el => el.value);
      if (checked.length === 0) return;

      Swal.fire({
        title: 'Bulk Delivery?',
        text: `Mark ${checked.length} complaint(s) as Resolved/Delivered?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Mark Resolved'
      }).then(r => {
        if (r.isConfirmed) {
          const fd = new FormData();
          fd.append('action', 'bulk_resolve');
          fd.append('complaint_ids', checked.join(','));

          showLoading(true);
          fetch('?', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast(`${checked.length} complaints resolved`);
                loadComplaints(State.activePage);
                document.getElementById('selectAll').checked = false;
                updateBatchToolbar();
              }
            });
        }
      });
    }

    // VIEW: HISTORY LOG MODULE
    function loadHistory() {
      const status = document.getElementById('histFilterStatus').value;
      const from = document.getElementById('histDateFrom').value;
      const to = document.getElementById('histDateTo').value;
      const search = document.getElementById('histSearch').value.trim();

      fetch(`?action=get_history&status=${status}&date_from=${from}&date_to=${to}&search=${search}`)
        .then(res => res.json())
        .then(res => {
          const tbody = document.getElementById('historyTableBody');
          tbody.innerHTML = '';

          if (res.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--text-muted);">No records found.</td></tr>';
            return;
          }

          res.rows.forEach(r => {
            const tr = document.createElement('tr');
            const resDate = new Date(r.resolved_at || r.updated_at).toLocaleString();
            
            tr.innerHTML = `
              <td style="font-weight:700;color:var(--primary);">#${r.id}</td>
              <td style="font-size:0.75rem;color:var(--text-muted);font-weight:600;" class="hide-mobile">${resDate}</td>
              <td>
                <div style="font-weight:700;">${escapeHtml(r.consumer_name)}</div>
                <div style="font-size:0.72rem;color:var(--text-muted);"><b>Acc No:</b> ${escapeHtml(r.consumer_number || 'N/A')}</div>
              </td>
              <td style="font-weight:700;color:var(--primary);font-size:0.9rem;">
                ${escapeHtml(r.mobile)}
              </td>
              <td style="max-width:180px;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;" title="${escapeHtml(r.address)}" class="hide-mobile">${escapeHtml(r.address)}</td>
              <td>${escapeHtml(r.complaint)}</td>
              <td style="font-weight:600;" class="hide-mobile">${escapeHtml(r.vendor || '-')}</td>
              <td><span class="badge badge-delivered"><i class="fas fa-check-double"></i> ${r.status}</span></td>
              <td>
                <button class="btn btn-primary btn-sm" onclick="viewComplaintDetails(${r.id})" style="background:#0f172a !important; color:#ffffff !important; border:none !important; font-weight:700;">
                  <i class="fas fa-eye"></i> View
                </button>
              </td>
            `;
            tbody.appendChild(tr);
          });
        });
    }

    // VIEW: VENDORS
    function loadVendorScorecard() {
      const container = document.getElementById('vendorScorecardContainer');
      container.innerHTML = '<div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
      fetch('?action=get_vendor_scorecard')
        .then(r => r.json())
        .then(res => {
          if (!res.success || res.rows.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted);">No vendor data available yet.</div>';
            return;
          }
          let html = `<div class="table-container"><table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f8fafc;font-size:0.78rem;text-transform:uppercase;color:#64748b;">
              <th style="padding:10px;">Rank</th>
              <th style="padding:10px;">Vendor</th>
              <th style="padding:10px;text-align:center;">Assigned</th>
              <th style="padding:10px;text-align:center;">Resolved</th>
              <th style="padding:10px;text-align:center;">Late (>48h)</th>
              <th style="padding:10px;text-align:center;">Score</th>
            </tr></thead><tbody>`;
          res.rows.forEach((v, i) => {
            const isBest  = i === 0;
            const isWorst = i === res.rows.length - 1 && res.rows.length > 1;
            const scoreColor = v.score >= 75 ? '#10b981' : v.score >= 40 ? '#f59e0b' : '#ef4444';
            const rowBg = isBest ? '#f0fdf4' : isWorst ? '#fef2f2' : '';
            const rankLabel = isBest
              ? '<span style="font-size:1.1rem;">🏆</span> Best'
              : isWorst ? '<span style="font-size:1.1rem;">⚠️</span> Worst' : `#${i+1}`;
            html += `<tr style="border-bottom:1px solid #f1f5f9;background:${rowBg};">
              <td style="padding:10px;font-weight:700;">${rankLabel}</td>
              <td style="padding:10px;font-weight:800;color:#0f172a;">${escapeHtml(v.vendor)}</td>
              <td style="padding:10px;text-align:center;">${v.assigned}</td>
              <td style="padding:10px;text-align:center;color:#10b981;font-weight:700;">${v.resolved}</td>
              <td style="padding:10px;text-align:center;color:#ef4444;font-weight:700;">${v.late}</td>
              <td style="padding:10px;text-align:center;">
                <span style="font-weight:800;font-size:1rem;color:${scoreColor};">${v.score}</span>
                <span style="font-size:0.7rem;color:#94a3b8;">/100</span>
              </td>
            </tr>`;
          });
          html += '</tbody></table></div>';
          container.innerHTML = html;
        });
    }

    function loadVendors() {
      fetch('?action=get_vendors')
        .then(res => res.json())
        .then(res => {
          State.vendors = res.vendors;
          const tbody = document.getElementById('vendorsTableBody');
          tbody.innerHTML = '';

          if (res.vendors.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;">No vendors directory entry.</td></tr>';
            return;
          }

          res.vendors.forEach(v => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td style="font-weight:700;">#${v.id}</td>
              <td style="font-weight:700;">${escapeHtml(v.name)}</td>
              <td style="font-weight:600;color:var(--primary-dark);">${escapeHtml(v.mobile)}</td>
              <td><code>${escapeHtml(v.code || '-')}</code></td>
              <td>${escapeHtml(v.notes || '')}</td>
              <td style="text-align:center;">
                <button class="btn btn-outline btn-sm" onclick="openEditVendorModal(${v.id})"><i class="fas fa-edit"></i></button>
                <button class="btn btn-danger btn-sm" onclick="deleteVendor(${v.id})"><i class="fas fa-trash-alt"></i></button>
              </td>
            `;
            tbody.appendChild(tr);
          });
        });
    }

    function openAddVendorModal() {
      document.getElementById('v_id').value = '';
      document.getElementById('v_name').value = '';
      document.getElementById('v_mob').value = '';
      document.getElementById('v_code').value = '';
      document.getElementById('v_notes').value = '';
      document.getElementById('vendorModalTitle').innerText = 'Add Vendor';
      openModal('vendorModal');
    }

    function openEditVendorModal(id) {
      const v = State.vendors.find(x => String(x.id) === String(id));
      if (!v) return;

      document.getElementById('v_id').value = v.id;
      document.getElementById('v_name').value = v.name;
      document.getElementById('v_mob').value = v.mobile;
      document.getElementById('v_code').value = v.code || '';
      document.getElementById('v_notes').value = v.notes || '';
      document.getElementById('vendorModalTitle').innerText = 'Edit Vendor';
      openModal('vendorModal');
    }

    function submitVendorForm() {
      const id = document.getElementById('v_id').value;
      const name = document.getElementById('v_name').value.trim();
      const mob = document.getElementById('v_mob').value.trim();
      const code = document.getElementById('v_code').value.trim();
      const notes = document.getElementById('v_notes').value.trim();

      if (!name || !mob) {
        showToast('Required fields missing', 'warning');
        return;
      }

      const fd = new FormData();
      fd.append('action', id ? 'update_vendor' : 'add_vendor');
      if (id) fd.append('id', id);
      fd.append('name', name);
      fd.append('mobile', mob);
      fd.append('code', code);
      fd.append('notes', notes);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast(id ? 'Vendor record updated' : 'Vendor registered successfully');
            closeModal('vendorModal');
            loadVendors();
          } else {
            showToast(res.error || 'Operation failed', 'error');
          }
        });
    }

    function deleteVendor(id) {
      Swal.fire({
        title: 'Remove Vendor?',
        text: 'Assignments connected to this vendor will be cleared.',
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Cancel'
      }).then(r => {
        if (r.isConfirmed) {
          showLoading(true);
          fetch(`?action=delete_vendor&id=${id}`)
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast('Vendor deleted');
                loadVendors();
              }
            });
        }
      });
    }

    // VIEW: TRIP MANIFEST REPORTS MODULE
    let _reportsData = null;
    function loadVendorReports() {
      showLoading(true);
      fetch('?action=get_vendor_reports')
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            _reportsData = res.report;
            renderVendorReportsGrid(res.report);
          }
        });
    }

    function renderVendorReportsGrid(report) {
      const grid = document.getElementById('vendorReportGrid');
      grid.innerHTML = '';

      report.forEach(item => {
        const vId = item.vendor.id || 'unassigned';
        
        let complaintsRows = '';
        if (item.openComplaints && item.openComplaints.length) {
          item.openComplaints.forEach((c, index) => {
            complaintsRows += `
              <tr>
                <td style="font-weight:700;color:var(--primary);">#${c.id}</td>
                <td><b>${escapeHtml(c.consumer_name)}</b><br><small style="color:var(--text-muted);">${escapeHtml(c.mobile)}</small></td>
                <td>${escapeHtml(c.address)}</td>
                <td>${escapeHtml(c.complaint)}</td>
                <td><span class="badge badge-pending">Open</span></td>
                <td>
                  <button class="btn btn-primary btn-sm" onclick="viewComplaintDetails(${c.id})" style="background:#0f172a !important; color:#ffffff !important; border:none !important; font-weight:700;">
                    <i class="fas fa-eye"></i> View
                  </button>
                </td>
              </tr>
            `;
          });
        } else {
          complaintsRows = '<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-muted);">No open complaints.</td></tr>';
        }

        const successRate = item.totalAssigned > 0 ? Math.round((item.deliveredCount / item.totalAssigned) * 100) : 0;

        const card = document.createElement('div');
        card.className = 'content-card';
        card.innerHTML = `
          <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.5rem;background:#f8fafc;border-bottom:1px solid var(--border-color);cursor:pointer;" onclick="toggleAccordion('${vId}')">
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <div style="width:36px;height:36px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:800;">
                ${item.vendor.name.charAt(0).toUpperCase()}
              </div>
              <div>
                <div style="font-weight:800;">${escapeHtml(item.vendor.name)}</div>
                <div style="font-size:0.72rem;color:var(--text-muted);">Assigned: ${item.totalAssigned} | Delivered: ${item.deliveredCount} | Rate: ${successRate}%</div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:0.8rem;">
              <span class="badge badge-warning" style="font-size:0.8rem;">Active Open: ${item.openCount}</span>
              <i class="fas fa-chevron-down" id="v-icon-${vId}" style="transition:transform 0.2s;"></i>
            </div>
          </div>
          <div id="v-body-${vId}" style="display:none;">
            ${item.vendor.id ? `
              <div style="padding:0.75rem 1.5rem;background:#ffffff;border-bottom:1px solid var(--border-color);display:flex;justify-content:flex-end;gap:0.5rem;">
                <button class="btn btn-outline btn-sm" onclick="copyManifestText('${item.vendor.id}')"><i class="fas fa-copy"></i> Copy Text</button>
                <button class="btn btn-success btn-sm" onclick="sendManifestWhatsApp('${item.vendor.id}')"><i class="fab fa-whatsapp"></i> Send WA</button>
                <button class="btn btn-primary btn-sm" onclick="printManifestTripSheet('${item.vendor.id}')"><i class="fas fa-print"></i> Print Sheet</button>
              </div>
            ` : ''}
            <div class="table-container">
              <table>
                <thead>
                  <tr>
                    <th style="width:70px;">ID</th>
                    <th>Consumer Info</th>
                    <th>Address</th>
                    <th>Complaint details</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:100px;">Actions</th>
                  </tr>
                </thead>
                <tbody>${complaintsRows}</tbody>
              </table>
            </div>
          </div>
        `;
        grid.appendChild(card);
      });
    }

    function toggleAccordion(vId) {
      const body = document.getElementById('v-body-' + vId);
      const icon = document.getElementById('v-icon-' + vId);
      if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
      } else {
        body.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
      }
    }

    function copyTextToClipboard(text, successMessage) {
      if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text).then(() => showToast(successMessage));
      }

      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', '');
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      const copied = document.execCommand('copy');
      textarea.remove();
      if (!copied) return Promise.reject(new Error('Clipboard copy failed'));
      showToast(successMessage);
      return Promise.resolve();
    }

    function copyManifestText(vId) {
      const item = _reportsData.find(r => String(r.vendor.id) === String(vId));
      if (!item) return;
      copyTextToClipboard(item.whatsappMessage, 'Trip Manifest text copied!').catch(() => {
        Swal.fire('Copy failed', 'Text manually select karke copy karein.', 'warning');
      });
    }

    function sendManifestWhatsApp(vId) {
      const item = _reportsData.find(r => String(r.vendor.id) === String(vId));
      if (!item || !item.vendor.mobile) return;
      
      const cleaned = item.vendor.mobile.replace(/\D/g, '');
      const waUrl = `https://wa.me/91${cleaned}?text=${encodeURIComponent(item.whatsappMessage)}`;
      window.open(waUrl, '_blank');
    }

    function generateManifestTripSheetHtml(item) {
      const logoUrl = State.company.company_logo && State.company.company_logo !== 'default-logo.png' 
        ? State.company.company_logo 
        : '';
        
      const logoImg = logoUrl ? `<img src="${logoUrl}" style="max-height: 60px; max-width: 65px; object-fit: contain; margin-right: 20px;" />` : '';

      let rowsHtml = '';
      item.openComplaints.forEach((c, idx) => {
        rowsHtml += `
          <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="text-align:center; padding:10px; font-weight:700; color:#64748b;">${idx+1}</td>
            <td style="padding:10px; font-weight:800; color:#2563eb;">#${c.id}</td>
            <td style="padding:10px; font-size:12px; line-height:1.4;">
              <b style="color:#1e293b; font-size:13px;">${escapeHtml(c.consumer_name)}</b><br>
              <b>Acc:</b> ${escapeHtml(c.consumer_number || 'N/A')}
            </td>
            <td style="padding:10px; font-weight:700; font-size:12px; color:#2563eb;">
              ${escapeHtml(c.mobile)}
            </td>
            <td style="padding:10px; font-size:12px; color:#334155; line-height:1.4; max-width:220px;">${escapeHtml(c.address)}</td>
            <td style="padding:10px; font-size:12px; font-style:italic; color:#ef4444; max-width:200px;">"${escapeHtml(c.complaint)}"</td>
            <td style="padding:10px; width:120px; border-left:1px solid #e2e8f0;"></td>
          </tr>
        `;
      });

      return `
        <div class="trip-manifest-page" style="font-family:'Plus Jakarta Sans', sans-serif; padding:30px; color:#1e293b; page-break-after: always; box-sizing: border-box;">
          
          <!-- Header Area -->
          <div style="display:flex; align-items:center; margin-bottom:20px; border-bottom:3px solid #3b82f6; padding-bottom:15px;">
            ${logoImg}
            <div style="flex:1;">
              <h1 style="margin:0 0 4px 0; font-size:1.6rem; font-weight:800; text-transform:uppercase; color:#0f172a; letter-spacing:-0.5px;">${escapeHtml(State.company.company_name)}</h1>
              <div style="font-size:0.8rem; color:#64748b; font-weight:600; line-height:1.4;">
                ${escapeHtml(State.company.company_address || '')}<br>
                <b>Ph:</b> ${escapeHtml(State.company.company_mobile || '')} | <b>Email:</b> ${escapeHtml(State.company.company_email || '')}
              </div>
            </div>
            <div style="text-align:right;">
              <span style="font-size: 11px; font-weight:800; background:#eff6ff; color:#2563eb; padding: 5px 12px; border-radius: 6px; text-transform:uppercase; letter-spacing:0.5px; display:inline-block; margin-bottom:8px;">TRIP MANIFEST</span>
              <div style="font-size:0.75rem; color:#64748b; font-weight:600;">Date: ${new Date().toLocaleDateString('en-IN')}</div>
            </div>
          </div>

          <!-- Trip details -->
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 20px; margin-bottom:20px; display:flex; justify-content:space-between; font-size:13px; font-weight:600; color:#475569;">
            <div><b>Technician:</b> <span style="color:#0f172a; font-weight:700;">${escapeHtml(item.vendor.name)}</span> (${escapeHtml(item.vendor.mobile)})</div>
            <div><b>Open Jobs to Attend:</b> <span style="color:#2563eb; font-weight:800;">${item.openCount}</span></div>
          </div>

          <!-- Complaints list table -->
          <table border="0" cellpadding="0" cellspacing="0" style="width:100%; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; border-collapse:collapse;">
            <thead>
              <tr style="background:#f1f5f9; border-bottom:2px solid #cbd5e1; font-size:11px; font-weight:800; text-transform:uppercase; text-align:left; color:#475569;">
                <th style="padding:12px; text-align:center; width:40px;">S.No</th>
                <th style="padding:12px; width:60px;">ID</th>
                <th style="padding:12px; width:180px;">Customer Info</th>
                <th style="padding:12px; width:110px;">Mobile No</th>
                <th style="padding:12px;">Delivery Address</th>
                <th style="padding:12px;">Complaint details</th>
                <th style="padding:12px; width:140px; border-left:1px solid #cbd5e1;">Customer Sign</th>
              </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
          </table>

          <!-- Footer signatures -->
          <div style="margin-top:4rem; display:flex; justify-content:space-between; font-size:13px; font-weight:600; color:#475569;">
            <div>Manager Signature: _______________________</div>
            <div>Technician Signature: _______________________</div>
          </div>
        </div>
      `;
    }

    function printManifestTripSheet(vId) {
      const item = _reportsData.find(r => String(r.vendor.id) === String(vId));
      if (!item || item.openComplaints.length === 0) return;

      const printWin = window.open('', '_blank', 'width=900,height=600');
      const body = generateManifestTripSheetHtml(item);
      
      printWin.document.write(`
        <html>
          <head>
            <title>Trip Sheet - ${item.vendor.name}</title>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
            <style>
              body { margin:0; padding:0; }
              @media print {
                .trip-manifest-page { page-break-after: avoid !important; }
              }
            </style>
          </head>
          <body onload="window.print()">${body}</body>
        </html>
      `);
      printWin.document.close();
    }

    function printAllManifests() {
      let combinedHtml = '';
      let manifestCount = 0;
      
      _reportsData.forEach(item => {
        if (item.vendor.id && item.openComplaints.length > 0) {
          combinedHtml += generateManifestTripSheetHtml(item);
          manifestCount++;
        }
      });
      
      if (manifestCount === 0) {
        showToast('No active manifests to print', 'info');
        return;
      }
      
      const printWin = window.open('', '_blank', 'width=900,height=600');
      printWin.document.write(`
        <html>
          <head>
            <title>All Trip Manifests</title>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
            <style>
              body { margin:0; padding:0; }
              @media print {
                .trip-manifest-page { page-break-after: always; }
                .trip-manifest-page:last-child { page-break-after: avoid !important; }
              }
            </style>
          </head>
          <body onload="window.print()">${combinedHtml}</body>
        </html>
      `);
      printWin.document.close();
    }

    // UTILITIES COPY & PRINT INDIVIDUAL SLIPS
    function copyComplaintText(id) {
      showLoading(true);
      fetch(`?action=get_clipboard_text&id=${id}`)
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            copyTextToClipboard(res.text, 'Complaint details copied!').catch(() => {
              Swal.fire('Copy failed', 'Text manually select karke copy karein.', 'warning');
            });
          }
        });
    }

    function printComplaintSlip(id) {
      let c = (State.activeRows || []).find(x => String(x.id) === String(id)) || 
              (State.histRows || []).find(x => String(x.id) === String(id));

      if (!c) {
        showLoading(true);
        fetch(`?action=get_complaint_details&id=${id}`)
          .then(res => res.json())
          .then(res => {
            showLoading(false);
            if (res.success && res.complaint) {
              triggerPrintSlipModal(res.complaint);
            } else {
              showToast('Complaint record not found', 'error');
            }
          })
          .catch(() => { showLoading(false); showToast('Network error', 'error'); });
        return;
      }

      triggerPrintSlipModal(c);
    }

    function triggerPrintSlipModal(c) {
      Swal.fire({
        title: 'Select Print Format',
        text: 'Choose layout for service slip:',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Standard A4',
        cancelButtonText: 'Thermal Printer',
        showDenyButton: true,
        denyButtonText: 'Cancel',
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#10b981',
        denyButtonColor: '#64748b'
      }).then((result) => {
        if (result.isConfirmed) {
          generatePrintSlip(c, 'A4');
        } else if (result.dismiss === Swal.DismissReason.cancel) {
          // If they click 'Thermal Printer', show sub-choice for 80mm vs 58mm
          Swal.fire({
            title: 'Select Receipt Width',
            text: 'Choose width matching your portable device:',
            showCancelButton: true,
            confirmButtonText: '80mm (3-inch)',
            cancelButtonText: '58mm (2-inch)',
            confirmButtonColor: '#0f766e',
            cancelButtonColor: '#2563eb'
          }).then((resSub) => {
            if (resSub.isConfirmed) {
              generatePrintSlip(c, '80mm');
            } else if (resSub.dismiss === Swal.DismissReason.cancel) {
              generatePrintSlip(c, '58mm');
            }
          });
        }
      });
    }

    function generatePrintSlip(c, format) {
      const logoUrl = State.company.company_logo && State.company.company_logo !== 'default-logo.png' 
        ? State.company.company_logo 
        : '';

      const dateFormatted = new Date(c.created_at).toLocaleString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });

      let body = '';
      let style = '';

      if (format === 'A4') {
        const logoImg = logoUrl 
          ? `<img src="${logoUrl}" style="height:64px; width:64px; object-fit:contain; border-radius:8px;" />`
          : `<div style="width:64px;height:64px;border-radius:8px;background:#1e3a5f;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:1.6rem;">${(State.company.company_name||'G').charAt(0)}</div>`;

        const statusColors = {
          'Pending':     { bg:'#fef3c7', color:'#b45309', border:'#fbbf24' },
          'In Progress': { bg:'#dbeafe', color:'#1d4ed8', border:'#3b82f6' },
          'Delivered':   { bg:'#d1fae5', color:'#065f46', border:'#10b981' },
          'Resolved':    { bg:'#ede9fe', color:'#5b21b6', border:'#8b5cf6' },
          'Closed':      { bg:'#f1f5f9', color:'#475569', border:'#94a3b8' },
        };
        const sc = statusColors[c.status] || statusColors['Pending'];

        body = `
          <div style="font-family:'Segoe UI', Arial, sans-serif; max-width:560px; margin:30px auto; background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 8px 32px rgba(0,0,0,0.1);">
            <div style="height:5px; background:linear-gradient(90deg,#1e3a5f,#3b82f6,#10b981);"></div>
            <div style="background:linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); padding:22px 28px; display:flex; align-items:center; gap:18px;">
              ${logoImg}
              <div style="flex:1;">
                <div style="color:#fff; font-size:1.15rem; font-weight:900; letter-spacing:0.3px; line-height:1.2;">${escapeHtml(State.company.company_name)}</div>
                <div style="color:#93c5fd; font-size:0.75rem; font-weight:600; margin-top:3px;">Authorized Gas Distributor</div>
                <div style="color:#64748b; font-size:0.68rem; margin-top:4px; line-height:1.4;">
                  ${escapeHtml(State.company.company_address || '')}
                  ${State.company.company_mobile ? ' · 📞 '+escapeHtml(State.company.company_mobile) : ''}
                </div>
              </div>
              <div style="text-align:right;">
                <div style="color:#93c5fd; font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">SERVICE ORDER</div>
                <div style="color:#fff; font-size:1.5rem; font-weight:900; line-height:1;">Order #${c.id}</div>
              </div>
            </div>
            <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:12px 28px; display:flex; justify-content:space-between; align-items:center; font-size:0.8rem; font-weight:600;">
              <div style="color:#475569;">
                <span style="color:#94a3b8;">Date:</span> ${dateFormatted}
              </div>
              <div style="display:flex; align-items:center; gap:10px;">
                <span style="background:${sc.bg}; color:${sc.color}; border:1px solid ${sc.border}; padding:4px 12px; border-radius:20px; font-size:0.72rem; font-weight:800; text-transform:uppercase;">${c.status}</span>
              </div>
              <div style="color:#475569;">
                <span style="color:#94a3b8;">Assigned To:</span> <span style="color:#1e293b; font-weight:800; text-transform:uppercase;">${escapeHtml(c.vendor || 'Unassigned')}</span>
              </div>
            </div>
            <div style="padding:24px 28px;">
              <div style="margin-bottom:20px;">
                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#3b82f6; border-bottom:2px solid #dbeafe; padding-bottom:6px; margin-bottom:14px;">
                  Customer Information
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                  <div>
                    <div style="font-size:0.68rem; color:#94a3b8; font-weight:700; text-transform:uppercase; margin-bottom:3px;">Customer Name</div>
                    <div style="font-size:0.95rem; font-weight:800; color:#1e293b;">${escapeHtml(c.consumer_name)}</div>
                  </div>
                  <div>
                    <div style="font-size:0.68rem; color:#94a3b8; font-weight:700; text-transform:uppercase; margin-bottom:3px;">Contact Number</div>
                    <div style="font-size:0.95rem; font-weight:800; color:#2563eb;">${escapeHtml(c.mobile)}</div>
                  </div>
                  <div>
                    <div style="font-size:0.68rem; color:#94a3b8; font-weight:700; text-transform:uppercase; margin-bottom:3px;">Consumer Number</div>
                    <div style="font-size:0.9rem; font-weight:700; color:#1e293b;">${escapeHtml(c.consumer_number || 'N/A')}</div>
                  </div>
                  <div>
                    <div style="font-size:0.68rem; color:#94a3b8; font-weight:700; text-transform:uppercase; margin-bottom:3px;">Source</div>
                    <div style="font-size:0.9rem; font-weight:700; color:#1e293b;">${escapeHtml(c.source)}</div>
                  </div>
                </div>
              </div>
              <div style="margin-bottom:20px;">
                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#3b82f6; border-bottom:2px solid #dbeafe; padding-bottom:6px; margin-bottom:10px;">
                  Service Address
                </div>
                <div style="font-size:0.9rem; color:#334155; line-height:1.6; font-weight:600;">${escapeHtml(c.address)}</div>
              </div>
              <div style="margin-bottom:20px;">
                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#3b82f6; border-bottom:2px solid #dbeafe; padding-bottom:6px; margin-bottom:10px;">
                  Service Details
                </div>
                <div style="background:#fef2f2; border-left:3px solid #ef4444; border-radius:0 6px 6px 0; padding:10px 14px; font-size:0.9rem; font-style:italic; color:#991b1b; font-weight:600;">
                  "${escapeHtml(c.complaint)}"
                </div>
              </div>
              <div style="margin-bottom:24px;">
                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#3b82f6; border-bottom:2px solid #dbeafe; padding-bottom:6px; margin-bottom:10px;">
                  Technician Notes / Parts Replaced
                </div>
                <div style="border:1px dashed #cbd5e1; border-radius:8px; padding:10px 14px; min-height:52px; background:#fafafa; font-size:0.82rem; color:#94a3b8;">
                  &nbsp;
                </div>
              </div>
              <div style="border-top:1px dashed #e2e8f0; padding-top:20px;">
                <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#3b82f6; margin-bottom:14px;">Customer Acknowledgment</div>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 14px; font-size:0.8rem; color:#166534; font-weight:600; margin-bottom:18px;">
                  I confirm that the service was completed satisfactorily.
                </div>
                <div style="display:flex; justify-content:space-between; gap:20px;">
                  <div style="flex:1; text-align:center;">
                    <div style="border-bottom:2px solid #334155; margin-bottom:6px; height:40px; display:flex; align-items:flex-end; justify-content:center; padding-bottom:4px; font-size:0.75rem; font-weight:800; color:#1e293b; text-transform:uppercase; letter-spacing:0.5px;">${escapeHtml(c.vendor || '')}</div>
                    <div style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Technician Signature</div>
                  </div>
                  <div style="flex:1; text-align:center;">
                    <div style="border-bottom:2px solid #334155; margin-bottom:6px; height:40px;"></div>
                    <div style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Customer Signature</div>
                  </div>
                </div>
              </div>
            </div>
            <div style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:10px 28px; display:flex; justify-content:space-between; align-items:center; font-size:0.68rem; color:#94a3b8;">
              <span>This operates as a formal service record. Keep this slip for future reference.</span>
              <span>Printed on: ${new Date().toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'})}</span>
            </div>
          </div>
        `;
        
        style = `
          @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&display=swap');
          body { margin:0; padding:20px 0; background:#f1f5f9; }
          @media print {
            body { background:#fff; padding:0; }
            @page { margin:10mm; }
          }
        `;
      } else {
        const width = format === '80mm' ? '280px' : '200px';
        const separator = '-'.repeat(format === '80mm' ? 34 : 24);

        body = `
          <div class="thermal-receipt" style="width: ${width}; font-family: monospace; font-size: 11px; line-height: 1.3; color: #000; background: #fff; margin: 0 auto; padding: 5px;">
            <div style="text-align: center; font-weight: bold; font-size: 13px; text-transform: uppercase;">
              ${escapeHtml(State.company.company_name)}
            </div>
            <div style="text-align: center; font-size: 9px; margin-top: 2px;">
              ${escapeHtml(State.company.company_address || '')}
              ${State.company.company_mobile ? '<br>Ph: ' + escapeHtml(State.company.company_mobile) : ''}
            </div>
            <div>${separator}</div>
            <div style="text-align: center; font-weight: bold; text-transform: uppercase;">
              SERVICE SLIP (ID: #${c.id})
            </div>
            <div>${separator}</div>
            
            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
              <tr>
                <td style="font-weight: bold; width: 40%;">DATE:</td>
                <td>${dateFormatted}</td>
              </tr>
              <tr>
                <td style="font-weight: bold;">STATUS:</td>
                <td style="font-weight: bold;">${c.status.toUpperCase()}</td>
              </tr>
              <tr>
                <td style="font-weight: bold;">STAFF:</td>
                <td>${escapeHtml(c.vendor || 'UNASSIGNED')}</td>
              </tr>
            </table>
            
            <div>${separator}</div>
            <div style="font-weight: bold; text-transform: uppercase; margin-bottom: 2px;">CUSTOMER:</div>
            <div style="font-weight: bold; font-size: 12px;">${escapeHtml(c.consumer_name)}</div>
            <div>NUM: ${escapeHtml(c.consumer_number || 'N/A')}</div>
            <div>PH:  ${escapeHtml(c.mobile)}</div>
            
            <div style="margin-top: 5px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px;">ADDRESS:</div>
            <div style="white-space: normal; word-break: break-all;">${escapeHtml(c.address)}</div>
            
            <div style="margin-top: 5px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px;">COMPLAINT:</div>
            <div style="font-style: italic; background: #f3f4f6; padding: 4px; border-left: 2px solid #000;">
              ${escapeHtml(c.complaint)}
            </div>
            
            <div style="margin-top: 15px; border-top: 1px dashed #000; padding-top: 5px;">
              <div style="font-weight: bold; text-transform: uppercase; margin-bottom: 2px;">TECH NOTES:</div>
              <div style="height: 35px; border: 1px dashed #ccc; margin-top: 5px;"></div>
            </div>
            
            <div style="margin-top: 15px; display: flex; justify-content: space-between; gap: 10px; text-align: center; font-size: 9px;">
              <div style="flex: 1;">
                <div style="border-top: 1px solid #000; margin-top: 20px; padding-top: 3px;">TECH SIGN</div>
              </div>
              <div style="flex: 1;">
                <div style="border-top: 1px solid #000; margin-top: 20px; padding-top: 3px;">CUST SIGN</div>
              </div>
            </div>
            
            <div style="text-align: center; font-size: 9px; margin-top: 15px; border-top: 1px dashed #000; padding-top: 5px;">
              Thank you for using our service.
            </div>
          </div>
        `;

        style = `
          body { margin: 0; padding: 0; background: #fff; }
          @media print {
            body { background: #fff; margin: 0; padding: 0; }
            @page { margin: 0; }
          }
        `;
      }

      const printWin = window.open('', '_blank', `width=${format === 'A4' ? 680 : 320},height=${format === 'A4' ? 900 : 600}`);
      printWin.document.write(`
        <html>
          <head>
            <title>Slip #${c.id}</title>
            <style>${style}</style>
          </head>
          <body onload="window.print()">${body}</body>
        </html>
      `);
      printWin.document.close();
    }

    // VIEW: ANALYTICS CONSTRUCTOR
    let chartsInstance = {};
    function loadAnalytics() {
      showLoading(true);
      fetch('?action=get_analytics')
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            renderCharts(res.analytics);
          }
        });
    }

    function renderCharts(data) {
      const destroy = (id) => { if (chartsInstance[id]) chartsInstance[id].destroy(); };

      // status chart
      destroy('status');
      const statusColors = { 'Pending': '#f59e0b', 'In Progress': '#3b82f6', 'Delivered': '#10b981', 'Resolved': '#8b5cf6', 'Closed': '#64748b' };
      chartsInstance['status'] = new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
          labels: data.status.labels,
          datasets: [{
            data: data.status.data,
            backgroundColor: data.status.labels.map(l => statusColors[l] || '#cbd5e1'),
            borderWidth: 2
          }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
      });

      // source chart
      destroy('source');
      chartsInstance['source'] = new Chart(document.getElementById('sourceChart'), {
        type: 'bar',
        data: {
          labels: data.source.labels,
          datasets: [{
            label: 'Complaints count',
            data: data.source.data,
            backgroundColor: '#3b82f6',
            borderRadius: 4
          }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
      });

      // trend chart
      destroy('trend');
      chartsInstance['trend'] = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
          labels: data.trend.labels,
          datasets: [{
            label: 'Registered Complaints',
            data: data.trend.data,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,0.1)',
            fill: true,
            tension: 0.3
          }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
      });

      // Render Vendor table rankings
      const tbody = document.getElementById('analyticsVendorRows');
      tbody.innerHTML = '';
      data.vendor_rankings.forEach(v => {
        tbody.innerHTML += `
          <tr>
            <td style="font-weight:700;">${escapeHtml(v.name)}</td>
            <td style="font-weight:700;color:var(--primary);">${v.delivered}</td>
            <td style="font-weight:800;color:#10b981;">${v.efficiency}%</td>
          </tr>
        `;
      });
    }

    // VIEW: DATA CSV EXPORT
    function triggerCsvExport() {
      const from = document.getElementById('exportFromDate').value;
      const to = document.getElementById('exportToDate').value;
      const status = document.getElementById('exportStatus').value;

      if (!from || !to) {
        showToast('Please select date range', 'warning');
        return;
      }

      window.open(`?action=export_csv&date_from=${from}&date_to=${to}&status=${status}`, '_blank');
    }

    // VIEW: EMPLOYEE ACCOUNTS
    let _employees = [];
    function loadEmployees() {
      fetch('?action=get_employees')
        .then(res => res.json())
        .then(res => {
          if (res.success) {
            _employees = res.employees;
            renderEmployeesTable(res.employees);
          }
        });
    }

    function setEmployeeViewMode(mode) {
      State.employeeViewMode = mode;
      document.getElementById('btnEmpGrid').classList.toggle('active', mode === 'grid');
      document.getElementById('btnEmpList').classList.toggle('active', mode === 'list');
      renderEmployeesTable(_employees);
    }

    function renderEmployeesListTable(list) {
      if (list.length === 0) {
        return `
          <div style="padding: 40px; text-align: center; color: var(--text-muted); background: #f8fafc; border-radius: 12px; border: 1px dashed var(--border-color); grid-column: 1 / -1; width: 100%;">
            <i class="fas fa-users" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
            <div style="font-weight: 700;">No employees found</div>
          </div>
        `;
      }

      let rowsHtml = '';
      list.forEach(u => {
        const isActive = u.active == 1;

        // Permissions Badges
        let badges = '';
        try {
          const perms = Array.isArray(u.permissions) ? u.permissions : JSON.parse(u.permissions || '[]');
          badges = perms.map(p => `<span style="font-size: 0.65rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; margin-right: 2px; display: inline-block;">${p.replace('complaints_', '').replace('vendors_', '').replace('settings_', '')}</span>`).join('');
        } catch(e) {
          const perms = (u.permissions || '').split(',').map(s => s.trim()).filter(s => s);
          badges = perms.map(p => `<span style="font-size: 0.65rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; margin-right: 2px; display: inline-block;">${p.replace('complaints_', '').replace('vendors_', '').replace('settings_', '')}</span>`).join('');
        }

        const photoHtml = u.profile_photo && u.profile_photo !== 'default-photo.png'
          ? `<img src="${u.profile_photo}" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">`
          : `<div style="width: 32px; height: 32px; border-radius: 50%; background: #2563eb; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 0.85rem;">${(u.name || 'E').charAt(0).toUpperCase()}</div>`;

        const hasPendingReset = u.pending_password && u.pending_password !== '';
        const resetBadge = hasPendingReset ? `<span class="badge" style="background:#fffbeb; color:#b45309; border: 1px solid #fef3c7; font-size:0.65rem; margin-left:4px;" title="Password Reset Requested!"><i class="fas fa-key"></i> Requested</span>` : '';

        let rowActions = `
          <button class="btn btn-outline btn-sm" style="padding: 2px 6px; font-size: 0.7rem;" onclick="openEditEmployeeModal(${u.id})" title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-outline btn-sm" style="padding: 2px 6px; font-size: 0.7rem;" onclick="toggleEmployeeActive(${u.id}, ${u.active})" title="${isActive ? 'Disable' : 'Enable'}"><i class="fas ${isActive ? 'fa-ban' : 'fa-check'}"></i></button>
          <button class="btn btn-outline btn-sm" style="padding: 2px 6px; font-size: 0.7rem; color: #ef4444; border-color: #fca5a5;" onclick="deleteEmployee(${u.id})" title="Delete"><i class="fas fa-trash"></i></button>
        `;
        if (State.user.role === 'Admin' && String(u.id) !== String(State.user.id) && isActive) {
          rowActions += `
            <button class="btn btn-outline btn-sm" style="padding: 2px 6px; font-size: 0.7rem; color: #581c87; border-color: #d8b4fe;" onclick="impersonateUser(${u.id})" title="Login As User"><i class="fas fa-user-secret"></i></button>
          `;
        }
        if (hasPendingReset) {
          rowActions += `
            <button class="btn btn-sm btn-success" style="padding: 2px 6px; font-size: 0.7rem; background:#10b981; border:none; color:#fff;" onclick="approveResetRequest(${u.id})" title="Approve Reset"><i class="fas fa-check-double"></i></button>
            <button class="btn btn-sm" style="padding: 2px 6px; font-size: 0.7rem; background:#fee2e2; border:none; color:#991b1b;" onclick="rejectResetRequest(${u.id})" title="Reject Reset"><i class="fas fa-times"></i></button>
          `;
        }

        rowsHtml += `
          <tr style="border-bottom: 1px solid var(--border-color);">
            <td style="text-align: center; padding: 10px;">${photoHtml}</td>
            <td style="font-weight: 800; color: #0f172a; padding: 10px; cursor: pointer; text-decoration: underline;" onclick="viewEmployeeDetails(${u.id})">${escapeHtml(u.name)}</td>
            <td style="font-weight: 600; color: #475569; padding: 10px;">${escapeHtml(u.username)}</td>
            <td style="font-weight: 600; color: #475569; padding: 10px;">${escapeHtml(u.mobile || 'N/A')}</td>
            <td style="padding: 10px;"><span style="font-size: 0.72rem; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 6px; border-radius: 4px;">${escapeHtml(u.role)}</span></td>
            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 10px;">${badges || 'Standard'}</td>
            <td style="text-align: center; padding: 10px;">
              <span style="font-size: 0.72rem; font-weight: 700; background: ${isActive ? '#d1fae5; color: #065f46' : '#fee2e2; color: #991b1b'}; padding: 2px 6px; border-radius: 4px;">${isActive ? 'Active' : 'Disabled'}</span>${resetBadge}
            </td>
            <td style="text-align: center; padding: 10px;">
              <div style="display: flex; gap: 4px; justify-content: center;">
                ${rowActions}
              </div>
            </td>
          </tr>
        `;
      });

      return `
        <div class="content-card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-color); border-radius: 12px; grid-column: 1 / -1; width: 100%;">
          <div class="table-container" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 800px;">
              <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid var(--border-color); text-align: left; font-size: 0.75rem; text-transform: uppercase; color: #64748b;">
                  <th style="width: 60px; text-align: center; padding: 12px;">Photo</th>
                  <th style="padding: 12px;">Name</th>
                  <th style="padding: 12px;">Gmail ID / Username</th>
                  <th style="padding: 12px;">Mobile No</th>
                  <th style="padding: 12px; width: 90px;">Role</th>
                  <th style="padding: 12px;">Permissions</th>
                  <th style="width: 90px; text-align: center; padding: 12px;">Status</th>
                  <th style="width: 110px; text-align: center; padding: 12px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                ${rowsHtml}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }

    function renderEmployeesTable(list) {
      const grid = document.getElementById('employeesCardGrid');
      
      if (State.employeeViewMode === 'list') {
        grid.style.display = 'block';
        grid.innerHTML = renderEmployeesListTable(list);
        return;
      }
      
      grid.style.display = 'grid';
      grid.innerHTML = '';

      if (list.length === 0) {
        grid.innerHTML = `
          <div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-muted); background: #f8fafc; border-radius: 12px; border: 1px dashed var(--border-color);">
            <i class="fas fa-users" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
            <div style="font-weight: 700;">No employees found</div>
          </div>
        `;
        return;
      }

      list.forEach(u => {
        const isActive = u.active == 1;

        // Permissions Badges
        let badges = '';
        try {
          const perms = Array.isArray(u.permissions) ? u.permissions : JSON.parse(u.permissions || '[]');
          badges = perms.map(p => `<span style="font-size: 0.65rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 9999px; margin-right: 4px; margin-bottom: 4px; display: inline-block;">${p.replace('complaints_', '').replace('vendors_', '').replace('settings_', '')}</span>`).join('');
        } catch(e) {
          const perms = (u.permissions || '').split(',').map(s => s.trim()).filter(s => s);
          badges = perms.map(p => `<span style="font-size: 0.65rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 9999px; margin-right: 4px; margin-bottom: 4px; display: inline-block;">${p.replace('complaints_', '').replace('vendors_', '').replace('settings_', '')}</span>`).join('');
        }

        // Profile Photo
        const photoHtml = u.profile_photo && u.profile_photo !== 'default-photo.png'
          ? `<img src="${u.profile_photo}" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); flex-shrink: 0;">`
          : `<div style="width: 60px; height: 60px; border-radius: 50%; background: #2563eb; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 1.5rem; border: 2px solid var(--primary); flex-shrink: 0;">${(u.name || 'E').charAt(0).toUpperCase()}</div>`;

        // Logs HTML
        let logsListHtml = '';
        const userLogs = u.logs || [];
        if (userLogs.length > 0) {
          userLogs.forEach(l => {
            const time = new Date(l.timestamp).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
            logsListHtml += `
              <div style="font-size: 0.72rem; border-bottom: 1px dashed #f1f5f9; padding: 6px 0; color: #475569;">
                <span style="font-weight: 700; color: #0f172a; text-transform: uppercase;">${escapeHtml(l.action)}</span> - ${escapeHtml(l.details || '')} 
                <span style="color: #94a3b8; float: right;">${time}</span>
              </div>
            `;
          });
        } else {
          logsListHtml = `<div style="font-size: 0.75rem; color: var(--text-muted); text-align: center; padding: 10px 0;">No activity logged yet.</div>`;
        }

        grid.innerHTML += `
          <div class="employee-card">
            
            <!-- Employee Card Header -->
            <div style="display: flex; gap: 14px; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 10px; cursor: pointer;" onclick="viewEmployeeDetails(${u.id})">
              ${photoHtml}
              <div style="flex: 1; min-width: 0;">
                <div style="display: flex; align-items: center; gap: 6px;">
                  <span style="font-weight: 800; font-size: 1.05rem; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(u.name)}">${escapeHtml(u.name)}</span>
                  <span style="font-size: 0.65rem; font-weight: 800; background: ${isActive ? '#d1fae5; color: #065f46' : '#fee2e2; color: #991b1b'}; padding: 2px 6px; border-radius: 4px; flex-shrink:0;">${isActive ? 'Active' : 'Disabled'}</span>
                </div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-top: 1px;">
                  <span style="background:#f1f5f9; padding: 2px 6px; border-radius: 4px;">${escapeHtml(u.role)}</span>
                </div>
              </div>
            </div>
            
            <!-- Details Block -->
            <div style="font-size: 0.8rem; display: flex; flex-direction: column; gap: 6px; color: #334155; margin-bottom: 12px;">
              <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><i class="fas fa-envelope" style="width: 16px; color: #64748b;"></i> <b>Gmail:</b> ${escapeHtml(u.username)}</div>
              <div><i class="fas fa-phone" style="width: 16px; color: #64748b;"></i> <b>Mobile:</b> ${escapeHtml(u.mobile || 'N/A')}</div>
              <div style="margin-top: 4px;">
                <div style="font-weight: 700; font-size: 0.7rem; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Access Permissions</div>
                <div style="display: flex; flex-wrap: wrap; gap: 2px;">${badges || '<span style="font-size: 0.7rem; color: #94a3b8;">None</span>'}</div>
              </div>
            </div>

            <!-- Actions Row -->
            <div style="display: flex; gap: 8px; border-top: 1px solid #f1f5f9; padding-top: 12px; margin-top: auto; margin-bottom: 12px;">
              <button class="btn btn-outline" style="flex: 1; padding: 6px; font-size: 0.75rem; font-weight: 700;" onclick="openEditEmployeeModal(${u.id})">
                <i class="fas fa-edit"></i> Edit
              </button>
              <button class="btn btn-outline" style="flex: 1; padding: 6px; font-size: 0.75rem; font-weight: 700;" onclick="toggleEmployeeActive(${u.id}, ${u.active})">
                <i class="fas ${isActive ? 'fa-ban' : 'fa-check'}"></i> ${isActive ? 'Disable' : 'Enable'}
              </button>
              <button class="btn" style="flex: 1; padding: 6px; font-size: 0.75rem; font-weight: 700; background: #fee2e2; color: #991b1b; border: none;" onclick="deleteEmployee(${u.id})">
                <i class="fas fa-trash"></i> Delete
              </button>
            </div>

            <!-- Reset password block (grid) -->
            ${(function() {
              if (u.pending_password && u.pending_password !== '') {
                return `
                  <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 10px; margin-bottom: 12px; display: flex; flex-direction: column; gap: 8px; flex-shrink: 0;">
                    <div style="font-size: 0.75rem; font-weight: 700; color: #b45309;"><i class="fas fa-exclamation-triangle"></i> Password Reset Requested!</div>
                    <div style="display: flex; gap: 6px;">
                      <button class="btn btn-sm btn-success" style="flex: 1; padding: 4px; font-size: 0.7rem; font-weight: 700; border: none; background: #10b981; color: #fff; border-radius: 4px;" onclick="approveResetRequest(${u.id})">Approve</button>
                      <button class="btn btn-sm" style="flex: 1; padding: 4px; font-size: 0.7rem; font-weight: 700; background: #fee2e2; color: #991b1b; border: none; border-radius: 4px;" onclick="rejectResetRequest(${u.id})">Reject</button>
                    </div>
                  </div>
                `;
              }
              return '';
            })()}

            <!-- Accordion Logs Block -->
            <div style="border: 1px solid #f1f5f9; border-radius: 8px; overflow: hidden; background: #f8fafc;">
              <button onclick="toggleCardLogs(${u.id})" style="width:100%; border:none; background:#f1f5f9; padding:6px 10px; font-size:0.75rem; font-weight:700; color:#334155; display:flex; align-items:center; justify-content:space-between; cursor:pointer;">
                <span><i class="fas fa-history" style="color:var(--primary)"></i> Activity Log</span>
                <i class="fas fa-chevron-down" id="log-icon-${u.id}" style="transition: transform 0.2s;"></i>
              </button>
              <div id="log-body-${u.id}" style="display:none; padding:8px 12px; max-height:150px; overflow-y:auto; background:#ffffff;">
                ${logsListHtml}
              </div>
            </div>

          </div>
        `;
      });
    }

    function toggleCardLogs(id) {
      const body = document.getElementById('log-body-' + id);
      const icon = document.getElementById('log-icon-' + id);
      if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
      } else {
        body.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
      }
    }

    function openAddEmployeeModal() {
      document.getElementById('u_id').value = '';
      document.getElementById('u_name').value = '';
      document.getElementById('u_uname').value = '';
      document.getElementById('u_mobile').value = '';
      document.getElementById('u_pw').value = '';
      document.getElementById('uPwLabel').innerText = 'Password *';
      document.getElementById('u_role').value = 'Employee';
      
      const logoPreview = document.getElementById('uLogoPreview');
      if (logoPreview) logoPreview.innerHTML = `<i class="fas fa-user" style="color:#94a3b8; font-size:1.4rem;"></i>`;
      State.tempEmployeeLogoData = 'default-photo.png';
      
      if (document.getElementById('u_branch_id')) {
        document.getElementById('u_branch_id').value = State.active_branch_id || (State.branches[0]?.id || '');
      }

      togglePermissionsBlock();
      // Uncheck all permissions
      document.querySelectorAll('.u-perm').forEach(el => el.checked = false);

      document.getElementById('employeeModalTitle').innerText = 'Add Employee Account';
      openModal('employeeModal');
    }

    function openEditEmployeeModal(id) {
      const u = _employees.find(x => String(x.id) === String(id));
      if (!u) return;

      document.getElementById('u_id').value = u.id;
      document.getElementById('u_name').value = u.name;
      document.getElementById('u_uname').value = u.username;
      document.getElementById('u_mobile').value = u.mobile || '';
      document.getElementById('u_pw').value = '';
      document.getElementById('uPwLabel').innerText = 'New Password (Optional)';
      document.getElementById('u_role').value = u.role;
      
      if (document.getElementById('u_branch_id')) {
        document.getElementById('u_branch_id').value = u.branch_id || '';
      }

      const logoPreview = document.getElementById('uLogoPreview');
      if (logoPreview) {
        if (u.profile_photo && u.profile_photo !== 'default-photo.png') {
          logoPreview.innerHTML = `<img src="${u.profile_photo}" style="width:100%; height:100%; object-fit:cover;">`;
          State.tempEmployeeLogoData = u.profile_photo;
        } else {
          logoPreview.innerHTML = `<i class="fas fa-user" style="color:#94a3b8; font-size:1.4rem;"></i>`;
          State.tempEmployeeLogoData = 'default-photo.png';
        }
      }
      
      togglePermissionsBlock();
      
      const perms = (u.permissions || '').split(',').map(s => s.trim());
      document.querySelectorAll('.u-perm').forEach(el => {
        el.checked = perms.includes(el.value);
      });

      document.getElementById('employeeModalTitle').innerText = 'Edit Employee';
      openModal('employeeModal');
    }

    function togglePermissionsBlock() {
      const role = document.getElementById('u_role').value;
      document.getElementById('permissionsBlock').style.display = (role === 'Admin') ? 'none' : 'block';
    }

    function submitEmployeeForm() {
      const id = document.getElementById('u_id').value;
      const name = document.getElementById('u_name').value.trim();
      const uname = document.getElementById('u_uname').value.trim();
      const mobile = document.getElementById('u_mobile').value.trim();
      const pw = document.getElementById('u_pw').value;
      const role = document.getElementById('u_role').value;
      const photo = State.tempEmployeeLogoData || 'default-photo.png';
      const branchId = document.getElementById('u_branch_id') ? document.getElementById('u_branch_id').value : '1';
      
      // Collect permissions
      const perms = Array.from(document.querySelectorAll('.u-perm:checked')).map(el => el.value);

      if (!name || !uname || (!id && !pw)) {
        showToast('Required fields missing', 'warning');
        return;
      }

      const fd = new FormData();
      fd.append('action', id ? 'update_employee' : 'add_employee');
      if (id) fd.append('id', id);
      fd.append('name', name);
      fd.append('username', uname);
      fd.append('mobile', mobile);
      fd.append('password', pw);
      fd.append('role', role);
      fd.append('profile_photo', photo);
      fd.append('permissions', perms.join(','));
      fd.append('branch_id', branchId);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Employee account saved');
            closeModal('employeeModal');
            loadEmployees();
          } else {
            showToast(res.error || 'Failed to save account', 'error');
          }
        });
    }

    function previewEmployeeLogo(e) {
      const file = e.target.files[0];
      if (!file) return;
      if (file.size > 2 * 1024 * 1024) {
        showToast('Photo file must be under 2MB', 'error');
        return;
      }
      const reader = new FileReader();
      reader.onload = function(evt) {
        const base64 = evt.target.result;
        State.tempEmployeeLogoData = base64;
        const logoPreview = document.getElementById('uLogoPreview');
        if (logoPreview) {
          logoPreview.innerHTML = `<img src="${base64}" style="width:100%; height:100%; object-fit:cover;">`;
        }
      };
      reader.readAsDataURL(file);
    }

    function clearEmployeeLogoPreview() {
      State.tempEmployeeLogoData = 'default-photo.png';
      const logoPreview = document.getElementById('uLogoPreview');
      if (logoPreview) {
        logoPreview.innerHTML = `<i class="fas fa-user" style="color:#94a3b8; font-size:1.4rem;"></i>`;
      }
      document.getElementById('uploadEmployeeLogo').value = '';
    }

    function toggleEmployeeActive(id, currentlyActive) {
      showLoading(true);
      fetch(`?action=toggle_employee&id=${id}&active=${currentlyActive ? 0 : 1}`)
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Employee status updated');
            loadEmployees();
          }
        });
    }

    function deleteEmployee(id) {
      if (String(id) === String(State.user.id)) {
        showToast('Cannot delete yourself!', 'error');
        return;
      }
      Swal.fire({
        title: 'Delete Profile?',
        text: 'This employee will lose all access permanently.',
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete'
      }).then(r => {
        if (r.isConfirmed) {
          showLoading(true);
          fetch(`?action=delete_employee&id=${id}`)
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast('Employee deleted');
                loadEmployees();
              } else {
                showToast(res.error || 'Operation failed', 'error');
              }
            });
        }
      });
    }

    function impersonateUser(id) {
      Swal.fire({
        title: 'Login As Employee?',
        text: 'You will login as this employee without their password. You can switch back anytime.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#6b21a8',
        confirmButtonText: 'Yes, Login'
      }).then(r => {
        if (r.isConfirmed) {
          showLoading(true);
          fetch(`?action=impersonate_user&id=${id}`)
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast('Logged in successfully', 'success');
                window.location.reload();
              } else {
                showToast(res.error || 'Failed to impersonate', 'error');
              }
            });
        }
      });
    }

    function switchBackToAdmin() {
      showLoading(true);
      fetch('?action=switch_back_to_admin')
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Returned to Admin session', 'success');
            window.location.reload();
          } else {
            showToast(res.error || 'Failed to switch back', 'error');
          }
        });
    }

    let State_activeEmpDetails = null;

    function viewEmployeeDetails(id) {
      const u = _employees.find(x => String(x.id) === String(id));
      if (!u) return;

      State_activeEmpDetails = u;

      document.getElementById('empDetailsName').innerText = u.name;
      document.getElementById('empDetailsDisplayName').innerText = u.name;
      document.getElementById('empDetailsGmail').innerText = u.username;
      document.getElementById('empDetailsMobile').innerText = u.mobile || 'N/A';
      
      document.getElementById('empDetailsRole').innerText = u.role;
      const statusEl = document.getElementById('empDetailsStatus');
      const isActive = u.active == 1;
      statusEl.innerText = isActive ? 'Active' : 'Disabled';
      statusEl.style.background = isActive ? '#d1fae5' : '#fee2e2';
      statusEl.style.color = isActive ? '#065f46' : '#991b1b';

      const photoEl = document.getElementById('empDetailsPhoto');
      if (u.profile_photo && u.profile_photo !== 'default-photo.png') {
        photoEl.innerHTML = `<img src="${u.profile_photo}" style="width:100%; height:100%; object-fit:cover;">`;
      } else {
        photoEl.innerHTML = `<div style="width:100%; height:100%; background:#2563eb; display:flex; align-items:center; justify-content:center; color:white; font-weight:800; font-size:2rem;">${(u.name || 'E').charAt(0).toUpperCase()}</div>`;
      }

      const permContainer = document.getElementById('empDetailsPermissions');
      permContainer.innerHTML = '';
      let perms = [];
      try {
        perms = Array.isArray(u.permissions) ? u.permissions : JSON.parse(u.permissions || '[]');
      } catch(e) {
        perms = (u.permissions || '').split(',').map(s => s.trim()).filter(s => s);
      }
      if (perms.length > 0) {
        perms.forEach(p => {
          permContainer.innerHTML += `<span style="font-size: 0.65rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 9999px;">${p.replace('complaints_', '').replace('vendors_', '').replace('settings_', '')}</span>`;
        });
      } else {
        permContainer.innerHTML = `<span style="font-size: 0.72rem; color: #94a3b8;">None (Standard)</span>`;
      }

      const logListEl = document.getElementById('empDetailsLogsList');
      const logCountEl = document.getElementById('empDetailsLogCount');
      logListEl.innerHTML = '';
      
      const userLogs = u.logs || [];
      logCountEl.innerText = userLogs.length;

      if (userLogs.length > 0) {
        userLogs.forEach(l => {
          const timeStr = new Date(l.timestamp).toLocaleString('en-IN', {
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
          });
          logListEl.innerHTML += `
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:0.8rem; line-height:1.4;">
              <div style="display:flex; justify-content:space-between; font-weight:700; color:#0f172a; margin-bottom:2px;">
                <span style="color:#2563eb; text-transform:uppercase; font-size:0.75rem;">${escapeHtml(l.action)}</span>
                <span style="font-size:0.7rem; color:#64748b; font-weight:600;">${timeStr}</span>
              </div>
              <div style="color:#475569;">${escapeHtml(l.details || '')}</div>
            </div>
          `;
        });
      } else {
        logListEl.innerHTML = `
          <div style="text-align:center; padding:30px; color:#94a3b8;">
            <i class="fas fa-inbox" style="font-size:2rem; margin-bottom:8px; display:block;"></i>
            No activity yet.
          </div>
        `;
      }

      switchEmpDetailTab('profile');
      openModal('employeeDetailsModal');
    }

    function switchEmpDetailTab(tab) {
      const isProfile = tab === 'profile';
      
      document.getElementById('empContentProfile').style.display = isProfile ? 'block' : 'none';
      document.getElementById('empContentActivity').style.display = isProfile ? 'none' : 'block';

      const tabProf = document.getElementById('tabEmpProfile');
      const tabAct = document.getElementById('tabEmpActivity');

      tabProf.style.color = isProfile ? '#2563eb' : '#64748b';
      tabProf.style.borderBottomColor = isProfile ? '#2563eb' : 'transparent';

      tabAct.style.color = isProfile ? '#64748b' : '#2563eb';
      tabAct.style.borderBottomColor = isProfile ? 'transparent' : '#2563eb';
    }

    function approveResetRequest(id) {
      showLoading(true);
      fetch(`?action=approve_reset&id=${id}`)
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Password reset approved successfully');
            loadEmployees();
          }
        });
    }

    function rejectResetRequest(id) {
      showLoading(true);
      fetch(`?action=reject_reset&id=${id}`)
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Reset request cancelled');
            loadEmployees();
          }
        });
    }

    // VIEW: SETTINGS
    function loadSettings() {
      showLoading(true);
      fetch('?action=get_settings')
        .then(res => {
          if (!res.ok) throw new Error(`Server error: ${res.status}`);
          return res.json();
        })
        .then(res => {
          showLoading(false);
          if (!res.success) {
            console.warn('Settings load failed:', res.error);
            return;
          }
          try {
            // Populate CRM Company Profile edit inputs
            const co = State.company || {};
            const nameInp   = document.getElementById('editCompanyName');
            const addrInp   = document.getElementById('editCompanyAddr');
            const mobileInp = document.getElementById('editCompanyMobile');
            const emailInp  = document.getElementById('editCompanyEmail');

            if (nameInp)   nameInp.value   = co.company_name    || '';
            if (addrInp)   addrInp.value   = co.company_address || '';
            if (mobileInp) mobileInp.value = co.company_mobile  || '';
            if (emailInp)  emailInp.value  = co.company_email   || '';

            const logoPreview = document.getElementById('editLogoPreview');
            if (logoPreview) {
              if (co.company_logo && co.company_logo !== 'default-logo.png') {
                logoPreview.innerHTML = `<img src="${co.company_logo}" style="width:100%; height:100%; object-fit:contain;">`;
                State.tempLogoData = co.company_logo;
              } else {
                logoPreview.innerHTML = `<i class="fas fa-image" style="color:#94a3b8; font-size:1.4rem;"></i>`;
                State.tempLogoData = 'default-logo.png';
              }
            }

            const settings = res.settings || {};
            const autoWA = document.getElementById('setAutoWA');
            if (autoWA) autoWA.checked = (settings.AutoWhatsApp === 'true');

            // Multi-Branch radio settings
            const multiBranchEnabled = (settings.MultiBranchEnabled === '1');
            const mbEn = document.getElementById('multiBranchEnabled');
            const mbDis = document.getElementById('multiBranchDisabled');
            if (mbEn)  mbEn.checked  = multiBranchEnabled;
            if (mbDis) mbDis.checked = !multiBranchEnabled;
            toggleMultiBranchSettings(multiBranchEnabled);

            const srcEl = document.getElementById('setSources');
            if (srcEl) srcEl.value = (res.sources || []).join('\n');
            renderSourcesTags();

            const tmplEl = document.getElementById('setTemplate');
            if (tmplEl) tmplEl.value = settings.VendorMessageTemplate || '';

            // Populate Network Info card
            const n = res.network || {};
            const ipEl   = document.getElementById('netInfoIP');
            const portEl = document.getElementById('netInfoPort');
            const urlEl  = document.getElementById('netInfoURL');
            // Fallback to current window host if network config not yet generated
            const fallbackHost = window.location.hostname;
            const fallbackPort = window.location.port || '8000';
            const fallbackURL  = `${window.location.protocol}//${fallbackHost}:${fallbackPort}`;
            if (ipEl)   ipEl.textContent   = n.ip   || fallbackHost;
            if (portEl) portEl.textContent = n.port || fallbackPort;
            if (urlEl)  urlEl.textContent  = n.url  || fallbackURL;
            State._networkURL = n.url || fallbackURL;
          } catch(e) {
            console.error('Error populating settings fields:', e);
          }
        })
        .catch(err => {
          showLoading(false);
          console.error('loadSettings failed:', err);
          // Show a non-blocking toast so the page is still usable
          if (window.Swal) {
            Swal.fire({ toast: true, icon: 'warning', title: 'Settings load failed — check server connection.', position: 'top-end', timer: 3500, showConfirmButton: false });
          }
        });
    }

    function copyNetworkURL() {
      const url = State._networkURL || document.getElementById('netInfoURL')?.textContent || '';
      if (!url || url === '—') { Swal.fire('Oops', 'Network URL abhi available nahi hai. Pehle run-app.bat se server start karein.', 'warning'); return; }
      navigator.clipboard.writeText(url).then(() => {
        Swal.fire({ icon: 'success', title: 'Copied!', text: `${url} — clipboard me copy ho gaya. Dusre devices ko share karein.`, timer: 2500, showConfirmButton: false });
      }).catch(() => {
        prompt('URL copy karein (Ctrl+C):', url);
      });
    }

    function renderSourcesTags() {
      const container = document.getElementById('sourcesTagContainer');
      if (!container) return;
      
      const txt = document.getElementById('setSources').value;
      const sources = txt.split('\n').map(s => s.trim()).filter(s => s);
      
      container.innerHTML = '';
      if (sources.length === 0) {
        container.innerHTML = '<span style="color:#94a3b8; font-size:0.8rem; font-style:italic;">No sources added yet.</span>';
        return;
      }
      
      sources.forEach((src, idx) => {
        const badge = document.createElement('span');
        badge.style.cssText = `
          display: inline-flex;
          align-items: center;
          gap: 6px;
          background: #e0f2fe;
          color: #0369a1;
          border: 1px solid #bae6fd;
          border-radius: 20px;
          padding: 4px 12px;
          font-size: 0.78rem;
          font-weight: 700;
        `;
        badge.innerHTML = `
          <span>${escapeHtml(src)}</span>
          <i class="fas fa-times-circle" style="cursor:pointer; color:#0284c7; transition:color 0.2s;" onclick="deleteSourceItem(${idx})" onmouseover="this.style.color='#b91c1c'" onmouseout="this.style.color='#0284c7'"></i>
        `;
        container.appendChild(badge);
      });
    }

    function addNewSourceItem() {
      const input = document.getElementById('newSourceInput');
      const value = input.value.trim();
      if (!value) return;
      
      const txtField = document.getElementById('setSources');
      const sources = txtField.value.split('\n').map(s => s.trim()).filter(s => s);
      
      // Prevent duplicates
      if (sources.some(s => s.toLowerCase() === value.toLowerCase())) {
        showToast('Source already exists!', 'warning');
        input.value = '';
        return;
      }
      
      sources.push(value);
      txtField.value = sources.join('\n');
      renderSourcesTags();
      input.value = '';
      input.focus();
    }

    function deleteSourceItem(index) {
      const txtField = document.getElementById('setSources');
      let sources = txtField.value.split('\n').map(s => s.trim()).filter(s => s);
      
      sources.splice(index, 1);
      txtField.value = sources.join('\n');
      renderSourcesTags();
    }

    function saveBrandingSettings() {
      const name = document.getElementById('editCompanyName').value.trim();
      const addr = document.getElementById('editCompanyAddr').value.trim();
      const mobile = document.getElementById('editCompanyMobile').value.trim();
      const email = document.getElementById('editCompanyEmail').value.trim();
      const logo = State.tempLogoData || 'default-logo.png';
      const autoWA = document.getElementById('setAutoWA').checked ? 'true' : 'false';
      const multiBranch = document.getElementById('multiBranchEnabled').checked ? '1' : '0';

      if (!name) {
        showToast('Company Name is required', 'error');
        return;
      }

      const fd = new FormData();
      fd.append('action', 'save_branding');
      fd.append('company_name', name);
      fd.append('company_address', addr);
      fd.append('company_mobile', mobile);
      fd.append('company_email', email);
      fd.append('company_logo', logo);
      fd.append('auto_whatsapp', autoWA);
      fd.append('multi_branch_enabled', multiBranch);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Agency settings updated');
            // Update local State so UI updates instantly
            State.company.company_name = name;
            State.company.company_address = addr;
            State.company.company_mobile = mobile;
            State.company.company_email = email;
            State.company.company_logo = logo;
            State.config.MultiBranchEnabled = multiBranch;
            
            // Re-apply sidebar brand and logo
            document.querySelectorAll('.sidebar-brand').forEach(el => el.innerText = name);
            const sidebarLogo = document.querySelector('.sidebar-logo-container');
            if (sidebarLogo) {
              sidebarLogo.innerHTML = logo !== 'default-logo.png'
                ? `<img src="${logo}" alt="Logo">`
                : `<i class="fas fa-gas-pump"></i>`;
            }
            loadSystemData();
          }
        });
    }

    function previewLogo(e) {
      const file = e.target.files[0];
      if (!file) return;
      if (file.size > 2 * 1024 * 1024) {
        showToast('Logo file must be under 2MB', 'error');
        return;
      }
      const reader = new FileReader();
      reader.onload = function(evt) {
        const base64 = evt.target.result;
        State.tempLogoData = base64;
        const logoPreview = document.getElementById('editLogoPreview');
        if (logoPreview) {
          logoPreview.innerHTML = `<img src="${base64}" style="width:100%; height:100%; object-fit:contain;">`;
        }
      };
      reader.readAsDataURL(file);
    }

    function clearLogoPreview() {
      State.tempLogoData = 'default-logo.png';
      const logoPreview = document.getElementById('editLogoPreview');
      if (logoPreview) {
        logoPreview.innerHTML = `<i class="fas fa-image" style="color:#94a3b8; font-size:1.4rem;"></i>`;
      }
      document.getElementById('uploadCompanyLogo').value = '';
    }

    function saveSourcesSettings() {
      const sources = document.getElementById('setSources').value.split('\n').map(s => s.trim()).filter(s => s);

      const fd = new FormData();
      fd.append('action', 'save_sources');
      fd.append('sources', sources.join(','));

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Complaint sources updated');
            loadSystemData();
          }
        });
    }

    function saveTemplateSettings() {
      const tmpl = document.getElementById('setTemplate').value.trim();

      const fd = new FormData();
      fd.append('action', 'save_template');
      fd.append('template', tmpl);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('WhatsApp template saved');
          }
        });
    }

    // AI Translation Assistant (English to Hindi & Hindi to English)
    function aiTranslateText(from, to) {
      const textarea = document.getElementById('c_comp');
      const text = textarea.value.trim();
      if (!text) {
        showToast('Please type some details first!', 'warning');
        return;
      }

      // Show processing state in badge
      const badge = document.getElementById('aiAssistBadge');
      const originalBadgeHTML = badge.innerHTML;
      badge.innerHTML = `<i class="fas fa-spinner fa-spin"></i> AI Translating...`;
      
      const langpair = `${from}|${to}`;
      fetch(`https://api.mymemory.translated.net/get?q=${encodeURIComponent(text)}&langpair=${langpair}`)
        .then(res => res.json())
        .then(data => {
          badge.innerHTML = originalBadgeHTML;
          if (data && data.responseData && data.responseData.translatedText) {
            textarea.value = data.responseData.translatedText;
            showToast('Text translated successfully!', 'success');
          } else {
            showToast('Translation service busy. Try again later.', 'error');
          }
        })
        .catch(err => {
          badge.innerHTML = originalBadgeHTML;
          console.error(err);
          showToast('Translation error occurred.', 'error');
        });
    }

    // AI Polish/Improve Text Shorthand
    function aiImproveText() {
      const textarea = document.getElementById('c_comp');
      const text = textarea.value.trim();
      if (!text) {
        showToast('Please type some details first!', 'warning');
        return;
      }

      const badge = document.getElementById('aiAssistBadge');
      const originalBadgeHTML = badge.innerHTML;
      badge.innerHTML = `<i class="fas fa-spinner fa-spin"></i> AI Improving...`;

      // Simulate network delay for AI thinking feel
      setTimeout(() => {
        badge.innerHTML = originalBadgeHTML;
        let improved = text;

        // Smart rules dictionary for Gas Agency logs
        const rules = [
          { keywords: ['leak', 'leakage', 'gas smell', 'smell', 'leakage problem'], replace: 'Urgent attention required: The customer reported a gas leakage issue. Immediate technical inspection and regulator valve replacement advised.' },
          { keywords: ['delivery', 'delay', 'booking', 'not delivered', 'late'], replace: 'Delivery scheduling conflict: The customer reported a delay in their gas cylinder delivery. Requesting immediate status check and expedited dispatch.' },
          { keywords: ['regulator', 'valve', 'pipe', 'hose', 'kharab'], replace: 'Equipment safety check: Consumer reported a defective regulator / connection hose issue. Requesting emergency technician visit for parts check.' },
          { keywords: ['bill', 'price', 'payment', 'extra money', 'charge'], replace: 'Billing dispute log: The customer requested audit check regarding booking charges. Price mismatch or voucher discount failed.' },
          { keywords: ['double', 'connection', 'dbc', '2 cylinder'], replace: 'Service upgrade query: Customer requested information or conversion of single bottle connection to Double Bottle Connection (DBC).' }
        ];

        // Find match
        const lower = text.toLowerCase();
        let matched = false;
        for (const rule of rules) {
          if (rule.keywords.some(kw => lower.includes(kw))) {
            improved = rule.replace;
            matched = true;
            break;
          }
        }

        if (!matched) {
          // If no specific match, write a formal polish wrapper
          improved = 'Service ticket logged: ' + text.charAt(0).toUpperCase() + text.slice(1) + '. Please coordinate with agency personnel to dispatch assistance.';
        }

        textarea.value = improved;
        showToast('Text polished into professional tone!', 'success');
      }, 700);
    }

    function triggerLogsCleanup() {
      Swal.fire({
        title: 'Delete System Logs?',
        text: 'This will clear the entire log audit trail history database.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Wipe Logs'
      }).then(r => {
        if (r.isConfirmed) {
          showLoading(true);
          fetch('?action=clear_logs')
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast(`Wiped ${res.count} log logs successfully`);
              }
            });
        }
      });
    }

    // ============ CONSUMER REGISTRY MODULE ============
    let consumerPage = 1;
    let consumerSearchVal = '';
    let consumerPreviewRows = [];
    let consumerFilterField = 'total';
    let consumerFilterVal = '';

    function loadConsumers(page = 1) {
      consumerPage = page;
      const searchInput = document.getElementById('consumerSearch');
      const search = searchInput ? searchInput.value.trim() : '';
      consumerSearchVal = search;

      // Fetch dashboard statistics
      fetch('?action=get_consumer_stats')
        .then(res => res.json())
        .then(res => {
          if (res.success) {
            if (document.getElementById('consStatTotal')) document.getElementById('consStatTotal').innerText = res.total || 0;
            if (document.getElementById('consStatDBC')) document.getElementById('consStatDBC').innerText = res.dbc || 0;
            if (document.getElementById('consStatSBC')) document.getElementById('consStatSBC').innerText = res.sbc || 0;
            if (document.getElementById('consStatKYCComplete')) document.getElementById('consStatKYCComplete').innerText = res.ekyc_completed || 0;
            if (document.getElementById('consStatKYCPending')) document.getElementById('consStatKYCPending').innerText = res.ekyc_pending || 0;
            if (document.getElementById('consStatBlocked')) document.getElementById('consStatBlocked').innerText = res.blocked || 0;
          }
        });

      // Fetch registry table rows with active card filters
      fetch(`?action=get_consumers&search=${encodeURIComponent(search)}&page=${page}&filter_field=${consumerFilterField}&filter_val=${encodeURIComponent(consumerFilterVal)}`)
        .then(res => res.json())
        .then(res => {
          if (res.success) {
            renderConsumersTable(res);
          }
        });
    }

    function setConsumerFilter(field, val) {
      consumerFilterField = field;
      consumerFilterVal = val;
      
      // Remove active filter outline from all stats cards
      document.querySelectorAll('#view-consumers .stat-card').forEach(card => {
        card.classList.remove('active-filter');
      });
      
      // Highlight clicked card
      let cardId = '';
      if (field === 'total') cardId = 'cardFilterTotal';
      else if (field === 'connection_type' && val === 'DBC') cardId = 'cardFilterDBC';
      else if (field === 'connection_type' && val === 'SBC') cardId = 'cardFilterSBC';
      else if (field === 'ekyc_status' && val === 'Completed') cardId = 'cardFilterKYCComplete';
      else if (field === 'ekyc_status' && val === 'Pending') cardId = 'cardFilterKYCPending';
      else if (field === 'status' && val === 'Blocked') cardId = 'cardFilterBlocked';

      const card = document.getElementById(cardId);
      if (card) {
        card.classList.add('active-filter');
      }

      loadConsumers(1);
    }

    let consumerSearchTimeout = null;
    function debouncedConsumerSearch() {
      clearTimeout(consumerSearchTimeout);
      consumerSearchTimeout = setTimeout(() => {
        loadConsumers(1);
      }, 300);
    }

    function renderConsumersTable(res) {
      const tbody = document.getElementById('consumerListBody');
      if (!tbody) return;
      tbody.innerHTML = '';
      
      const badge = document.getElementById('consumerTotalBadge');
      if (badge) badge.innerText = `${res.total} Consumers`;

      const isAdmin = State.user.role === 'Admin';
      
      // Hide upload card for non-admins
      const uploadCard = document.getElementById('consumerUploadCard');
      if (uploadCard) {
        uploadCard.style.display = isAdmin ? 'block' : 'none';
      }

      // Hide/Show Action Column Header
      const actionHeaders = document.querySelectorAll('#view-consumers table thead th:last-child');
      actionHeaders.forEach(el => {
        el.style.display = isAdmin ? 'table-cell' : 'none';
      });

      if (!res.rows || res.rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${isAdmin ? 10 : 9}" style="text-align:center;padding:3rem;color:var(--text-muted);"><i class="fas fa-users" style="font-size:2rem;margin-bottom:1rem;display:block;"></i>No consumers imported yet.</td></tr>`;
        const pgInfo = document.getElementById('consumerPgInfo');
        if (pgInfo) pgInfo.innerText = '0 of 0';
        const pgControls = document.getElementById('consumerPgControls');
        if (pgControls) pgControls.innerHTML = '';
        return;
      }

      res.rows.forEach((r, i) => {
        const tr = document.createElement('tr');
        const index = (res.page - 1) * 100 + i + 1;
        
        // Format connection badge
        let connBadge = '<span class="badge badge-secondary" style="background:#f1f5f9;color:#475569;">SBC</span>';
        if (r.connection_type && r.connection_type.toUpperCase().includes('DBC')) {
          connBadge = '<span class="badge badge-primary" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;font-weight:700;">DBC</span>';
        } else if (r.connection_type && r.connection_type.toUpperCase().includes('SBC')) {
          connBadge = '<span class="badge badge-secondary" style="background:#ecfeff;color:#0891b2;border:1px solid #cffafe;font-weight:700;">SBC</span>';
        } else if (r.connection_type) {
          connBadge = `<span class="badge" style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;">${escapeHtml(r.connection_type)}</span>`;
        }

        // Format status badge
        let statusBadge = '<span class="badge badge-secondary" style="background:#f1f5f9;color:#64748b;">N/A</span>';
        if (r.status && r.status.toUpperCase().includes('ACTIVE')) {
          statusBadge = '<span class="badge badge-success" style="background:#f0fdf4;color:#16a34a;border:1px solid #dcfce7;font-weight:700;">Active</span>';
        } else if (r.status) {
          statusBadge = `<span class="badge" style="background:#fef2f2;color:#dc2626;border:1px solid #fee2e2;font-weight:700;">${escapeHtml(r.status)}</span>`;
        }

        // Format E-KYC badge (Y/Completed vs N/Pending)
        let kycBadge = '<span class="badge badge-secondary" style="background:#f1f5f9;color:#64748b;">Pending</span>';
        if (r.ekyc_status && (r.ekyc_status.toUpperCase() === 'Y' || r.ekyc_status.toUpperCase() === 'YES' || r.ekyc_status.toUpperCase() === 'COMPLETED' || r.ekyc_status.toUpperCase() === 'COMPLETE')) {
          kycBadge = '<span class="badge badge-success" style="background:#f0fdf4;color:#16a34a;border:1px solid #dcfce7;font-weight:700;">Completed</span>';
        } else if (r.ekyc_status && (r.ekyc_status.toUpperCase() === 'N' || r.ekyc_status.toUpperCase() === 'NO' || r.ekyc_status.toUpperCase() === 'PENDING')) {
          kycBadge = '<span class="badge badge-warning" style="background:#fffbeb;color:#d97706;border:1px solid #fef3c7;font-weight:700;">Pending</span>';
        } else if (r.ekyc_status) {
          kycBadge = `<span class="badge" style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;">${escapeHtml(r.ekyc_status)}</span>`;
        }

        let actionTd = '';
        if (isAdmin) {
          actionTd = `
            <td style="text-align:center;">
              <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;padding:4px 8px;font-weight:700;" onclick="deleteConsumer(${r.id})">
                <i class="fas fa-trash"></i> Remove
              </button>
            </td>
          `;
        }

        tr.innerHTML = `
          <td style="font-weight:700;color:var(--text-muted);">${index}</td>
          <td style="font-weight:700;color:var(--primary);">${escapeHtml(r.consumer_number || 'N/A')}</td>
          <td style="font-weight:800;color:#0f172a;">${escapeHtml(r.consumer_name)}</td>
          <td style="font-weight:700;">${escapeHtml(r.mobile || 'N/A')}</td>
          <td style="text-align:center;">${connBadge}</td>
          <td style="text-align:center;">${statusBadge}</td>
          <td style="text-align:center;">${kycBadge}</td>
          <td><span class="badge badge-secondary" style="background:#fff7ed;color:#c2410c;border:1px solid #ffedd5;">${escapeHtml(r.area || 'N/A')}</span></td>
          <td style="max-width:250px;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;" title="${escapeHtml(r.address)}">${escapeHtml(r.address || 'N/A')}</td>
          ${actionTd}
        `;
        tbody.appendChild(tr);
      });

      // Pagination
      const totalPages = Math.ceil(res.total / 100);
      const pgInfo = document.getElementById('consumerPgInfo');
      if (pgInfo) pgInfo.innerText = `Showing ${(res.page-1)*100+1} - ${Math.min(res.page*100, res.total)} of ${res.total}`;
      
      const pc = document.getElementById('consumerPgControls');
      if (pc) {
        pc.innerHTML = '';
        if (totalPages > 1) {
          if (res.page > 1) {
            pc.innerHTML += `<button class="btn btn-outline btn-sm" onclick="loadConsumers(${res.page - 1})"><i class="fas fa-chevron-left"></i></button>`;
          }
          pc.innerHTML += `<span style="font-size:0.8rem;margin:0 10px;align-self:center;">${res.page}/${totalPages}</span>`;
          if (res.page < totalPages) {
            pc.innerHTML += `<button class="btn btn-outline btn-sm" onclick="loadConsumers(${res.page + 1})"><i class="fas fa-chevron-right"></i></button>`;
          }
        }
      }
    }

    function deleteConsumer(id) {
      Swal.fire({
        title: 'Remove Consumer?',
        text: 'Are you sure you want to remove this consumer profile?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Remove'
      }).then(r => {
        if (r.isConfirmed) {
          showLoading(true);
          fetch(`?action=delete_consumer&id=${id}`)
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast('Consumer profile removed', 'success');
                loadConsumers(consumerPage);
              }
            });
        }
      });
    }

    function clearAllConsumers() {
      Swal.fire({
        title: 'Clear Consumer Registry?',
        text: 'This will remove all uploaded consumers from the system.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Wipe Registry',
        confirmButtonColor: '#ef4444'
      }).then(r => {
        if (r.isConfirmed) {
          showLoading(true);
          fetch('?action=clear_consumers')
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast('All consumers cleared successfully', 'success');
                loadConsumers(1);
              }
            });
        }
      });
    }

    // Drag-Drop and Select Excel/CSV processing
    function handleConsumerFileSelect(e) {
      const file = e.target.files[0];
      if (file) processConsumerFile(file);
    }

    function handleConsumerFileDrop(e) {
      e.preventDefault();
      const dropZone = document.getElementById('consumerDropZone');
      if (dropZone) {
        dropZone.style.borderColor = '#e2e8f0';
        dropZone.style.background = '#fafafa';
      }
      const file = e.dataTransfer.files[0];
      if (file) processConsumerFile(file);
    }

    function processConsumerFile(file) {
      const reader = new FileReader();
      reader.onload = function(e) {
        try {
          const data = new Uint8Array(e.target.result);
          const workbook = XLSX.read(data, { type: 'array' });
          const firstSheetName = workbook.SheetNames[0];
          const worksheet = workbook.Sheets[firstSheetName];
          const json = XLSX.utils.sheet_to_json(worksheet);
          
          if (json.length === 0) {
            showToast('File contains no records', 'warning');
            return;
          }
          
          consumerPreviewRows = json;
          showConsumerPreview();
        } catch (err) {
          console.error(err);
          showToast('Error parsing file. Ensure it is a valid Excel or CSV.', 'error');
        }
      };
      reader.readAsArrayBuffer(file);
    }

    function showConsumerPreview() {
      const sect = document.getElementById('consumerPreviewSection');
      if (!sect) return;
      sect.style.display = 'block';
      
      const countLabel = document.getElementById('previewCountLabel');
      if (countLabel) countLabel.innerText = consumerPreviewRows.length;
      
      const head = document.getElementById('previewTableHead');
      const body = document.getElementById('previewTableBody');
      if (!head || !body) return;
      head.innerHTML = '';
      body.innerHTML = '';

      if (consumerPreviewRows.length === 0) return;

      // Detect headers
      const sample = consumerPreviewRows[0];
      const keys = Object.keys(sample);
      
      let headerTr = document.createElement('tr');
      headerTr.style.background = '#f1f5f9';
      keys.forEach(k => {
        headerTr.innerHTML += `<th style="padding:8px;font-weight:700;text-align:left;">${escapeHtml(k)}</th>`;
      });
      head.appendChild(headerTr);

      // Render first 10 preview rows
      consumerPreviewRows.slice(0, 10).forEach(r => {
        let tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #e2e8f0';
        keys.forEach(k => {
          let val = r[k];
          if (val === undefined || val === null || Number.isNaN(val) || String(val).trim().toLowerCase() === 'nan' || String(val).trim().toLowerCase() === 'null') {
            val = '';
          }
          tr.innerHTML += `<td style="padding:8px;">${escapeHtml(String(val))}</td>`;
        });
        body.appendChild(tr);
      });
      
      if (consumerPreviewRows.length > 10) {
        let tr = document.createElement('tr');
        tr.innerHTML = `<td colspan="${keys.length}" style="text-align:center;padding:8px;color:var(--text-muted);font-style:italic;">...and ${consumerPreviewRows.length - 10} more rows</td>`;
        body.appendChild(tr);
      }
    }

    function cancelConsumerImport() {
      consumerPreviewRows = [];
      const sect = document.getElementById('consumerPreviewSection');
      if (sect) sect.style.display = 'none';
      const input = document.getElementById('consumerFileInput');
      if (input) input.value = '';
    }

    function confirmConsumerImport() {
      if (consumerPreviewRows.length === 0) return;
      showLoading(true);
      fetch('?action=import_consumers', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(consumerPreviewRows)
      })
      .then(res => res.json())
      .then(res => {
        showLoading(false);
        if (res.success) {
          showToast(`Successfully uploaded ${res.imported} consumers!`, 'success');
          cancelConsumerImport();
          loadConsumers(1);
        } else {
          showToast(res.error || 'Failed to upload data', 'error');
        }
      });
    }

    // Auto-fill logic when consumer number or mobile number is entered
    function lookupConsumer(field) {
      let val = '';
      if (field === 'no') {
        val = document.getElementById('c_no').value.trim();
      } else if (field === 'mob') {
        val = document.getElementById('c_mob').value.trim();
      }
      if (!val) return;

      // Skip if editing existing complaint
      if (document.getElementById('c_id').value) return;

      fetch(`?action=get_consumer_by_mobile_or_number&query=${encodeURIComponent(val)}`)
        .then(res => res.json())
        .then(res => {
          if (res.success && res.consumer) {
            const cons = res.consumer;
            if (cons.consumer_number) document.getElementById('c_no').value = cons.consumer_number;
            if (cons.consumer_name) document.getElementById('c_name').value = cons.consumer_name;
            if (cons.mobile) document.getElementById('c_mob').value = cons.mobile;
            if (cons.address) {
              let fullAddr = cons.address;
              if (cons.area) {
                fullAddr += (fullAddr.endsWith(',') || fullAddr.endsWith(' ') ? '' : ', ') + cons.area;
              }
              document.getElementById('c_addr').value = fullAddr;
            }
            showToast('Consumer details autofilled!', 'success');
          }
          // Always run consumer history checks to warn operator of prior complaints
          checkConsumerHistory();
        })
        .catch(() => {
          // Fallback to history check on failure
          checkConsumerHistory();
        });
    }

    function openMyProfileModal() {
      // Re-populate values
      document.getElementById('my_profile_name').value = State.user.name || '';
      document.getElementById('my_profile_mobile').value = State.user.mobile || '';
      document.getElementById('my_profile_pw').value = '';
      
      const logoPreview = document.getElementById('myProfileLogoPreview');
      if (logoPreview) {
        if (State.tempMyProfilePhotoData && State.tempMyProfilePhotoData !== 'default-photo.png') {
          logoPreview.innerHTML = `<img src="${State.tempMyProfilePhotoData}" style="width:100%; height:100%; object-fit:cover;">`;
        } else {
          logoPreview.innerHTML = `<i class="fas fa-user" style="color:#94a3b8; font-size:1.6rem;"></i>`;
        }
      }
      openModal('myProfileModal');
    }

    function previewMyProfilePhoto(e) {
      const file = e.target.files[0];
      if (!file) return;
      if (file.size > 2 * 1024 * 1024) {
        showToast('Photo file must be under 2MB', 'error');
        return;
      }
      const reader = new FileReader();
      reader.onload = function(evt) {
        const base64 = evt.target.result;
        State.tempMyProfilePhotoData = base64;
        const logoPreview = document.getElementById('myProfileLogoPreview');
        if (logoPreview) {
          logoPreview.innerHTML = `<img src="${base64}" style="width:100%; height:100%; object-fit:cover;">`;
        }
      };
      reader.readAsDataURL(file);
    }

    function clearMyProfilePhoto() {
      State.tempMyProfilePhotoData = 'default-photo.png';
      const logoPreview = document.getElementById('myProfileLogoPreview');
      if (logoPreview) {
        logoPreview.innerHTML = `<i class="fas fa-user" style="color:#94a3b8; font-size:1.6rem;"></i>`;
      }
      document.getElementById('uploadMyProfilePhoto').value = '';
    }

    function submitMyProfileForm() {
      const name = document.getElementById('my_profile_name').value.trim();
      const mobile = document.getElementById('my_profile_mobile').value.trim();
      const pw = document.getElementById('my_profile_pw').value;
      const photo = State.tempMyProfilePhotoData || 'default-photo.png';

      if (!name) {
        showToast('Name is required', 'warning');
        return;
      }

      const fd = new FormData();
      fd.append('action', 'update_my_profile');
      fd.append('name', name);
      fd.append('mobile', mobile);
      fd.append('password', pw);
      fd.append('profile_photo', photo);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Profile updated successfully!', 'success');
            closeModal('myProfileModal');
            window.location.reload();
          } else {
            showToast(res.error || 'Failed to update profile', 'error');
          }
        })
        .catch(err => {
          showLoading(false);
          showToast('Failed to update: network or server issue', 'error');
        });
    }

    function updateLiveClock() {
      const now = new Date();
      
      // Format time: HH:MM:SS AM/PM
      let hours = now.getHours();
      const minutes = String(now.getMinutes()).padStart(2, '0');
      const seconds = String(now.getSeconds()).padStart(2, '0');
      const ampm = hours >= 12 ? 'PM' : 'AM';
      hours = hours % 12;
      hours = hours ? hours : 12; // the hour '0' should be '12'
      const timeStr = `${String(hours).padStart(2, '0')}:${minutes}:${seconds} ${ampm}`;
      
      // Format date: DayOfWeek, DD Month YYYY
      const options = { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' };
      const dateStr = now.toLocaleDateString('en-IN', options);
      
      // Determine greeting and icon based on hour (24-hour format)
      const currentHour = now.getHours();
      let greeting = 'Good Morning';
      let iconClass = 'fa-sun';
      let iconColor = '#f59e0b';
      
      if (currentHour >= 12 && currentHour < 17) {
        greeting = 'Good Afternoon';
        iconClass = 'fa-cloud-sun';
        iconColor = '#ea580c';
      } else if (currentHour >= 17 && currentHour < 21) {
        greeting = 'Good Evening';
        iconClass = 'fa-moon';
        iconColor = '#6366f1';
      } else if (currentHour >= 21 || currentHour < 5) {
        greeting = 'Good Night';
        iconClass = 'fa-star';
        iconColor = '#475569';
      }
      
      const greetingEl = document.getElementById('greetingText');
      const iconEl = document.getElementById('greetingIcon');
      const timeEl = document.getElementById('clockTime');
      const dateEl = document.getElementById('clockDate');
      
      if (greetingEl) greetingEl.innerText = greeting;
      if (iconEl) {
        iconEl.className = `fas ${iconClass}`;
        iconEl.style.color = iconColor;
      }
      if (timeEl) timeEl.innerText = timeStr;
      if (dateEl) dateEl.innerText = dateStr;
    }

    function toggleMultiBranchSettings(enabled) {
      const card = document.getElementById('branchManagementCard');
      if (card) {
        card.style.display = enabled ? 'block' : 'none';
      }
      if (enabled) {
        loadBranches();
      }
    }

    let StateBranches = [];

    function loadBranches() {
      fetch('?action=get_branches')
        .then(res => res.json())
        .then(res => {
          if (res.success) {
            StateBranches = res.branches;
            renderBranchesTable();
          }
        });
    }

    function renderBranchesTable() {
      const tbody = document.getElementById('branchListBody');
      if (!tbody) return;
      tbody.innerHTML = '';
      
      if (StateBranches.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted);">No branches registered yet. Click "Add Branch" to start.</td></tr>';
        return;
      }

      StateBranches.forEach(b => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #e2e8f0';
        tr.innerHTML = `
          <td style="padding:10px;font-weight:700;color:var(--text-muted);">${b.id}</td>
          <td style="padding:10px;font-weight:800;color:#0f172a;">${escapeHtml(b.name)}</td>
          <td style="padding:10px;"><span class="badge badge-secondary" style="font-weight:700;">${escapeHtml(b.code)}</span></td>
          <td style="padding:10px;text-align:center;"><span class="badge badge-primary" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;font-weight:700;">${escapeHtml(b.brand)} Gas</span></td>
          <td style="padding:10px;font-size:0.8rem;color:var(--text-muted);">${escapeHtml(b.address || 'N/A')}</td>
          <td style="padding:10px;font-weight:700;">${escapeHtml(b.mobile || 'N/A')}</td>
          <td style="padding:10px;text-align:center;">
            <button class="btn btn-sm btn-outline" style="padding:2px 6px;margin-right:4px;" onclick="openEditBranchModal(${b.id})"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;padding:2px 6px;" onclick="deleteBranch(${b.id})"><i class="fas fa-trash"></i></button>
          </td>
        `;
        tbody.appendChild(tr);
      });
    }

    function openAddBranchModal() {
      document.getElementById('branchModalTitle').innerText = 'Add Branch';
      document.getElementById('br_id').value = '';
      document.getElementById('br_name').value = '';
      document.getElementById('br_code').value = '';
      document.getElementById('br_brand').value = 'HP';
      document.getElementById('br_mobile').value = '';
      document.getElementById('br_address').value = '';
      openModal('branchModal');
    }

    function openEditBranchModal(id) {
      const b = StateBranches.find(x => x.id === id);
      if (!b) return;

      document.getElementById('branchModalTitle').innerText = 'Edit Branch';
      document.getElementById('br_id').value = b.id;
      document.getElementById('br_name').value = b.name;
      document.getElementById('br_code').value = b.code;
      document.getElementById('br_brand').value = b.brand;
      document.getElementById('br_mobile').value = b.mobile || '';
      document.getElementById('br_address').value = b.address || '';
      openModal('branchModal');
    }

    function submitBranchForm() {
      const id = document.getElementById('br_id').value;
      const name = document.getElementById('br_name').value.trim();
      const code = document.getElementById('br_code').value.trim();
      const brand = document.getElementById('br_brand').value;
      const mobile = document.getElementById('br_mobile').value.trim();
      const address = document.getElementById('br_address').value.trim();

      if (!name || !code) {
        showToast('Branch Name and Code are required', 'warning');
        return;
      }

      const fd = new FormData();
      fd.append('action', 'add_branch');
      fd.append('id', id);
      fd.append('name', name);
      fd.append('code', code);
      fd.append('brand', brand);
      fd.append('mobile', mobile);
      fd.append('address', address);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Branch saved successfully!', 'success');
            closeModal('branchModal');
            loadBranches();
            if (typeof loadSystemData === 'function') loadSystemData();
          } else {
            showToast(res.error || 'Failed to save branch', 'error');
          }
        });
    }

    function deleteBranch(id) {
      Swal.fire({
        title: 'Are you sure?',
        text: "Deleting a branch will re-assign all of its users and complaints to another branch. This cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
      }).then((result) => {
        if (result.isConfirmed) {
          const fd = new FormData();
          fd.append('action', 'delete_branch');
          fd.append('id', id);

          showLoading(true);
          fetch('?', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(res => {
              showLoading(false);
              if (res.success) {
                showToast('Branch deleted and records migrated!', 'success');
                loadBranches();
                if (typeof loadSystemData === 'function') loadSystemData();
              } else {
                showToast(res.error || 'Failed to delete branch', 'error');
              }
            });
        }
      });
    }

    function setupBranchSelectors() {
      const isMulti = State.config.MultiBranchEnabled === '1';
      const isAdmin = State.user.role === 'Admin';
      
      const headerSel = document.getElementById('headerBranchSelector');
      if (headerSel) {
        if (isMulti && isAdmin) {
          headerSel.style.display = 'block';
          headerSel.innerHTML = '<option value="0">All Branches (Admin)</option>';
          State.branches.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.innerText = `${b.name} (${b.brand})`;
            if (String(b.id) === String(State.active_branch_id)) {
              opt.selected = true;
            }
            headerSel.appendChild(opt);
          });
        } else {
          headerSel.style.display = 'none';
        }
      }

      // Employee modal branch assignment dropdown
      const empBranchBlock = document.getElementById('empBranchSelectBlock');
      const empBranchSel = document.getElementById('u_branch_id');
      if (empBranchSel && empBranchBlock) {
        if (isMulti) {
          empBranchBlock.style.display = 'block';
          empBranchSel.innerHTML = '';
          State.branches.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.innerText = `${b.name} (${b.brand})`;
            empBranchSel.appendChild(opt);
          });
        } else {
          empBranchBlock.style.display = 'none';
        }
      }
    }

    function changeActiveBranch(val) {
      const fd = new FormData();
      fd.append('action', 'set_active_branch');
      fd.append('branch_id', val);

      showLoading(true);
      fetch('?', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
          showLoading(false);
          if (res.success) {
            showToast('Branch scope updated!', 'success');
            loadSystemData();
            if (State.currentView === 'consumers') {
              loadConsumers(1);
              loadConsumerStats();
            } else if (State.currentView === 'history') {
              loadHistory();
            } else if (State.currentView === 'analytics') {
              loadAnalytics();
            }
          } else {
            showToast(res.error || 'Failed to change branch scope', 'error');
          }
        });
    }

    // Escape HTML strings to prevent cross site scripting (XSS)
    function escapeHtml(text) {
      if (text === null || text === undefined) return '';
      const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
      return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
  </script>
</body>
</html>

