<?php

require_once __DIR__ . '/../../php/SourceDefinitionFixture.php';
require_once __DIR__ . '/../../../php/xmlrpc_response.php';

// Loaded downloads as rtorrent answers the questions of shared.php and the
// replacement cleanup: d.multicall rows of hash, directory, file count and size
// (erasedataLoadedDownloads()), of hash and the sizes of its files joined by
// cat (erasedataFileSizes()), or of hash and chk-* custom values; and
// "<hash>:f<index>" reads of f.path (erasedataClaimPaths()), alone or in a
// system.multicall. A fleet is hash => ['directory' => ..., 'files' =>
// [relative path => size], 'customs' => [chk-replacement, chk-replaces,
// chk-replacing, chk-reuse]]. 'faults' => [kind => true] fails one kind of
// question: 'loaded', 'customs', 'sizes' or 'paths' ('files' fails both of
// the last two); 'faults' => ['exhaust' => kind] makes that question exhaust
// the memory_limit instead, a fatal error, so only in a child process. The
// collector stub answers an unscripted question with an empty fleet: nothing
// else is loaded.
final class ErasedataLoadedFleet
{
    // 'loaded', 'customs', 'sizes' or 'paths', or null for another question.
    public static function kind($method, array $params)
    {
        $columns = implode(' ', array_map('strval', $params));
        if (in_array($method, ['d.multicall', 'd.multicall2'], true)) {
            return(strpos($columns, 'chk-replacement') !== false ? 'customs'
                : (strpos($columns, 'size_files') !== false ? 'loaded'
                : (strpos($columns, 'size_bytes') !== false ? 'sizes' : null)));
        }
        return(in_array($method, ['f.path', 'f.get_path'], true) ? 'paths' : null);
    }

    public static function failed(array $response, $kind)
    {
        return(!empty($response['fault']) || !empty($response['faults'][$kind])
            || (in_array($kind, ['sizes', 'paths'], true) && !empty($response['faults']['files'])));
    }

    // One call's result, nested as rtorrent nests it; false for an unknown
    // download or file, which rtorrent answers with a fault.
    public static function result(array $fleet, $method, array $params)
    {
        if (in_array($method, ['d.multicall', 'd.multicall2'], true)) {
            $rows = [];
            foreach ($fleet as $hash => $entry) {
                $row = [];
                foreach ($params as $column) {
                    $column = (string) $column;
                    if (in_array($column, ['', 'main', 'default'], true)) {
                        continue;
                    }
                    if (preg_match('/^d\.(get_)?hash=$/', $column)) {
                        $row[] = (string) $hash;
                    } elseif (preg_match('/^d\.(get_)?directory=$/', $column)) {
                        $row[] = $entry['directory'];
                    } elseif (preg_match('/^d\.(get_)?size_files=$/', $column)) {
                        $row[] = count($entry['files']);
                    } elseif (preg_match('/^d\.(get_)?size_bytes=$/', $column)) {
                        $row[] = array_sum($entry['files']);
                    } elseif (strpos($column, 'size_bytes') !== false) {
                        $sizes = '';
                        foreach ($entry['files'] as $size) {
                            $sizes .= $size . '/';
                        }
                        $row[] = $sizes;
                    } else {
                        $customs = isset($entry['customs']) ? $entry['customs'] : ['', '', '', ''];
                        $keys = ['chk-replacement', 'chk-replaces', 'chk-replacing', 'chk-reuse'];
                        $at = array_search(preg_replace('/^.*=/', '', $column), $keys, true);
                        $row[] = $at === false ? '' : $customs[$at];
                    }
                }
                $rows[] = $row;
            }
            return($rows);
        }
        $target = count($params) > 1 ? [(string) $params[0], (int) $params[1]]
            : explode(':f', (string) $params[0]);
        $names = isset($fleet[$target[0]]) ? array_keys($fleet[$target[0]]['files']) : [];
        if (!isset($target[1], $names[(int) $target[1]])) {
            return(false);
        }
        return((string) $names[(int) $target[1]]);
    }

    public static function flatten($value)
    {
        if (!is_array($value)) {
            return([$value]);
        }
        $flat = [];
        foreach ($value as $item) {
            $flat = array_merge($flat, self::flatten($item));
        }
        return($flat);
    }

    // The collector stub's scripted response for one request.
    public static function reply(array $response, array $commands)
    {
        $val = [];
        foreach ($commands as $command) {
            $params = is_array($command->params) ? $command->params : [$command->params];
            $kind = self::kind($command->command, $params);
            if ($kind !== null && ($response['faults']['exhaust'] ?? null) === $kind) {
                ini_set('memory_limit', (string) (memory_get_usage(true) + (16 << 20)));
                $response['exhausted'] = str_repeat('x', 64 << 20);
            }
            $result = $kind === null || self::failed($response, $kind) ? false
                : self::result($response['fleet'], $command->command, $params);
            if ($result === false) {
                return(['ok' => true, 'fault' => true, 'val' => [], 'faultString' => 'scripted fleet fault']);
            }
            $val = array_merge($val, self::flatten($result));
        }
        return(['ok' => true, 'fault' => false, 'val' => $val]);
    }
}

// Legacy scenarios spell a physical three-command reply as a flat list.
// Encode that explicit shorthand as wire data, then let the production parser
// retain row provenance; arbitrary responseValues never prove an XML table.
class ErasedataCollectorRpcWire
{
    public static function value($value)
    {
        if (is_string($value)) {
            return '<value><base64>' . base64_encode($value) . '</base64></value>';
        }
        if (is_bool($value)) {
            return '<value><boolean>' . (int) $value . '</boolean></value>';
        }
        if (is_int($value)) {
            return '<value><i8>' . $value . '</i8></value>';
        }
        if (is_float($value)) {
            return '<value><double>' . $value . '</double></value>';
        }
        if (is_array($value)) {
            $body = '';
            foreach ($value as $cell) {
                $body .= self::value($cell);
            }
            return '<value><array><data>' . $body . '</data></array></value>';
        }
        throw new RuntimeException('Unsupported collector RPC fixture value');
    }

    public static function physicalBatch(array $flat)
    {
        $results = [];
        foreach (array_slice($flat, 0, 2) as $value) {
            $results[] = self::value($value);
        }
        if (count($flat) >= 2) {
            $rows = '';
            foreach (array_slice($flat, 2) as $path) {
                $rows .= self::value([$path]);
            }
            $results[] = '<value><array><data>' . $rows . '</data></array></value>';
        }
        return self::batch($results);
    }

    public static function batch(array $results)
    {
        $body = '';
        foreach ($results as $result) {
            $body .= '<value><array><data>' . $result . '</data></array></value>';
        }
        return '<methodResponse><params><param><value><array><data>' . $body
            . '</data></array></value></param></params></methodResponse>';
    }
}

trait ErasedataCollectorTypedReplies
{
    public $responseCommandValues = [];
    public $responseCommandTypes = [];
    private $responseCommandStringTableWidths = [];
    private $responseStringTableWidths = [];
    // Keep the wrapper visible to static analysis while executing the real
    // request owner's accessor against the fixture's parsed wire evidence.
    public function stringTable($columns, $resultIndex = null)
    {
        static $reader = null;
        if ($reader === null) {
            $definition = loadFunctionDefinition(__DIR__ . '/../../../php/xmlrpc.php', 'stringTable');
            $reader = eval('return ' . preg_replace('/^function\s+stringTable\s*/', 'function ', $definition, 1) . ';');
        }
        $bound = $reader->bindTo($this, __CLASS__);
        return $bound($columns, $resultIndex);
    }

    private function clearPhysicalReply()
    {
        $this->responseCommandValues = $this->responseCommandTypes = [];
        $this->responseCommandStringTableWidths = $this->responseStringTableWidths = [];
    }

    private function acceptPhysicalReply(array $commands, array $response)
    {
        if (count($commands) !== 3
            || !in_array($commands[0]->command, [getCmd('d.get_base_path'), getCmd('d.get_directory')], true)
            || $commands[1]->command !== getCmd('d.is_multi_file')
            || $commands[2]->command !== getCmd('f.multicall')) {
            return;
        }
        $arguments = $commands[2]->params;
        if (!is_array($arguments) || count($arguments) !== 3 || $arguments[1] !== ''
            || !in_array($arguments[2], [getCmd('f.get_path') . '=', getCmd('f.get_frozen_path') . '='], true)) {
            return;
        }
        $wire = array_key_exists('wire', $response) ? $response['wire']
            : ErasedataCollectorRpcWire::physicalBatch($this->val);
        if (!is_string($wire)) {
            throw new RuntimeException('Collector wire fixture must be a string');
        }
        try {
            $parsed = (new rXMLRPCResponse($wire, true))->read();
        } catch (UnexpectedValueException $error) {
            $this->val = $this->responseValues = $this->responseTypes = [];
            $this->fault = true;
            return;
        }
        $this->val = array_column($parsed->scalars, 1);
        $this->responseValues = $parsed->values;
        $this->responseTypes = $parsed->valueTypes;
        $this->responseStringTableWidths = $parsed->stringTableWidths;
        $this->responseCommandValues = $parsed->commandValues;
        $this->responseCommandTypes = $parsed->commandTypes;
        $this->responseCommandStringTableWidths = $parsed->commandStringTableWidths;
        $this->fault = $this->fault || $parsed->fault;
    }
}
