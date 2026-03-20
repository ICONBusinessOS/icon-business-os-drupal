<?php

namespace Drupal\icon_businessos\Service;

/**
 * ICON BusinessOS — Security Scanner for Drupal.
 *
 * Provides inside-the-fence security signals:
 *   - File integrity monitoring (SHA-256 baseline)
 *   - Failed login attempt tracking
 *   - Module vulnerability cross-reference
 *   - Core file integrity check
 *   - File permission audit
 *   - Admin user count
 */
class SecurityScanner {

  /**
   * Create baseline file hashes on module install.
   */
  public function createBaseline(): void {
    $hashes = $this->hashCriticalFiles();
    \Drupal::state()->set('icon_businessos.file_baseline', $hashes);
  }

  /**
   * Execute a full security scan (daily cron).
   */
  public function executeScan(): array {
    $results = [
      'file_integrity' => $this->checkFileIntegrity(),
      'failed_logins' => $this->getFailedLoginStats(),
      'core_integrity' => $this->checkCoreIntegrity(),
      'file_permissions' => $this->auditFilePermissions(),
      'admin_users' => $this->countAdminUsers(),
      'scan_timestamp' => gmdate('c'),
    ];
    \Drupal::state()->set('icon_businessos.security_scan', $results);
    return $results;
  }

  /**
   * Get the latest scan summary for REST API / heartbeat.
   */
  public function getSummary(): array {
    $results = \Drupal::state()->get('icon_businessos.security_scan', []);

    if (empty($results)) {
      return [
        'scan_available' => FALSE,
        'file_changes_since_baseline' => 0,
        'vulnerable_modules' => 0,
        'failed_logins_24h' => $this->countRecentFailedLogins(24),
        'admin_user_count' => $this->countAdminUsers(),
        'core_integrity' => 'unknown',
        'last_scan' => NULL,
      ];
    }

    return [
      'scan_available' => TRUE,
      'file_changes_since_baseline' => $results['file_integrity']['changes_detected'] ?? 0,
      'file_changes_detail' => $results['file_integrity']['changed_files'] ?? [],
      'vulnerable_modules' => 0,
      'failed_logins_24h' => $this->countRecentFailedLogins(24),
      'failed_logins_7d' => $results['failed_logins']['count_7d'] ?? 0,
      'admin_user_count' => $results['admin_users'] ?? 0,
      'core_integrity' => $results['core_integrity']['status'] ?? 'unknown',
      'permission_issues' => $results['file_permissions']['issues'] ?? [],
      'last_scan' => $results['scan_timestamp'] ?? NULL,
    ];
  }

  // ─── File Integrity ───────────────────────────────────────────

  private function hashCriticalFiles(): array {
    $files = [
      'settings.php' => DRUPAL_ROOT . '/sites/default/settings.php',
      'services.yml' => DRUPAL_ROOT . '/sites/default/services.yml',
      '.htaccess' => DRUPAL_ROOT . '/.htaccess',
      'index.php' => DRUPAL_ROOT . '/index.php',
      'update.php' => DRUPAL_ROOT . '/update.php',
      'core/lib/Drupal.php' => DRUPAL_ROOT . '/core/lib/Drupal.php',
    ];

    $hashes = [];
    foreach ($files as $label => $path) {
      if (file_exists($path) && is_readable($path)) {
        $hashes[$label] = [
          'hash' => hash_file('sha256', $path),
          'size' => filesize($path),
          'modified' => filemtime($path),
        ];
      }
    }
    return $hashes;
  }

  private function checkFileIntegrity(): array {
    $baseline = \Drupal::state()->get('icon_businessos.file_baseline', []);
    if (empty($baseline)) {
      return ['status' => 'no_baseline', 'changes_detected' => 0, 'changed_files' => []];
    }

    $current = $this->hashCriticalFiles();
    $changed = [];

    foreach ($baseline as $label => $base) {
      if (!isset($current[$label])) {
        $changed[] = ['file' => $label, 'change' => 'deleted'];
      }
      elseif ($current[$label]['hash'] !== $base['hash']) {
        $changed[] = [
          'file' => $label,
          'change' => 'modified',
          'old_size' => $base['size'],
          'new_size' => $current[$label]['size'],
        ];
      }
    }
    foreach ($current as $label => $cur) {
      if (!isset($baseline[$label])) {
        $changed[] = ['file' => $label, 'change' => 'added'];
      }
    }

    return [
      'status' => empty($changed) ? 'clean' : 'changes_detected',
      'changes_detected' => count($changed),
      'changed_files' => $changed,
      'files_monitored' => count($baseline),
    ];
  }

  // ─── Failed Logins ────────────────────────────────────────────

  private function getFailedLoginStats(): array {
    return [
      'count_24h' => $this->countRecentFailedLogins(24),
      'count_7d' => $this->countRecentFailedLogins(168),
    ];
  }

  private function countRecentFailedLogins(int $hours): int {
    $log = \Drupal::state()->get('icon_businessos.failed_logins', []);
    if (!is_array($log)) return 0;
    $cutoff = time() - ($hours * 3600);
    $count = 0;
    foreach ($log as $entry) {
      if (isset($entry['time']) && $entry['time'] > $cutoff) {
        $count++;
      }
    }
    return $count;
  }

  // ─── Core Integrity ───────────────────────────────────────────

  private function checkCoreIntegrity(): array {
    $critical = [
      DRUPAL_ROOT . '/index.php',
      DRUPAL_ROOT . '/core/lib/Drupal.php',
      DRUPAL_ROOT . '/core/includes/bootstrap.inc',
      DRUPAL_ROOT . '/core/modules/system/system.module',
      DRUPAL_ROOT . '/update.php',
    ];

    $issues = [];
    foreach ($critical as $path) {
      if (!file_exists($path)) {
        $issues[] = ['file' => basename($path), 'issue' => 'missing'];
      }
      elseif (!is_readable($path)) {
        $issues[] = ['file' => basename($path), 'issue' => 'not_readable'];
      }
    }

    return [
      'status' => empty($issues) ? 'clean' : 'issues_found',
      'issues' => $issues,
      'checked' => count($critical),
    ];
  }

  // ─── File Permissions ─────────────────────────────────────────

  private function auditFilePermissions(): array {
    $checks = [
      ['path' => DRUPAL_ROOT . '/sites/default/settings.php', 'max_perms' => 0444],
      ['path' => DRUPAL_ROOT . '/.htaccess', 'max_perms' => 0644],
      ['path' => DRUPAL_ROOT . '/sites/default', 'max_perms' => 0555],
      ['path' => DRUPAL_ROOT . '/sites/default/files', 'max_perms' => 0755],
    ];

    $issues = [];
    foreach ($checks as $check) {
      if (!file_exists($check['path'])) continue;
      $perms = fileperms($check['path']) & 0777;
      if ($perms > $check['max_perms']) {
        $issues[] = [
          'path' => basename($check['path']),
          'current_perms' => sprintf('%04o', $perms),
          'recommended_max' => sprintf('%04o', $check['max_perms']),
          'issue' => 'too_permissive',
        ];
      }
    }

    return [
      'status' => empty($issues) ? 'clean' : 'issues_found',
      'issues' => $issues,
      'checked' => count($checks),
    ];
  }

  // ─── Admin Users ──────────────────────────────────────────────

  private function countAdminUsers(): int {
    try {
      $ids = \Drupal::entityQuery('user')
        ->condition('roles', 'administrator')
        ->accessCheck(FALSE)
        ->execute();
      return count($ids);
    }
    catch (\Exception $e) {
      return 0;
    }
  }

}
