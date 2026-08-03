<?php

/**
 * ServerMonitoring - Collect and store server metrics
 * 
 * Collects:
 * - Client traffic speed
 */
class ServerMonitoring
{
    private VpnServer $server;
    private array $serverData;
    
    public function __construct(int $serverId)
    {
        $this->server = new VpnServer($serverId);
        $this->serverData = $this->server->getData();
    }
    
    /**
     * Collect client traffic metrics
     */
    public function collectClientMetrics(): array
    {
        $clients = VpnClient::listByServer($this->serverData['id']);
        $results = [];
        
        foreach ($clients as $client) {
            if ($client['status'] !== 'active') continue;
            
            $stats = $this->getClientStats($client);
            if ($stats) {
                $this->saveClientMetrics($client, $stats);
                $results[] = [
                    'client_id' => $client['id'],
                    'client_name' => $client['name'],
                    'speed_up_kbps' => $stats['speed_up_kbps'],
                    'speed_down_kbps' => $stats['speed_down_kbps'],
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get client current stats and calculate speed
     */
    private function getClientStats(array $client): ?array
    {
        $db = DB::conn();
        
        // Get current stats from server
        $containerName = $this->serverData['container_name'];
        $publicKey = $client['public_key'];
        
        $cmd = "docker exec {$containerName} /usr/local/bin/awg show all dump | grep '{$publicKey}' | awk '{print \$4, \$6, \$7, \$8}'";
        $result = $this->execSSH($cmd);
        
        if (!$result) return null;
        
        $parts = explode(' ', trim($result));
        if (count($parts) < 4) return null;
        
        $endpoint = $parts[0];
        $lastHandshake = (int)$parts[1];
        $bytesSent = (int)$parts[2];       // transfer_rx - client sent (upload)
        $bytesReceived = (int)$parts[3];   // transfer_tx - client received (download)
        
        // Get previous metrics (30 seconds ago)
        $stmt = $db->prepare("
            SELECT bytes_sent, bytes_received, collected_at
            FROM client_metrics
            WHERE client_id = ?
            ORDER BY collected_at DESC
            LIMIT 1
        ");
        $stmt->execute([$client['id']]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $speedUp = 0;
        $speedDown = 0;
        
        if ($previous) {
            $timeDiff = time() - strtotime($previous['collected_at']);
            if ($timeDiff > 0) {
                // Calculate speed in Kbps
                $rawBytesDiffSent = $bytesSent - (int)$previous['bytes_sent'];
                $rawBytesDiffReceived = $bytesReceived - (int)$previous['bytes_received'];
                
                $deltaSent = $rawBytesDiffSent >= 0 ? $rawBytesDiffSent : $bytesSent;
                $deltaReceived = $rawBytesDiffReceived >= 0 ? $rawBytesDiffReceived : $bytesReceived;
                
                // speedUp = Client Upload = deltaSent
                // speedDown = Client Download = deltaReceived
                $speedUp = round(($deltaSent * 8) / $timeDiff / 1000, 2);
                $speedDown = round(($deltaReceived * 8) / $timeDiff / 1000, 2);
            }
        }
        
        return [
            'bytes_sent' => $bytesSent,
            'bytes_received' => $bytesReceived,
            'speed_up_kbps' => $speedUp,
            'speed_down_kbps' => $speedDown,
            'last_handshake' => $lastHandshake,
            'endpoint' => $endpoint,
        ];
    }
    
    /**
     * Save client metrics to database
     */
    private function saveClientMetrics(array $client, array $stats): void
    {
        $clientId = $client['id'];
        $db = DB::conn();
        
        // Update GeoIP information if endpoint IP has changed
        VpnClient::updateGeoIpForClient($clientId, $stats['endpoint'] ?? null, $client['last_endpoint_ip'] ?? null);
        
        $stmt = $db->prepare("
            INSERT INTO client_metrics 
            (client_id, bytes_sent, bytes_received, speed_up_kbps, speed_down_kbps)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $clientId,
            $stats['bytes_sent'],
            $stats['bytes_received'],
            $stats['speed_up_kbps'],
            $stats['speed_down_kbps'],
        ]);
        
        // Update last_handshake in vpn_clients table if it's > 0
        if (!empty($stats['last_handshake']) && $stats['last_handshake'] > 0) {
            $lastHandshake = date('Y-m-d H:i:s', $stats['last_handshake']);
            $stmt = $db->prepare("
                UPDATE vpn_clients 
                SET last_handshake = ?, last_sync_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$lastHandshake, $clientId]);
        }
    }
    
    /**
     * Get server metrics for last 24 hours
     */
    public static function getServerMetrics(int $serverId, int $hours = 24): array
    {
        $db = DB::conn();
        
        $stmt = $db->prepare("
            SELECT *
            FROM server_metrics
            WHERE server_id = ?
            AND collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY collected_at ASC
        ");
        
        $stmt->execute([$serverId, $hours]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get client metrics for last 24 hours
     */
    public static function getClientMetrics(int $clientId, float $hours = 24): array
    {
        $db = DB::conn();
        
        // Determine interval in minutes (N) based on hours
        $bucketMinutes = 5;
        if ($hours <= 2) {
            $bucketMinutes = 1;
        } elseif ($hours <= 12) {
            $bucketMinutes = 2;
        } elseif ($hours <= 24) {
            $bucketMinutes = 5;
        } elseif ($hours <= 48) {
            $bucketMinutes = 10;
        } elseif ($hours <= 168) {
            $bucketMinutes = 30;
        } else {
            $bucketMinutes = 60;
        }
        $seconds = $bucketMinutes * 60;
        $since = date('Y-m-d H:i:s', time() - (int)($hours * 3600));
        
        $stmt = $db->prepare("
            SELECT 
                client_id,
                MAX(speed_up_kbps) as speed_up_kbps,
                MAX(speed_down_kbps) as speed_down_kbps,
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(collected_at) / ?) * ?) as time_bucket
            FROM client_metrics
            WHERE client_id = ?
            AND collected_at >= ?
            GROUP BY client_id, time_bucket
            ORDER BY time_bucket ASC
        ");
        
        $stmt->execute([$seconds, $seconds, $clientId, $since]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get aggregated upload/download speeds for a server from its active clients
     */
    public static function getAggregatedServerSpeed(int $serverId): array
    {
        $db = DB::conn();
        $stmt = $db->prepare("
            SELECT 
                COALESCE(SUM(speed_up_kbps), 0) as speed_up,
                COALESCE(SUM(speed_down_kbps), 0) as speed_down
            FROM vpn_clients
            WHERE server_id = ? AND status = 'active'
        ");
        $stmt->execute([$serverId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['speed_up' => 0.00, 'speed_down' => 0.00];
    }
    
    /**
     * Clean old metrics (older than 24 hours)
     */
    public static function cleanOldMetrics(): void
    {
        $db = DB::conn();
        
        // Clean server metrics
        $db->exec("DELETE FROM server_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        
        // Clean client metrics
        $db->exec("DELETE FROM client_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }
    
    /**
     * Execute SSH command on server
     */
    private function execSSH(string $cmd): ?string
    {
        $host = $this->serverData['host'];
        $port = $this->serverData['port'];
        $username = $this->serverData['username'];
        $password = $this->serverData['password'];
        
        $sshCmd = sprintf(
            'SSHPASS=%s sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p %d %s@%s %s 2>/dev/null',
            escapeshellarg($password),
            $port,
            escapeshellarg($username),
            escapeshellarg($host),
            escapeshellarg($cmd)
        );
        
        $output = shell_exec($sshCmd);
        
        return $output ?: null;
    }
}
