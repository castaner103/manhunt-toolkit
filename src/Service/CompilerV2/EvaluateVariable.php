<?php

namespace App\Service\CompilerV2;

use App\Service\Helper;
use Exception;

class EvaluateVariable{

    public $msg = "";

    /** @var Compiler */
    private $compiler;


    public function __construct( Compiler $compiler )
    {

        $this->compiler = $compiler;
    }

    public function ret(){
        $this->add('10000000', 'Return');
        $this->add('01000000', 'Return');
    }

    public function retString(){
        $this->ret();

        $this->add('10000000', 'Return String');
        $this->add('02000000', 'Return String');
    }

    public function readSize( int $offset){
        $this->msg = "read data ";
        $this->add('12000000');
        $this->add('02000000');
        $this->add(Helper::fromIntToHex($offset), 'Size');
    }

    public function valuePointer($offset ){

        $this->msg = "get simple value pointer";
        $this->add('12000000');
        $this->add('01000000');
        $this->add(
            is_int($offset) ?
                    Helper::fromIntToHex($offset) :
                    Helper::fromFloatToHex($offset),
            "Offset"
        );
    }

    public function variablePointer( Associations $association, $type = null){
        $type = is_null($type) ? $association->varType : $type;

        if (in_array($type,
            ['real', 'state', 'entityptr', 'boolean', 'integer', 'eaicombattype', 'ecollectabletype']
        ) !== false ){
            $this->add($association->section == "header" ? '14000000' : '13000000', ' Pointer from Section ' . $association->section);
            $this->add('01000000', 'Read Variable');
            $this->add('04000000', 'Read Variable');
            $this->add(Helper::fromIntToHex($association->offset), 'Offset');
        }
    }

    public function memoryPointer( Associations $association){
        $this->add($association->section == "header" ? '21000000' : '22000000', 'Read memory from Section ' . $association->section);
        $this->add('04000000', 'Read memory');
        $this->add('01000000', 'Read memory');
        $this->add(Helper::fromIntToHex($association->offset), 'Offset');

    }

    public function negate(Associations $association){
        if (is_float($association->value)){
            $this->compiler->evalVar->ret();

            $this->add('4f000000', 'Negate Float');
            $this->add('32000000', 'Negate Float');
            $this->add('09000000', 'Negate Float');
            $this->add('04000000', 'Negate Float');

        }else{
            $this->add('2a000000', 'Negate Integer');
            $this->add('01000000', 'Negate Integer');

        }
    }

    /**
     * @param $type
     * @throws Exception
     */
    public function math( $type ){
        $this->add('0f000000');
        $this->add('04000000');

        if ($type == Tokens::T_ADDITION) {
            $this->add('31000000');
            $this->add('01000000');
            $this->add('04000000');
        }else if ($type == Tokens::T_MULTIPLY){
            $this->add('35000000');
            $this->add('04000000');
        }else if ($type == Tokens::T_SUBSTRACTION){
            $this->add('33000000');
            $this->add('04000000');
            $this->add('01000000');

            $this->add('11000000');
            $this->add('01000000');
            $this->add('04000000');
        }else if ($type == Tokens::T_DIVISION){
            $this->add('T_DIVISION');
        }else{
            throw new Exception("Math-Type not implemented " . $association->math->type);
        }
    }


    private function add($code, $appendix = null ){
        $msg = $this->msg;

        if (!is_null($appendix)) $msg .= ' | ' . $appendix;


        $this->compiler->codes[] = [
            'code' => $code,
            'msg' => $msg
        ];
    }
}