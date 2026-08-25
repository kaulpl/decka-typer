<?php
/**
 * Plugin Name: TypujKosza.pl
 * Description: Koszykarski typer dla kibiców — typowanie zwycięzców, typowania kolejek, rankingi, bonusy i synchronizacja wyników.
 * Version: 0.5.46
 * Author: TypujKosza.pl
 * Text Domain: decka-typer
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Update URI: https://github.com/kaulpl/decka-typer
 */

if (!defined('ABSPATH')) exit;

define('DT_VERSION', '0.5.46');
define('DT_FILE', __FILE__);
define('DT_DIR', plugin_dir_path(__FILE__));
define('DT_URL', plugin_dir_url(__FILE__));

require_once DT_DIR . 'includes/class-dt-db.php';
require_once DT_DIR . 'includes/class-dt-avatar.php';
require_once DT_DIR . 'includes/class-dt-artur-ai.php';
require_once DT_DIR . 'includes/class-dt-logger.php';
require_once DT_DIR . 'includes/class-dt-bonus.php';
require_once DT_DIR . 'includes/class-dt-scoring.php';
require_once DT_DIR . 'includes/class-dt-sync.php';
require_once DT_DIR . 'includes/class-dt-oauth.php';
require_once DT_DIR . 'includes/class-dt-mobile-auth.php';
require_once DT_DIR . 'includes/class-dt-rest.php';
require_once DT_DIR . 'includes/class-dt-feedback.php';
require_once DT_DIR . 'includes/class-dt-ads.php';
require_once DT_DIR . 'includes/class-dt-round-access.php';
require_once DT_DIR . 'includes/class-dt-submission.php';
require_once DT_DIR . 'includes/class-dt-admin.php';
require_once DT_DIR . 'includes/class-dt-frontend.php';
require_once DT_DIR . 'includes/class-dt-team-logos.php';
require_once DT_DIR . 'includes/class-dt-ui.php';
require_once DT_DIR . 'includes/class-dt-updater.php';
require_once DT_DIR . 'includes/class-dt-my-coupons.php';
require_once DT_DIR . 'includes/class-dt-league-ui.php';
require_once DT_DIR . 'includes/class-dt-winner-highlight.php';
require_once DT_DIR . 'includes/class-dt-user-settings.php';
require_once DT_DIR . 'includes/class-dt-ranking-view.php';
require_once DT_DIR . 'includes/class-dt-session-persistence.php';
require_once DT_DIR . 'includes/class-dt-brand.php';
require_once DT_DIR . 'includes/class-dt-legal.php';
require_once DT_DIR . 'includes/class-dt-marketing.php';
require_once DT_DIR . 'includes/class-dt-canonical.php';
require_once DT_DIR . 'includes/class-dt-copy.php';
require_once DT_DIR . 'includes/class-dt-plugin.php';

register_activation_hook(__FILE__, ['DT_DB', 'activate']);
register_deactivation_hook(__FILE__, ['DT_DB', 'deactivate']);

add_action('plugins_loaded', static function () {
    DT_Plugin::instance()->boot();
    DT_Canonical::register();
    DT_Brand::register();
    DT_Legal::register();
    DT_Marketing::register();
    DT_Copy::register();
    DT_Session_Persistence::register();
    DT_Mobile_Auth::register();
    DT_Submission::register();
    DT_Team_Logos::register();
    DT_UI::register();
    DT_My_Coupons::register();
    DT_League_UI::register();
    DT_Winner_Highlight::register();
    DT_User_Settings::register();
    DT_Round_Access::register();
    DT_Bonus::register();
    DT_Ranking_View::register();
    DT_Feedback::register();
    DT_Ads::register();
    DT_Artur_AI::register();
});
