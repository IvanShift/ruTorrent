<?php

namespace ErasedataRenameProbeReadFixture;

require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');

// The production read with its lock call scripted and each lstat announced and
// amendable, so a test can refuse the lock without a filesystem that refuses it, look
// at the lock from outside during the read, and show metadata this filesystem cannot
// produce. The rest is the production source.
class Script
{
	public static $lockRefused = false;
	public static $beforeRead = null;
	public static $lstatChanges = array();
}

function flock($handle, $operation, &$wouldBlock = null)
{
	return Script::$lockRefused ? false : \flock($handle, $operation, $wouldBlock);
}

function lstat($path)
{
	if(Script::$beforeRead !== null) call_user_func(Script::$beforeRead);
	$answer = \lstat($path);
	return isset(Script::$lstatChanges[$path]) ? Script::$lstatChanges[$path] + $answer : $answer;
}

eval('namespace '.__NAMESPACE__.'; '.\loadFunctionDefinition(
	__DIR__.'/../../../plugins/erasedata/filesystem.php', 'erasedataRenameProbeWorkspaceValid'));
