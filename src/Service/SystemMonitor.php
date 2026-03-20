<?php

namespace Drupal\icon_businessos\Service;

/**
 * ICON BusinessOS — System Monitor for Drupal.
 *
 * Collects server-level resource metrics from within the Drupal environment.
 * Equivalent to the WordPress class-system-monitor.php but using Drupal APIs.
 */
class SystemMonitor {

  /**
   * Collect all system resource metrics.
   */
  public function collect(): array {
    return [
      'disk_total_gb' => $this->getDiskTotal(),
      'disk_free_gb' => $this->getDiskFree(),
      'disk_used_gb' => $this->getDiskUsed(),
      'disk_usage_pct' => $this->getDiskUsagePct(),

      'memory_total_mb' => $this->getMemoryTotal(),
      'memory_used_mb' => $this->getMemoryUsed(),
      'memory_free_mb' => $this->getMemoryFree(),
      'memory_usage_pct' => $this->getMemoryUsagePct(),

      'php_memory_limit_mb' => $this->getPhpMemoryLimit(),
      'php_memory_usage_mb' => round(memory_get_usage(TRUE) / 1048576, 1),
      'php_memory_peak_mb' => round(memory_get_peak_usage(TRUE) / 1048576, 1),

      'load_avg_1m' => $this->getLoadAvg(0),
      'load_avg_5m' => $this->getLoadAvg(1),
      'load_avg_15m' => $this->getLoadAvg(2),
      'cpu_cores' => $this->getCpuCores(),

      'server_uptime_hours' => $this->getServerUptime(),

      'php_version' => PHP_VERSION,
      'php_sapi' => PHP_SAPI,
      'php_extensions' => $this->getCriticalExtensions(),

      'drupal_version' => \Drupal::VERSION,
      'active_theme' => \Drupal::theme()->getActiveTheme()->getName(),
      'active_modules_count' => count(\Drupal::moduleHandler()->getModuleList()),
      'module_updates_available' => $this->getModuleUpdateCount(),

      'db_size_mb' => $this->getDatabaseSize(),

      'php_error_count_24h' => $this->countPhpErrors(24),
      'php_error_log_path' => ini_get('error_log') ?: 'not set',

      'cron_last_run' => \Drupal::state()->get('system.cron_last', 0),
      'cron_overdue' => (\Drupal::time()->getRequestTime() - \Drupal::state()->get('system.cron_last', 0)) > 3600,

      'collected_at' => gmdate('c'),
    ];
  }

  private function getDiskTotal(): ?float {
    $total = @disk_total_space(DRUPAL_ROOT);
    return $total ? round($total / 1073741824, 1) : NULL;
  }

  private function getDiskFree(): ?float {
    $free = @disk_free_space(DRUPAL_ROOT);
    return $free ? round($free / 1073741824, 1) : NULL;
  }

  private function getDiskUsed(): ?float {
    $total = @disk_total_space(DRUPAL_ROOT);
    $free = @disk_free_space(DRUPAL_ROOT);
    return ($total && $free) ? round(($total - $free) / 1073741824, 1) : NULL;
  }

  private function getDiskUsagePct(): ?float {
    $total = @disk_total_space(DRUPAL_ROOT);
    $free = @disk_free_space(DRUPAL_ROOT);
    return ($total && $total > 0) ? round((($total - $free) / $total) * 100, 1) : NULL;
  }

  private function getMemoryTotal(): ?int {
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
      $meminfo = @file_get_contents('/proc/meminfo');
      if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
        return (int) round((int) $m[1] / 1024);
      }
    }
    return NULL;
  }

  private function getMemoryFree(): ?int {
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
      $meminfo = @file_get_contents('/proc/meminfo');
      if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
        return (int) round((int) $m[1] / 1024);
      }
    }
    return NULL;
  }

  private function getMemoryUsed(): ?int {
    $total = $this->getMemoryTotal();
    $free = $this->getMemoryFree();
    return ($total !== NULL && $free !== NULL) ? $total - $free : NULL;
  }

  private function getMemoryUsagePct(): ?float {
    $total = $this->getMemoryTotal();
    $used = $this->getMemoryUsed();
    return ($total && $total > 0) ? round(($used / $total) * 100, 1) : NULL;
  }

  private function getPhpMemoryLimit(): float {
    $limit = ini_get('memory_limit');
    if ($limit === '-1') return -1;
    return (float) (\Drupal\Component\Utility\Bytes::toNumber($limit) / 1048576);
  }

  private function getLoadAvg(int $index): ?float {
    if (function_exists('sys_getloadavg')) {
      $load = sys_getloadavg();
      return isset($load[$index]) ? round($load[$index], 2) : NULL;
    }
    return NULL;
  }

  private function getCpuCores(): ?int {
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/cpuinfo')) {
      return substr_count(@file_get_contents('/proc/cpuinfo'), 'processor');
    }
    return NULL;
  }

  private function getServerUptime(): ?float {
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/uptime')) {
      $uptime = @file_get_contents('/proc/uptime');
      if ($uptime) {
        return round((float) explode(' ', trim($uptime))[0] / 3600, 1);
      }
    }
    return NULL;
  }

  private function getCriticalExtensions(): array {
    $check = ['curl', 'mbstring', 'openssl', 'zip', 'gd', 'imagick', 'xml', 'json', 'mysqli', 'pdo_mysql', 'intl', 'opcache'];
    $loaded = [];
    foreach ($check as $ext) {
      $loaded[$ext] = extension_loaded($ext);
    }
    return $loaded;
  }

  private function getModuleUpdateCount(): int {
    if (\Drupal::moduleHandler()->moduleExists('update')) {
      try {
        $available = update_get_available(TRUE);
        $projects = update_calculate_project_data($available);
        $count = 0;
        foreach ($projects as $project) {
          if (isset($project['status']) && $project['status'] !== UPDATE_CURRENT) {
            $count++;
          }
        }
        return $count;
      }
      catch (\Exception $e) {
        return 0;
      }
    }
    return 0;
  }

  /**
   * Get full module inventory with update status.
   */
  public function getModuleInventory(): array {
    $modules = \Drupal::moduleHandler()->getModuleList();
    $inventory = [];
    foreach ($modules as $name => $extension) {
      $info = \Drupal::service('extension.list.module')->getExtensionInfo($name);
      $inventory[] = [
        'name' => $name,
        'display_name' => $info['name'] ?? $name,
        'version' => $info['version'] ?? 'unknown',
        'package' => $info['package'] ?? '',
        'status' => 'enabled',
        'core_module' => strpos($extension->getPath(), 'core/') === 0,
      ];
    }
    return [
      'total' => count($inventory),
      'core' => count(array_filter($inventory, fn($m) => $m['core_module'])),
      'contrib' => count(array_filter($inventory, fn($m) => !$m['core_module'])),
      'updates_available' => $this->getModuleUpdateCount(),
      'modules' => $inventory,
      'collected_at' => gmdate('c'),
    ];
  }

  private function getDatabaseSize(): ?float {
    try {
      $db = \Drupal::database();
      $db_name = $db->getConnectionOptions()['database'] ?? NULL;
      if (!$db_name) return NULL;
      $result = $db->query(
        "SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) AS size FROM information_schema.TABLES WHERE table_schema = :db",
        [':db' => $db_name]
      )->fetchField();
      return $result ? (float) $result : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  private function countPhpErrors(int $hours): int {
    $log_path = ini_get('error_log');
    if (!$log_path || !is_readable($log_path)) return 0;
    $cutoff = time() - ($hours * 3600);
    $count = 0;
    $fh = @fopen($log_path, 'r');
    if (!$fh) return 0;
    fseek($fh, max(0, filesize($log_path) - 102400));
    while (($line = fgets($fh)) !== FALSE) {
      if (preg_match('/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
        $ts = strtotime($m[1]);
        if ($ts && $ts > $cutoff) $count++;
      }
    }
    fclose($fh);
    return $count;
  }

  /**
   * Get PHP error log tail.
   */
  public function getPhpErrors(int $lines = 50): array {
    $log_path = ini_get('error_log');
    if (!$log_path || !is_readable($log_path)) {
      return ['available' => FALSE, 'path' => $log_path ?: 'not set', 'lines' => []];
    }
    $fh = @fopen($log_path, 'r');
    if (!$fh) return ['available' => FALSE, 'path' => $log_path, 'lines' => []];
    $size = filesize($log_path);
    fseek($fh, max(0, $size - 204800));
    $all_lines = [];
    while (($line = fgets($fh)) !== FALSE) {
      $all_lines[] = rtrim($line);
    }
    fclose($fh);
    $result = array_slice($all_lines, -$lines);
    return [
      'available' => TRUE,
      'path' => $log_path,
      'total_size' => $size,
      'lines_returned' => count($result),
      'lines' => $result,
      'collected_at' => gmdate('c'),
    ];
  }

}
