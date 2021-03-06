<?php
namespace App\Service\Archive\Inst;

use App\MHT;
use App\Service\Archive\Inst;
use App\Service\CompilerV2\Manhunt2;
use App\Service\Helper;
use App\Service\NBinary;

class Extract {

    public function get( NBinary $binary ){

        // detect the platform
        $placementsBinary = $binary->get(4);

        if ($binary->unpack($placementsBinary, NBinary::INT_32) > 100000){
            $binary->numericBigEndian = true;
        }

        $placements = $binary->consume(4, NBinary::INT_32);

        if ($placements === 0){
            return [];
        }

        $sizesLength = $placements * 4;

        //split sizes (header) from content
        $sizes = $binary->consume($sizesLength, NBinary::HEX);
        $sizes = str_split($sizes, 8);

        //extract every record
        $records = [];

        foreach ($sizes as $i => $size) {
            $size = $binary->unpack(hex2bin($size), NBinary::INT_32);

            $block = new NBinary( $binary->consume($size, NBinary::BINARY) );

            $block->numericBigEndian = $binary->numericBigEndian;
            $record = $this->parseRecord( $block );

            $records[($i + 1) . "#" .$record['internalName'] . '.json'] = $record;
        }

        return $records;
    }

    private function parseRecord( NBinary $binary ){

        $glgRecord = $binary->getString();
        $internalName = $binary->getString();

        $position = $binary->readXYZ();
        $rotation = $binary->readXYZW();

        $entityClass = $binary->getString();

        $params = [];

        $game = MHT::GAME_AUTO;

        if ($binary->remain()){
            // Guess the game
            if ($game == MHT::GAME_AUTO){
                if ($binary->remain() >= 12){
                    $maybeType  = trim($binary->get(4, 4));
                    if (in_array($maybeType, [ 'flo', 'boo', 'str', 'int' ])){
                        $game = MHT::GAME_MANHUNT_2;
                    }else{
                        $game = MHT::GAME_MANHUNT;
                    }
                }else{
                    $game = MHT::GAME_MANHUNT;
                }
            }
        }

        $paramIndex = 0;

        while($binary->remain() > 0) {

            if ($game == MHT::GAME_MANHUNT){

                $value = $binary->consume(4, NBinary::INT_32);

                $params[] = [
                    'value' => $value
                ];
                continue;
//
//
//                if (!isset(Inst::$mh1Map[$entityClass])){
//                    var_dump($entityClass);
//                    exit;
//                }
//
//                $paramBase = Inst::$mh1Map[$entityClass];
//
//                foreach ($paramBase as $name => $type) {
//                    if ($binary->remain() == 0) break;
//                    $value = $binary->consume(4, $type);
//
//                    $params[] = [
//                        'parameterId' => $name,
//                        'type' => strtolower(substr($type, 0, 3)),
//                        'value' => $value
//                    ];
//
//                }

            }else{

                $parameter = $binary->consume(4, NBinary::HEX);
                if ($binary->numericBigEndian) $parameter = Helper::toBigEndian($parameter);

                $parameterName = Manhunt2::getNameByHash($parameter);
                if ($parameterName == false){
                    $parameterName = $parameter;
                }

                $type = $binary->consume(4, NBinary::STRING);

                // float, boolean, integer are always 4-byte long
                // string need to be calculated
                switch ($type) {
                    case 'flo':
                        $value = $binary->consume(4, NBinary::FLOAT_32);
                        break;

                    case 'boo':
                    case 'int':
                        $value = $binary->consume(4, NBinary::INT_32);
//                        $value = $binary->consume(1, NBinary::INT_8);
//                        $value2 = $binary->consume(3, NBinary::HEX);

                        if($parameterName == "WEAPON") {
                            $value = Inst::getWeaponNameById($value);
                        }else if($parameterName == "WEAPON2"){
                            $value = Inst::getWeapon2NameById($value);
                        }

                        break;
                    case 'str':
                        $value = $binary->getString();
                        break;
                }

                $params[] = [
                    'parameterId' => $parameterName,
                    'type' => $type,
                    'value' => $value
                ];
            }

            $paramIndex++;
        };

        return [
            'record' => $glgRecord,
            'internalName' => $internalName,
            'entityClass' => $entityClass,
            'position' => $position,
            'rotation' => $rotation,
            'parameters' => $params,
            'game' => $game
        ];
    }

}