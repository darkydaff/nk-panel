<?php
class Config {
  protected static array $env = [];

  public static function load(string $path): void {
    if (!file_exists($path)) {
      // allow running with only environment variables exported
      return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (str_starts_with(trim($line), '#')) continue;
      $parts = explode('=', $line, 2);
      if (count($parts) !== 2) continue;
      $key = trim($parts[0]);
      $value = trim($parts[1]);
      $value = trim($value, "\"' ");
      self::$env[$key] = $value;
      @putenv($key . '=' . $value);
    }
  }

  public static function get(string $key, $default = null) {
    $env = getenv($key);
    if ($env !== false && $env !== null) return $env;
    return self::$env[$key] ?? $default;
  }

  public static function getOutgoingIp(): ?string {
    try {
      $pdo = DB::conn();
      $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE namespace = 'network' AND `key` = 'outgoing_bind_ip' LIMIT 1");
      $stmt->execute();
      $res = $stmt->fetchColumn();
      if ($res) {
        $val = json_decode($res, true);
        if (is_string($val) && trim($val) !== '') {
          return trim($val);
        }
      }
    } catch (\Throwable $e) {
      // Return default if DB table/connection fails
    }
    return null;
  }
}