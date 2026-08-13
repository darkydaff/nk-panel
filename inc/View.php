<?php
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;

class View {
  private static ?Environment $twig = null;

  public static function init(string $templatesPath, array $globals = []): void {
    if (!class_exists(Environment::class)) {
      throw new RuntimeException('Twig is not installed. Run composer require twig/twig');
    }
    $loader = new FilesystemLoader($templatesPath);
    self::$twig = new Environment($loader, [
      'cache' => false,
      'autoescape' => 'html',
    ]);

    // Add translation function
    $tFunc = new TwigFunction('t', function (string $key, array $params = [], ?string $default = null) {
      return Translator::t($key, $params, $default);
    });
    self::$twig->addFunction($tFunc);

    // Add format_bytes filter
    $formatBytesFilter = new TwigFilter('format_bytes', function ($bytes, bool $splitUnit = false) {
      $bytes = (int)$bytes;
      if ($bytes <= 0) {
        return $splitUnit ? '0 <span class="text-xs text-slate-500">MB</span>' : '0 MB';
      }
      $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
      $i = (int)floor(log($bytes, 1024));
      $i = max(0, min($i, count($units) - 1));
      $val = $bytes / pow(1024, $i);
      $decimals = $i === 0 ? 0 : ($i === 1 ? 1 : 2); // 0 dec for B, 1 dec for KB, 2 dec for MB/GB/TB
      
      $formattedNum = number_format($val, $decimals);
      if ($splitUnit) {
        return $formattedNum . ' <span class="text-xs text-slate-500">' . $units[$i] . '</span>';
      }
      return $formattedNum . ' ' . $units[$i];
    }, ['is_safe' => ['html']]);
    self::$twig->addFilter($formatBytesFilter);

    // Add format_date filter (formats YYYY-MM-DD to DD.MM.YYYY)
    $formatDateFilter = new TwigFilter('format_date', function ($dateStr) {
      if (empty($dateStr)) return '-';
      $str = trim((string)$dateStr);
      if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?$/', $str, $m)) {
          $datePart = "{$m[3]}.{$m[2]}.{$m[1]}";
          return !empty($m[4]) ? "{$datePart} {$m[4]}" : $datePart;
      }
      return $str;
    });
    self::$twig->addFilter($formatDateFilter);

    // Add flag emoji function
    $flagFunc = new TwigFunction('getFlag', function (string $langCode) {
      $flags = [
        'en' => '🇬🇧',
        'ru' => '🇷🇺',
        'es' => '🇪🇸',
        'de' => '🇩🇪',
        'fr' => '🇫🇷',
        'zh' => '🇨🇳',
      ];
      return $flags[$langCode] ?? '🌐';
    });
    self::$twig->addFunction($flagFunc);

    // Add CSRF helper function
    $csrfFunc = new TwigFunction('csrf_field', function () {
      return Csrf::field();
    }, ['is_safe' => ['html']]);
    self::$twig->addFunction($csrfFunc);

    $csrfTokenFunc = new TwigFunction('csrf_token', function () {
      return Csrf::getToken();
    });
    self::$twig->addFunction($csrfTokenFunc);

    // Add globals
    self::$twig->addGlobal('csrf_token', Csrf::getToken());
    foreach ($globals as $k => $v) self::$twig->addGlobal($k, $v);
  }

  public static function render(string $template, array $vars = []): void {
    if (!self::$twig) throw new RuntimeException('Twig is not initialized');
    echo self::$twig->render($template, $vars);
  }
}