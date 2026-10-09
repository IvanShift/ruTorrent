<?php
require_once dirname(__DIR__, 2) . '/php/rtorrent.php';

require_once( dirname(__FILE__)."/../../php/settings.php");
require_once( dirname(__FILE__)."/../../php/utility/json.php");
eval(FileUtil::getPluginConf('autotools'));

class rAutoTools
{
    static public function moveMode()
    {
        $native = array('system.autotools.move_queue', 'system.autotools.move_dispatch',
            'system.autotools.move_state', 'system.autotools.move_owner', 'system.autotools.move_reset', 'd.autotools.move_enqueue',
            'd.autotools.move_begin', 'd.autotools.move_defer', 'd.autotools.move_finish', 'd.autotools.move_cancel');
        $lease = array('d.stop_close_claim_state', 'd.stop_close_claim', 'd.replay_stop_close_claim',
            'd.ack_stop_close_claim', 'd.directory.set_if_stop_close_claim',
            'd.directory.base.set_if_stop_close_claim', 'd.start_if_stop_close_claim',
            'd.open_if_stop_close_claim', 'd.release_stop_close_claim');
        $capability = rpcMethodCapability(array_merge($native, $lease), $available);
        if ($capability === 'available') return 'queue';
        if ($capability !== 'unsupported' || $available === null) return 'paused';
        foreach ($available as $method)
            if (strpos($method, 'system.autotools.') === 0 || strpos($method, 'd.autotools.') === 0)
                return 'paused';
        return 'upstream';
    }

    public function modeNotice()
    {
        if (!$this->enable_move || $this->fileop_type !== 'Move') return '';
        if ($this->moveStatus === 'paused')
            return "noty('AutoTools Move paused: rTorrent file-job support could not be verified.', 'error');";
        if ($this->moveStatus === 'upstream')
            return "noty('AutoTools Move runs in upstream mode: this rTorrent has no native file-job queue, so a crash or a retrackers reload during a move can leave a torrent split between folders.', 'warning');";
        return '';
    }

	public $hash = "autotools.dat";
	public $modified = false;
	public $enable_label = 0;
	public $label_template = "{DIR}";
	public $enable_move = 0;
	public $path_to_finished = "";
	public $skip_move_for_files = "";
	public $fileop_type = "Move";
	public $enable_watch = 0;
	public $path_to_watch = "";
	public $watch_start = 0;
	public $automove_filter = "/.*/";
	public $addName = 0;
	public $addLabel = 0;
	public $moveStatus = 'queue';
    public $finishedPathRefused = false;

	static public function load()
	{
		$cache = new rCache();
		$at = new rAutoTools();
		$cache->get( $at );
		if( !property_exists( $at, "automove_filter" ) || (@preg_match($at->automove_filter, null) === false) )
			$at->automove_filter = "/.*/";
		if( !property_exists( $at, "skip_move_for_files" ) ||
			(strlen($at->skip_move_for_files) && (@preg_match($at->skip_move_for_files."u", null) === false)) )
			$at->skip_move_for_files = "/(?:\.rar|\.zip)$/";
		if( !property_exists( $at, "addName" ) )
			$at->addName = 0;
		if( !property_exists( $at, "addLabel" ) )
			$at->addLabel = 0;
		return $at;
	}
	public function store()
	{
		$cache = new rCache();
		return $cache->set( $this );
	}
	public function set()
	{
		if( !isset( $HTTP_RAW_POST_DATA ) )
			$HTTP_RAW_POST_DATA = file_get_contents( "php://input" );
		$vars = explode( '&', $HTTP_RAW_POST_DATA );
		$this->enable_label = 0;
		$this->label_template = "{DIR}";
		$this->enable_move = 0;
		$this->fileop_type = "Move";
		$this->path_to_finished = "";
		$this->skip_move_for_files= "/(?:\.rar|\.zip)$/";
		$this->enable_watch = 0;
		$this->path_to_watch = "";
		$this->watch_start = 0;
		$this->automove_filter = "/.*/";
		$this->addName = 0;
		$this->addLabel = 0;
		foreach( $vars as $var )
		{
			$parts = explode( "=", $var );
			if( $parts[0] == "enable_label" )
			{
				$this->enable_label = $parts[1];
			}
			else if( $parts[0] == "label_template" )
			{
				$this->label_template = $parts[1];
			}
			else if( $parts[0] == "automove_filter" )
			{
				$this->automove_filter = $parts[1];
				if(@preg_match($this->automove_filter, null) === false)
					$this->automove_filter = "/.*/";
			}
			else if( $parts[0] == "enable_move" )
			{
				$this->enable_move = $parts[1];
			}
			else if( $parts[0] == "fileop_type" )
			{
				$this->fileop_type = $parts[1];
			}
			else if( $parts[0] == "path_to_finished" )
			{
				$this->path_to_finished = $parts[1];
				if(!rTorrentSettings::get()->correctDirectory($this->path_to_finished)) {
                    $this->path_to_finished = '';
                    $this->finishedPathRefused = true;
                }
			}
			else if( $parts[0] == "skip_move_for_files" )
			{
				$this->skip_move_for_files = $parts[1];
				if(strlen($this->skip_move_for_files) && (@preg_match($this->skip_move_for_files."u", null) === false))
					$this->skip_move_for_files = "/(?:\.rar|\.zip)$/";
			}
			else if( $parts[0] == "enable_watch" )
			{
				$this->enable_watch = $parts[1];
			}
			else if( $parts[0] == "path_to_watch" )
			{
				$this->path_to_watch = $parts[1];
				if(!rTorrentSettings::get()->correctDirectory($this->path_to_watch))
					$this->path_to_watch = '';
			}
			else if( $parts[0] == "watch_start" )
			{
				$this->watch_start = $parts[1];
			}
			else if( $parts[0] == "add_label" )
			{
				$this->addLabel = $parts[1];
			}
			else if( $parts[0] == "add_name" )
			{
				$this->addName = $parts[1];
			}
		}
		$this->setHandlers();
		$this->store();
	}
	public function get()
	{
		return("theWebUI.autotools = ".JSON::jsValue(array(
			'EnableLabel' => intval($this->enable_label),
			'LabelTemplate' => (string)$this->label_template,
			'EnableMove' => intval($this->enable_move),
			'FileOpType' => (string)$this->fileop_type,
			'PathToFinished' => (string)$this->path_to_finished,
			'SkipMoveForFiles' => (string)$this->skip_move_for_files,
			'EnableWatch' => intval($this->enable_watch),
			'PathToWatch' => (string)$this->path_to_watch,
			'MoveFilter' => (string)$this->automove_filter,
			'WatchStart' => intval($this->watch_start),
			'AddLabel' => intval($this->addLabel),
			'AddName' => intval($this->addName)
		)).";\n");
	}
	private static function pathIsInside($root, $path)
	{
		return $path === $root || strpos($path, rtrim($root, '/') . '/') === 0;
	}

	public static function destinationWithinRoot($finishedRoot, $destination)
	{
		if(!is_string($finishedRoot) || !is_string($destination) || $finishedRoot === ''
			|| $destination === '' || strpos($finishedRoot, "\0") !== false
			|| strpos($destination, "\0") !== false) return false;
		$root = FileUtil::fullpath($finishedRoot);
		$path = FileUtil::fullpath($destination);
		if(!self::pathIsInside($root, $path)) return false;
		$realRoot = realpath($finishedRoot);
		if($realRoot === false) return false;
		$probe = $path;
		while(!file_exists($probe) && !is_link($probe))
		{
			$parent = dirname($probe);
			if($parent === $probe) return false;
			$probe = $parent;
		}
		$realProbe = realpath($probe);
		return $realProbe !== false && self::pathIsInside($realRoot, $realProbe);
	}

	public static function completionMailFile($destination, $finishedRoot)
	{
		$path = rtrim($destination, '/');
		$root = rtrim($finishedRoot, '/');
		while($path !== '' && $path !== $root)
		{
			$file = $path . '/.mailto';
			if(is_file($file)) return $file;
			$parent = dirname($path);
			if($parent === $path) break;
			$path = $parent;
		}
		return null;
	}

	public static function notifyCompletedFileTransfer($destination, $finishedRoot, $torrentName, $send = null)
	{
		$file = self::completionMailFile($destination, $finishedRoot);
		if($file === null) return false;
		$lines = @file($file);
		if($lines === false)
		{
			FileUtil::toLog('autotools: completion mail refused: unreadable .mailto');
			return false;
		}
		$fields = array('TO'=>'', 'CC'=>'', 'BCC'=>'', 'FROM'=>'', 'SUBJECT'=>'');
		while($lines)
		{
			$parts = explode(':', $lines[0], 2);
			$key = trim($parts[0]);
			if(count($parts) < 2 || !array_key_exists($key, $fields)) break;
			$fields[$key] = trim($parts[1]);
			array_shift($lines);
		}
		if($fields['TO'] === '')
		{
			FileUtil::toLog('autotools: completion mail refused: .mailto missing TO');
			return false;
		}
		$subject = str_replace('{TORRENT}', $torrentName, $fields['SUBJECT']);
		$message = str_replace('{TORRENT}', $torrentName, implode('', $lines));
		$headers = 'From: ' . $fields['FROM'] . "\r\n";
		if($fields['CC'] !== '') $headers .= 'CC: ' . $fields['CC'] . "\r\n";
		if($fields['BCC'] !== '') $headers .= 'BCC: ' . $fields['BCC'] . "\r\n";
		$headers .= "Content-type: text/plain; charset=utf-8\r\n";
		$sent = $send === null ? mail($fields['TO'], $subject, $message, $headers)
			: call_user_func($send, $fields['TO'], $subject, $message, $headers);
		if(!$sent) FileUtil::toLog('autotools: completion mail failed');
		return $sent;
	}
    public function setHandlers()
    {
        global $autowatch_interval;
        $settings = rTorrentSettings::get();
        $q = array('rTorrent', 'quoteCommandArg');
        $user = User::getUser();
        $php = $q(Utility::getPHP());
        $root = __DIR__;
        $execute = function ($script, $args, $kind = 'execute') use ($q, $php, $root) {
            return getCmd($kind) . '={' . $php . ',' . $q($root . '/' . $script)
                . ($args === '' ? '' : ',' . $args) . '}';
        };
        $request = new rXMLRPCRequest($settings->getOnInsertCommand(array('autolabel' . $user, getCmd('cat='))));
        $label = $this->enable_label ? getCmd('branch') . '=$' . getCmd('not') . '=$'
            . getCmd('d.get_custom1') . '=,' . $q($execute('label.php', '$' . getCmd('d.get_hash') . '=,' . $q($user)))
            : getCmd('cat=');
        $request->addCommand($settings->getOnInsertCommand(array('_autolabel' . $user, $label)));
        $hook = getCmd('cat=');
        $this->moveStatus = self::moveMode();
        // Always drain captured obligations, including after the checkbox is disabled.
        if ($this->moveStatus === 'queue')
            $request->addCommand(new rXMLRPCCommand('schedule.if_absent', array('',
                'am.queue.' . hash('sha256', $user), '1', '1', 'system.autotools.move_dispatch=' . $q($user))));
        if ($this->enable_move && $this->path_to_finished !== '') {
            if ($this->fileop_type === 'Move') {
                if ($this->moveStatus === 'queue') {
                    // Native enqueue keeps its visible refusal and direct RPC fault;
                    // the finished hook must still run unrelated later handlers.
                    $hook = 'try=' . $q('d.autotools.move_enqueue=' . $q($user));
                } elseif ($this->moveStatus === 'upstream') {
                    // Both invocations run synchronously. Their bootstrap forbids RPC.
                    $script = 'upstream_move.php';
                    $args = '$' . getCmd('d.get_base_path') . '=,$' . getCmd('d.get_base_filename')
                        . '=,$' . getCmd('d.is_multi_file') . '=,$' . getCmd('d.get_custom1')
                        . '=,$' . getCmd('d.get_name') . '=,' . $q($user);
                    $hook = getCmd('d.set_directory_base') . '='
                        . $q('$' . $execute($script, 'check,' . $args, 'execute_capture'))
                        . ' ; ' . $execute($script, 'move,$' . getCmd('d.get_hash') . '=,' . $args);
                    FileUtil::toLog('autotools: move mode upstream (native file-job queue absent)');
                } else FileUtil::toLog('autotools: move paused: incomplete or unconfirmed native file-job ABI');
            } else {
                $marker = getCmd('d.set_custom') . '=x-autotools-nonmove-job,'
                    . $q('$' . $execute('token.php', '$' . getCmd('d.get_custom') . '=x-autotools-nonmove-job', 'execute_capture'))
                    . ' ; d.save_full_session= ; ';
                $args = '$' . getCmd('d.get_hash') . '=,$' . getCmd('d.get_base_path') . '=,$'
                    . getCmd('d.get_base_filename') . '=,$' . getCmd('d.is_multi_file') . '=,$'
                    . getCmd('d.get_custom1') . '=,$' . getCmd('d.get_name') . '=,' . $q($user)
                    . ',$' . getCmd('d.get_custom') . '=x-autotools-nonmove-job';
                $hook = $marker . getCmd('d.set_custom') . '=x-dest,' . $q('$' . $execute('move.php', $args, 'execute_capture'));
                if ($settings->iVersion < 0x808)
                    $hook .= ' ; ' . getCmd('branch') . '=$' . getCmd('not') . '=$' . getCmd('d.get_custom')
                        . '=x-dest,,' . getCmd('d.set_directory_base') . '=$' . getCmd('d.get_custom') . '=x-dest';
            }
        }
        $request->addCommand($settings->getOnFinishedCommand(array('automove' . $user, $hook)));
        $request->addCommand($this->enable_watch && $this->path_to_watch !== ''
            ? $settings->getAlignedScheduleCommand('autowatch', $autowatch_interval,
                $execute('watch.php', $q($user), 'execute.nothrow.bg'))
            : $settings->getRemoveScheduleCommand('autowatch'));
        $recover = $execute('recover.php', 'All,' . $q($user), 'execute.nothrow.bg');
        $request->addCommand($settings->getAlignedScheduleCommand('autorecover', 60, $recover));
        return $request->success();
    }
}
