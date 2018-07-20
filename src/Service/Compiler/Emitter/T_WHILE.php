<?php
namespace App\Service\Compiler\Emitter;


use App\Bytecode\Helper;
use App\Service\Compiler\Token;

class T_WHILE {

    static public function map( $node, \Closure $getLine, \Closure $emitter, $data ){

        $code = [];


        $resultCode = T_IF::map($node, $getLine,$emitter, $data );

        $firstLine = current($resultCode);

        foreach ($resultCode as $line) {
            $code[] = $line;
        }

        //this is like a "goto" function, 3c == goto => line offset
        //move pointer back to while start
        $code[] = $getLine('3c000000');
        $code[] = $getLine(Helper::fromIntToHex($firstLine->lineNumber * 4));


        return $code;

    }

}