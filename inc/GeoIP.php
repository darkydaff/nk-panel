<?php
/**
 * GeoIP Service Helper
 * Performs GeoIP lookups on client IP addresses using ip-api.com
 */
class GeoIP {
    /**
     * Perform GeoIP lookup on a public IP address
     * 
     * @param string $ip IP address
     * @return array|null Array containing country, city, and isp, or null on failure
     */
    public static function lookup(string $ip): ?array {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Ignore private/reserved IP addresses (e.g. 10.x.x.x, 192.168.x.x, 127.x.x.x)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,message,country,city,isp";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 seconds timeout to prevent blocking
        curl_setopt($ch, CURLOPT_USERAGENT, 'Nk-Panel/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (($data['status'] ?? '') !== 'success') {
            return null;
        }

        return [
            'country' => $data['country'] ?? 'Unknown',
            'city' => $data['city'] ?? 'Unknown',
            'isp' => $data['isp'] ?? 'Unknown'
        ];
    }
}
