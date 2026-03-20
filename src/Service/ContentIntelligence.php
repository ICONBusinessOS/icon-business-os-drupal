<?php

namespace Drupal\icon_businessos\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * ICON BusinessOS — Content Intelligence for Drupal.
 *
 * Provides inside-the-fence content signals:
 *   - Publishing velocity (7d, 30d, drafts, scheduled)
 *   - Content freshness (stale percentage, grade)
 *   - Edit activity (24h, 7d, active editors)
 *   - Content type counts
 *   - Comment stats
 */
class ContentIntelligence {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * Collect all content intelligence signals.
   */
  public function collect(): array {
    return [
      'publishing' => $this->getPublishingVelocity(),
      'freshness' => $this->getContentFreshness(),
      'edit_activity' => $this->getEditActivity(),
      'content_types' => $this->getContentTypeCounts(),
      'comments' => $this->getCommentStats(),
      'collected_at' => gmdate('c'),
    ];
  }

  // ─── Publishing Velocity ──────────────────────────────────────

  private function getPublishingVelocity(): array {
    $now = \Drupal::time()->getRequestTime();
    $seven_days_ago = $now - (7 * 86400);
    $thirty_days_ago = $now - (30 * 86400);

    $published_7d = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE status = 1 AND created > :since",
      [':since' => $seven_days_ago]
    )->fetchField();

    $published_30d = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE status = 1 AND created > :since",
      [':since' => $thirty_days_ago]
    )->fetchField();

    $drafts = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE status = 0"
    )->fetchField();

    $avg_interval = $published_30d > 1 ? round(30 / $published_30d, 1) : NULL;

    return [
      'published_7d' => $published_7d,
      'published_30d' => $published_30d,
      'drafts_in_progress' => $drafts,
      'avg_days_between' => $avg_interval,
      'velocity_trend' => $published_7d >= ($published_30d / 4) ? 'on_pace' : 'slowing',
    ];
  }

  // ─── Content Freshness ────────────────────────────────────────

  private function getContentFreshness(): array {
    $now = \Drupal::time()->getRequestTime();
    $ninety_days_ago = $now - (90 * 86400);

    $latest = $this->database->query(
      "SELECT MAX(changed) FROM {node_field_data} WHERE status = 1"
    )->fetchField();

    $stale_90d = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE status = 1 AND changed < :cutoff",
      [':cutoff' => $ninety_days_ago]
    )->fetchField();

    $total_published = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE status = 1"
    )->fetchField();

    $stale_pct = $total_published > 0 ? round(($stale_90d / $total_published) * 100, 1) : 0;

    return [
      'last_updated' => $latest ? gmdate('c', $latest) : NULL,
      'days_since_update' => $latest ? round(($now - $latest) / 86400, 1) : NULL,
      'stale_posts_90d' => $stale_90d,
      'total_published' => $total_published,
      'stale_percentage' => $stale_pct,
      'freshness_grade' => $stale_pct < 20 ? 'A' : ($stale_pct < 40 ? 'B' : ($stale_pct < 60 ? 'C' : 'D')),
    ];
  }

  // ─── Edit Activity ────────────────────────────────────────────

  private function getEditActivity(): array {
    $now = \Drupal::time()->getRequestTime();

    $edits_24h = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE changed > :since",
      [':since' => $now - 86400]
    )->fetchField();

    $edits_7d = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE changed > :since",
      [':since' => $now - (7 * 86400)]
    )->fetchField();

    $editors = $this->database->query(
      "SELECT uid, COUNT(*) as edit_count FROM {node_field_data} WHERE changed > :since GROUP BY uid ORDER BY edit_count DESC LIMIT 5",
      [':since' => $now - (7 * 86400)]
    )->fetchAll();

    $editor_names = [];
    foreach ($editors as $e) {
      $user = $this->entityTypeManager->getStorage('user')->load($e->uid);
      $editor_names[] = [
        'display_name' => $user ? $user->getDisplayName() : 'Unknown',
        'edit_count' => (int) $e->edit_count,
      ];
    }

    return [
      'edits_24h' => $edits_24h,
      'edits_7d' => $edits_7d,
      'active_editors' => $editor_names,
    ];
  }

  // ─── Content Type Counts ──────────────────────────────────────

  private function getContentTypeCounts(): array {
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $counts = [];
    foreach ($types as $type_id => $type) {
      $published = (int) $this->database->query(
        "SELECT COUNT(*) FROM {node_field_data} WHERE type = :type AND status = 1",
        [':type' => $type_id]
      )->fetchField();

      $draft = (int) $this->database->query(
        "SELECT COUNT(*) FROM {node_field_data} WHERE type = :type AND status = 0",
        [':type' => $type_id]
      )->fetchField();

      $counts[$type_id] = [
        'label' => $type->label(),
        'published' => $published,
        'draft' => $draft,
      ];
    }
    return $counts;
  }

  // ─── Comments ─────────────────────────────────────────────────

  private function getCommentStats(): array {
    if (!\Drupal::moduleHandler()->moduleExists('comment')) {
      return ['available' => FALSE];
    }

    try {
      $published = (int) $this->database->query(
        "SELECT COUNT(*) FROM {comment_field_data} WHERE status = 1"
      )->fetchField();

      $unpublished = (int) $this->database->query(
        "SELECT COUNT(*) FROM {comment_field_data} WHERE status = 0"
      )->fetchField();

      return [
        'available' => TRUE,
        'published' => $published,
        'unpublished' => $unpublished,
        'queue_depth' => $unpublished,
        'total' => $published + $unpublished,
      ];
    }
    catch (\Exception $e) {
      return ['available' => FALSE, 'error' => $e->getMessage()];
    }
  }

}
