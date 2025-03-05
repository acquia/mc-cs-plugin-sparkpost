# Mautic Sparkpost Plugin

This plugin enable Mautic 5 to run Sparkpost as an email transport. Features:
- API transport. This transport can send up to 2000 emails per API request which makes it very fast compared to SMTP.
- Bounce webhook handling. This plugin will unsubscribe contacts in Mautic based on the hard bounces while Sparkpost will take care of the soft bounce retrieals.

## Installation

There are several ways how to install this plugin. Here are the options from best to worst.

### Via Composer

This is the best option for Mautic instances that were installed via Composer (recommended way to install Mautic)

Steps:
1. `composer install acquia/mc-cs-plugin-sparkpost`
2. `bin/console mautic:plugins:install`

### Via Git

This option is useful for development or testing of this plugin as you'll be able to checkout different branches of this repository.

Steps:
1. `cd plugins`
2. `git clone git@github.com:acquia/mc-cs-plugin-sparkpost.git SparkpostBundle`
3. `cd ..`
4. `bin/console mautic:plugins:install`

### Via SFTP

You should reconsider using this method as the other two above are way better, but this is also possible.

Steps:
1. [Download this plugin](https://github.com/acquia/mc-cs-plugin-sparkpost/archive/refs/heads/main.zip)
2. Rename the folder `mc-cs-plugin-sparkpost-main` to `SparkpostBundle`
3. Upload this folder to the `plugins` directory of your Mautic files.
4. `bin/console mautic:plugins:install`

## Configuration

After the plugin is installed go to the Mautic's global configuration, the Email settings and configure the DSN.

### Mautic Mailer DSN Scheme
`mautic+sparkpost+api`

#### Mautic Mailer DSN Example
`'mailer_dsn' => 'mautic+sparkpost+api://:<api_key>@default?region=<region>',`
- api_key: Get Sparkpost API key from https://app.sparkpost.com/account/api-keys/create
- options:
  - region: `us` (SparkPost https://api.sparkpost.com/api/v1) OR `eu` (SparkPost EU https://api.eu.sparkpost.com/api/v1)

<img width="1105" alt="sparkpost-email-dsn-example" src="Assets/img/sparkpost-email-dsn-example.png">

### Sparkpost tracking

The Sparkpost tracking is disabled by default as then the email open and clicks would be tracked twice. Once by Sparkpost, second time by Mautic. This can create some unexpected behavior. The Sparkpost tracking is disabled by default, but you can enable it by adding this row to the Mautic configuration file located at `config/local.php`:

```php
'sparkpost_tracking_enabled' => true,
```

## Testing

To run all tests `composer phpunit`

To run unit tests `composer unit`

To run functional tests `composer functional`

### Static analysis tools

To run fixes by friendsofphp/php-cs-fixer `composer fixcs`

To run phpstan `composer phpstan`
