<?php
namespace App\Service\Compiler\Emitter;

use App\MHT;
use App\Service\Compiler\Evaluate;
use App\Service\Compiler\FunctionMap\Manhunt;
use App\Service\Compiler\FunctionMap\Manhunt2;
use App\Service\Compiler\FunctionMap\ManhuntDefault;
use App\Service\Compiler\Line;
use App\Service\Compiler\Token;
use App\Service\Helper;

class T_VARIABLE extends TAbstract {

    static public function getMapping( $node, $data ){

        $value = $node['value'];

        if (
            isset($data['customData']['customFunctions']) &&
            isset($data['customData']['customFunctions'][ $value ])
        ) {

            $mapped = $data['customData']['blockOffsets'][$value];

        }else if (
            isset($data['customData']['procedureVars']) &&
            isset($data['customData']['procedureVars'][ $value ])
        ) {
            $mapped = $data['customData']['procedureVars'][$value];


        }else if (
            isset($data['customData']['customFunctionVars']) &&
            isset($data['customData']['customFunctionVars'][ $value ])
        ) {
            $mapped = $data['customData']['customFunctionVars'][$value];

        //array index access
        }else if (strpos($value, '[') !== false){

            $variableName = explode('[', $value)[0];

            $mapped = $data['combinedVariables'][$variableName];


        }else if (strpos($value, '.') !== false){

            //vec3d object pos.x
            $mapped = Evaluate::getObjectToAttributeSplit($value, $data);

        }else if (
            isset($node['target']) &&
            isset($data['types'][ $node['target'] ])
        ){
            $variableType = $data['types'][$node['target']];
            $mapped = $variableType[ strtolower($value) ];

        }else if (isset($data['combinedVariables'][ $value ])){
            $mapped = $data['combinedVariables'][ $value ];

        }else{
            throw new \Exception(sprintf("T_VARIABLE: unable to find variable offset for %s", $value));
        }

        return $mapped;
    }

    public function map( $node, \Closure $getLine, \Closure $emitter, $data ){


        $mapped = self::getMapping($node, $data);

        $debugMsg = "[T_VARIABLE] map type " . $mapped['type'] . ' ' . $node['value'] . " ";

        $code = [];

        switch ($mapped['type']){
            case Token::T_VEC3D:
                Evaluate::fromObject($mapped, $code, $getLine, $node['value']);
                break;
            case Token::T_OBJECT:
                $code = $this->fromObjectAttribute($node, $data, $getLine);
                break;

            case Token::T_STRING_ARRAY:
                Evaluate::readData($mapped, $code, $getLine, $node['value']);
                Evaluate::readStringPosition($mapped['size'], $code, $getLine);
                break;

            case 'level_var stringarray':
                Evaluate::fromLevelVarStringArray($mapped, $code, $getLine);
                Evaluate::readStringPosition($mapped['size'], $code, $getLine);
                break;

            case 'custom_functions':
                Evaluate::gotoBlock(
                    $node['value'],
                    $data['customData']['customFunctions'][$node['value']],
                    $code,
                    $getLine
                );
                break;

            case Token::T_INT:
                Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                break;
            case Token::T_CONSTANT_INTEGER:
                Evaluate::readIndex($mapped['offset'], $code, $getLine);

                if (
                    isset($data['customData']) &&
                    isset($data['customData']['fromFunction']) &&
                    $data['customData']['fromFunction']
                ){
                    Evaluate::regularReturn($code, $getLine);
                }
                break;

            default:

                if(isset($mapped['isLevelVar']) && $mapped['isLevelVar']) {

                    if (isset($node['target'])){
                        $variableType = $data['types'][$node['target']];
                        $mapped = $variableType[ $node['value'] ];

                        Evaluate::readIndex($mapped['offset'], $code, $getLine);

                    }else{

                        Evaluate::fromLevelVar($mapped, $code, $getLine);
                    }


                }else if(isset($mapped['isGameVar']) && $mapped['isGameVar']) {
                    Evaluate::fromGameVar($node, $mapped, $code, $getLine);



                    /*
                     * TODO: cleanup this block ... all the same
                     */
                }else if($mapped['objectType'] == "rgbaint") {
                    Evaluate::readData($mapped, $code, $getLine, $node['value']);
                    Evaluate::regularReturn($code, $getLine);
//                    Evaluate::readStringPosition($mapped['size'], $code, $getLine);


                }else if($mapped['objectType'] == Token::T_REAL) {
                    Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                    Evaluate::regularReturn($code, $getLine);

                }else if($mapped['objectType'] == Token::T_STRING) {

                    Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                    Evaluate::readStringPosition(0, $code, $getLine);
                }else if(
                    array_key_exists('valueType', $mapped) &&
                    $mapped['valueType'] == Token::T_STRING) {

                    Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                    Evaluate::readStringPosition(0, $code, $getLine);
                }else if($mapped['objectType'] == 'matrixptr') {
                    Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                    Evaluate::regularReturn($code, $getLine);
                }else if($mapped['objectType'] == 'effectptr') {
                    Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                    Evaluate::regularReturn($code, $getLine);

                }else if($mapped['objectType'] == 'entityptr') {
                    Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                    Evaluate::regularReturn($code, $getLine);

                }else{
//                    var_dump($mapped);
//                    exit;

                    Evaluate::fromFinedANameforMeTodoSecond($mapped, $code, $getLine);
                }
        }

        $result = [];
        foreach ($code as $item) {
            if ($item instanceof Line){
                $result[] = $item;
            }else{
                $result[] = $getLine($item, false, $debugMsg);
            }
        }

        return $result;
    }


    private function fromObjectAttribute($node, $data, $getLine){

        $mapped = Evaluate::getObjectToAttributeSplit($node['value'], $data);

        $code = [];

        if ($mapped['offset'] !== $mapped['object']['offset']) {
            Evaluate::fromObjectAttribute($mapped, $code, $getLine, $node['value']);
        }else{
            Evaluate::fromObject($mapped, $code, $getLine, $node['value']);
        }

        //TODO: unknown code sequence... lookup needed

        Evaluate::variableObjectUnknownCommand($code, $getLine);

        return $code;

    }

}