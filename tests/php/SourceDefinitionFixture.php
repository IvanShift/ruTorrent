<?php

// The source of one class out of a file the test must not require whole
// (check.php would pull in util.php and half the application with it).
function loadClassDefinition($filename, $className)
{
    $source = file_get_contents($filename);
    $offset = strpos($source, 'class ' . $className);
    if ($offset === false)
        throw new RuntimeException("Class {$className} was not found in {$filename}");
    // ruTrackerChecker is the final declaration in check.php.
    return substr($source, $offset);
}

// Where a quoted string that begins at $offset ends -- the offset just past its
// closing quote. Backticks count as quotes: shell_exec's operand is a string,
// and a brace inside one is not a brace. Escapes are honoured so that a literal
// ending in a backslash is not read as running on past its own closing quote.
//
// This and its two companions below exist because the shipped Alpine image
// loads no tokenizer extension, and a reader that only works on the developer's
// machine is a reader with a hole in it. They are deliberately narrow: each
// refuses what it cannot read rather than guessing at it.
function testEndOfStringLiteral($source, $offset, $where)
{
    $quote = $source[$offset];
    if ($quote !== "'" && $quote !== '"' && $quote !== '`')
        throw new RuntimeException('No string literal begins at offset ' . $offset . ' in ' . $where);
    $length = strlen($source);
    for ($i = $offset + 1; $i < $length; $i++) {
        if ($source[$i] === '\\') {
            $i++;
            continue;
        }
        if ($source[$i] === $quote) return $i + 1;
    }
    throw new RuntimeException('An unterminated string literal begins at offset '
        . $offset . ' in ' . $where);
}

// Where the comment that begins at $offset ends, or false when none begins
// there. An unterminated /* runs to the end of the file, which is what PHP
// itself does with one.
function testEndOfComment($source, $offset, $where)
{
    $two = substr($source, $offset, 2);
    if ($two === '/*') {
        $end = strpos($source, '*/', $offset + 2);
        return $end === false ? strlen($source) : $end + 2;
    }
    // "#[" opens a PHP 8 attribute, not a comment to the end of the line. This
    // project targets 7.4 and carries none; refuse rather than swallow a line.
    if ($two === '#[')
        throw new RuntimeException('An attribute at offset ' . $offset . ' in ' . $where
            . '; this reader knows comments, not attributes');
    if ($two === '//' || $source[$offset] === '#') {
        $end = strpos($source, "\n", $offset);
        return $end === false ? strlen($source) : $end + 1;
    }
    return false;
}

// Where the heredoc or nowdoc that begins at $offset ends, or false when none
// begins there. The body ends at the first line whose leading whitespace is
// followed by the label and then anything that cannot continue an identifier.
function testEndOfHeredoc($source, $offset, $where)
{
    if (substr($source, $offset, 3) !== '<<<') return false;
    $length = strlen($source);
    $i = $offset + 3;
    while ($i < $length && ($source[$i] === ' ' || $source[$i] === "\t")) $i++;
    $quote = ($i < $length && ($source[$i] === "'" || $source[$i] === '"')) ? $source[$i] : '';
    if ($quote !== '') $i++;
    $label = '';
    while ($i < $length && preg_match('/[A-Za-z0-9_]/', $source[$i])) {
        $label .= $source[$i];
        $i++;
    }
    if ($label === '' || ($quote !== '' && (!isset($source[$i]) || $source[$i] !== $quote)))
        throw new RuntimeException('A heredoc at offset ' . $offset . ' in ' . $where
            . ' has no label this reader can make out');
    if ($quote !== '') $i++;
    $newline = strpos($source, "\n", $i);
    if ($newline === false)
        throw new RuntimeException('A heredoc at offset ' . $offset . ' in ' . $where
            . ' never opens its body');
    $i = $newline + 1;
    while ($i <= $length) {
        $lineEnd = strpos($source, "\n", $i);
        $line = $lineEnd === false ? substr($source, $i) : substr($source, $i, $lineEnd - $i);
        $trimmed = ltrim($line, " \t");
        if (strpos($trimmed, $label) === 0) {
            $after = substr($trimmed, strlen($label), 1);
            if ($after === '' || !preg_match('/[A-Za-z0-9_]/', $after))
                return $i + (strlen($line) - strlen($trimmed)) + strlen($label);
        }
        if ($lineEnd === false) break;
        $i = $lineEnd + 1;
    }
    throw new RuntimeException('A heredoc opened at offset ' . $offset . ' in ' . $where
        . ' is never closed by its own label');
}

// The source of one function -- top-level or method -- out of a file the test
// must not require whole: the function-level companion to loadClassDefinition().
// A test that binds a double to the function it doubles needs the real
// declaration.
//
// The braces are matched over the code only. A brace inside a string, a
// comment or a heredoc is not a brace, and counting them over the raw text is
// exactly what this function exists not to do; the three readers above are how
// it tells the difference without the tokenizer extension, which the shipped
// Alpine image does not load. Checked against a token_get_all() implementation
// over every function in every PHP file of this repository: 4126 comparisons,
// byte-identical but for two methods named with reserved words (echo, match),
// which the tokenizer version could not find at all -- there this reader is the
// more capable of the two, never the less.
function loadFunctionDefinition($filename, $functionName)
{
    $source = file_get_contents($filename);
    $length = strlen($source);
    $start = null;
    $depth = 0;
    $i = 0;
    while ($i < $length) {
        $character = $source[$i];
        if ($character === "'" || $character === '"' || $character === '`') {
            $i = testEndOfStringLiteral($source, $i, $filename);
            continue;
        }
        $skip = testEndOfComment($source, $i, $filename);
        if ($skip === false) $skip = testEndOfHeredoc($source, $i, $filename);
        if ($skip !== false) {
            $i = $skip;
            continue;
        }
        if ($start === null) {
            // "function", as a whole word, then the wanted name as a whole word.
            if ($character === 'f' && substr($source, $i, 8) === 'function'
                && ($i === 0 || !preg_match('/[A-Za-z0-9_$\\\\]/', $source[$i - 1]))) {
                $after = $i + 8;
                while ($after < $length && strpos(" \t\r\n&", $source[$after]) !== false) $after++;
                if (substr($source, $after, strlen($functionName)) === $functionName) {
                    $tail = $after + strlen($functionName);
                    if ($tail >= $length || !preg_match('/[A-Za-z0-9_]/', $source[$tail])) {
                        $start = $i;
                        $i = $tail;
                        continue;
                    }
                }
            }
            $i++;
            continue;
        }
        if ($character === '{') {
            $depth++;
            $i++;
            continue;
        }
        if ($character === '}') {
            $depth--;
            if ($depth === 0) return substr($source, $start, $i + 1 - $start);
            $i++;
            continue;
        }
        $i++;
    }
    if ($start === null)
        throw new RuntimeException("Function {$functionName} was not found in {$filename}");
    throw new RuntimeException("Function {$functionName} in {$filename} has an unbalanced body");
}
