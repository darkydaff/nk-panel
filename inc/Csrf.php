<?php
/**
 * CSRF Protection Helper Class
 */
class Csrf {
    /**
     * Get or generate the CSRF token stored in session
     */
    public static function getToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify if the provided CSRF token matches the session token
     */
    public static function verifyToken(?string $token): bool {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Validate CSRF token from current request (POST parameters, headers, or JSON body)
     */
    public static function validateRequest(): bool {
        // 1. Check direct POST parameters
        $token = $_POST['csrf_token'] ?? $_POST['_token'] ?? null;

        // 2. Check standard $_SERVER header variables
        if ($token === null) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN']
                ?? $_SERVER['HTTP_X_XSRF_TOKEN']
                ?? $_SERVER['HTTP_CSRF_TOKEN']
                ?? $_SERVER['HTTP_X_CSRF_TOKEN_']
                ?? null;
        }

        // 3. Scan all $_SERVER keys case-insensitively for CSRF headers
        if ($token === null) {
            foreach ($_SERVER as $key => $val) {
                if (str_starts_with($key, 'HTTP_')) {
                    $normalized = str_replace('_', '-', strtolower(substr($key, 5)));
                    if (in_array($normalized, ['x-csrf-token', 'x-xsrf-token', 'csrf-token', 'xsrf-token'], true)) {
                        $token = (string)$val;
                        break;
                    }
                }
            }
        }

        // 4. Check getallheaders() if available
        if ($token === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $val) {
                    $kLower = strtolower((string)$key);
                    if (in_array($kLower, ['x-csrf-token', 'x-xsrf-token', 'csrf-token', 'xsrf-token'], true)) {
                        $token = is_array($val) ? ($val[0] ?? null) : (string)$val;
                        break;
                    }
                }
            }
        }

        // 5. Check JSON payload in request body
        if ($token === null) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $jsonData = json_decode($rawInput, true);
                if (is_array($jsonData)) {
                    $token = $jsonData['csrf_token'] ?? $jsonData['_token'] ?? $jsonData['csrfToken'] ?? null;
                }
            }
        }

        return self::verifyToken($token ? trim((string)$token) : null);
    }

    /**
     * Generate HTML input field for form inclusion
     */
    public static function field(): string {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
