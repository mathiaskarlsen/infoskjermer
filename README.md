# Drupal Project

A Drupal 11 site built with Composer and managed with DDEV.

## Prerequisites

- [DDEV](https://ddev.readthedocs.io/en/stable/#installation) installed on your machine
- [Composer](https://getcomposer.org/) (comes with DDEV)
- Git

## Local Development Installation

**Note:** These instructions are for setting up a local development environment using DDEV. For production deployment, see the [Production Deployment](#production-deployment) section below.

1. **Clone the repository**
   ```bash
   git clone https://github.com/mathiaskarlsen/infoskjermer infoskjermer
   cd infoskjermer
   ```

2. **Install dependencies with Composer**
   ```bash
   ddev composer install
   ```

3. **Start DDEV**
   ```bash
   ddev start
   ```

4. **Install Drupal**
   
   For a fresh installation:
   ```bash
   ddev drush site:install --account-name=admin --account-pass=admin
   ```
   
   Or if importing an existing database:
   ```bash
   ddev import-db --file=/path/to/database.sql.gz
   ```

5. **Import configuration** (if applicable)
   ```bash
   ddev drush config:import
   ```

6. **Access the site**
   ```bash
   ddev launch
   ```
   
   Or visit the URL shown by `ddev describe`

## Common Commands

- **Clear cache:** `ddev drush cr`
- **Export configuration:** `ddev drush config:export`
- **Import configuration:** `ddev drush config:import`
- **Run Composer commands:** `ddev composer <command>`
- **Stop DDEV:** `ddev stop`
- **Restart DDEV:** `ddev restart`

## Development

- Custom modules go in `web/modules/custom/`
- Custom themes go in `web/themes/custom/`
- Configuration is exported to `config/sync/` (if configured)

## Database and Files

This repository does not include:
- The database (export/import separately)
- User-uploaded files in `web/sites/default/files/` (sync separately or use Stage File Proxy module)

## Troubleshooting

If you encounter issues:
```bash
ddev restart
ddev composer install
ddev drush cr
```

## Production Deployment

**DDEV is NOT used on production servers.** It's a local development tool only.

### Production Server Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/mathiaskarlsen/infoskjermer /var/www/html
   cd /var/www/html
   ```

2. **Install dependencies (without dev packages)**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Configure Drupal settings**
   - Copy `web/sites/default/default.settings.php` to `web/sites/default/settings.php`
   - Add your production database credentials
   - Configure trusted host patterns
   - Set proper file permissions

4. **Import configuration**
   ```bash
   drush config:import
   drush cache:rebuild
   ```

5. **Set up web server**
   - Point document root to `web/`
   - Configure Apache/Nginx for Drupal
   - Set up SSL certificate

### Key Differences from Local Development

| Task | Local (DDEV) | Production |
|------|-------------|------------|
| Start environment | `ddev start` | Web server runs as service |
| Run Composer | `ddev composer install` | `composer install --no-dev` |
| Run Drush | `ddev drush cr` | `drush cr` |
| Access database | `ddev mysql` | Direct MySQL client |

### Deployment Workflow

1. Test changes locally with DDEV
2. Commit and push to Git
3. On production: `git pull`
4. Run `composer install --no-dev`
5. Import config: `drush config:import`
6. Clear cache: `drush cache:rebuild`
