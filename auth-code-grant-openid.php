<?php
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * authorization code grant のサンプル(open id connect版)
 *
 * 登場人物一覧
 * - rp:  http://localhost:8082 (このスクリプト)
 * - idp: http://localhost:8081 (APIプラグインをインストールしたEC-CUBE)
 * - resource server: http://localhost:8081 (同上)
 *
 * 参考
 *  - https://www.slideshare.net/kura_lab/openid-connect-id
 *
 * 実装
 * - done: authorization request
 * - done: authorization code
 * - done: token request
 * - done: resouece access(user info endpoint)
 *
 */
define('CLIENT_ID', 'eb11d480a5c8dcb7a61a7af76f41a3e6e4137c2d');
define('CLIENT_SECRET', 'f32675bbb8665325a5260eeff59ac19e27f9c7ce');
define('REDIRECT_URL', 'http://localhost:8082/auth-code-grant-openid.php');
define('ENDPOINT_AUTHORIZE', 'http://localhost:8081/index_dev.php/admin/OAuth2/v0/authorize');
define('ENDPOINT_ACCESS_TOKEN', 'http://localhost:8081/index_dev.php/OAuth2/v0/token');
define('ENDPOINT_USER_INFO', 'http://localhost:8081/index_dev.php/OAuth2/v0/userinfo');

$request = Request::createFromGlobals();
$session = new Session();
$session->start();

// エラー時はdescription出力して終了.
if ($request->query->has('error')) {
    echo 'error:'.$request->query->get('error_description');
    exit;
}

// authorization codeがない場合は, 認可サーバにリクエストする(リダイレクト).
if (false === $request->query->has('code')) {

    // state, nonceを生成. とりあえずuniqidで.
    $state = uniqid('s');
    $nonce = uniqid('n');

    // 認可エンドポイントへのurlの構築
    $url = ENDPOINT_AUTHORIZE
        .'?response_type=code'
        .'&client_id='.CLIENT_ID
        .'&redirect_uri='.rawurlencode(REDIRECT_URL)
        .'&scope='.rawurlencode('openid email product_read') // openidは必須
        .'&state='.$state   // csrf対策
        .'&nonce='.$nonce;  // リプレイアタック対策

    // state, nonceをセッションに保持.
    $session->set('state', $state);
    $session->set('nonce', $nonce);

    // 認可サーバにリダイレクト
    header('Location: '.$url);
    exit;

// 認可サーバへリダイレクト後、同意を得たら認可コードが返却される
} else {
    $state = $request->query->get('state');
    $stateInSession = $session->get('state');

    // stateのチェック
    if (is_null($state) || $state !== $stateInSession) {
        echo 'error: state is invalid.';
        exit;
    }

    // アクセストークンの取得
    // basic認証が必要なケースもあるがここでは省略.
    $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
    $body = 'grant_type=authorization_code'
        .'&code='.$request->query->get('code')
        .'&redirect_uri='.rawurlencode(REDIRECT_URL)
        // http://doc.ec-cube.net/api_authorization のサンプルではclientidとsecuretも必要. state, nonceは必要？なくても動く
        .'&client_id='.CLIENT_ID
        .'&client_secret='.CLIENT_SECRET
//        .'&state='.$state
//        .'&nonce='.$nonce;
    ;

    $client = new \GuzzleHttp\Client();
    $response = $client->request(
        'POST',
        ENDPOINT_ACCESS_TOKEN,
        [
            'headers' => $headers,
            'body' => $body,
        ]
    );

    $body = (string)$response->getBody();
    $token = json_decode($body);
//    object(stdClass)[46]
//      public 'access_token' => string '87cb6e9dc73ab213ea672fa25b9e010059ef1a7e' (length=40)
//      public 'expires_in' => int 3600
//      public 'token_type' => string 'bearer' (length=6)
//      public 'scope' => string 'openid email product_read' (length=25)
//      public 'id_token' => string 'eyJ0eXAiOi...'... (length=688)
    // ※ refresh tokenはとれない？

    // UserInfoEndpointへアクセス
    $client = new \GuzzleHttp\Client();
    $headers = ['Authorization' => 'Bearer '.$token->access_token];  // Bearer [access token] の書式で渡す
    $response = $client->request(
        'GET',
        ENDPOINT_USER_INFO,
        [
            'headers' => $headers,
        ]
    );

    $body = (string)$response->getBody();
    var_dump(json_decode($body));
//    object(stdClass)[42]
//  public 'sub' => string 'TwmPJzZqxUUAOrTVNs6HoK5F-4RwHfUCCRfhkQ-W0bk' (length=43)
//  public 'email' => null
//  public 'email_verified' => boolean false

    // id tokenの利用についてはスライドのp80から. nonceの扱いもでてくる
    // https://www.slideshare.net/kura_lab/openid-connect-id/80
}

