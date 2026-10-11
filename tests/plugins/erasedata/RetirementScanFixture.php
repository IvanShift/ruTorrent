<?php

namespace ErasedataRetirementScanFixture;

require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');

// Only the large directory enumeration is scripted. Classification and durable
// state reads remain the production functions; no 65k-file fixture is needed.
class DirectoryEntries
{
	public static $entries = null;
}

function scandir($path)
{
	return DirectoryEntries::$entries === null ? \scandir($path) : DirectoryEntries::$entries;
}

eval('namespace '.__NAMESPACE__.'; '.\loadFunctionDefinition(
	__DIR__.'/../../../plugins/erasedata/removewithdata.php', 'erasedataRetirementScan'));
