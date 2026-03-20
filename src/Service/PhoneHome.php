<?php

namespace Drupal\icon_businessos\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * ICON BusinessOS — Phone Home service for Drupal.
 *
 * Pushes composite health payload to fleet master.
 * Handles registration and heartbeat lifecycle.
 */
class PhoneHome {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected SystemMonitor $systemMonitor;
  protected SecurityScanner $securityScanner;
  protected ContentIntelligence $contentIntelligence;

  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    SystemMonitor $system_monitor,
    SecurityScanner $security_scanner,
    ContentIntelligence $content_intelligence
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->systemMonitor = $system_monitor;
    $this->securityScanner = $security_scanner;
    $this->contentIntelligence = $content_intelligence;
  }

  /**
   * Register this silo with the fleet master.
   */
  public function register(): array {
    $config = $this->configFactory->get('icon_businessos.settings');
    $tenant_id = $config->get('tenant_id');
    if (empty($tenant_id)) {
      return ['success' => FALSE, 'error' => 'Tenant ID is required.'];
    }

    $fleet_url = $config->get('fleet_master_url') ?: 'https://os.theicon.ai/api/silo';

    $payload = [
      'tenant_id' => $tenant_id,
      'site_url' => \Drupal::request()->getSchemeAndHttpHost(),
      'site_name' => $this->configFactory->get('system.site')->get('name'),
      'cms' => 'drupal',
      'cms_version' => \Drupal::VERSION,
      'php_version' => PHP_VERSION,
      'plugin_version' => '1.0.0',
      'silo_version' => '2.3.0',
      'heartbeat_url' => \Drupal::request()->getSchemeAndHttpHost() . '/api/icon/v1/heartbeat',
      'status_url' => \Drupal::request()->getSchemeAndHttpHost() . '/api/icon/v1/status',
      'capabilities' => [
        'http_probes', 'system_resources', 'security_scanning',
        'content_intelligence', 'phone_home', 'silo_heartbeat',
      ],
      'registered_at' => gmdate('c'),
    ];

    try {
      $response = $this->httpClient->request('POST', $fleet_url . '/register', [
        'json' => $payload,
        'timeout' => 30,
        'headers' => ['Accept' => 'application/json'],
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);

      if (!empty($body['silo_api_key'])) {
        $editable = $this->configFactory->getEditable('icon_businessos.settings');
        $editable->set('silo_api_key', $body['silo_api_key']);
        $editable->set('registered_at', gmdate('c'));
        $editable->save();
        return ['success' => TRUE, 'silo_api_key' => $body['silo_api_key']];
      }

      return ['success' => FALSE, 'error' => $body['error'] ?? 'No API key returned.'];
    }
    catch (\Exception $e) {
      return ['success' => FALSE, 'error' => $e->getMessage()];
    }
  }

  /**
   * Execute phone-home heartbeat push (called by cron).
   */
  public function execute(): array {
    $config = $this->configFactory->get('icon_businessos.settings');
    $silo_key = $config->get('silo_api_key');
    if (empty($silo_key)) {
      return ['success' => FALSE, 'error' => 'Not registered with fleet master.'];
    }

    $fleet_url = $config->get('fleet_master_url') ?: 'https://os.theicon.ai/api/silo';

    $system = $this->systemMonitor->collect();
    $security = $this->securityScanner->getSummary();
    $content = $this->contentIntelligence->collect();

    $health_score = $this->computeHealthScore($system, $security);

    $payload = [
      'silo_version' => '2.3.0',
      'tenant_id' => $config->get('tenant_id'),
      'site_url' => \Drupal::request()->getSchemeAndHttpHost(),
      'cms' => 'drupal',
      'cms_version' => \Drupal::VERSION,
      'health_score' => $health_score,
      'health_grade' => $this->scoreToGrade($health_score),
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
        'failed_logins_24h' => $security['failed_logins_24h'],
        'core_integrity' => $security['core_integrity'],
      ],
      'content' => $content,
      'timestamp' => gmdate('c'),
    ];

    try {
      $response = $this->httpClient->request('POST', $fleet_url . '/heartbeat', [
        'json' => $payload,
        'timeout' => 15,
        'headers' => [
          'Accept' => 'application/json',
          'X-Silo-Key' => $silo_key,
          'X-Tenant-ID' => $config->get('tenant_id'),
        ],
      ]);

      $this->logPhoneHome(TRUE);
      \Drupal::state()->set('icon_businessos.last_heartbeat_score', $health_score);

      // Process fleet commands
      $body = json_decode($response->getBody()->getContents(), TRUE);
      if (!empty($body['commands'])) {
        $this->processFleetCommands($body['commands']);
      }

      return ['success' => TRUE, 'score' => $health_score];
    }
    catch (\Exception $e) {
      $this->logPhoneHome(FALSE, $e->getMessage());
      return ['success' => FALSE, 'error' => $e->getMessage()];
    }
  }

  /**
   * Process commands from fleet master.
   */
  private function processFleetCommands(array $commands): void {
    foreach ($commands as $cmd) {
      $action = $cmd['action'] ?? '';
      switch ($action) {
        case 'refresh_baseline':
          $this->securityScanner->createBaseline();
          break;
        case 'run_security_scan':
          $this->securityScanner->executeScan();
          break;
        case 'update_interval':
          if (isset($cmd['interval_minutes'])) {
            $editable = $this->configFactory->getEditable('icon_businessos.settings');
            $editable->set('phone_home_interval', (int) $cmd['interval_minutes']);
            $editable->save();
          }
          break;
      }
    }
  }

  private function logPhoneHome(bool $success, string $error = ''): void {
    $log = \Drupal::state()->get('icon_businessos.phone_home_log', []);
    $log[] = ['success' => $success, 'error' => $error, 'timestamp' => gmdate('c')];
    if (count($log) > 50) {
      $log = array_slice($log, -50);
    }
    \Drupal::state()->set('icon_businessos.phone_home_log', $log);
  }

  private function computeHealthScore(array $system, array $security): int {
    $score = 100;
    $disk = $system['disk_usage_pct'] ?? 0;
    if ($disk > 90) $score -= 25;
    elseif ($disk > 80) $score -= 15;
    elseif ($disk > 70) $score -= 5;

    $mem = $system['memory_usage_pct'] ?? 0;
    if ($mem > 95) $score -= 20;
    elseif ($mem > 85) $score -= 10;
    elseif ($mem > 75) $score -= 3;

    $errors = $system['php_error_count_24h'] ?? 0;
    if ($errors > 100) $score -= 15;
    elseif ($errors > 20) $score -= 8;
    elseif ($errors > 0) $score -= 3;

    $file_changes = $security['file_changes_since_baseline'] ?? 0;
    if ($file_changes > 0) $score -= min($file_changes * 5, 20);

    $failed_logins = $security['failed_logins_24h'] ?? 0;
    if ($failed_logins > 50) $score -= 15;
    elseif ($failed_logins > 10) $score -= 5;

    return max(0, min(100, $score));
  }

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
