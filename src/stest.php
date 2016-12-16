<?php

namespace STest;

// ----------------------------------------
//
// PUBLIC
//

const VERSION = 3.0;

function stop(string $message) {
    throw new StopException($message);
}

function alert(string $message) {
    throw new AlertException($message);
}

// poor-man DI container
function i($class="STest", ...$params) : STest { # STest instance
    static $cache;
    $K = $class.json_encode($params);
    if ($r = @$cache[$K])
        return $r;
    return $cache[$K] = new $class(...$params);
}

// ----------------------------------------
//
// INTERNAL
//

class STest {

    public $params;

    PUBLIC function run($argv) {
        echo json_encode($argv);
    }

    // NON-PUBLIC METHODS

    function params() {

    }

    /**
     * Lexem types:
     * - comment  - php comment
     * - expr     - expression
     * - test     - test
     * - result   - test-result (null if result is not in file)
     *
     * $source_file = join( array_values($lexems) );
     *
     */
    function lexemReader(string $file) : \Generator { # TYPE => Stest Lexem
        $lines = file($file);
        $r = "";
        foreach ($lines as $key => $value) {
            // multi-line php
            // multi-line comments


        }

    }


}

class Exception extends \Exception {}
class StopException extends Exception {}
class AlertException extends Exception {}