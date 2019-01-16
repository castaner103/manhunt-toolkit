<?php
namespace App\Service\Compiler\Emitter;

use App\Service\Helper;
use App\Service\Compiler\Token;

class T_ASSIGN {


    static public function map( $node, \Closure $getLine, \Closure $emitter, $data ){
        $code = [];
        $mapped = T_VARIABLE::getMapping($node, null, $data);

        $isObject = $mapped['type'] == "object";

        $leftHand = $node['body'][0];
        //HACK
        //when we have a type usage, we have no variable entry
        //so the compiler think its a function...
        if ($leftHand['type'] == Token::T_FUNCTION ){

            if (isset($data['customData']['customFunctions'][strtolower($node['value'])])){
//                $leftHand['type'] = Token::T_CUSTOM_FUNCTION;
                $code[] = $getLine('10000000');
                $code[] = $getLine('02000000');
                $code[] = $getLine('11000000');
                $code[] = $getLine('02000000');
                $code[] = $getLine('0a000000');
                $code[] = $getLine('34000000');
                $code[] = $getLine('02000000');
                $code[] = $getLine('04000000');
                $code[] = $getLine('20000000');
                $code[] = $getLine('01000000');
                $code[] = $getLine('04000000');
                $code[] = $getLine('02000000');
                $code[] = $getLine('0f000000');
                $code[] = $getLine('02000000');
                $code[] = $getLine('10000000');
                $code[] = $getLine('01000000');

            }


            $stateVar = str_replace('level_var ', '',$mapped['type']);

            if (isset($data['types'][$stateVar])){
                $leftHand['target'] = $stateVar;
                $leftHand['type'] = Token::T_VARIABLE;
            }
        }


        if ($mapped['type'] == "vec3d"){
            self::fromVec3d($mapped, $code, $getLine);

        }else if($isObject){
            self::fromObject($mapped, $code, $getLine);

        }

        //hack: nil is detected as function, but its T_NIL actual....
        if ($leftHand['type'] == Token::T_FUNCTION && $leftHand['value'] == "nil"){
            $leftHand['type'] = Token::T_NIL;
        }

        /**
         * Evaluate the left hand
         */
        foreach ($emitter($leftHand) as $item) $code[] = $item;

        //we do here some math...
        if (isset($node['body'][1]) && isset($node['body'][2])){

            $rightHand = $node['body'][2];
            $operator = $node['body'][1];

            $code[] = $getLine('10000000');
            $code[] = $getLine('01000000');

            /**
             * Evaluate the right hand
             */
            foreach ($emitter($rightHand) as $item) $code[] = $item;

            if ($rightHand['type'] == Token::T_INT) {
                $code[] = $getLine('0f000000');
                $code[] = $getLine('04000000');

                if ($operator['type'] == Token::T_ADDITION) {

                    $code[] = $getLine('31000000');
                    $code[] = $getLine('01000000');
                    $code[] = $getLine('04000000');

                }else if ($operator['type'] == Token::T_SUBSTRACTION){

                    $code[] = $getLine('33000000');
                    $code[] = $getLine('04000000');
                    $code[] = $getLine('01000000');

                    $code[] = $getLine('11000000');
                    $code[] = $getLine('01000000');
                    $code[] = $getLine('04000000');


                }else{
                    throw new \Exception(sprintf('T_ASSIGN: handleSimpleMath operator not supported: %s', $operator['type']));
                }

            }else if ($rightHand['type'] == Token::T_FLOAT){
                self::applyFloatMath($operator['type'], $code, $getLine);


            }else if (
                $rightHand['type'] == Token::T_FUNCTION ||
                $rightHand['type'] == Token::T_VARIABLE
            ){

                $rightMapped = T_VARIABLE::getMapping($rightHand, null, $data);

                foreach ($emitter($rightMapped) as $item) $code[] = $item;

                self::applyFloatMath($operator['type'], $code, $getLine);

            }else{
                throw new \Exception(sprintf('T_ASSIGN: rightHand operator not supported: %s', $rightHand['type']));
            }
        }

        /*
         * Assign TO variable handling
         */

        if ($mapped['type'] == "vec3d") {
            self::toVec3D($mapped['offset'], $code, $getLine);

        }else if ($mapped['type'] == "object"){
            self::toObject( $code, $getLine);

        }else if ($mapped['type'] == "stringarray"){
            self::toHeaderStringArray( $mapped['offset'], $mapped['size'], $code, $getLine);

        }else if ($mapped['type'] == "custom_functions"){
            self::toCustomFunctions( $code, $getLine);

        }else if(substr($mapped['type'], 0, 9) == "level_var") {
            self::toLevelVar($mapped['offset'], $code, $getLine);

        }else if (isset($mapped['abstract']) && $mapped['abstract'] == "state"){
            self::toHeader( $mapped['offset'], $code, $getLine);

        //regular assignment
        }else if (isset($node['body'][1]) == false){
            if ($mapped['section'] == "header") self::toHeader( $mapped['offset'], $code, $getLine);
            if ($mapped['section'] == "script") self::toScript( $mapped['offset'], $code, $getLine);

        //math operation
        }else if (isset($node['body'][1]) == true){
            self::toScript($mapped['offset'], $code, $getLine);
        }else{
            throw new \Exception(sprintf('T_ASSIGN: unhandled assignment '));
        }

        return $code;
    }


    static public function applyFloatMath( $type, &$code, \Closure $getLine){

        $code[] = $getLine('10000000');
        $code[] = $getLine('01000000');


        if ($type == Token::T_ADDITION) {
            $code[] = $getLine('50000000');
        }else if ($type == Token::T_SUBSTRACTION) {
            $code[] = $getLine('51000000');
        }else if ($type == Token::T_MULTIPLY) {
            $code[] = $getLine('52000000');
        }else{
            throw new \Exception('divide not implemented');
        }
    }


    static public function toCustomFunctions( &$code, \Closure $getLine){

        self::toObject($code, $getLine);

        $code[] = $getLine('13000000');
        $code[] = $getLine('01000000');
        $code[] = $getLine('04000000');
        $code[] = $getLine('04000000'); //offset?
    }

    static public function fromVec3d($mapped, &$code, \Closure $getLine){
        $code[] = $getLine($mapped['section'] == "header" ? '21000000' : '22000000');

        $code[] = $getLine('04000000');
        $code[] = $getLine('01000000');
        $code[] = $getLine($mapped['offset']);

        $code[] = $getLine('10000000');
        $code[] = $getLine('01000000');
    }

    static public function fromObject($mapped, &$code, \Closure $getLine){

        self::fromVec3d([
            'offset' => $mapped['object']['offset'],
            'section' => $mapped['section']
        ], $code, $getLine);

        if ($mapped['offset'] != $mapped['object']['offset']){
            $code[] = $getLine('0f000000');
            $code[] = $getLine('01000000');

            $code[] = $getLine('32000000');
            $code[] = $getLine('01000000');

            $code[] = $getLine($mapped['offset']);

            $code[] = $getLine('10000000');
            $code[] = $getLine('01000000');
        }
    }


    static public function toObject( &$code, \Closure $getLine){
        $code[] = $getLine('0f000000');
        $code[] = $getLine('02000000');
        $code[] = $getLine('17000000');
        $code[] = $getLine('04000000');
        $code[] = $getLine('02000000');
        $code[] = $getLine('01000000');
    }

    static public function toVec3D( $offset, &$code, \Closure $getLine){
        $code[] = $getLine('12000000');
        $code[] = $getLine('03000000');
        $code[] = $getLine( $offset );

        $code[] = $getLine('0f000000');
        $code[] = $getLine('01000000');
        $code[] = $getLine('0f000000');
        $code[] = $getLine('04000000');
        $code[] = $getLine('44000000');

    }

    static public function toHeader( $offset, &$code, \Closure $getLine){
        $code[] = $getLine('16000000');
        $code[] = $getLine('04000000');
        $code[] = $getLine($offset);
        $code[] = $getLine('01000000');
    }

    static public function toScript( $offset, &$code, \Closure $getLine){

        $code[] = $getLine('15000000');
        $code[] = $getLine('04000000');
        $code[] = $getLine( $offset );
        $code[] = $getLine('01000000');
    }

    static public function toLevelVar( $offset, &$code, \Closure $getLine){
        $code[] = $getLine('1a000000');
        $code[] = $getLine('01000000');
        $code[] = $getLine( $offset );
        $code[] = $getLine('04000000');
    }

    static public function toHeaderStringArray( $offset, $size, &$code, \Closure $getLine){
        //define target offset
        $code[] = $getLine('21000000');
        $code[] = $getLine('04000000');
        $code[] = $getLine('04000000');
        $code[] = $getLine( $offset );

        //define the length
        $code[] = $getLine('12000000');
        $code[] = $getLine('03000000');
        $code[] = $getLine( Helper::fromIntToHex($size) );
        $code[] = $getLine('10000000');
        $code[] = $getLine('04000000');

        // save result
        $code[] = $getLine('10000000');
        $code[] = $getLine('03000000');
        $code[] = $getLine('48000000');
    }
}