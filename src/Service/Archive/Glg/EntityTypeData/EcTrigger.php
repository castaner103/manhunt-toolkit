<?php
namespace App\Service\Archive\Glg\EntityTypeData;

use App\MHT;

class EcTrigger extends Ec {

    public $class           = MHT::EC_TRIGGER;
    public $name            = null;

    protected $map = [
        'CLASS' => true,
        'MODEL' => null,
        'LOD_DATA' => [],
    ];


}
