<?php
if (!defined('ABSPATH')) exit;

class DT_OAuth {
    public static function register(): void {
        add_action('admin_post_nopriv_dt_oauth_start', [__CLASS__, 'start']);
        add_action('admin_post_dt_oauth_start', [__CLASS__, 'start']);
        add_action('admin_post_nopriv_dt_oauth_callback', [__CLASS__, 'callback']);
        add_action('admin_post_dt_oauth_callback', [__CLASS__, 'callback']);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
    }

    public static function rest_routes(): void {
        register_rest_route('decka-typer/v1', '/oauth/(?P<provider>google|facebook|apple)/callback', [
            'methods' => ['GET','POST'],
            'callback' => [__CLASS__, 'rest_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_callback(WP_REST_Request $request) {
        $_REQUEST['provider'] = sanitize_key((string)$request['provider']);
        foreach ($request->get_params() as $k=>$v) {
            if (is_scalar($v)) $_REQUEST[$k] = (string)$v;
        }
        self::callback();
        return new WP_REST_Response(null, 204);
    }

    public static function configured(string $provider): bool {
        $s=DT_DB::settings();
        return match($provider) {
            'google' => !empty($s['google_client_id']) && !empty($s['google_client_secret']),
            'facebook' => !empty($s['facebook_app_id']) && !empty($s['facebook_app_secret']),
            'apple' => !empty($s['apple_client_id']) && !empty($s['apple_team_id']) && !empty($s['apple_key_id']) && !empty($s['apple_private_key']),
            default => false,
        };
    }

    public static function start(): void {
        $provider=sanitize_key($_GET['provider'] ?? '');
        if (!in_array($provider,['google','facebook','apple'],true) || !self::configured($provider)) {
            self::fail('Logowanie tym sposobem nie jest jeszcze skonfigurowane.');
        }
        $state=wp_generate_password(48,false,false);
        set_transient('dt_oauth_' . hash('sha256',$state), ['provider'=>$provider,'created'=>time()], 10*MINUTE_IN_SECONDS);
        $callback=self::callback_url($provider);
        $s=DT_DB::settings();

        if ($provider==='google') {
            $url=add_query_arg([
                'client_id'=>$s['google_client_id'],'redirect_uri'=>$callback,'response_type'=>'code',
                'scope'=>'openid email profile','state'=>$state,'prompt'=>'select_account','access_type'=>'online',
            ], 'https://accounts.google.com/o/oauth2/v2/auth');
        } elseif ($provider==='facebook') {
            $url=add_query_arg([
                'client_id'=>$s['facebook_app_id'],'redirect_uri'=>$callback,'response_type'=>'code',
                'scope'=>'email,public_profile','state'=>$state,
            ], 'https://www.facebook.com/v26.0/dialog/oauth');
        } else {
            $url=add_query_arg([
                'client_id'=>$s['apple_client_id'],'redirect_uri'=>$callback,'response_type'=>'code id_token',
                'response_mode'=>'form_post','scope'=>'name email','state'=>$state,
            ], 'https://appleid.apple.com/auth/authorize');
        }
        wp_redirect($url,302,'Decka Typer');
        exit;
    }

    public static function callback(): void {
        $provider=sanitize_key($_REQUEST['provider'] ?? '');
        $state=sanitize_text_field($_REQUEST['state'] ?? '');
        $code=sanitize_text_field($_REQUEST['code'] ?? '');
        $error=sanitize_text_field($_REQUEST['error'] ?? '');
        if ($error) self::fail('Logowanie anulowane lub odrzucone: ' . $error);
        if (!$provider || !$state || !$code) self::fail('Niepełna odpowiedź dostawcy logowania.');
        $key='dt_oauth_' . hash('sha256',$state);
        $saved=get_transient($key);
        delete_transient($key);
        if (!$saved || !hash_equals((string)$saved['provider'],$provider)) self::fail('Sesja logowania wygasła. Spróbuj ponownie.');

        try {
            $identity = match($provider) {
                'google' => self::google_identity($code),
                'facebook' => self::facebook_identity($code),
                'apple' => self::apple_identity($code, sanitize_text_field($_REQUEST['id_token'] ?? '')),
                default => throw new RuntimeException('Nieznany dostawca logowania.'),
            };
            $userId=self::login_identity($provider,$identity);
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId,true,is_ssl());
            DT_Logger::log('oauth_login','Logowanie społecznościowe.', ['provider'=>$provider], 'info', $userId);
            $url=add_query_arg('dt_login','ok', self::typer_url());
            wp_safe_redirect($url);
            exit;
        } catch (Throwable $e) {
            DT_Logger::log('oauth_error',$e->getMessage(),['provider'=>$provider],'error');
            self::fail('Nie udało się zalogować. ' . $e->getMessage());
        }
    }

    private static function google_identity(string $code): array {
        $s=DT_DB::settings();
        $res=wp_remote_post('https://oauth2.googleapis.com/token',[
            'timeout'=>20,'body'=>[
                'code'=>$code,'client_id'=>$s['google_client_id'],'client_secret'=>$s['google_client_secret'],
                'redirect_uri'=>self::callback_url('google'),'grant_type'=>'authorization_code'
            ]
        ]);
        $token=self::json_response($res,'Google token');
        if (empty($token['access_token'])) throw new RuntimeException('Google nie zwrócił tokenu dostępu.');
        $res=wp_remote_get('https://openidconnect.googleapis.com/v1/userinfo',['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$token['access_token']]]);
        $u=self::json_response($res,'Google userinfo');
        if (empty($u['sub']) || empty($u['email']) || empty($u['email_verified'])) throw new RuntimeException('Google nie potwierdził adresu e-mail.');
        return ['sub'=>(string)$u['sub'],'email'=>sanitize_email($u['email']),'name'=>sanitize_text_field($u['name'] ?? $u['email'])];
    }

    private static function facebook_identity(string $code): array {
        $s=DT_DB::settings();
        $url=add_query_arg([
            'client_id'=>$s['facebook_app_id'],'client_secret'=>$s['facebook_app_secret'],
            'redirect_uri'=>self::callback_url('facebook'),'code'=>$code,
        ],'https://graph.facebook.com/v26.0/oauth/access_token');
        $token=self::json_response(wp_remote_get($url,['timeout'=>20]),'Facebook token');
        if (empty($token['access_token'])) throw new RuntimeException('Facebook nie zwrócił tokenu dostępu.');
        $profileUrl=add_query_arg(['fields'=>'id,name,email','access_token'=>$token['access_token']],'https://graph.facebook.com/v26.0/me');
        $u=self::json_response(wp_remote_get($profileUrl,['timeout'=>20]),'Facebook profile');
        if (empty($u['id'])) throw new RuntimeException('Facebook nie zwrócił identyfikatora konta.');
        if (empty($u['email'])) throw new RuntimeException('Konto Facebook nie udostępniło adresu e-mail.');
        return ['sub'=>(string)$u['id'],'email'=>sanitize_email($u['email']),'name'=>sanitize_text_field($u['name'] ?? $u['email'])];
    }

    private static function apple_identity(string $code, string $postedIdToken): array {
        $s=DT_DB::settings();
        $clientSecret=self::apple_client_secret($s);
        $res=wp_remote_post('https://appleid.apple.com/auth/token',[
            'timeout'=>20,'body'=>[
                'client_id'=>$s['apple_client_id'],'client_secret'=>$clientSecret,'code'=>$code,
                'grant_type'=>'authorization_code','redirect_uri'=>self::callback_url('apple')
            ]
        ]);
        $token=self::json_response($res,'Apple token');
        $jwt=(string)($token['id_token'] ?? $postedIdToken);
        if (!$jwt) throw new RuntimeException('Apple nie zwrócił tokenu tożsamości.');
        $claims=self::verify_apple_jwt($jwt,(string)$s['apple_client_id']);
        if (empty($claims['sub'])) throw new RuntimeException('Brak identyfikatora konta Apple.');
        $email=sanitize_email((string)($claims['email'] ?? ''));
        if (!$email) {
            global $wpdb;
            $row=$wpdb->get_row($wpdb->prepare("SELECT email FROM " . DT_DB::table('social_accounts') . " WHERE provider='apple' AND provider_user_id=%s",$claims['sub']));
            $email=$row ? sanitize_email($row->email) : '';
        }
        if (!$email) throw new RuntimeException('Apple nie udostępnił adresu e-mail dla nowego konta.');
        return ['sub'=>(string)$claims['sub'],'email'=>$email,'name'=>$email];
    }

    private static function apple_client_secret(array $s): string {
        $header=self::b64url(wp_json_encode(['alg'=>'ES256','kid'=>$s['apple_key_id']]));
        $now=time();
        $payload=self::b64url(wp_json_encode(['iss'=>$s['apple_team_id'],'iat'=>$now,'exp'=>$now+15552000,'aud'=>'https://appleid.apple.com','sub'=>$s['apple_client_id']]));
        $input=$header.'.'.$payload;
        $key=openssl_pkey_get_private(trim($s['apple_private_key']));
        if (!$key) throw new RuntimeException('Nieprawidłowy klucz prywatny Apple (.p8).');
        $der='';
        if (!openssl_sign($input,$der,$key,OPENSSL_ALGO_SHA256)) throw new RuntimeException('Nie można podpisać sekretu Apple.');
        return $input.'.'.self::b64url(self::ecdsa_der_to_jose($der,32));
    }

    private static function verify_apple_jwt(string $jwt, string $audience): array {
        $parts=explode('.',$jwt);
        if (count($parts)!==3) throw new RuntimeException('Nieprawidłowy token Apple.');
        $header=json_decode(self::b64url_decode($parts[0]),true);
        $claims=json_decode(self::b64url_decode($parts[1]),true);
        if (!is_array($header)||!is_array($claims)||empty($header['kid'])) throw new RuntimeException('Nieprawidłowa struktura tokenu Apple.');
        if (($claims['iss']??'')!=='https://appleid.apple.com' || ($claims['aud']??'')!==$audience || (int)($claims['exp']??0)<time()-60) {
            throw new RuntimeException('Token Apple ma nieprawidłowe parametry.');
        }
        $keys=self::json_response(wp_remote_get('https://appleid.apple.com/auth/keys',['timeout'=>20]),'Apple keys');
        $jwk=null;
        foreach (($keys['keys']??[]) as $k) if (($k['kid']??'')===$header['kid']) {$jwk=$k;break;}
        if (!$jwk || empty($jwk['x']) || empty($jwk['y'])) throw new RuntimeException('Nie znaleziono klucza podpisu Apple.');
        $pem=self::apple_jwk_to_pem($jwk['x'],$jwk['y']);
        $sig=self::b64url_decode($parts[2]);
        $der=self::ecdsa_jose_to_der($sig);
        $ok=openssl_verify($parts[0].'.'.$parts[1],$der,$pem,OPENSSL_ALGO_SHA256);
        if ($ok!==1) throw new RuntimeException('Nie udało się zweryfikować podpisu Apple.');
        return $claims;
    }

    private static function login_identity(string $provider,array $id): int {
        global $wpdb;
        $table=DT_DB::table('social_accounts');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE provider=%s AND provider_user_id=%s",$provider,$id['sub']));
        $now=current_time('mysql');
        if ($row) {
            $wpdb->update($table,['last_login_at'=>$now,'email'=>$id['email']],['id'=>(int)$row->id]);
            return (int)$row->user_id;
        }
        $user=get_user_by('email',$id['email']);
        if (!$user) {
            $base=sanitize_user(strstr($id['email'],'@',true) ?: 'kibic',true);
            if (!$base) $base='kibic';
            $login=$base; $n=1;
            while (username_exists($login)) $login=$base . (++$n);
            $uid=wp_create_user($login,wp_generate_password(32,true,true),$id['email']);
            if (is_wp_error($uid)) throw new RuntimeException($uid->get_error_message());
            wp_update_user(['ID'=>$uid,'display_name'=>$id['name'] ?: $login,'nickname'=>$id['name'] ?: $login]);
            $user=get_user_by('id',$uid);
        }
        $wpdb->insert($table,['user_id'=>$user->ID,'provider'=>$provider,'provider_user_id'=>$id['sub'],'email'=>$id['email'],'created_at'=>$now,'last_login_at'=>$now]);
        return (int)$user->ID;
    }

    public static function callback_url(string $provider): string {
        return rest_url('decka-typer/v1/oauth/' . sanitize_key($provider) . '/callback');
    }

    public static function start_url(string $provider): string {
        return add_query_arg(['action'=>'dt_oauth_start','provider'=>$provider],admin_url('admin-post.php'));
    }

    private static function typer_url(): string {
        $s=DT_DB::settings();
        $url=!empty($s['typer_page_id']) ? get_permalink((int)$s['typer_page_id']) : home_url('/typer/');
        return $url ?: home_url('/typer/');
    }

    private static function fail(string $message): void {
        $url=add_query_arg('dt_login_error',rawurlencode($message),self::typer_url());
        wp_safe_redirect($url);
        exit;
    }

    private static function json_response($res,string $label): array {
        if (is_wp_error($res)) throw new RuntimeException($label . ': ' . $res->get_error_message());
        $code=wp_remote_retrieve_response_code($res);
        $json=json_decode(wp_remote_retrieve_body($res),true);
        if ($code<200 || $code>=300 || !is_array($json)) {
            $detail=is_array($json) ? ($json['error_description'] ?? $json['error']['message'] ?? $json['error'] ?? '') : '';
            throw new RuntimeException($label . ' zwrócił błąd' . ($detail ? ': '.sanitize_text_field((string)$detail) : '.'));
        }
        return $json;
    }

    private static function b64url(string $data): string { return rtrim(strtr(base64_encode($data),'+/','-_'),'='); }
    private static function b64url_decode(string $data): string { return base64_decode(strtr($data,'-_','+/') . str_repeat('=',(4-strlen($data)%4)%4)); }

    private static function ecdsa_der_to_jose(string $der,int $partLength): string {
        $pos=0;
        if (ord($der[$pos++])!==0x30) throw new RuntimeException('Błędny podpis ECDSA.');
        self::read_asn_len($der,$pos);
        if (ord($der[$pos++])!==0x02) throw new RuntimeException('Błędny podpis ECDSA.');
        $rlen=self::read_asn_len($der,$pos); $r=substr($der,$pos,$rlen); $pos+=$rlen;
        if (ord($der[$pos++])!==0x02) throw new RuntimeException('Błędny podpis ECDSA.');
        $slen=self::read_asn_len($der,$pos); $s=substr($der,$pos,$slen);
        $r=str_pad(ltrim($r,"\0"),$partLength,"\0",STR_PAD_LEFT);
        $s=str_pad(ltrim($s,"\0"),$partLength,"\0",STR_PAD_LEFT);
        return substr($r,-$partLength).substr($s,-$partLength);
    }

    private static function ecdsa_jose_to_der(string $sig): string {
        $len=intdiv(strlen($sig),2); $r=substr($sig,0,$len); $s=substr($sig,$len);
        $r=ltrim($r,"\0"); $s=ltrim($s,"\0");
        if ($r==='' || (ord($r[0])&0x80)) $r="\0".$r;
        if ($s==='' || (ord($s[0])&0x80)) $s="\0".$s;
        $seq="\x02".self::asn_len(strlen($r)).$r."\x02".self::asn_len(strlen($s)).$s;
        return "\x30".self::asn_len(strlen($seq)).$seq;
    }

    private static function read_asn_len(string $data,int &$pos): int {
        $len=ord($data[$pos++]);
        if (($len&0x80)===0) return $len;
        $n=$len&0x7f; $len=0;
        while($n-->0) $len=($len<<8)|ord($data[$pos++]);
        return $len;
    }
    private static function asn_len(int $len): string {
        if ($len<128) return chr($len);
        $out=''; while($len>0){$out=chr($len&0xff).$out;$len>>=8;} return chr(0x80|strlen($out)).$out;
    }

    private static function apple_jwk_to_pem(string $x,string $y): string {
        $point="\x04".self::b64url_decode($x).self::b64url_decode($y);
        $alg="\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $bit="\x03".self::asn_len(strlen($point)+1)."\x00".$point;
        $der="\x30".self::asn_len(strlen($alg)+strlen($bit)).$alg.$bit;
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
    }
}
