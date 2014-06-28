<?
function _debug($someText, $someVar = null) {
    if (php_sapi_name() == 'cli') {
        echo date('Y.m.d H:i:s') . ' ' . $someText . "\n";
        return;
    }

    $logFile = "/tmp/phpdlna-debug.txt";
    $fh = fopen($logFile, 'a') or die();
    fwrite($fh, date('Y.m.d H:i:s') . ' ' . $someText . "\n");
    if ($someVar) fwrite($fh, print_r($someVar, true) . "\n");
    fclose($fh);
}

$headers=array_change_key_case(getallheaders()); //ofcourse ther's a php function for that...

$body = @file_get_contents('php://input');

_debug("Request:",array('url'=>"$_SERVER[REQUEST_METHOD] http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", 'headers'=>$headers, 'body'=>$body));

if (array_key_exists('SID', $headers)) {// renewal or unsubscribe

} elseif (!array_key_exists('nt', $headers) || $headers['nt'] !== 'upnp:event' || !array_key_exists('callback', $headers)) {
    header('HTTP/1.1 412 Precondition Failed');
    die();
}


if ($_SERVER['REQUEST_METHOD'] === 'SUBSCRIBE') {
    if (array_key_exists('sid', $headers))
        header('SID: '.$headers['sid']);
    else
        header('SID: uuid:kj9d4fae-7dec-11d0-a765-00a0c91e6bf6'); // why not?
    if (array_key_exists('timeout', $headers))
        header('Timeout: '.$headers['timeout']);
    else
        header('Timeout: Second-3600');
    if (array_key_exists('nt', $headers))
        header('NT: '.$headers['nt']);
}

?>
