<?php
require_once('rules.php');

$cmd = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['mode'] ?? null) : ($_GET['mode'] ?? null);
if($cmd === 'setrules')
	Requests::requirePost();
$mngr = rURLRewriteRulesList::load();
$val = null;

switch($cmd)
{
	case "checkrule":
	{
		$rule = new rURLRewriteRule( 'test',
			trim($_REQUEST['pattern']), trim($_REQUEST['replacement']) );
		$href = trim($_REQUEST['test']);
		$rslt = $rule->apply($href,$href);
		$val = array( "msg"=>$rslt );
		break;
	}
	case "setrules":
	{
		$mngr->set();
		break;
	}
}

if(is_null($val))
	$val = $mngr->getContents();

CachedEcho::send(JSON::safeEncode($val),"application/json",true);
