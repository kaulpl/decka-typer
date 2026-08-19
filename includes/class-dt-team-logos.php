<?php
if (!defined('ABSPATH')) exit;

class DT_Team_Logos {
    private const ASSET_VERSION = '0.2.2';

    public static function register(): void {
        add_filter('rest_request_after_callbacks', [__CLASS__, 'decorate_rest_response'], 10, 3);
        add_action('admin_init', [__CLASS__, 'maybe_apply_to_database']);
    }

    public static function decorate_rest_response($response, $handler, WP_REST_Request $request) {
        if (!($response instanceof WP_REST_Response)) return $response;
        if (!str_starts_with($request->get_route(), '/decka-typer/v1/')) return $response;
        $data = self::decorate_payload($response->get_data());
        $response->set_data($data);
        return $response;
    }

    private static function decorate_payload($data) {
        if (!is_array($data)) return $data;
        if (!empty($data['home_name'])) {
            $logo = self::url_for((string)$data['home_name']);
            if ($logo) $data['home_logo'] = $logo;
        }
        if (!empty($data['away_name'])) {
            $logo = self::url_for((string)$data['away_name']);
            if ($logo) $data['away_logo'] = $logo;
        }
        if (!empty($data['name']) && array_key_exists('logo_url', $data)) {
            $logo = self::url_for((string)$data['name']);
            if ($logo) $data['logo_url'] = $logo;
        }
        foreach ($data as $key=>$value) if (is_array($value)) $data[$key] = self::decorate_payload($value);
        return $data;
    }

    public static function maybe_apply_to_database(): void {
        if ((string)get_option('dt_team_logos_version','') === self::ASSET_VERSION) return;
        global $wpdb;
        $table = DT_DB::table('teams');
        $rows = $wpdb->get_results("SELECT id,name FROM $table");
        if (!is_array($rows)) return;
        foreach ($rows as $team) {
            $url = self::url_for((string)$team->name);
            if ($url) $wpdb->update($table,['logo_url'=>$url],['id'=>(int)$team->id],['%s'],['%d']);
        }
        update_option('dt_team_logos_version',self::ASSET_VERSION,false);
    }

    public static function url_for(string $teamName): ?string {
        $normalized = self::normalize($teamName);
        foreach (self::map() as $needle=>$file) {
            if (!str_contains($normalized,$needle)) continue;
            $path = DT_DIR . 'assets/img/teams/' . $file;
            return is_readable($path) ? DT_URL . 'assets/img/teams/' . $file : null;
        }
        return null;
    }

    private static function map(): array {
        return [
            'polonia 1912 leszno'=>'polonia-leszno.png','polonia leszno'=>'polonia-leszno.png',
            'polonia bytom'=>'bs-polonia-bytom.png','polonia warszawa'=>'kks-polonia-warszawa.png',
            'kotwica'=>'kotwica-kolobrzeg.png','decka pelplin'=>'decka-pelplin.png',
            'basket poznan'=>'enea-basket-poznan.png','gks tychy'=>'gks-tychy.png',
            'notec inowroclaw'=>'notec-inowroclaw.png','lks coolpack lodz'=>'lks-lodz.png','lks lodz'=>'lks-lodz.png',
            'miasto szkla krosno'=>'miasto-szkla-krosno.png','resovia rzeszow'=>'resovia-rzeszow.png',
            'spojnia stargard'=>'spojnia-stargard.png','starogard gdanski'=>'sks-starogard-gdanski.png',
            'sokol lancut'=>'sokol-lancut.png','politechnika opolska'=>'azs-politechnika-opolska.png',
            'wkk'=>'wkk-wroclaw.png',
        ];
    }

    private static function normalize(string $value): string {
        $value = strtolower(remove_accents(wp_strip_all_tags($value)));
        $value = preg_replace('/[^a-z0-9]+/',' ',$value);
        return trim(preg_replace('/\s+/',' ',(string)$value));
    }
}
