# ICON BusinessOS — Drupal Silo Module

> Connects your Drupal site to the ICON BusinessOS autonomic nervous system. Acts as a silo agent in the fleet monitoring architecture.

## What This Module Does

The ICON BusinessOS Drupal module turns your Drupal site into a **silo node** in the BusinessOS fleet. It provides inside-the-fence signals that external API monitoring cannot detect.

### Capabilities
- **System Resources**: disk, memory, CPU, PHP, database, cron health
- **Security Scanning**: file integrity (SHA-256 baseline), failed logins, core integrity, file permissions
- **Content Intelligence**: publishing velocity, content freshness, edit activity, content type counts
- **Phone Home**: push composite health to fleet master on configurable cron interval

### REST Endpoints
| Endpoint | Auth | Description |
|----------|------|-------------|
| `/api/icon/v1/heartbeat` | Yes | Full silo health payload (v2.3 contract) |
| `/api/icon/v1/resources` | Yes | Server resource metrics |
| `/api/icon/v1/security` | Yes | Security scan summary |
| `/api/icon/v1/content` | Yes | Content intelligence signals |
| `/api/icon/v1/modules` | Yes | Module inventory + update status |
| `/api/icon/v1/errors` | Yes | PHP error log tail |
| `/api/icon/v1/status` | No | Liveness check (fleet discovery) |

## Installation

1. Copy `icon_businessos` folder to `modules/custom/`
2. Enable: `drush en icon_businessos`
3. Configure at **Admin → Configuration → Web Services → ICON BusinessOS**
4. Enter your **Tenant ID** and click **Register with Fleet Master**

## Requirements
- Drupal 9.4+ / 10.x / 11.x
- PHP 8.1+
- `rest` and `serialization` core modules enabled

## Silo Contract v2.3
Same heartbeat payload schema as WordPress plugin — CMS-agnostic silo contract.

## License
GPL v2 or later
