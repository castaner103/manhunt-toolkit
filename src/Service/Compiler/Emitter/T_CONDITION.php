<?php
namespace App\Service\Compiler\Emitter;

use App\MHT;
use App\Service\Compiler\Evaluate;
use App\Service\Compiler\Token;
use App\Service\Helper;

class T_CONDITION {

    static public function map( $node, \Closure $getLine, \Closure $emitter, $data ){
        $code = [];

        $debugMsg = '[T_CONDITION] map ';

        $token = $node['body'][0];

        if ($token['type'] == Token::T_OPERATION){

            if (count($token['params']) == 1){

                if (
                    $data['game'] == MHT::GAME_MANHUNT &&
                    $token['params'][0]['type'] == Token::T_BOOLEAN
                ){
                    Evaluate::regularReturn($code, $getLine);

                    $code[] = $getLine('7d000000', false, $debugMsg . 'mh1 boolean special');
                }

                foreach ($emitter($token['params'][0]) as $item){
                    $item->debug = $debugMsg . ' ' . $item->debug;
                    $code[] = $item;
                }

                if ($node['isNot'] || $node['isOuterNot']){
                    Evaluate::setStatementNot($code, $getLine);
                }

            }else{


                /**
                 * little hack to map state variables
                 */

                $param1 = $token['params'][0]['value'];

                if (isset($data['variables'][ $param1 ])){

                    $var = $data['variables'][ $param1 ];

                    $searchedType = $var['objectType'];

                    if (isset($data['types'][ $searchedType ])){
                        $types = $data['types'][ $searchedType ];

                        $token['params'][1]['target'] = $searchedType;
                        $token['params'][1]['types'] = $types;
                    }
                }

                $operator = $token['operator'];

                $lastIndex = count($token['params']) - 1;
                foreach ($token['params'] as $index => $operation) {
                    $isLastIndex = $index == $lastIndex;


                    //remove brackets from the operation
                    if ($operation['type'] == Token::T_BRACKET_OPEN){
                        $operation = $operation['params'][0];
                    }


                    $debugMsg = sprintf('[T_CONDITION] map: type ');

                    foreach ($emitter($operation) as $item){
                        $item->debug = $debugMsg . ' ' . $item->debug;
                        $code[] = $item;
                    }


                    /**
                     * We need for the parameters a special return code, depend on the used type
                     */

                    $output = "none";

                    if ( $operation['type'] == Token::T_FUNCTION ) {

                        $funcMappedTo = $data['customData']['functions'][ $operation['value'] ];

                        if (!isset($funcMappedTo['return'])){
                            throw new \Exception(sprintf('No return value configured for %s', $operation['value']));
                        }

                        if ($funcMappedTo['return'] != 'string') $output = "regular";

                    }else if ($operation['type'] == Token::T_VARIABLE){
                        $mappedTo = T_VARIABLE::getMapping(
                            $operation,
                            $data
                        );


                        if (
                            $mappedTo['isGameVar'] ||
                            $mappedTo['isLevelVar'] ||
                            $mappedTo['type'] == "entityptr" ||
                            $mappedTo['type'] == "constant" ||
                            $mappedTo['type'] == "integer" ||
                            $mappedTo['type'] == "boolean" ||
                            $mappedTo['type'] == "mhfxptr" ||
                            $mappedTo['type'] == "object"
                        ) {
                            $output = "regular";

                        }else if ($mappedTo['type'] == "customFunction") {
                            $output = "customFunction";

                        }else if ($mappedTo['type'] == "stringarray") {
                            $output = "string";

                        }else if ($mappedTo['type'] == "array") {
                            $output = "array";

                        }else if (isset($mappedTo['abstract']) && $mappedTo['abstract'] == "state") {
                            $output = "state";

                        }else{

                            throw new \Exception(sprintf(
                                'T_CONDITION: script config missed for %s',
                                $mappedTo['type']
                            ));
                        }

                    }else if (
                        $operation['type'] == Token::T_INT ||
                        $operation['type'] == Token::T_NIL ||
                        $operation['type'] == Token::T_BOOLEAN ||
                        $operation['type'] == Token::T_SELF
                    ){
                        $output = "regular";

                    }else if ( $operation['type'] == Token::T_FLOAT ){
                        $output = "float";

                    }else if ( $operation['type'] == Token::T_STRING ){

                        $output = "none";

                    }else{

                        throw new \Exception(sprintf(
                            'T_CONDITION:  missed for %s',
                            $operation['type']
                        ));

                    }

                    if ($operation['type'] == Token::T_INT && $operation['value'] < 0) {
                        Evaluate::negate($operation['type'], $code, $getLine);
                    }


                    //todo: die abfrage erscheint mir komisch, es muss ein anderer faktor sein...
                    if ($isLastIndex && $output == "regular"){
                        Evaluate::returnCache($code, $getLine);

                    }else if ($output == "string" || $output == "array") {
                        Evaluate::stringReturn($code, $getLine);
                    }else if($output !== "none"){
                        Evaluate::regularReturn($code, $getLine);

                    }
                }

                if ($token['operation']['type'] == Token::T_AND) {

                    Evaluate::setStatementOperator($token['operation']['type'], $code, $getLine);
                    Evaluate::returnCache($code, $getLine);
                }

                if ($node['isNot']) Evaluate::setStatementNot($code, $getLine);


                /**
                 * this part tell the engine something about the comparision
                 * strings and floats need a special code
                 * any other conditions share the same code
                 */

                $toHandle = [];
                if (count($token['params']) == 2){

                    list($left, $right) = $token['params'];

                    $toHandle = [$left['type'], $right['type'] ];

                    foreach ($token['params'] as $side) {
                        if ($side['type'] == Token::T_VARIABLE) {
                            $mappedTo = T_VARIABLE::getMapping(
                                $side,
                                $data
                            );

                            $toHandle[] = $mappedTo['type'];
                        }
                    }
                }

                if (
                    in_array('stringarray', $toHandle) !== false ||
                    in_array('string', $toHandle) !== false ||
                    in_array(Token::T_STRING, $toHandle) !== false
                ) {
                    Evaluate::compareString($code, $getLine);

                }else if (in_array(Token::T_FLOAT, $toHandle) !== false ||in_array('customFunction', $toHandle) !== false ){
                    Evaluate::compareFloat($code, $getLine);

                }else{
                    Evaluate::compareInteger($code, $getLine);
                }


                Evaluate::readIndex(1, $code, $getLine);


                if ($operator){

                    Evaluate::setOperation($operator['type'], $code, $getLine);

                    $lastLine = end($code)->lineNumber + 4;

                    // line offset for the IF start (or so)
                    $code[] = $getLine( Helper::fromIntToHex($lastLine * 4) );

                    if($token['params'][1] == Token::T_FLOAT) {
                        $code[] = $getLine('12000000', false, '[T_CONDITION] map ( ka ) float');
                    }else{
                        $code[] = $getLine('33000000', false, '[T_CONDITION] map ( ka ) NO float');
                    }

                    $code[] = $getLine('01000000', false, '[T_CONDITION] map ( ka ) end');
                    $code[] = $getLine('01000000', false, '[T_CONDITION] map ( ka ) end');
                }

                if (isset($node['isOuterNot']) && $node['isOuterNot']){
                    Evaluate::setStatementNot($code, $getLine);
                }
            }
        }

        return $code;
    }

}