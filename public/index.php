<?php
/**
 * Amnezia VPN Web Panel
 * Main entry point
 */

session_name(getenv('SESSION_NAME') ?: 'amnezia_panel_session');
session_start();

// Standardize PHP timezone to UTC
date_default_timezone_set('UTC');

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Auth.php';
require_once __DIR__ . '/../inc/Router.php';
require_once __DIR__ . '/../inc/View.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';
require_once __DIR__ . '/../inc/Translator.php';
require_once __DIR__ . '/../inc/JWT.php';
require_once __DIR__ . '/../inc/PanelImporter.php';
require_once __DIR__ . '/../inc/ServerMonitoring.php';
require_once __DIR__ . '/../inc/GeoIP.php';
require_once __DIR__ . '/../inc/ExtDB.php';
require_once __DIR__ . '/../inc/BackupManager.php';

// Load environment configuration
Config::load(__DIR__ . '/../.env');

// Test database connection
try {
    DB::conn();
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
}

// Seed admin user if not exists
try {
    $adminEmail = Config::get('ADMIN_EMAIL');
    $adminPass = Config::get('ADMIN_PASSWORD');
    if ($adminEmail && $adminPass) {
        Auth::seedAdmin($adminEmail, $adminPass);
    }
} catch (Throwable $e) {
    // Ignore errors
}

// Initialize translator
Translator::init();

// Initialize template engine
$user = Auth::user();
$appName = Config::get('APP_NAME', 'Nk-VPN Panel');

/**
 * Helper function to authenticate user from JWT or session
 * Returns user array or null if unauthorized
 */
function authenticateRequest(): ?array {
    // Check JWT token in Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
        $user = JWT::verify($token);
        if ($user) {
            return $user;
        }
    }
    
    // Fallback to session
    if (isset($_SESSION['user_id'])) {
        return Auth::user();
    }
    
    return null;
}

View::init(__DIR__ . '/../templates', [
    'app_name' => $appName,
    'user' => $user,
    'current_language' => Translator::getCurrentLanguage(),
    'languages' => Translator::getSupportedLanguages(),
    'current_uri' => $_SERVER['REQUEST_URI'] ?? '/dashboard',
    't' => function($key, $params = []) {
        return Translator::t($key, $params);
    }
]);

// Helper function for redirects
function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

function isJsonRequest(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    return stripos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';
}

// Helper function to require authentication
function requireAuth(): void {
    if (!Auth::check()) {
        if (isJsonRequest()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        redirect('/login');
    }
}

// Helper function to require admin
function requireAdmin(): void {
    requireAuth();
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo 'Forbidden: Admin access required';
        exit;
    }
}

// Helper function to get authenticated user (JWT or session)
function getAuthUser(): ?array {
    // Try JWT first
    $token = JWT::getTokenFromHeader();
    if ($token !== null) {
        $user = JWT::verify($token);
        if ($user !== null) {
            return $user;
        }
    }
    
    // Fall back to session
    if (Auth::check()) {
        return Auth::user();
    }
    
    return null;
}

// Helper function to require authentication (JWT or session) for API
function requireApiAuth(): ?array {
    $user = getAuthUser();
    
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        return null;
    }
    
    return $user;
}

/**
 * PUBLIC ROUTES
 */

// Home page
Router::get('/', function () {
    if (!Auth::check()) {
        redirect('/login');
    }
    redirect('/dashboard');
});

// Login page
Router::get('/login', function () {
    if (Auth::check()) {
        redirect('/dashboard');
    }
    View::render('login.twig');
});

Router::post('/login', function () {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (Auth::login($email, $password)) {
        redirect('/dashboard');
    }
    
    View::render('login.twig', ['error' => 'Invalid credentials']);
});

// Register page
Router::get('/register', function () {
    if (Auth::check()) {
        redirect('/dashboard');
    }
    View::render('register.twig');
});

Router::post('/register', function () {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        View::render('register.twig', ['error' => 'Invalid email address']);
        return;
    }
    
    if (strlen($password) < 6) {
        View::render('register.twig', ['error' => 'Password must be at least 6 characters']);
        return;
    }
    
    try {
        $success = Auth::register($name, $email, $password);
        if ($success) {
            Auth::login($email, $password);
            redirect('/dashboard');
        }
    } catch (Throwable $e) {
        // Email already exists or other error
    }
    
    View::render('register.twig', ['error' => 'Registration failed. Email may already be in use.']);
});

// Logout
Router::get('/logout', function () {
    Auth::logout();
    redirect('/login');
});

/**
 * AUTHENTICATED ROUTES
 */

// Dashboard
Router::get('/dashboard', function () {
    requireAuth();
    $user = Auth::user();
    
    // Get user's servers
    $servers = VpnServer::listByUser($user['id']);
    
    // Get user's clients
    $clients = VpnClient::listByUser($user['id']);
    
    // Get subscription health stats
    $subStats = [
        'active' => 0,
        'expiring_soon' => 0,
        'expired' => 0,
        'paused' => 0
    ];
    try {
        $pdo = DB::conn();
        $subQuery = $pdo->query("
            SELECT 
                SUM(CASE WHEN func = 'WORK' AND DATE_ADD(start_date, INTERVAL (sub * 30) DAY) > DATE_ADD(CURDATE(), INTERVAL 10 DAY) THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN func = 'WORK' AND DATE_ADD(start_date, INTERVAL (sub * 30) DAY) > CURDATE() AND DATE_ADD(start_date, INTERVAL (sub * 30) DAY) <= DATE_ADD(CURDATE(), INTERVAL 10 DAY) THEN 1 ELSE 0 END) as expiring_soon,
                SUM(CASE WHEN func = 'WORK' AND DATE_ADD(start_date, INTERVAL (sub * 30) DAY) <= CURDATE() THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN func = 'PAUSE' THEN 1 ELSE 0 END) as paused
            FROM ext_clients
        ");
        $rowStats = $subQuery->fetch(PDO::FETCH_ASSOC);
        if ($rowStats) {
            $subStats['active'] = (int)($rowStats['active'] ?? 0);
            $subStats['expiring_soon'] = (int)($rowStats['expiring_soon'] ?? 0);
            $subStats['expired'] = (int)($rowStats['expired'] ?? 0);
            $subStats['paused'] = (int)($rowStats['paused'] ?? 0);
        }
    } catch (Throwable $e) {
        // Table system_settings / ext_clients might not be created yet during first load
    }
    
    View::render('dashboard.twig', [
        'servers' => $servers,
        'clients' => $clients,
        'sub_stats' => $subStats,
    ]);
});

// Servers list
Router::get('/servers', function () {
    requireAuth();
    $user = Auth::user();
    
    $servers = Auth::isAdmin() 
        ? VpnServer::listAll() 
        : VpnServer::listByUser($user['id']);
    
    View::render('servers/index.twig', ['servers' => $servers]);
});

// Create server page
Router::get('/servers/create', function () {
    requireAuth();
    $randomPort = rand(30000, 65000);
    View::render('servers/create.twig', ['random_port' => $randomPort]);
});

// Create server action
Router::post('/servers/create', function () {
    requireAuth();
    $user = Auth::user();
    
    $name = trim($_POST['name'] ?? '');
    $host = trim($_POST['host'] ?? '');
    $port = (int)($_POST['port'] ?? 22);
    $username = trim($_POST['username'] ?? 'root');
    $password = $_POST['password'] ?? '';
    
    if (empty($name) || empty($host) || empty($password)) {
        View::render('servers/create.twig', ['error' => 'All fields are required']);
        return;
    }
    
    try {
        $serverId = VpnServer::create([
            'user_id' => $user['id'],
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'vpn_port' => !empty($_POST['vpn_port']) ? (int)$_POST['vpn_port'] : NULL,
            'mimicry_type' => $_POST['mimicry_type'] ?? 'quic'
        ]);
        
        // Handle import if enabled
        if (!empty($_POST['enable_import']) && !empty($_POST['panel_type']) && isset($_FILES['backup_file'])) {
            $panelType = $_POST['panel_type'];
            
            if (in_array($panelType, ['wg-easy', '3x-ui']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                // Store import info in session for processing after deployment
                $_SESSION['pending_import'] = [
                    'server_id' => $serverId,
                    'panel_type' => $panelType,
                    'backup_file' => $_FILES['backup_file']['tmp_name'],
                    'backup_name' => $_FILES['backup_file']['name']
                ];
            }
        }
        
        redirect('/servers/' . $serverId . '/deploy');
    } catch (Exception $e) {
        View::render('servers/create.twig', ['error' => $e->getMessage()]);
    }
});

// Delete server action
Router::post('/servers/{id}/delete', function ($params) {
    requireAuth();
    $user = Auth::user();
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership or admin
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        $server->delete();
        $_SESSION['success_message'] = 'Server deleted successfully';
        redirect('/servers');
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        redirect('/servers');
    }
});

// Deploy server page
Router::get('/servers/{id}/deploy', function ($params) {
    requireAuth();
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        View::render('servers/deploy.twig', ['server' => $serverData]);
    } catch (Exception $e) {
        http_response_code(404);
        echo 'Server not found';
    }
});

// Deploy server action (AJAX)
Router::post('/servers/{id}/deploy', function ($params) {
    requireAuth();
    header('Content-Type: application/json');
    
    $serverId = (int)$params['id'];
    ob_start();
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $result = $server->deploy();
        $unexpectedOutput = trim((string)ob_get_clean());
        if ($unexpectedOutput !== '') {
            error_log('Deploy produced non-JSON output: ' . substr($unexpectedOutput, 0, 1000));
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Deploy returned unexpected output',
                'details' => substr(strip_tags($unexpectedOutput), 0, 500)
            ]);
            return;
        }
        echo json_encode($result);
    } catch (Throwable $e) {
        $unexpectedOutput = trim((string)ob_get_clean());
        http_response_code(500);
        error_log('Deploy failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        if ($unexpectedOutput !== '') {
            error_log('Deploy buffered output before error: ' . substr($unexpectedOutput, 0, 1000));
        }
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'details' => $unexpectedOutput !== '' ? substr(strip_tags($unexpectedOutput), 0, 500) : null
        ]);
    }
});

// View server
Router::get('/servers/{id}', function ($params) {
    requireAuth();
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        // Get clients for this server
        $clients = VpnClient::listByServer($serverId);
        
        // Check for pending import
        $importMessage = null;
        if (!empty($_SESSION['pending_import']) && $_SESSION['pending_import']['server_id'] == $serverId) {
            $pendingImport = $_SESSION['pending_import'];
            
            // Only process import if server is active
            if ($serverData['status'] === 'active') {
                try {
                    $backupContent = file_get_contents($pendingImport['backup_file']);
                    
                    $importer = new PanelImporter($serverId, $user['id'], $pendingImport['panel_type']);
                    $importer->parseBackupFile($backupContent);
                    $result = $importer->import();
                    
                    if ($result['success']) {
                        $importMessage = [
                            'type' => 'success',
                            'text' => "Successfully imported {$result['imported_count']} clients"
                        ];
                    }
                    
                    // Clean up
                    @unlink($pendingImport['backup_file']);
                    unset($_SESSION['pending_import']);
                    
                } catch (Exception $e) {
                    $importMessage = [
                        'type' => 'error',
                        'text' => 'Import failed: ' . $e->getMessage()
                    ];
                    unset($_SESSION['pending_import']);
                }
                
                // Refresh clients list after import
                $clients = VpnClient::listByServer($serverId);
            }
        }
        
        $agentOnline = false;
        if (!empty($serverData['last_check_at'])) {
            $agentOnline = (time() - strtotime($serverData['last_check_at'])) < 120;
        }
        
        View::render('servers/view.twig', [
            'server' => $serverData,
            'clients' => $clients,
            'import_message' => $importMessage,
            'agent_online' => $agentOnline,
        ]);
    } catch (Exception $e) {
        error_log('Server view error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(404);
        echo 'Server not found: ' . htmlspecialchars($e->getMessage());
    }
});



// Delete server
Router::post('/servers/{id}/delete', function ($params) {
    requireAuth();
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        $server->delete();
        redirect('/servers');
    } catch (Exception $e) {
        redirect('/servers');
    }
});

// Create client for server
Router::post('/servers/{id}/clients/create', function ($params) {
    requireAuth();
    $serverId = (int)$params['id'];
    $clientName = trim($_POST['name'] ?? '');
    
    // Handle expiration: either from dropdown (days) or custom input (seconds)
    $expiresInDays = null;
    if (!empty($_POST['expires_in_seconds'])) {
        // Convert seconds to days (round up)
        $expiresInDays = (int)ceil((int)$_POST['expires_in_seconds'] / 86400);
    } elseif (!empty($_POST['expires_in_days']) && $_POST['expires_in_days'] !== 'custom') {
        $expiresInDays = (int)$_POST['expires_in_days'];
    }
    
    // Handle traffic limit: either from dropdown (GB) or custom input (MB)
    $trafficLimitBytes = null;
    if (!empty($_POST['traffic_limit_mb'])) {
        // Convert MB to bytes
        $trafficLimitBytes = (int)((float)$_POST['traffic_limit_mb'] * 1048576);
    } elseif (!empty($_POST['traffic_limit_gb']) && $_POST['traffic_limit_gb'] !== 'custom') {
        // Convert GB to bytes
        $trafficLimitBytes = (int)((float)$_POST['traffic_limit_gb'] * 1073741824);
    }
    
    if (empty($clientName)) {
        redirect('/servers/' . $serverId . '?error=Client+name+is+required');
        return;
    }
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        $clientId = VpnClient::create($serverId, $user['id'], $clientName, $expiresInDays);
        
        // Set traffic limit if specified
        if ($trafficLimitBytes !== null && $trafficLimitBytes > 0) {
            $client = new VpnClient($clientId);
            $client->setTrafficLimit($trafficLimitBytes);
        }

        // Link to external client code if provided, otherwise try auto-linking
        $extClientCode = trim($_POST['ext_client_code'] ?? '');
        if ($extClientCode !== '') {
            try {
                $pdo = DB::conn();
                $stmt = $pdo->prepare('UPDATE vpn_clients SET ext_client_code = ? WHERE id = ?');
                $stmt->execute([$extClientCode, $clientId]);
            } catch (Throwable $e) {
                error_log('Manual link failed for client ' . $clientId . ': ' . $e->getMessage());
            }
        } else {
            // Try auto-linking
            try {
                $matchingCode = VpnClient::findMatchingCode($clientName);
                if ($matchingCode !== null) {
                    $pdo = DB::conn();
                    $stmt = $pdo->prepare('UPDATE vpn_clients SET ext_client_code = ? WHERE id = ?');
                    $stmt->execute([$matchingCode, $clientId]);
                }
            } catch (Throwable $e) {
                error_log('Auto-link on create failed for client ' . $clientId . ': ' . $e->getMessage());
            }
        }
        
        redirect('/clients/' . $clientId);
    } catch (Exception $e) {
        redirect('/servers/' . $serverId . '?error=' . urlencode($e->getMessage()));
    }
});

// Clients list (from local MySQL cached ext_clients table)
Router::get('/clients', function () {
    requireAuth();

    $search     = trim($_GET['search'] ?? '');
    $codeFilter = trim($_GET['code'] ?? '');
    $filter     = trim($_GET['filter'] ?? 'all');
    $sort       = trim($_GET['sort'] ?? 'code');
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $perPage    = 30;
    $offset     = ($page - 1) * $perPage;

    $clients      = [];
    $totalCount   = 0;
    $totalPages   = 1;
    $extDbError   = null;
    $lastSync     = null;

    // Check PostgreSQL connection status for header warning only
    try {
        if (!ExtDB::isAvailable()) {
            $extDbError = "External PostgreSQL database is unreachable.";
        }
    } catch (Throwable $e) {
        $extDbError = $e->getMessage();
    }
    try {
        $pdo = DB::conn();

        // Get last sync timestamp
        try {
            $stmtSync = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'last_ext_clients_sync' LIMIT 1");
            $lastSyncVal = $stmtSync->fetchColumn();
            if ($lastSyncVal) {
                $lastSync = date('d.m.Y H:i', strtotime($lastSyncVal));
            }
        } catch (Throwable $e) {}

        if ($codeFilter !== '') {
            // Exact match for the code filter
            $stmt = $pdo->prepare('SELECT code, name, start_date, sub, func, router, bytes_sent, bytes_received FROM ext_clients WHERE code = ? LIMIT 1');
            $stmt->execute([$codeFilter]);
            $rowExt = $stmt->fetch();
            if ($rowExt) {
                $totalCount = 1;
                $rawCodes   = [['Code' => $codeFilter, 'name' => $rowExt['name'], 'start_date' => $rowExt['start_date'], 'sub' => $rowExt['sub'], 'func' => $rowExt['func'], 'router' => $rowExt['router'], 'bytes_sent' => $rowExt['bytes_sent'], 'bytes_received' => $rowExt['bytes_received']]];
            } else {
                $totalCount = 0;
                $rawCodes   = [];
            }
        } else {
            // Build WHERE clauses dynamically based on search & status filter
            $whereClauses = [];
            $queryParams = [];

            if ($filter === 'active') {
                $whereClauses[] = "ec.func = 'WORK' AND DATE_ADD(ec.start_date, INTERVAL (ec.sub * 30) DAY) > DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
            } elseif ($filter === 'expiring') {
                $whereClauses[] = "ec.func = 'WORK' AND DATE_ADD(ec.start_date, INTERVAL (ec.sub * 30) DAY) > CURDATE() AND DATE_ADD(ec.start_date, INTERVAL (ec.sub * 30) DAY) <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)";
            } elseif ($filter === 'expired') {
                $whereClauses[] = "ec.func = 'WORK' AND DATE_ADD(ec.start_date, INTERVAL (ec.sub * 30) DAY) <= CURDATE()";
            } elseif ($filter === 'paused') {
                $whereClauses[] = "ec.func = 'PAUSE'";
            }

            if ($search !== '') {
                $whereClauses[] = "(ec.code LIKE ? OR ec.name LIKE ? OR ec.router LIKE ? OR vc.name LIKE ? OR vc.client_ip LIKE ?)";
                $likeParam = '%' . $search . '%';
                $queryParams = array_merge($queryParams, [$likeParam, $likeParam, $likeParam, $likeParam, $likeParam]);
            }

            $whereSql = '';
            if (!empty($whereClauses)) {
                $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
            }

            // Determine sorting clause
            $orderBy = 'ec.code ASC';
            if ($sort === 'expiry_asc') {
                $orderBy = "CASE WHEN ec.func = 'WORK' AND ec.start_date IS NOT NULL AND ec.sub IS NOT NULL AND ec.sub > 0 THEN DATE_ADD(ec.start_date, INTERVAL (ec.sub * 30) DAY) ELSE '9999-12-31' END ASC, ec.code ASC";
            } elseif ($sort === 'expiry_desc') {
                $orderBy = "CASE WHEN ec.func = 'WORK' AND ec.start_date IS NOT NULL AND ec.sub IS NOT NULL AND ec.sub > 0 THEN DATE_ADD(ec.start_date, INTERVAL (ec.sub * 30) DAY) ELSE '1970-01-01' END DESC, ec.code ASC";
            } elseif ($sort === 'traffic_desc') {
                $orderBy = "(ec.bytes_sent + ec.bytes_received) DESC, ec.code ASC";
            } elseif ($sort === 'traffic_asc') {
                $orderBy = "(ec.bytes_sent + ec.bytes_received) ASC, ec.code ASC";
            }

            // Get total count
            $countSql = "
                SELECT COUNT(DISTINCT ec.code) 
                FROM ext_clients ec
                LEFT JOIN vpn_clients vc ON vc.ext_client_code = ec.code
                {$whereSql}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($queryParams);
            $totalCount = (int)$stmtCount->fetchColumn();

            // Fetch list
            $selectSql = "
                SELECT DISTINCT ec.code AS \"Code\", ec.name, ec.start_date, ec.sub, ec.func, ec.router, ec.bytes_sent, ec.bytes_received
                FROM ext_clients ec
                LEFT JOIN vpn_clients vc ON vc.ext_client_code = ec.code
                {$whereSql}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ";
            $stmt = $pdo->prepare($selectSql);
            $paramIndex = 1;
            foreach ($queryParams as $val) {
                $stmt->bindValue($paramIndex++, $val, PDO::PARAM_STR);
            }
            $stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rawCodes = $stmt->fetchAll();
        }
        $totalPages = max(1, (int)ceil($totalCount / $perPage));

        // For each code, count and list linked vpn_clients in MySQL
        foreach ($rawCodes as $row) {
            $code = $row['Code'];
            $stmt = $pdo->prepare(
                'SELECT c.id, c.name, c.status, s.name AS server_name
                 FROM vpn_clients c
                 JOIN vpn_servers s ON s.id = c.server_id
                 WHERE c.ext_client_code = ?
                 ORDER BY c.created_at DESC'
            );
            $stmt->execute([$code]);
            $configs = $stmt->fetchAll();

            // Calculate subscription expiry ONLY if Func is WORK
            $expiryDate = null;
            $daysLeft = null;
            $func = $row['func'] ?? null;
            if ($func !== null && strtoupper($func) === 'WORK') {
                $startDate = $row['start_date'] ?? null;
                $sub = $row['sub'] ?? null;
                if ($startDate && $sub !== null && $sub > 0) {
                    $daysToAdd = (int)$sub * 30;
                    $expiryTimestamp = strtotime($startDate . " + $daysToAdd days");
                    $expiryDate = date('d.m.Y', $expiryTimestamp);
                    $daysLeft = (int)round(($expiryTimestamp - strtotime(date('Y-m-d'))) / 86400);
                }
            }

            $clients[] = [
                'Code'         => $code,
                'Name'         => $row['name'] ?? null,
                'Func'         => $row['func'] ?? null,
                'Router'       => $row['router'] ?? null,
                'ExpiryDate'   => $expiryDate,
                'DaysLeft'     => $daysLeft,
                'BytesSent'    => (int)($row['bytes_sent'] ?? 0),
                'BytesReceived'=> (int)($row['bytes_received'] ?? 0),
                'configs'      => $configs,
                'config_count' => count($configs),
            ];
        }
    } catch (Throwable $e) {
        $extDbError = "Local database query failed: " . $e->getMessage();
    }

    // If filtering by a single code, also load all configs for that code
    $codeConfigs = null;
    if ($codeFilter !== '') {
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare(
                'SELECT c.id, c.name, c.status, s.name AS server_name
                 FROM vpn_clients c
                 JOIN vpn_servers s ON s.id = c.server_id
                 WHERE c.ext_client_code = ?
                 ORDER BY c.created_at DESC'
            );
            $stmt->execute([$codeFilter]);
            $codeConfigs = $stmt->fetchAll();
        } catch (Throwable $e) {
            // ignore
        }
    }

    // All servers (for the modal's server picker)
    $user = Auth::user();
    $allServers = Auth::isAdmin() ? VpnServer::listAll() : VpnServer::listByUser($user['id']);

    View::render('clients/index.twig', [
        'clients'      => $clients,
        'total_count'  => $totalCount,
        'total_pages'  => $totalPages,
        'current_page' => $page,
        'search'       => $search,
        'filter'       => $filter,
        'sort'         => $sort,
        'last_sync'    => $lastSync,
        'code_filter'  => $codeFilter,
        'code_configs' => $codeConfigs,
        'all_servers'  => $allServers,
        'ext_db_error' => $extDbError,
    ]);
});

// API: Autocomplete client codes from local cached MySQL table
Router::get('/api/ext-clients/search', function () {
    requireAuth();
    header('Content-Type: application/json');

    $q     = trim($_GET['q'] ?? '');
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 15)));

    try {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT code FROM ext_clients WHERE code LIKE ? ORDER BY code LIMIT ?');
        $stmt->bindValue(1, '%' . $q . '%', PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['codes' => $codes]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'codes' => []]);
    }
});

// API: Manual trigger to synchronize Postgres to MySQL
Router::post('/api/ext-clients/sync', function () {
    requireAuth();
    header('Content-Type: application/json');

    try {
        if (!ExtDB::isAvailable()) {
            throw new Exception("External PostgreSQL database is unreachable.");
        }

        $pgPdo = ExtDB::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        $stmt = $pgPdo->query("SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
        $rawClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $myPdo = DB::conn();
        $myPdo->beginTransaction();
        $myPdo->exec('DELETE FROM ext_clients');
        if (!empty($rawClients)) {
            $insertStmt = $myPdo->prepare('INSERT INTO ext_clients (code, name, start_date, sub, func, router) VALUES (?, ?, ?, ?, ?, ?)');
            $syncedCount = 0;
            foreach ($rawClients as $row) {
                $code = trim($row['Code'] ?? '');
                if ($code === '') continue;
                $name = isset($row['Name']) ? trim($row['Name']) : null;
                $startDate = isset($row['Start_Date']) ? trim($row['Start_Date']) : null;
                if ($startDate === '') $startDate = null;
                $sub = isset($row['Sub']) ? (int)$row['Sub'] : null;
                $func = isset($row['Func']) ? trim($row['Func']) : null;
                $router = isset($row['Router']) ? trim($row['Router']) : null;

                $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router]);
                $syncedCount++;
            }
        }
        $myPdo->commit();

        // Run automatic client linking
        try {
            VpnClient::autoLinkAll();
        } catch (Throwable $e) {
            error_log('Automatic client linking failed during manual sync: ' . $e->getMessage());
        }

        // Store last sync timestamp
        try {
            $myPdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('last_ext_clients_sync', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                  ->execute([date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {}

        echo json_encode(['success' => true, 'count' => $syncedCount]);
    } catch (Throwable $e) {
        if (isset($myPdo) && $myPdo->inTransaction()) {
            $myPdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
});

// View client
Router::get('/clients/{id}', function ($params) {
    requireAuth();
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $stats = $client->getFormattedStats();
        
        // Fetch server details for client breadcrumbs
        $server = new VpnServer($clientData['server_id']);
        $serverData = $server->getData();
        
        // Fetch external client details if linked
        $extClient = null;
        if (!empty($clientData['ext_client_code'])) {
            $pdo = DB::conn();
            $stmtExt = $pdo->prepare('SELECT * FROM ext_clients WHERE code = ? LIMIT 1');
            $stmtExt->execute([$clientData['ext_client_code']]);
            $rowExt = $stmtExt->fetch();
            if ($rowExt) {
                $expiryDate = null;
                $daysLeft = null;
                $func = $rowExt['func'] ?? null;
                if ($func !== null && strtoupper($func) === 'WORK') {
                    $startDate = $rowExt['start_date'] ?? null;
                    $sub = $rowExt['sub'] ?? null;
                    if ($startDate && $sub !== null && $sub > 0) {
                        $daysToAdd = (int)$sub * 30;
                        $expiryTimestamp = strtotime($startDate . " + $daysToAdd days");
                        $expiryDate = date('d.m.Y', $expiryTimestamp);
                        $daysLeft = (int)round(($expiryTimestamp - strtotime(date('Y-m-d'))) / 86400);
                    }
                }

                $extClient = [
                    'code'        => $rowExt['code'],
                    'name'        => $rowExt['name'],
                    'start_date'  => $rowExt['start_date'],
                    'sub'         => $rowExt['sub'],
                    'func'        => $rowExt['func'],
                    'router'      => $rowExt['router'],
                    'bytes_sent'  => (int)($rowExt['bytes_sent'] ?? 0),
                    'bytes_received' => (int)($rowExt['bytes_received'] ?? 0),
                    'expiry_date' => $expiryDate,
                    'days_left'   => $daysLeft,
                ];
            }
        }
        
        $pdo = DB::conn();
        $stmtAllExt = $pdo->query('SELECT code, name FROM ext_clients ORDER BY code ASC');
        $allExtClients = $stmtAllExt->fetchAll(PDO::FETCH_ASSOC);

        View::render('clients/view.twig', [
            'client' => $clientData,
            'stats' => $stats,
            'server' => $serverData,
            'ext_client' => $extClient,
            'all_ext_clients' => $allExtClients
        ]);
    } catch (Exception $e) {
        http_response_code(404);
        echo 'Client not found';
    }
});

// Update client settings
Router::post('/clients/{id}/update', function ($params) {
    requireAuth();
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            die('Forbidden');
        }

        $pdo = DB::conn();

        if (isset($_POST['name']) && trim($_POST['name']) !== '') {
            $stmt = $pdo->prepare('UPDATE vpn_clients SET name = ? WHERE id = ?');
            $stmt->execute([trim($_POST['name']), $clientId]);
        }

        if (isset($_POST['ext_client_code'])) {
            $extClientCode = trim($_POST['ext_client_code']);
            if ($extClientCode === '') {
                $stmt = $pdo->prepare('UPDATE vpn_clients SET ext_client_code = NULL WHERE id = ?');
                $stmt->execute([$clientId]);
            } else {
                $stmtLoc = $pdo->prepare('SELECT 1 FROM ext_clients WHERE code = ? LIMIT 1');
                $stmtLoc->execute([$extClientCode]);
                if ($stmtLoc->fetchColumn() !== false) {
                    $stmt = $pdo->prepare('UPDATE vpn_clients SET ext_client_code = ? WHERE id = ?');
                    $stmt->execute([$extClientCode, $clientId]);
                    
                    // Force statistics sync to calculate aggregate immediately
                    $client->syncStats();
                }
            }
        }
        
        if (!empty($_POST['add_days'])) {
            if ($_POST['add_days'] === 'remove') {
                VpnClient::setExpiration($clientId, null);
            } else {
                $days = $_POST['add_days'] === 'custom' ? max(1, (int)($_POST['custom_seconds'] / 86400)) : (int)$_POST['add_days'];
                VpnClient::extendExpiration($clientId, $days);
            }
        }
        
        if (!empty($_POST['new_limit_gb'])) {
            if ($_POST['new_limit_gb'] === 'remove') {
                $client->setTrafficLimit(null);
            } else {
                $mb = $_POST['new_limit_gb'] === 'custom' ? (int)$_POST['custom_mb'] : (int)$_POST['new_limit_gb'] * 1024;
                if ($mb > 0) {
                    $client->setTrafficLimit($mb * 1024 * 1024);
                }
            }
        }

        redirect('/clients/' . $clientId . '?success=' . urlencode('Client updated successfully'));
    } catch (Exception $e) {
        redirect('/clients/' . $clientId . '?error=' . urlencode($e->getMessage()));
    }
});

// Download client config
Router::get('/clients/{id}/download', function ($params) {
    requireAuth();
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        $config = $client->getConfig();
        
        // Check if name contains non-Latin characters
        $hasNonLatin = preg_match('/[^a-zA-Z0-9_-]/', $clientData['name']);
        if ($hasNonLatin) {
            // Use user_(client_id)_s(server_id).conf format for non-Latin names
            $filename = 'user_' . $clientData['id'] . '_s' . $clientData['server_id'] . '.conf';
        } else {
            // Use client name for Latin characters
            $filename = $clientData['name'] . '.conf';
        }
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($config));
        echo $config;
    } catch (Exception $e) {
        http_response_code(404);
        echo 'Client not found';
    }
});

// Revoke client access
Router::post('/clients/{id}/revoke', function ($params) {
    requireAuth();
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        if ($client->revoke()) {
            redirect('/servers/' . $clientData['server_id'] . '?success=Client+revoked');
        } else {
            redirect('/servers/' . $clientData['server_id'] . '?error=Failed+to+revoke+client');
        }
    } catch (Exception $e) {
        redirect('/dashboard?error=' . urlencode($e->getMessage()));
    }
});

// Restore client access
Router::post('/clients/{id}/restore', function ($params) {
    requireAuth();
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        if ($client->restore()) {
            redirect('/servers/' . $clientData['server_id'] . '?success=Client+restored');
        } else {
            redirect('/servers/' . $clientData['server_id'] . '?error=Failed+to+restore+client');
        }
    } catch (Exception $e) {
        redirect('/dashboard?error=' . urlencode($e->getMessage()));
    }
});

// Delete client
Router::post('/clients/{id}/delete', function ($params) {
    requireAuth();
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        
        $serverId = $clientData['server_id'];
        
        if ($client->delete()) {
            redirect('/servers/' . $serverId . '?success=Client+deleted');
        } else {
            redirect('/servers/' . $serverId . '?error=Failed+to+delete+client');
        }
    } catch (Exception $e) {
        redirect('/dashboard?error=' . urlencode($e->getMessage()));
    }
});

// Sync client stats
Router::post('/clients/{id}/sync-stats', function ($params) {
    requireAuth();
    $clientId = (int)$params['id'];
    
    header('Content-Type: application/json');
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        if ($client->syncStats()) {
            // Reload client data
            $client = new VpnClient($clientId);
            $stats = $client->getFormattedStats();
            echo json_encode(['success' => true, 'stats' => $stats]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to sync stats']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Sync all stats for server
Router::post('/servers/{id}/sync-stats', function ($params) {
    requireAuth();
    $serverId = (int)$params['id'];
    
    header('Content-Type: application/json');
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $synced = VpnClient::syncAllStatsForServer($serverId);
        echo json_encode(['success' => true, 'synced' => $synced]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Deploy monitoring agent for server
Router::post('/servers/{id}/deploy-monitoring', function ($params) {
    requireAuth();
    $serverId = (int)$params['id'];
    
    header('Content-Type: application/json');
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        $user = Auth::user();
        if ($serverData['user_id'] != $user['id'] && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $panelUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        
        $server->deployMonitoringAgent($panelUrl);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});


// API: Report metrics from remote server (used by push agent)
Router::post('/api/servers/report-metrics', function () {
    header('Content-Type: application/json');
    
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $token = $data['token'] ?? '';
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token is required']);
        return;
    }
    
    $pdo = DB::conn();
    
    // Find server by token
    $stmt = $pdo->prepare('SELECT id FROM vpn_servers WHERE secret_token = ?');
    $stmt->execute([$token]);
    $serverId = $stmt->fetchColumn();
    
    if (!$serverId) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid server token']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update last_check_at for the server to show the agent is online
        $stmt = $pdo->prepare('UPDATE vpn_servers SET last_check_at = NOW() WHERE id = ?');
        $stmt->execute([$serverId]);
        
        // Process client metrics
        if (isset($data['clients']) && is_array($data['clients'])) {
            foreach ($data['clients'] as $c) {
                $publicKey = $c['public_key'] ?? '';
                if (empty($publicKey)) continue;
                
                // Find client by public_key and server_id (include ext_client_code)
                $stmt = $pdo->prepare('SELECT id, bytes_sent, bytes_received, last_endpoint_ip, ext_client_code FROM vpn_clients WHERE server_id = ? AND public_key = ?');
                $stmt->execute([$serverId, $publicKey]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($client) {
                    $clientId = $client['id'];
                    
                    // Update GeoIP information if endpoint IP has changed
                    VpnClient::updateGeoIpForClient($clientId, $c['endpoint'] ?? null, $client['last_endpoint_ip'] ?? null);
                    $rawBytesSent = (int)($c['bytes_sent'] ?? 0);
                    $rawBytesReceived = (int)($c['bytes_received'] ?? 0);
                    $lastHandshakeVal = (int)($c['last_handshake'] ?? 0);
                    
                    // Fetch latest recorded raw metrics to calculate speed and traffic deltas
                    $stmt = $pdo->prepare('
                        SELECT bytes_sent, bytes_received, collected_at 
                        FROM client_metrics 
                        WHERE client_id = ? 
                        ORDER BY collected_at DESC 
                        LIMIT 1
                    ');
                    $stmt->execute([$clientId]);
                    $prev = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $speedUp = 0;
                    $speedDown = 0;
                    $deltaSent = $rawBytesSent;
                    $deltaReceived = $rawBytesReceived;
                    
                    if ($prev) {
                        $timeDiff = time() - strtotime($prev['collected_at']);
                        if ($timeDiff > 0) {
                            $rawBytesDiffSent = $rawBytesSent - (int)$prev['bytes_sent'];
                            $rawBytesDiffReceived = $rawBytesReceived - (int)$prev['bytes_received'];
                            
                            // Handle potential stats reset on container/interface restart
                            if ($rawBytesDiffSent >= 0) {
                                $deltaSent = $rawBytesDiffSent;
                            }
                            if ($rawBytesDiffReceived >= 0) {
                                $deltaReceived = $rawBytesDiffReceived;
                            }
                            
                            // speedUp = Client Upload = Received by Server (rawBytesDiffReceived)
                            // speedDown = Client Download = Transmitted by Server (rawBytesDiffSent)
                            $speedUp = round(($deltaReceived * 8) / $timeDiff / 1000, 2);
                            $speedDown = round(($deltaSent * 8) / $timeDiff / 1000, 2);
                        }
                    }
                    
                    // Save raw client metrics for speed calculations
                    $stmt = $pdo->prepare('
                        INSERT INTO client_metrics 
                        (client_id, bytes_sent, bytes_received, speed_up_kbps, speed_down_kbps)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $clientId,
                        $rawBytesSent,
                        $rawBytesReceived,
                        $speedUp,
                        $speedDown
                    ]);
                    
                    // Accumulate client traffic in main table to prevent resets
                    $newTotalSent = (int)$client['bytes_sent'] + $deltaSent;
                    $newTotalReceived = (int)$client['bytes_received'] + $deltaReceived;

                    // Increment persistent traffic aggregate in ext_clients
                    if (!empty($client['ext_client_code']) && ($deltaSent > 0 || $deltaReceived > 0)) {
                        $stmtExtInc = $pdo->prepare('
                            UPDATE ext_clients 
                            SET bytes_sent = bytes_sent + ?, bytes_received = bytes_received + ?
                            WHERE code = ?
                        ');
                        $stmtExtInc->execute([$deltaSent, $deltaReceived, $client['ext_client_code']]);
                    }
                    
                    $lastHandshake = $lastHandshakeVal > 0 ? date('Y-m-d H:i:s', $lastHandshakeVal) : null;
                    $stmt = $pdo->prepare('
                        UPDATE vpn_clients 
                        SET bytes_sent = ?, 
                            bytes_received = ?, 
                            speed_up_kbps = ?, 
                            speed_down_kbps = ?, 
                            last_handshake = ?, 
                            last_sync_at = NOW()
                        WHERE id = ?
                    ');
                    $stmt->execute([
                        $newTotalSent,
                        $newTotalReceived,
                        $speedUp,
                        $speedDown,
                        $lastHandshake,
                        $clientId
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        // Clean old metrics (older than 24h)
        ServerMonitoring::cleanOldMetrics();
        
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

/**
 * API ROUTES (for Telegram bot integration)
 */

// API: Generate JWT token
Router::post('/api/auth/token', function () {
    header('Content-Type: application/json');
    
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password are required']);
        return;
    }
    
    $user = Auth::getUserByEmail($email);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        return;
    }
    
    try {
        $token = JWT::generate($user['id']);
        echo json_encode([
            'success' => true,
            'token' => $token,
            'type' => 'Bearer',
            'expires_in' => 30 * 24 * 3600 // 30 days
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Token generation failed']);
    }
});

// API: Create persistent API token
Router::post('/api/tokens', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $name = $_POST['name'] ?? 'API Token';
    $expiresIn = isset($_POST['expires_in']) ? (int)$_POST['expires_in'] : 2592000; // 30 days default
    
    try {
        $tokenData = JWT::createApiToken($user['id'], $name, $expiresIn);
        echo json_encode([
            'success' => true,
            'token' => $tokenData
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: List user's API tokens
Router::get('/api/tokens', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $stmt = DB::get()->prepare("
        SELECT id, name, token, expires_at, created_at, last_used_at
        FROM api_tokens
        WHERE user_id = ? AND revoked_at IS NULL
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $tokens = $stmt->fetchAll();
    
    // Don't expose full token in list
    foreach ($tokens as &$token) {
        $token['token'] = substr($token['token'], 0, 10) . '...';
    }
    
    echo json_encode(['tokens' => $tokens]);
});

// API: Revoke API token
Router::delete('/api/tokens/{id}', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    try {
        JWT::revokeApiToken($params['id'], $user['id']);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(404);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: List servers
Router::get('/api/servers', function () {
    header('Content-Type: application/json');
    
    $user = authenticateRequest();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $servers = VpnServer::listByUser($user['id']);
    echo json_encode(['servers' => $servers]);
});

// API: Get dashboard aggregated bandwidth metrics
Router::get('/api/dashboard/metrics', function () {
    header('Content-Type: application/json');
    
    $user = authenticateRequest();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $hours = isset($_GET['hours']) ? max(1, min(168, (float)$_GET['hours'])) : 24;
    $serverId = isset($_GET['server_id']) && $_GET['server_id'] !== '' ? (int)$_GET['server_id'] : null;
    
    $pdo = DB::conn();
    
    try {
        if ($serverId) {
            // Verify server ownership
            $stmt = $pdo->prepare('SELECT user_id FROM vpn_servers WHERE id = ?');
            $stmt->execute([$serverId]);
            $ownerId = $stmt->fetchColumn();
            if ($ownerId != $user['id'] && !Auth::isAdmin()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            
            $query = "
                SELECT 
                    DATE_FORMAT(cm.collected_at, '%Y-%m-%d %H:%i:00') as time_bucket,
                    SUM(cm.speed_up_kbps) as speed_up,
                    SUM(cm.speed_down_kbps) as speed_down
                FROM client_metrics cm
                JOIN vpn_clients c ON cm.client_id = c.id
                WHERE c.server_id = ? AND cm.collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY time_bucket
                ORDER BY time_bucket ASC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$serverId, $hours]);
        } else {
            // Global aggregate for user
            $query = "
                SELECT 
                    DATE_FORMAT(cm.collected_at, '%Y-%m-%d %H:%i:00') as time_bucket,
                    SUM(cm.speed_up_kbps) as speed_up,
                    SUM(cm.speed_down_kbps) as speed_down
                FROM client_metrics cm
                JOIN vpn_clients c ON cm.client_id = c.id
                WHERE c.user_id = ? AND cm.collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY time_bucket
                ORDER BY time_bucket ASC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$user['id'], $hours]);
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format results as numeric values in the JSON output
        foreach ($results as &$row) {
            $row['speed_up'] = (float)$row['speed_up'];
            $row['speed_down'] = (float)$row['speed_down'];
        }
        
        echo json_encode([
            'success' => true,
            'metrics' => $results
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Create server
Router::post('/api/servers/create', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($input['name'] ?? '');
    $host = trim($input['host'] ?? '');
    $port = (int)($input['port'] ?? 22);
    $username = trim($input['username'] ?? 'root');
    $password = $input['password'] ?? '';
    
    if (empty($name) || empty($host) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: name, host, password']);
        return;
    }
    
    try {
        $serverId = VpnServer::create([
            'user_id' => $user['id'],
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ]);
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'server_id' => $serverId,
            'message' => 'Server created successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Delete server
Router::delete('/api/servers/{id}/delete', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();

// API: Import from existing panel
Router::post('/api/servers/{id}/import', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $serverId = (int)$params['id'];
    
    // Validate server ownership
    $server = VpnServer::getById($serverId);
    if (!$server || $server['user_id'] != $user['id']) {
        http_response_code(404);
        echo json_encode(['error' => 'Server not found']);
        return;
    }
    
    $panelType = $_POST['panel_type'] ?? '';
    
    if (!in_array($panelType, ['wg-easy', '3x-ui'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid panel type. Supported: wg-easy, 3x-ui']);
        return;
    }
    
    // Handle file upload
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No backup file uploaded']);
        return;
    }
    
    $backupContent = file_get_contents($_FILES['backup_file']['tmp_name']);
    
    try {
        $importer = new PanelImporter($serverId, $user['id'], $panelType);
        
        if (!$importer->parseBackupFile($backupContent)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid backup file format']);
            return;
        }
        
        $result = $importer->import();
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
});

// API: Get import history
Router::get('/api/servers/{id}/imports', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $serverId = (int)$params['id'];
    
    // Validate server ownership
    $server = VpnServer::getById($serverId);
    if (!$server || $server['user_id'] != $user['id']) {
        http_response_code(404);
        echo json_encode(['error' => 'Server not found']);
        return;
    }
    
    $imports = PanelImporter::getImportHistory($serverId);
    
    echo json_encode([
        'success' => true,
        'imports' => $imports
    ]);
});
    if (!$user) return;
    
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership or admin
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $server->delete();
        echo json_encode([
            'success' => true,
            'message' => 'Server deleted successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Create backup
Router::post('/api/servers/{id}/backup', function ($params) {
    header('Content-Type: application/json');
    
    $user = requireApiAuth();
    if (!$user) return;
    
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership or admin
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $backupId = $server->createBackup($user['id'], 'manual');
        $backup = VpnServer::getBackup($backupId);
        
        echo json_encode([
            'success' => true,
            'backup' => $backup
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: List backups
Router::get('/api/servers/{id}/backups', function ($params) {
    header('Content-Type: application/json');
    
    $user = requireApiAuth();
    if (!$user) return;
    
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership or admin
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $backups = $server->listBackups();
        
        echo json_encode([
            'success' => true,
            'backups' => $backups,
            'count' => count($backups)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Restore backup
Router::post('/api/servers/{id}/restore', function ($params) {
    header('Content-Type: application/json');
    
    $user = requireApiAuth();
    if (!$user) return;
    
    $serverId = (int)$params['id'];
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $backupId = (int)($data['backup_id'] ?? 0);
    
    if ($backupId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'backup_id is required']);
        return;
    }
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership or admin
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $result = $server->restoreBackup($backupId);
        
        // Log the result for debugging
        error_log('Restore backup result: ' . json_encode($result));
        
        // Always return the result, even if success is false
        echo json_encode($result);
    } catch (Exception $e) {
        error_log('Restore backup exception: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'success' => false]);
    }
});

// API: Delete backup
Router::delete('/api/backups/{id}', function ($params) {
    header('Content-Type: application/json');
    
    $user = requireApiAuth();
    if (!$user) return;
    
    $backupId = (int)$params['id'];
    
    try {
        $backup = VpnServer::getBackup($backupId);
        
        if (!$backup) {
            http_response_code(404);
            echo json_encode(['error' => 'Backup not found']);
            return;
        }
        
        // Get server to check ownership
        $server = new VpnServer($backup['server_id']);
        $serverData = $server->getData();
        
        // Check ownership or admin
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        VpnServer::deleteBackup($backupId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Backup deleted successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: List clients
Router::get('/api/clients', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clients = VpnClient::listByUser($user['id']);
    echo json_encode(['clients' => $clients]);
});

// API: Get client details with stats
Router::get('/api/clients/{id}/details', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        if ($clientData['user_id'] != $user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        // Sync stats before returning
        $client->syncStats();
        
        // Reload data
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        $stats = $client->getFormattedStats();
        
        echo json_encode([
            'success' => true,
            'client' => [
                'id' => $clientData['id'],
                'name' => $clientData['name'],
                'server_id' => $clientData['server_id'],
                'client_ip' => $clientData['client_ip'],
                'status' => $clientData['status'],
                'created_at' => $clientData['created_at'],
                'stats' => $stats,
                'bytes_sent' => $clientData['bytes_sent'],
                'bytes_received' => $clientData['bytes_received'],
                'last_handshake' => $clientData['last_handshake'],
                'config' => $clientData['config'],
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(404);
        echo json_encode(['error' => 'Client not found']);
    }
});



// API: Revoke client
Router::post('/api/clients/{id}/revoke', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        if ($clientData['user_id'] != $user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        if ($client->revoke()) {
            echo json_encode(['success' => true, 'message' => 'Client revoked']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to revoke client']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Restore client
Router::post('/api/clients/{id}/restore', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        if ($clientData['user_id'] != $user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        if ($client->restore()) {
            echo json_encode(['success' => true, 'message' => 'Client restored']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to restore client']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Get server metrics
Router::get('/api/servers/{id}/metrics', function ($params) {
    header('Content-Type: application/json');
    
    // Check authentication - either JWT or session
    $user = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        // JWT authentication
        $token = $matches[1];
        $user = JWT::verify($token);
    } else if (isset($_SESSION['user_id'])) {
        // Session authentication
        $user = Auth::user();
    }
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $serverId = (int)$params['id'];
    $hours = isset($_GET['hours']) ? (float)$_GET['hours'] : 24;
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $metrics = ServerMonitoring::getServerMetrics($serverId, $hours);
        
        echo json_encode(['success' => true, 'metrics' => $metrics]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Get client metrics
Router::get('/api/clients/{id}/metrics', function ($params) {
    header('Content-Type: application/json');
    
    // Check authentication - either JWT or session
    $user = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        // JWT authentication
        $token = $matches[1];
        $user = JWT::verify($token);
    } else if (isset($_SESSION['user_id'])) {
        // Session authentication
        $user = Auth::user();
    }
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $clientId = (int)$params['id'];
    $hours = isset($_GET['hours']) ? (float)$_GET['hours'] : 24;
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Get server to check ownership
        $server = new VpnServer($clientData['server_id']);
        $serverData = $server->getData();
        
        // Check ownership
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $metrics = ServerMonitoring::getClientMetrics($clientId, $hours);
        
        echo json_encode(['success' => true, 'metrics' => $metrics]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Get server clients
Router::get('/api/servers/{id}/clients', function ($params) {
    header('Content-Type: application/json');
    
    $user = authenticateRequest();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $serverId = (int)$params['id'];
    
    try {
        $server = new VpnServer($serverId);
        $serverData = $server->getData();
        
        // Check ownership
        if ($serverData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $agentOnline = false;
        if (!empty($serverData['last_check_at'])) {
            $agentOnline = (time() - strtotime($serverData['last_check_at'])) < 120;
        }
        
        // Only run SSH-pull sync if the push agent is offline
        if (!$agentOnline) {
            try {
                VpnClient::syncAllStatsForServer($serverId);
            } catch (Throwable $e) {
                // Ignore sync errors to prevent API crashes
            }
        }
        
        $clients = VpnClient::listByServer($serverId);
        $clientsData = [];
        
        foreach ($clients as $clientData) {
            $client = new VpnClient($clientData['id']);
            $stats = $client->getFormattedStats();
            
            $lh = $clientData['last_handshake'];
            $isNever = !$lh || $lh === '0000-00-00 00:00:00' || $lh === '1970-01-01 00:00:00' || $lh === '0';
            
            $clientsData[] = [
                'id' => $clientData['id'],
                'name' => $clientData['name'],
                'client_ip' => $clientData['client_ip'],
                'status' => $clientData['status'],
                'created_at' => $clientData['created_at'],
                'stats' => $stats,
                'bytes_sent' => $clientData['bytes_sent'],
                'bytes_received' => $clientData['bytes_received'],
                'last_handshake' => $isNever ? null : $lh,
                'last_handshake_raw' => $isNever ? null : strtotime($lh),
                'city' => $clientData['city'],
            ];
        }
        
        echo json_encode([
            'success' => true, 
            'agent_online' => $agentOnline,
            'clients' => $clientsData
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Create client
Router::post('/api/clients/create', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $serverId = (int)($data['server_id'] ?? 0);
    $name = trim($data['name'] ?? '');
    $expiresInDays = isset($data['expires_in_days']) ? (int)$data['expires_in_days'] : null;
    
    if ($serverId <= 0 || empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'server_id and name are required']);
        return;
    }
    
    try {
        $clientId = VpnClient::create($serverId, $user['id'], $name, $expiresInDays);
        
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Return client data
        echo json_encode([
            'success' => true,
            'client' => [
                'id' => $clientData['id'],
                'name' => $clientData['name'],
                'server_id' => $clientData['server_id'],
                'client_ip' => $clientData['client_ip'],
                'status' => $clientData['status'],
                'expires_at' => $clientData['expires_at'],
                'created_at' => $clientData['created_at'],
                'config' => $clientData['config'],
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Set client expiration
Router::post('/api/clients/{id}/set-expiration', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clientId = (int)$params['id'];
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $expiresAt = $data['expires_at'] ?? null; // Y-m-d H:i:s format or null
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        if ($clientData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        VpnClient::setExpiration($clientId, $expiresAt);
        
        echo json_encode([
            'success' => true,
            'expires_at' => $expiresAt
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Extend client expiration
Router::post('/api/clients/{id}/extend', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clientId = (int)$params['id'];
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $days = (int)($data['days'] ?? 30);
    
    if ($days <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'days must be positive']);
        return;
    }
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        if ($clientData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        VpnClient::extendExpiration($clientId, $days);
        
        // Get updated expiration
        $client = new VpnClient($clientId);
        $updated = $client->getData();
        
        echo json_encode([
            'success' => true,
            'expires_at' => $updated['expires_at'],
            'extended_days' => $days
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Get expiring clients
Router::get('/api/clients/expiring', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $days = (int)($_GET['days'] ?? 7);
    
    try {
        $clients = VpnClient::getExpiringClients($days);
        
        // Filter by user if not admin
        if ($user['role'] !== 'admin') {
            $clients = array_filter($clients, function($c) use ($user) {
                return $c['user_id'] == $user['id'];
            });
        }
        
        echo json_encode([
            'success' => true,
            'clients' => array_values($clients),
            'count' => count($clients)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Set client traffic limit
Router::post('/api/clients/{id}/set-traffic-limit', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clientId = (int)$params['id'];
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    // limit_bytes can be null (unlimited) or positive integer
    $limitBytes = isset($data['limit_bytes']) ? (int)$data['limit_bytes'] : null;
    
    if ($limitBytes !== null && $limitBytes < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'limit_bytes must be positive or null for unlimited']);
        return;
    }
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        if ($clientData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $client->setTrafficLimit($limitBytes);
        
        echo json_encode([
            'success' => true,
            'limit_bytes' => $limitBytes,
            'limit_gb' => $limitBytes ? round($limitBytes / 1073741824, 2) : null
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Check client traffic limit status
Router::get('/api/clients/{id}/traffic-limit-status', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $clientId = (int)$params['id'];
    
    try {
        $client = new VpnClient($clientId);
        $clientData = $client->getData();
        
        // Check ownership
        if ($clientData['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        
        $status = $client->getTrafficLimitStatus();
        
        echo json_encode([
            'success' => true,
            'status' => $status
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Get clients over traffic limit
Router::get('/api/clients/overlimit', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    try {
        $clients = VpnClient::getClientsOverLimit();
        
        // Filter by user if not admin
        if ($user['role'] !== 'admin') {
            $clients = array_filter($clients, function($c) use ($user) {
                return $c['user_id'] == $user['id'];
            });
        }
        
        echo json_encode([
            'success' => true,
            'clients' => array_values($clients),
            'count' => count($clients)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

/**
 * SETTINGS ROUTES
 */

// Settings page
Router::get('/settings', function () {
    requireAuth();
    
    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->index();
});

// Save API key
Router::post('/settings/api-key', function () {
    requireAdmin();
    
    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->saveApiKey();
});

// Change password
Router::post('/settings/change-password', function () {
    requireAuth();
    
    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->changePassword();
});

// Add user
Router::post('/settings/add-user', function () {
    requireAdmin();
    
    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->addUser();
});

// Delete user
Router::post('/settings/delete-user/{id}', function ($params) {
    requireAdmin();
    
    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->deleteUser($params['id']);
});

// Save Backup Config
Router::post('/settings/backup-config', function () {
    requireAdmin();
    $pdo = DB::conn();
    $enabled = isset($_POST['enabled']) ? true : false;
    $botToken = trim($_POST['bot_token'] ?? '');
    $chatId = trim($_POST['chat_id'] ?? '');
    $schedule = $_POST['schedule'] ?? 'disabled';
    $retentionDays = (int)($_POST['retention_days'] ?? 7);

    $tgVal = json_encode(['enabled' => $enabled, 'bot_token' => $botToken, 'chat_id' => $chatId, 'schedule' => $schedule]);
    $retVal = json_encode($retentionDays);

    $stmt = $pdo->prepare("INSERT INTO settings (namespace, `key`, value) VALUES ('backup', 'telegram_settings', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $stmt->execute([$tgVal]);

    $stmt = $pdo->prepare("INSERT INTO settings (namespace, `key`, value) VALUES ('backup', 'retention_days', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $stmt->execute([$retVal]);

    $_SESSION['settings_success'] = 'Backup configuration saved successfully';
    redirect('/settings#backups');
});

// Create Backup
Router::post('/settings/backup-create', function () {
    requireAdmin();
    $target = $_POST['target'] ?? 'panel';
    $user = Auth::user();

    try {
        $bm = new BackupManager();
        if ($target === 'panel') {
            $path = $bm->createPanelBackup($user['id']);
            $bm->sendToTelegram($path);
            $_SESSION['settings_success'] = 'Full panel backup successfully created';
        } else {
            $serverId = (int)$target;
            $server = new VpnServer($serverId);
            $path = $server->createBackup($user['id']);
            $_SESSION['settings_success'] = 'Server backup successfully created';
        }
    } catch (Exception $e) {
        $_SESSION['settings_error'] = 'Backup failed: ' . $e->getMessage();
    }
    redirect('/settings#backups');
});

// Download Backup
Router::get('/settings/backup-download/{id}', function ($params) {
    requireAdmin();
    $id = (int)$params['id'];
    $pdo = DB::conn();

    $stmt = $pdo->prepare("SELECT backup_path, backup_name FROM server_backups WHERE id = ?");
    $stmt->execute([$id]);
    $backup = $stmt->fetch();

    if ($backup && file_exists($backup['backup_path'])) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($backup['backup_name']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backup['backup_path']));
        readfile($backup['backup_path']);
        exit;
    }
    $_SESSION['settings_error'] = 'Backup file not found';
    redirect('/settings#backups');
});

// Delete Backup
Router::get('/settings/backup-delete/{id}', function ($params) {
    requireAdmin();
    $id = (int)$params['id'];
    VpnServer::deleteBackup($id);
    $_SESSION['settings_success'] = 'Backup deleted successfully';
    redirect('/settings#backups');
});



/**
 * LANGUAGE ROUTES
 */

// Change language
Router::post('/language/change', function () {
    $lang = $_POST['language'] ?? '';
    
    if (Translator::setLanguage($lang)) {
        $_SESSION['success'] = 'Language changed successfully';
    } else {
        $_SESSION['error'] = 'Invalid language';
    }
    
    $redirect = $_POST['redirect'] ?? '/dashboard';
    redirect($redirect);
});

Router::get('/language/change', function () {
    redirect('/dashboard');
});

// API: Get translation statistics
Router::get('/api/translations/stats', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $stats = Translator::getStatistics();
    echo json_encode(['stats' => $stats]);
});

// API: Auto-translate missing keys
Router::post('/api/translations/auto-translate', function () {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $targetLang = $data['language'] ?? '';
    
    if (empty($targetLang)) {
        http_response_code(400);
        echo json_encode(['error' => 'Language is required']);
        return;
    }
    
    try {
        $stats = Translator::translateMissingKeys($targetLang);
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Export translations
Router::get('/api/translations/export/{lang}', function ($params) {
    header('Content-Type: application/json');
    
    $user = JWT::requireAuth();
    if (!$user) return;
    
    $lang = $params['lang'];
    
    try {
        $json = Translator::exportToJson($lang);
        header('Content-Disposition: attachment; filename="translations_' . $lang . '.json"');
        echo $json;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// Dispatch router
Router::dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
