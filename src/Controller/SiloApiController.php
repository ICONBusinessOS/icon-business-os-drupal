<?php

namespace Drupal\icon_businessos\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST API controller for ICON BusinessOS silo endpoints.
 *
 * Endpoints:
 *   GET /api/icon/v1/heartbeat   → composite silo health (v2.3 contract)
 *   GET /api/icon/v1/resources   → server resource metrics
 *   GET /api/icon/v1/security    → security scan summary
 *   GET /api/icon/v1/content     → content intelligence signals
 *   GET /api/icon/v1/modules     → module inventory + update status
 *   GET /api/icon/v1/errors      → PHP error log tail
 *   GET /api/icon/v1/status      → lightweight liveness check (no auth)
 */
class SiloApiController extends ControllerBase {

  const SILO_VERSION = '2.3.0';
  const PLUGIN_VERSION = '1.0.0';

  /**
   * Custom access check — X-Silo-Key header or admin permission.
   */
  public function accessCheck(AccountInterface $account, Request $request = NULL) {
    // Check admin permission first
    if ($account->hasPermission('administer icon businessos')) {
      return AccessResult::allowed();
    }

    // Check X-Silo-Key header
    if ($request) {
      $silo_key = $request->headers->get('X-Silo-Key');
      $stored_key = $this->config('icon_businessos.settings')->get('silo_api_key');
      if ($silo_key && $stored_key && hash_equals($stored_key, $silo_key)) {
        return AccessResult::allowed()->addCacheContexts(['headers:X-Silo-Key']);
      }
    }

    return AccessResult::forbidden('Authentication required.');
  }

  /**
   * GET /api/icon/v1/heartbeat — Full silo contract v2.3 payload.
   */
  public function heartbeat() {
    $system = \Drupal::service('icon_businessos.system_monitor')->collect();
    $security = \Drupal::service('icon_businessos.security_scanner')->getSummary();
    $content = \Drupal::service('icon_businessos.content_intelligence')->collect();

    $health_score = $this->computeHealthScore($system, $security);
    $grade = $this->scoreToGrade($health_score);

    $config = $this->config('icon_businessos.settings');

    $payload = [
      'silo_version' => self::SILO_VERSION,
      'plugin_version' => self::PLUGIN_VERSION,
      'tenant_id' => $config->get('tenant_id'),
      'site_url' => \Drupal::request()->getSchemeAndHttpHost(),
      'site_name' => $this->config('system.site')->get('name'),
      'cms' => 'drupal',
      'cms_version' => \Drupal::VERSION,
      'php_version' => PHP_VERSION,
      'health_score' => $health_score,
      'health_grade' => $grade,
      'system_resources' => [
        'disk_usage_pct' => $system['disk_usage_pct'],
        'memory_usage_pct' => $system['memory_usage_pct'],
        'load_avg_1m' => $system['load_avg_1m'],
        'php_memory_usage_mb' => $system['php_memory_usage_mb'],
        'php_error_count_24h' => $system['php_error_count_24h'],
        'module_updates_available' => $system['module_updates_available'],
        'db_size_mb' => $system['db_size_mb'],
      ],
      'security' => [
        'file_changes_since_baseline' => $security['file_changes_since_baseline'],
        'vulnerable_modules' => $security['vulnerable_modules'] ?? 0,
        'failed_logins_24h' => $security['failed_logins_24h'],
        'core_integrity' => $security['core_integrity'],
      ],
      'content' => $content,
      'capabilities' => [
        'http_probes', 'system_resources', 'security_scanning',
        'content_intelligence', 'phone_home', 'silo_heartbeat',
      ],
      'timestamp' => gmdate('c'),
    ];

    return new JsonResponse($payload);
  }

  /**
   * GET /api/icon/v1/resources — Server resource metrics.
   */
  public function resources() {
    $data = \Drupal::service('icon_businessos.system_monitor')->collect();
    return new JsonResponse($data);
  }

  /**
   * GET /api/icon/v1/security — Security scan summary.
   */
  public function security() {
    $data = \Drupal::service('icon_businessos.security_scanner')->getSummary();
    return new JsonResponse($data);
  }

  /**
   * GET /api/icon/v1/content — Content intelligence signals.
   */
  public function content() {
    $data = \Drupal::service('icon_businessos.content_intelligence')->collect();
    return new JsonResponse($data);
  }

  /**
   * GET /api/icon/v1/modules — Module inventory with update status.
   */
  public function modules() {
    $data = \Drupal::service('icon_businessos.system_monitor')->getModuleInventory();
    return new JsonResponse($data);
  }

  /**
   * GET /api/icon/v1/errors — PHP error log tail.
   */
  public function errors(Request $request) {
    $lines = (int) $request->query->get('lines', 50);
    $lines = min($lines, 200);
    $data = \Drupal::service('icon_businessos.system_monitor')->getPhpErrors($lines);
    return new JsonResponse($data);
  }

  /**
   * GET /api/icon/v1/status — Lightweight liveness check (no auth).
   */
  public function status() {
    $config = $this->config('icon_businessos.settings');
    return new JsonResponse([
      'status' => 'ok',
      'silo_version' => self::SILO_VERSION,
      'cms' => 'drupal',
      'cms_version' => \Drupal::VERSION,
      'registered' => !empty($config->get('silo_api_key')),
      'timestamp' => gmdate('c'),
    ]);
  }

  /**
   * Compute composite health score (0-100).
   */
  private function computeHealthScore(array $system, array $security): int {
    $score = 100;

    // Disk usage penalty
    $disk = $system['disk_usage_pct'] ?? 0;
    if ($disk > 90) $score -= 25;
    elseif ($disk > 80) $score -= 15;
    elseif ($disk > 70) $score -= 5;

    // Memory penalty
    $mem = $system['memory_usage_pct'] ?? 0;
    if ($mem > 95) $score -= 20;
    elseif ($mem > 85) $score -= 10;
    elseif ($mem > 75) $score -= 3;

    // Load average penalty
    $cores = $system['cpu_cores'] ?? 1;
    $load = $system['load_avg_1m'] ?? 0;
    if ($cores > 0 && ($load / $cores) > 2.0) $score -= 15;
    elseif ($cores > 0 && ($load / $cores) > 1.0) $score -= 5;

    // PHP errors penalty
    $errors = $system['php_error_count_24h'] ?? 0;
    if ($errors > 100) $score -= 15;
    elseif ($errors > 20) $score -= 8;
    elseif ($errors > 0) $score -= 3;

    // Module updates penalty
    $updates = $system['module_updates_available'] ?? 0;
    if ($updates > 10) $score -= 10;
    elseif ($updates > 3) $score -= 5;
    elseif ($updates > 0) $score -= 2;

    // Security penalties
    $file_changes = $security['file_changes_since_baseline'] ?? 0;
    if ($file_changes > 0) $score -= min($file_changes * 5, 20);

    $failed_logins = $security['failed_logins_24h'] ?? 0;
    if ($failed_logins > 50) $score -= 15;
    elseif ($failed_logins > 10) $score -= 5;

    return max(0, min(100, $score));
  }

  /**
   * Convert score to letter grade.
   */
  private function scoreToGrade(int $score): string {
    if ($score >= 97) return 'A+';
    if ($score >= 93) return 'A';
    if ($score >= 90) return 'A-';
    if ($score >= 87) return 'B+';
    if ($score >= 83) return 'B';
    if ($score >= 80) return 'B-';
    if ($score >= 77) return 'C+';
    if ($score >= 73) return 'C';
    if ($score >= 70) return 'C-';
    if ($score >= 60) return 'D';
    return 'F';
  }

}
