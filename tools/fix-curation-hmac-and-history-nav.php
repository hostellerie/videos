<?php
$root = dirname(__DIR__);

function rt($p){$c=file_get_contents($p); if($c===false) throw new RuntimeException('read '.$p); return $c;}
function wt($p,$c){if(file_put_contents($p,$c)===false) throw new RuntimeException('write '.$p);}

$watchPath=$root.'/public_html/watch.php';
$watch=rt($watchPath);
$old=<<<'PHP'
    if (!SEC_checkToken()) {
        $curationMessage = isset($LANG_VIDEOS['history_csrf'])
            ? $LANG_VIDEOS['history_csrf'] : 'The security token has expired.';
    } else {
        $curationAction = COM_applyFilter($_POST['videos_curation_action']);
PHP;
$new=<<<'PHP'
    $curationAction = COM_applyFilter($_POST['videos_curation_action']);
    $curationExpires = isset($_POST['videos_curation_expires'])
        ? (int) $_POST['videos_curation_expires'] : 0;
    $curationProof = isset($_POST['videos_curation_proof'])
        ? (string) $_POST['videos_curation_proof'] : '';
    $expectedProof = videos_watch_curation_proof(
        $videoId,
        $curationAction,
        $curationExpires,
        $uid,
        $bootstrap->getSecret()
    );
    $proofValid = $curationExpires >= time()
        && $curationExpires <= time() + 1800
        && $curationProof !== ''
        && hash_equals($expectedProof, $curationProof);
    if (!$proofValid) {
        $curationMessage = isset($LANG_VIDEOS['history_csrf'])
            ? $LANG_VIDEOS['history_csrf'] : 'The security token has expired.';
    } else {
PHP;
if(strpos($watch,$old)===false) throw new RuntimeException('csrf block not found');
$watch=str_replace($old,$new,$watch);
$watch=str_replace("\n\$curationToken = SEC_createToken();",'',$watch);
$watch=preg_replace('/videos_watch_curation_form\(([^;\n]+), \$curationToken\)/','videos_watch_curation_form($1, $bootstrap->getSecret(), $uid)',$watch,-1,$cnt);
if($cnt<4) throw new RuntimeException('forms not replaced '.$cnt);
$oldFn=<<<'PHP'
function videos_watch_curation_form($actionUrl, $action, $videoId, $label, $token)
{
    return '<form class="videos-inline-form" method="post" action="'
        . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="videos_curation_action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="video_id" value="' . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></form>';
}
PHP;
$newFn=<<<'PHP'
function videos_watch_curation_proof($videoId, $action, $expires, $uid, $secret)
{
    return hash_hmac(
        'sha256',
        'curation:' . (int) $uid . ':' . (string) $videoId . ':'
            . (string) $action . ':' . (int) $expires,
        (string) $secret
    );
}

function videos_watch_curation_form($actionUrl, $action, $videoId, $label, $secret, $uid)
{
    $expires = time() + 900;
    $proof = videos_watch_curation_proof($videoId, $action, $expires, $uid, $secret);
    return '<form class="videos-inline-form" method="post" action="'
        . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="videos_curation_action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="video_id" value="' . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="videos_curation_expires" value="' . (int) $expires . '">'
        . '<input type="hidden" name="videos_curation_proof" value="' . htmlspecialchars($proof, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></form>';
}
PHP;
if(strpos($watch,$oldFn)===false) throw new RuntimeException('form function not found');
$watch=str_replace($oldFn,$newFn,$watch);
wt($watchPath,$watch);

$fnPath=$root.'/functions.inc';
$fn=rt($fnPath);
$oldNav=<<<'PHP'
    if (!COM_isAnonUser() && SEC_hasRights('videos.personal_history') && !empty($_VIDEOS_CONF['account_history_enabled'])) {
        $items['history'] = array('label' => $LANG_VIDEOS['my_videos'], 'url' => $_CONF['site_url'] . '/videos/history.php');
    }
PHP;
$newNav=<<<'PHP'
    if (!COM_isAnonUser() && !empty($_VIDEOS_CONF['account_history_enabled'])) {
        $items['history'] = array('label' => $LANG_VIDEOS['my_videos'], 'url' => $_CONF['site_url'] . '/videos/history.php');
    }
PHP;
if(strpos($fn,$oldNav)===false) throw new RuntimeException('nav condition not found');
$fn=str_replace($oldNav,$newNav,$fn);
wt($fnPath,$fn);

echo "Curation HMAC and history navigation applied.\n";
