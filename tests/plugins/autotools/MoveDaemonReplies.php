<?php
/** Serializable native queue/lease model for the real SCGI parser and CLI worker. */
class AutoToolsMoveDaemonReplies
{
    public $state;
    public function __construct($state) { $this->state = $state; }
    private function save() { file_put_contents($this->state['root'] . '/native.json', json_encode($this->state)); }
    public function __invoke($body, $methods)
    {
        preg_match_all('~<string>(.*?)</string>~s', $body, $matches);
        $args = array_map(function ($v) { return html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8'); }, $matches[1]);
        foreach ($methods as $method) if (isset($args[0]) && $args[0] === $method) array_shift($args);
        $s =& $this->state;
        $method = $methods[0];
        $receipt = $s['token'] . '|' . $s['run'][0] . '|' . $s['run'][1] . '|' . $s['run'][2] . '|' . $s['hash'];
        $queue = 'queued:' . hash('sha256', 'tester') . ':' . $s['qid'] . ':' . $s['hash'] . ':' . $s['retry'];
        $answer = array('0');
        switch ($method) {
            case 'system.client_version': $answer=array('0.16.25');break;
            case 'directory.default':
                $answer=array($s['root'].'/dl',$s['root'].'/session','0.16.25','test','50000-50000','','');break;

            case 'system.listMethods':
                if(isset($s['capabilities'])) { $answer=$s['capabilities']; break; }
                $answer = array('system.listMethods', 'system.autotools.move_queue', 'system.autotools.move_dispatch',
                    'system.autotools.move_state', 'system.autotools.move_owner', 'system.autotools.move_reset',
                    'd.autotools.move_enqueue', 'd.autotools.move_begin', 'd.autotools.move_defer',
                    'd.autotools.move_finish', 'd.autotools.move_cancel', 'd.stop_close_claim_state',
                    'd.stop_close_claim', 'd.replay_stop_close_claim', 'd.ack_stop_close_claim',
                    'd.directory.set_if_stop_close_claim', 'd.directory.base.set_if_stop_close_claim',
                    'd.start_if_stop_close_claim', 'd.open_if_stop_close_claim', 'd.release_stop_close_claim'); break;
            case 'system.autotools.move_state':
                if(isset($s['nativeState'])) { $answer=array($s['nativeState']); break; }
                $answer = array(!$s['queued'] ? 'none' : ($s['lease'] !== 'absent'
                    ? 'leased:' . hash('sha256', 'tester') . ':' . $s['qid'] . ':' . $s['token'] : $queue)); break;
            case 'd.get_directory': $answer = array($s['directory']); break;
            case 'd.is_multi_file': $answer = array($s['multi'] ? '1' : '0'); break;
            case 'd.get_base_filename': $answer = array($s['basename']); break;
            case 'd.get_name': $answer = array($s['name']); break;
            case 'd.get_custom1': $answer = array(''); break;
            case 'd.complete':
                if (!empty($s['missing'])) return new FakeRtorrentFault(-500, 'invalid parameters: info-hash not found');
                $answer = array('1'); break;
            case 'd.hash':
                if (!empty($s['missing'])) return new FakeRtorrentFault(-500, 'invalid parameters: info-hash not found');
                $answer = array($s['hash']); break;
            case 'd.get_base_path': $answer = array($s['multi'] ? $s['directory'] : $s['directory'] . '/' . $s['basename']); break;
            case 'd.is_open': $answer = array((string)$s['open']); break;
            case 'd.is_active': $answer = array((string)$s['active']); break;
            case 'd.stop_close_claim_state':
                if (!isset($args[1]) || $args[1] !== $s['qid']) return new FakeRtorrentFault(-500, 'stop claim: reserved by another job');
                $answer = array($s['lease'] === 'absent' ? 'absent' : ($s['lease'] === 'active'
                    ? 'active|' . $receipt : $s['lease'] . '|' . $s['mode'] . '|' . $receipt)); break;
            case 'd.autotools.move_enqueue':
                $s['queued'] = true; $answer = array('queued:' . $s['qid']); break;
            case 'd.autotools.move_begin':
                if ($args[1] !== $s['qid']) return new FakeRtorrentFault(-500, 'stale Q');
                if (!empty($s['busy'])) { $answer = array('busy:owner'); break; }
                $s['lease'] = 'active'; $s['active'] = 0; $s['open'] = 0;
                if (!empty($s['collision'])) file_put_contents($s['destination'], 'foreign payload');
                $answer = array('active|' . $receipt); break;
            case 'd.directory.base.set_if_stop_close_claim':
                $s['directory'] = $args[2]; $answer = array('1'); break;
            case 'd.directory.set_if_stop_close_claim':
                $s['directory'] = $args[2] . ($s['multi'] ? '/' . $s['name'] : ''); $answer = array('1'); break;
            case 'd.start_if_stop_close_claim':
            case 'd.open_if_stop_close_claim':
            case 'd.release_stop_close_claim':
                $s['mode'] = !empty($s['lateStop']) ? 'K' : ($method === 'd.start_if_stop_close_claim' ? 'S'
                    : ($method === 'd.open_if_stop_close_claim' ? 'O' : 'R'));
                $s['lease'] = 'done';
                $s['active'] = $s['mode'] === 'S' ? 1 : 0;
                $s['open'] = in_array($s['mode'], array('S','O'), true) ? 1 : 0;
                $answer = array($s['mode'] === 'K' ? '0' : '1'); break;
            case 'd.replay_stop_close_claim':
                if (!isset($args[1]) || $args[1] !== $s['qid']) return new FakeRtorrentFault(-500, 'stop claim: reserved by another job');
                $s['lease'] = 'done'; $answer = array('1'); break;
            case 'd.ack_stop_close_claim':
                if (!isset($args[1]) || $args[1] !== $s['qid']) return new FakeRtorrentFault(-500, 'stop claim: reserved by another job');
                $s['lease'] = 'absent'; $answer = array('1'); break;
            case 'd.autotools.move_finish':
                if ($s['lease'] !== 'absent') return new FakeRtorrentFault(-500, 'lease held');
                $s['queued'] = false; $answer = array('finished'); break;
            case 'd.autotools.move_cancel': $s['queued'] = false; $answer = array('cancelled'); break;
            case 'd.autotools.move_defer':
                if ($args[1] !== $s['qid'] || $args[2] !== $s['retry'] || $s['lease'] !== 'absent')
                    return new FakeRtorrentFault(-500, 'CAS refused');
                $s['retry'] = $args[3]; $answer = array('deferred'); break;
            case 'd.custom.items':
                if ($args !== array($s['hash'])) return new FakeRtorrentFault(-500, 'd.custom.keys/items takes no arguments.');
                if (array_key_exists('customReply', $s))
                    return $s['customReply'] === null ? null : new FakeRtorrentRawReply(ScgiToolPeer::response($s['customReply']));
                $members='';
                foreach (isset($s['customItems']) ? $s['customItems'] : array() as $key=>$value)
                    $members.='<member><name>'.htmlspecialchars($key,ENT_XML1).'</name><value><string>'
                        .htmlspecialchars($value,ENT_XML1).'</string></value></member>';
                return new FakeRtorrentRawReply(ScgiToolPeer::response('<struct>'.$members.'</struct>'));
            case 'd.multicall':
            case 'd.multicall2':
                if(isset($s['aliasVersion']) && (count($args)<3 || $args[0]!=='' || $args[1]!=='main'))
                    return new FakeRtorrentFault(-500, "Could not find '=' in command 'main'.");
                if (array_key_exists('inventoryReply', $s))
                    return $s['inventoryReply'] === null ? null : new FakeRtorrentRawReply($s['inventoryReply']);
                $rows=array();
                if(!empty($s['inventoryLoaded'])) {
                    $row=array($s['hash']);
                    if(in_array('d.name=',$args,true))$row[]=$s['name'];
                    $rows[]=$row;
                }
                return new FakeRtorrentRawReply(ScgiToolPeer::rows($rows));
            default: $answer = array_fill(0, count($methods), '0');
        }
        $this->save();
        if (isset($s['drop']) && $s['drop'] === $method) { $s['drop'] = ''; $this->save(); return null; }
        return $answer;
    }
}
