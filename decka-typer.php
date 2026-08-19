<?php
/**
 * Plugin Name: Decka Typer
 * Description: Nowoczesny typer 1 Ligi dla społeczności Decki Pelplin — wybór zwycięzców, nieedytowalne kupony kolejek, rankingi, synchronizacja 1LM i logowanie społecznościowe.
 * Version: 0.2.2
 * Author: Decka Pelplin
 * Text Domain: decka-typer
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Update URI: https://github.com/kaulpl/decka-typer
 */

if (!defined('ABSPATH')) exit;

define('DT_VERSION', '0.2.2');
define('DT_FILE', __FILE__);
define('DT_DIR', plugin_dir_path(__FILE__));
define('DT_URL', plugin_dir_url(__FILE__));

require_once DT_DIR . 'includes/class-dt-db.php';
require_once DT_DIR . 'includes/class-dt-logger.php';
require_once DT_DIR . 'includes/class-dt-scoring.php';
require_once DT_DIR . 'includes/class-dt-sync.php';
require_once DT_DIR . 'includes/class-dt-oauth.php';
require_once DT_DIR . 'includes/class-dt-rest.php';
require_once DT_DIR . 'includes/class-dt-admin.php';
require_once DT_DIR . 'includes/class-dt-frontend.php';
require_once DT_DIR . 'includes/class-dt-team-logos.php';
require_once DT_DIR . 'includes/class-dt-ui.php';
require_once DT_DIR . 'includes/class-dt-updater.php';
require_once DT_DIR . 'includes/class-dt-plugin.php';

register_activation_hook(__FILE__, ['DT_DB', 'activate']);
register_deactivation_hook(__FILE__, ['DT_DB', 'deactivate']);

add_action('plugins_loaded', static function () {
    DT_Plugin::instance()->boot();
    DT_Team_Logos::register();
    DT_UI::register();
});
