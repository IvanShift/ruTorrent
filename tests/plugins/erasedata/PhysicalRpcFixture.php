<?php
require_once __DIR__ . '/../../php/SourceDefinitionFixture.php';
require_once __DIR__ . '/../../../php/xmlrpc_response.php';

// Legacy scenarios spell a physical three-command reply as a flat list.
// Encode that explicit shorthand as wire data, then let the production parser
// retain row provenance; arbitrary responseValues never prove an XML table.
class ErasedataCollectorRpcWire
{
	public static function value($value)
	{
		if(is_string($value)) return '<value><base64>'.base64_encode($value).'</base64></value>';
		if(is_bool($value)) return '<value><boolean>'.(int)$value.'</boolean></value>';
		if(is_int($value)) return '<value><i8>'.$value.'</i8></value>';
		if(is_float($value)) return '<value><double>'.$value.'</double></value>';
		if(is_array($value))
		{
			$body = '';
			foreach($value as $cell) $body .= self::value($cell);
			return '<value><array><data>'.$body.'</data></array></value>';
		}
		throw new RuntimeException('Unsupported collector RPC fixture value');
	}

	public static function physicalBatch(array $flat)
	{
		$results = array();
		foreach(array_slice($flat, 0, 2) as $value) $results[] = self::value($value);
		if(count($flat) >= 2)
		{
			$rows = '';
			foreach(array_slice($flat, 2) as $path) $rows .= self::value(array($path));
			$results[] = '<value><array><data>'.$rows.'</data></array></value>';
		}
		return self::batch($results);
	}

	public static function batch(array $results)
	{
		$body = '';
		foreach($results as $result)
			$body .= '<value><array><data>'.$result.'</data></array></value>';
		return '<methodResponse><params><param><value><array><data>'.$body
			.'</data></array></value></param></params></methodResponse>';
	}
}

// Borrow the request owner's accessor verbatim. The fixture only supplies
// its parser fields; it cannot turn a PHP array into XML row provenance.
eval('trait ErasedataCollectorStringTable { public '
    . loadFunctionDefinition(__DIR__ . '/../../../php/xmlrpc.php', 'stringTable') . '}');

trait ErasedataCollectorTypedReplies
{
    use ErasedataCollectorStringTable;

    public $responseCommandValues = array();
    public $responseCommandTypes = array();
    private $responseCommandStringTableWidths = array();
    private $responseStringTableWidths = array();

    private function clearPhysicalReply()
    {
        $this->responseCommandValues = $this->responseCommandTypes = array();
        $this->responseCommandStringTableWidths = $this->responseStringTableWidths = array();
    }

    private function acceptPhysicalReply(array $commands, array $response)
    {
        if (count($commands) !== 3
            || !in_array($commands[0]->command, array(getCmd('d.get_base_path'), getCmd('d.get_directory')), true)
            || $commands[1]->command !== getCmd('d.is_multi_file')
            || $commands[2]->command !== getCmd('f.multicall')) return;
        $arguments = $commands[2]->params;
        if (!is_array($arguments) || count($arguments) !== 3 || $arguments[1] !== ''
            || !in_array($arguments[2], array(getCmd('f.get_path') . '=', getCmd('f.get_frozen_path') . '='), true)) return;
        $wire = array_key_exists('wire', $response) ? $response['wire']
            : ErasedataCollectorRpcWire::physicalBatch($this->val);
        if (!is_string($wire)) throw new RuntimeException('Collector wire fixture must be a string');
        try { $parsed = (new rXMLRPCResponse($wire, true))->read(); }
        catch (UnexpectedValueException $error) {
            $this->val = $this->responseValues = $this->responseTypes = array();
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
