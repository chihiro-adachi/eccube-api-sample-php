<?php

if (php_sapi_name() !== 'cli') {
    die();
}

require_once 'vendor/autoload.php';

// 参考
// - http://qiita.com/TakahikoKawasaki/items/200951e5b5929f840a1f
// - https://mattn.kaoriya.net/software/lang/go/20161231001721.htm

define('CLIENT_ID', 'c9ec750ff963d606cadc708e9b0a31bdb2a414b4');
define('CLIENT_SECRET', '23bf78143c627f9c96c71689b30fc9a3b078d717');
define('REDIRECT_URL', 'http://localhost:8090/receive');
define('ENDPOINT_AUTHORIZE', 'http://localhost:8081/index_dev.php/admin/OAuth2/v0/authorize');
define('ENDPOINT_ACCESS_TOKEN', 'http://localhost:8081/index_dev.php/OAuth2/v0/token');
define('ENDPOINT_USER_INFO', 'http://localhost:8081/index_dev.php/OAuth2/v0/userinfo');
define('ENDPOINT_PRODUCT', 'http://localhost:8081/index_dev.php/products');

$state = uniqid('s');
$nonce = uniqid('n');

// 認可エンドポイントへのurlの構築
$url = ENDPOINT_AUTHORIZE
    .'?response_type='.rawurlencode('id_token token') // implicitなのでtokenを取得
    .'&client_id='.CLIENT_ID
    .'&redirect_uri='.rawurlencode(REDIRECT_URL)
    .'&scope='.rawurlencode('openid email product_read')
    .'&state='.$state   // csrf対策
    .'&nonce='.$nonce   // リプレイアタック対策
;
// ブラウザを立ち上げて同意画面へ
echo 'open '.$url;
exec("open '$url'");

// アクセストークン受取用のバックエンドサーバを立ち上げる
$server = stream_socket_server('tcp://0.0.0.0:8090', $errno, $errstr, STREAM_SERVER_LISTEN | STREAM_SERVER_BIND);

$header = "HTTP/1.1 200 OK\r\n".
    "Content-Type: text/html; charset=UTF-8\r\n".
    "Content-Length: %s\r\n".
    "Connection: Close\r\n";

$token = [];

while ($connection = @stream_socket_accept($server)) {

    echo "client connected.".PHP_EOL;

    $line = fgets($connection);
    echo $line;

    // #access_token=xxxxx&scope=xxxx...のような形式でリダイレクトとされるので、parseできない
    // /close?access_token=xxxにループバックしてトークンを取得する
    // @see https://mattn.kaoriya.net/software/lang/go/20161231001721.htm
    if (preg_match('|^GET /receive HTTP/1.1|', $line, $matches)) {
        $body = '<script>location.href = "/close?" + location.hash.substring(1);</script>';
        $length = strlen($body);
        $message = sprintf($header, $length)."\r\n".$body;

    } elseif (preg_match('|GET /close\?(access_token=.+?) HTTP/1.1|', $line, $matches)) {

        parse_str($matches[1], $token);

        // window.closeしたい
        $body = "token received!. please close this window. <script>window.open('about:blank', '_self').close();</script>";
        $length = strlen($body);
        $message = sprintf($header, $length)."\r\n".$body;

        $token_received = true;

    } else {
        $body = "welcome to simple php echo server!";
        $length = strlen($body);
        $message = sprintf($header, $length)."\r\n".$body;
    }

    fwrite($connection, $message, strlen($message));
    fclose($connection);

    echo "connection closed.".PHP_EOL;

    // tokenを取得できたらサーバを終了
    if (!empty($token)) {
        break;
    }
}

fclose($server);

var_dump($token);

// stateのチェック
if ($state !== $token['state']) {
    die();
}

// UserInfoEndpointへアクセス
$client = new \GuzzleHttp\Client();
$headers = ['Authorization' => 'Bearer '.$token['access_token']];  // Bearer [access token] の書式で渡す
$response = $client->request(
    'GET',
    ENDPOINT_USER_INFO,
    [
        'headers' => $headers,
    ]
);

$body = (string)$response->getBody();
var_dump(json_decode($body));
