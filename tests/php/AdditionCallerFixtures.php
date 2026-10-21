<?php

/**
 * A settings object carrying a real alias table, for every rtorrent the tree
 * supports.
 *
 * An addition is checked by name, and the name a caller builds is whatever
 * rTorrentSettings::getCommand() maps its alias to on the daemon in front of
 * it. So a test that pins one spelling proves nothing about the others: the
 * allowlist has to hold under every table php/ ships, and these fixtures hand
 * the tests one installed settings object per table.
 *
 * php/settings.php builds the table inside the method that interrogates a live
 * daemon, which a test has none of, so the cascade is repeated here -- read out
 * of php/settings.php rather than transcribed from it. The version gates, the
 * method file each one loads and the base pair the first gate installs are all
 * taken from that source, and the version list below is then derived by
 * building a table either side of every gate and keeping one representative per
 * distinct result. A gate added there is a table here on the commit that adds
 * it, with nobody to remember it.
 */
trait AdditionCallerFixtures
{
	/** php/settings.php, read once. */
	static private $settingsSource = null;

	/** iVersion => the version string it stands for, one per distinct table. */
	static private $tableVersions = null;

	public function repoRoot()
	{
		return realpath(__DIR__ . '/../..');
	}

	private function settingsSource()
	{
		if (self::$settingsSource === null) {
			self::$settingsSource = file_get_contents($this->repoRoot() . '/php/settings.php');
		}
		return self::$settingsSource;
	}

	/**
	 * The (operator, iVersion, method file) gates obtain() loads its alias
	 * files behind, in the order it applies them.
	 */
	public function methodFileGates()
	{
		preg_match_all('/if\s*\(\s*\$this->iVersion\s*(>=|>|<=|<|==)\s*(0x[0-9a-fA-F]+)\s*\)\s*\{\s*'
			. 'require(?:_once)?\s*\(\s*\'(methods-[^\']+)\'\s*\)\s*;/s',
			$this->settingsSource(), $matches, PREG_SET_ORDER);
		$gates = array();
		foreach ($matches as $m) {
			$gates[] = array('op' => $m[1], 'version' => hexdec($m[2]), 'file' => $m[3]);
		}
		return $gates;
	}

	/**
	 * The gate and the contents of the pair obtain() assigns to $aliases before
	 * any method file is merged in.
	 */
	public function baseAliasGate()
	{
		// Bind the literal to its version gate; an earlier empty reset is not a base table.
		if (!preg_match('/\$this->iVersion\s*(>=|>)\s*(0x[0-9a-fA-F]+)\s*\)\s*\{\s*'
			. '\$this->aliases\s*=\s*/s', $this->settingsSource(), $m, PREG_OFFSET_CAPTURE)) {
			return null;
		}
		$tokens = $this->significantTokens('<?php '.substr($this->settingsSource(),
			$m[0][1] + strlen($m[0][0])));
		$at = 0;
		$aliases = $this->constantValue($tokens, $at);
		if (!is_array($aliases)) return null;
		return array('op' => $m[1][0], 'version' => hexdec($m[2][0]), 'aliases' => $aliases);
	}

	/**
	 * The tokens of $source with whitespace and comments dropped, each as
	 * array(token id or null for punctuation, text).
	 */
	private function significantTokens($source)
	{
		$out = array();
		foreach (token_get_all($source) as $token) {
			if (is_array($token)) {
				if (in_array($token[0], array(T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
					continue;
				}
				$out[] = array($token[0], $token[1]);
			} else {
				$out[] = array(null, $token);
			}
		}
		return $out;
	}

	/**
	 * The value of the constant expression starting at $at, advancing $at past
	 * it, or null if the expression is not one this can evaluate -- anything
	 * built from a variable or a call.
	 */
	private function constantValue($tokens, &$at)
	{
		if (!isset($tokens[$at])) {
			return null;
		}
		list($id, $text) = $tokens[$at];

		if ($id === T_ARRAY) {
			$at++;
			if (!isset($tokens[$at]) || $tokens[$at][1] !== '(') {
				return null;
			}
			return $this->constantArray($tokens, $at, ')');
		}
		if ($text === '[') {
			return $this->constantArray($tokens, $at, ']');
		}
		if ($id === T_CONSTANT_ENCAPSED_STRING) {
			$at++;
			return $this->literalString($text);
		}
		if ($id === T_LNUMBER) {
			$at++;
			return intval($text, 0);
		}
		if ($id === T_DNUMBER) {
			$at++;
			return floatval($text);
		}
		if ($text === '-') {
			$at++;
			$value = $this->constantValue($tokens, $at);
			return is_int($value) || is_float($value) ? -$value : null;
		}
		if ($id === T_STRING && in_array(strtolower($text), array('true', 'false', 'null'), true)) {
			$at++;
			switch (strtolower($text)) {
				case 'true': return true;
				case 'false': return false;
			}
			return null;
		}
		return null;
	}

	/**
	 * The entries of an array literal whose opening bracket is at $at, up to
	 * $close. A trailing comma is allowed, as PHP allows it.
	 */
	private function constantArray($tokens, &$at, $close)
	{
		$at++;    // the opening bracket
		$result = array();
		while (isset($tokens[$at])) {
			if ($tokens[$at][1] === $close) {
				$at++;
				return $result;
			}
			$first = $this->constantValue($tokens, $at);
			if ($first === null) {
				return null;
			}
			if (isset($tokens[$at]) && $tokens[$at][0] === T_DOUBLE_ARROW) {
				$at++;
				$second = $this->constantValue($tokens, $at);
				if ($second === null) {
					return null;
				}
				$result[$first] = $second;
			} else {
				$result[] = $first;
			}
			if (isset($tokens[$at]) && $tokens[$at][1] === ',') {
				$at++;
			}
		}
		return null;
	}

	/**
	 * The text a quoted literal stands for. A single-quoted one knows only two
	 * escapes; a double-quoted one here carries no interpolation, because an
	 * interpolated string is not one token.
	 */
	private function literalString($text)
	{
		$body = substr($text, 1, -1);
		if (substr($text, 0, 1) === "'") {
			return str_replace(array("\\\\", "\\'"), array("\\", "'"), $body);
		}
		return stripcslashes($body);
	}


	private function gatePasses($op, $iVersion, $constant)
	{
		switch ($op) {
			case '>=': return $iVersion >= $constant;
			case '>':  return $iVersion > $constant;
			case '<=': return $iVersion <= $constant;
			case '<':  return $iVersion < $constant;
			case '==': return $iVersion == $constant;
		}
		return false;
	}

	/** The method files php/settings.php loads for $iVersion, in its order. */
	public function methodFilesFor($iVersion)
	{
		$files = array();
		foreach ($this->methodFileGates() as $gate) {
			if ($this->gatePasses($gate['op'], $iVersion, $gate['version'])) {
				$files[] = $gate['file'];
			}
		}
		return $files;
	}

	/** The base pair for $iVersion, before any method file. */
	public function baseAliasesFor($iVersion)
	{
		$base = $this->baseAliasGate();
		if ($base === null) {
			return array();
		}
		return $this->gatePasses($base['op'], $iVersion, $base['version'])
			? $base['aliases'] : array();
	}

	/** The alias table $iVersion produces, without installing it. */
	public function aliasTableFor($iVersion)
	{
		$holder = new AliasTableHolder();
		$holder->aliases = $this->baseAliasesFor($iVersion);
		$php = $this->repoRoot() . '/php/';
		$files = $this->methodFilesFor($iVersion);
		// The method files assign to $this->aliases, so they are included in a
		// scope where that is the table being built -- and with include rather
		// than require_once, because one process builds several tables.
		$load = Closure::bind(function () use ($php, $files) {
			foreach ($files as $file) {
				include($php . $file);
			}
		}, $holder, 'AliasTableHolder');
		$load();
		return $holder->aliases;
	}

	/**
	 * Every iVersion the gates distinguish: one either side of each of them,
	 * which is where a table can change, plus one below the lowest.
	 */
	private function probeVersions()
	{
		$constants = array();
		$base = $this->baseAliasGate();
		if ($base !== null) {
			$constants[] = $base['version'];
		}
		foreach ($this->methodFileGates() as $gate) {
			$constants[] = $gate['version'];
		}
		$probes = array();
		foreach ($constants as $c) {
			$probes[] = $c - 1;
			$probes[] = $c;
			$probes[] = $c + 1;
		}
		$probes = array_unique($probes);
		sort($probes);
		return $probes;
	}

	/**
	 * iVersion => version string, one entry per distinct alias table php/ can
	 * produce. The representative is the lowest iVersion that produces it.
	 */
	public function supportedVersions()
	{
		if (self::$tableVersions !== null) {
			return self::$tableVersions;
		}
		$seen = array();
		$versions = array();
		foreach ($this->probeVersions() as $iVersion) {
			$table = $this->aliasTableFor($iVersion);
			ksort($table);
			$key = md5(serialize($table));
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$versions[$iVersion] = sprintf('0.%d.%d', ($iVersion >> 8) & 0xff, $iVersion & 0xff);
		}
		ksort($versions);
		self::$tableVersions = $versions;
		return $versions;
	}

	/** Installs settings for $iVersion and returns the object. */
	public function installSettingsFor($iVersion)
	{
		$reflection = new ReflectionClass('rTorrentSettings');
		$settings = $reflection->newInstanceWithoutConstructor();
		$settings->iVersion = $iVersion;
		$settings->apiVersion = 0;
		$settings->directory = sys_get_temp_dir();
		$settings->aliases = $this->aliasTableFor($iVersion);

		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		$property->setValue(null, $settings);
		return $settings;
	}

	public function restoreSettings($previous)
	{
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		$property->setValue(null, $previous);
	}

	public function currentSettings()
	{
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		self::makeAccessible($property);
		return $property->getValue();
	}

	/** Every php/methods-*.php is loaded by at least one supported version. */
	public function versionsCoverEveryMethodFile()
	{
		$loaded = array();
		foreach (array_keys($this->supportedVersions()) as $iVersion) {
			foreach ($this->methodFilesFor($iVersion) as $file) {
				$loaded[$file] = true;
			}
		}
		$missing = array();
		foreach (glob($this->repoRoot() . '/php/methods-*.php') as $path) {
			if (!isset($loaded[basename($path)])) {
				$missing[] = basename($path);
			}
		}
		return $missing;
	}

	public function areValidAdditions($addition)
	{
		return rTorrent::areValidAdditions($addition);
	}
}

/** The scope a method file's "$this->aliases = array_merge(...)" runs in. */
class AliasTableHolder
{
	public $aliases = array();
}
