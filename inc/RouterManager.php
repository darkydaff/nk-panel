<?php
/**
 * Router Management Service
 * Orchestrates interaction between KeeneticRouter adapter, database states, and VPN client data
 */
class RouterManager {

    /**
     * Synchronize routers table from cached ext_clients
     * Automatically creates router records for clients with configured domain and pass
     */
    public static function syncRoutersFromExtClients(): int {
        $pdo = DB::conn();
        
        // Find ext_clients that have domain and pass populated
        $stmt = $pdo->query("
            SELECT code, domain, pass, name, router 
            FROM ext_clients 
            WHERE domain IS NOT NULL AND domain != '' 
              AND pass IS NOT NULL AND pass != ''
        ");
        $extClients = $stmt->fetchAll();
        
        $syncedCount = 0;
        foreach ($extClients as $ec) {
            $code = $ec['code'];
            $domain = $ec['domain'];
            $pass = $ec['pass'];
            $model = $ec['router'] ?? null;
            
            // Check if router already exists
            $stmtCheck = $pdo->prepare("SELECT id, domain, password, router_model FROM routers WHERE ext_client_code = ?");
            $stmtCheck->execute([$code]);
            $existing = $stmtCheck->fetch();
            
            if ($existing) {
                $needsUpdate = false;
                $updateSqlParts = [];
                $updateParams = [];
                
                if ($existing['domain'] !== $domain || $existing['password'] !== $pass) {
                    $updateSqlParts[] = "domain = ?";
                    $updateSqlParts[] = "password = ?";
                    $updateSqlParts[] = "status = 'pending'";
                    $updateSqlParts[] = "error_message = NULL";
                    $updateParams[] = $domain;
                    $updateParams[] = $pass;
                    $needsUpdate = true;
                }
                
                if ($existing['router_model'] !== $model) {
                    $updateSqlParts[] = "router_model = ?";
                    $updateParams[] = $model;
                    $needsUpdate = true;
                }
                
                if ($needsUpdate) {
                    $updateParams[] = $existing['id'];
                    $sql = "UPDATE routers SET " . implode(', ', $updateSqlParts) . " WHERE id = ?";
                    $stmtUpdate = $pdo->prepare($sql);
                    $stmtUpdate->execute($updateParams);
                    $syncedCount++;
                }
            } else {
                // Create new router record
                $stmtInsert = $pdo->prepare("
                    INSERT INTO routers (ext_client_code, domain, password, router_model, status) 
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $stmtInsert->execute([$code, $domain, $pass, $model]);
                $syncedCount++;
            }
        }
        
        // Prune routers that are no longer present or active in ext_clients
        $pdo->exec("
            DELETE FROM routers 
            WHERE ext_client_code NOT IN (
                SELECT code FROM ext_clients 
                WHERE domain IS NOT NULL AND domain != '' 
                  AND pass IS NOT NULL AND pass != ''
            )
        ");
        
        return $syncedCount;
    }

    /**
     * Push VPN Client configuration to Keenetic Router
     */
    public static function pushConfigToRouter(int $routerId, ?int $vpnClientId = null): array {
        $pdo = DB::conn();
        
        // 1. Get router details
        $stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ?");
        $stmt->execute([$routerId]);
        $router = $stmt->fetch();
        
        if (!$router) {
            throw new Exception("Router not found.");
        }
        
        // Update status to pending/connecting
        $pdo->prepare("UPDATE routers SET status = 'pending', error_message = NULL WHERE id = ?")
            ->execute([$routerId]);
            
        try {
            // 2. Find VPN Client configuration to push
            if ($vpnClientId) {
                $stmtClient = $pdo->prepare("
                    SELECT c.*, s.host AS server_host, s.vpn_port AS server_vpn_port 
                    FROM vpn_clients c
                    JOIN vpn_servers s ON c.server_id = s.id
                    WHERE c.id = ? AND c.ext_client_code = ?
                ");
                $stmtClient->execute([$vpnClientId, $router['ext_client_code']]);
            } else {
                $stmtClient = $pdo->prepare("
                    SELECT c.*, s.host AS server_host, s.vpn_port AS server_vpn_port 
                    FROM vpn_clients c
                    JOIN vpn_servers s ON c.server_id = s.id
                    WHERE c.ext_client_code = ? AND c.status = 'active'
                    ORDER BY c.created_at DESC LIMIT 1
                ");
                $stmtClient->execute([$router['ext_client_code']]);
            }
            $vpnClient = $stmtClient->fetch();
            
            if (!$vpnClient) {
                if ($vpnClientId) {
                    throw new Exception("VPN Client configuration ID $vpnClientId not found or not linked to client code: " . $router['ext_client_code']);
                } else {
                    throw new Exception("No active VPN client configuration linked to client code: " . $router['ext_client_code']);
                }
            }
            
            $configContent = $vpnClient['config'];
            if (empty($configContent)) {
                throw new Exception("VPN Client configuration is empty. Try regenerating it first.");
            }
            
            // 3. Connect to the router
            $login = $router['login'] ?: 'admin';
            $adapter = new KeeneticRouter($router['domain'], $router['password'], $login);
            $adapter->setTimeout(5);
            
            // Test connection first
            $connTest = $adapter->testConnection();
            if (!$connTest['success']) {
                throw new Exception("Connection/Auth failed: " . ($connTest['error'] ?? 'Unknown error'));
            }
            
            // 4. Import configuration
            $parsedConf = KeeneticRouter::parseWgConfig($configContent);
            
            $clientCode = $router['ext_client_code'];
            if ($clientCode[0] !== '#') {
                $clientCode = '#' . $clientCode;
            }
            $peerName = $parsedConf['peer']['Name'] ?? '';
            $description = $clientCode;
            if (!empty($peerName)) {
                $description .= " - " . $peerName;
            }
            
            $interfaceId = $adapter->importWgConfig($configContent, $description, $router['wg_interface_id']);
            
            // 5. Query applied obfuscation parameters
            $obfuscationResult = $adapter->applyObfuscation($interfaceId, $parsedConf['interface']);
            
            // 6. Update database record on success
            $stmtSuccess = $pdo->prepare("
                UPDATE routers 
                SET vpn_client_id = ?, 
                    server_id = ?, 
                    wg_interface_id = ?, 
                    wg_interface_name = ?, 
                    pushed_awg_params = ?, 
                    status = 'connected', 
                    last_push_at = NOW(), 
                    last_check_at = NOW(), 
                    error_message = NULL 
                WHERE id = ?
            ");
            
            $stmtSuccess->execute([
                $vpnClient['id'],
                $vpnClient['server_id'],
                $interfaceId,
                $description,
                json_encode($obfuscationResult['applied']),
                $routerId
            ]);
            
            return [
                'success' => true,
                'interface_id' => $interfaceId,
                'router_model' => $router['router_model'] ?: 'Keenetic',
                'firmware_version' => $router['firmware_version'] ?: 'Unknown',
                'obfuscation' => $obfuscationResult
            ];
            
        } catch (Throwable $e) {
            // Update router status on failure
            $stmtError = $pdo->prepare("
                UPDATE routers 
                SET status = 'error', 
                    error_message = ?, 
                    last_check_at = NOW() 
                WHERE id = ?
            ");
            $stmtError->execute([$e->getMessage(), $routerId]);
            
            throw $e;
        }
    }

    /**
     * Check connection and VPN interface status of a router
     */
    public static function checkRouterStatus(int $routerId): array {
        $pdo = DB::conn();
        
        $stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ?");
        $stmt->execute([$routerId]);
        $router = $stmt->fetch();
        
        if (!$router) {
            return ['success' => false, 'error' => 'Router not found'];
        }
        
        try {
            $login = $router['login'] ?: 'admin';
            $adapter = new KeeneticRouter($router['domain'], $router['password'], $login);
            $adapter->setTimeout(5);
            
            $connTest = $adapter->testConnection();
            if (!$connTest['success']) {
                // Mark router as offline
                $pdo->prepare("UPDATE routers SET status = 'offline', error_message = ?, last_check_at = NOW() WHERE id = ?")
                    ->execute([$connTest['error'] ?? 'Connection timed out', $routerId]);
                    
                return [
                    'success' => false,
                    'status' => 'offline',
                    'error' => $connTest['error']
                ];
            }
            
            $status = 'connected';
            $errorMsg = null;
            
            // Check interface status if we have pushed config
            if ($router['wg_interface_id']) {
                $ifStatus = $adapter->getInterfaceStatus($router['wg_interface_id']);
                if (empty($ifStatus)) {
                    $status = 'error';
                    $errorMsg = "Interface {$router['wg_interface_id']} not found on the router.";
                } else {
                    $isUp = isset($ifStatus['state']) && strtolower($ifStatus['state']) === 'up';
                    $connected = isset($ifStatus['connected']) && ($ifStatus['connected'] === true || $ifStatus['connected'] === 'yes');
                    
                    if (!$isUp) {
                        $status = 'error';
                        $errorMsg = "Interface {$router['wg_interface_id']} is disabled on the router.";
                    }
                }
            } else {
                $status = 'connected'; // Connection works, but no interface pushed yet
            }
            
            // Update database
            $stmtUpdate = $pdo->prepare("
                UPDATE routers 
                SET status = ?, 
                    error_message = ?, 
                    last_check_at = NOW() 
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $status,
                $errorMsg,
                $routerId
            ]);
            
            return [
                'success' => true,
                'status' => $status,
                'router_model' => $router['router_model'] ?: 'Keenetic',
                'firmware_version' => $router['firmware_version'] ?: 'Unknown',
                'error' => $errorMsg
            ];
            
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE routers SET status = 'error', error_message = ?, last_check_at = NOW() WHERE id = ?")
                ->execute([$e->getMessage(), $routerId]);
                
            return [
                'success' => false,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Batch check status for all active/configured routers
     */
    public static function checkAllRouters(): array {
        $pdo = DB::conn();
        $stmt = $pdo->query("SELECT id FROM routers");
        $routerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $results = [];
        foreach ($routerIds as $id) {
            $results[$id] = self::checkRouterStatus((int)$id);
        }
        return $results;
    }

    /**
     * Remove the WireGuard interface from the router
     */
    public static function removeFromRouter(int $routerId): bool {
        $pdo = DB::conn();
        
        $stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ?");
        $stmt->execute([$routerId]);
        $router = $stmt->fetch();
        
        if (!$router || empty($router['wg_interface_id'])) {
            return false;
        }
        
        try {
            $login = $router['login'] ?: 'admin';
            $adapter = new KeeneticRouter($router['domain'], $router['password'], $login);
            $adapter->setTimeout(5);
            $adapter->authenticate();
            
            // Delete interface
            $adapter->removeInterface($router['wg_interface_id']);
            
            // Reset DB status
            $stmtReset = $pdo->prepare("
                UPDATE routers 
                SET wg_interface_id = NULL, 
                    wg_interface_name = NULL, 
                    vpn_client_id = NULL, 
                    server_id = NULL, 
                    pushed_awg_params = NULL, 
                    status = 'pending', 
                    error_message = NULL, 
                    last_check_at = NOW() 
                WHERE id = ?
            ");
            $stmtReset->execute([$routerId]);
            
            return true;
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE routers SET status = 'error', error_message = ?, last_check_at = NOW() WHERE id = ?")
                ->execute([$e->getMessage(), $routerId]);
            throw $e;
        }
    }

    /**
     * Push selected FQDN routing groups to Keenetic Router
     */
    public static function pushRoutingGroupsToRouter(int $routerId, array $groupIds): array {
        $pdo = DB::conn();

        // 1. Get router details
        $stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ?");
        $stmt->execute([$routerId]);
        $router = $stmt->fetch();

        if (!$router) {
            throw new Exception("Router not found.");
        }

        if (empty($router['wg_interface_id'])) {
            throw new Exception("No active WireGuard interface configured on this router. Push configuration first.");
        }

        if (empty($groupIds)) {
            throw new Exception("No routing groups selected.");
        }

        // 2. Get FQDN groups from database
        $inQuery = implode(',', array_fill(0, count($groupIds), '?'));
        $stmtGroups = $pdo->prepare("SELECT * FROM routing_groups WHERE id IN ($inQuery)");
        $stmtGroups->execute($groupIds);
        $groups = $stmtGroups->fetchAll();

        if (empty($groups)) {
            throw new Exception("Selected routing groups not found in database.");
        }

        // 3. Connect to the router
        $login = $router['login'] ?: 'admin';
        $adapter = new KeeneticRouter($router['domain'], $router['password'], $login);
        $adapter->setTimeout(15); // Give it extra time for large arrays

        $connTest = $adapter->testConnection();
        if (!$connTest['success']) {
            throw new Exception("Connection/Auth failed: " . ($connTest['error'] ?? 'Unknown error'));
        }

        // 4. Push FQDN groups
        $adapter->pushRoutingGroups($groups, $router['wg_interface_id']);

        return [
            'success' => true,
            'groups_count' => count($groups)
        ];
    }
}
