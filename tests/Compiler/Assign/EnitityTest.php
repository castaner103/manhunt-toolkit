<?php
namespace App\Tests\Command;

use App\Service\Archive\Glg;
use App\Service\Archive\Mls;
use App\Service\Compiler\Compiler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EnitityTest extends KernelTestCase
{

    public function test()
    {

        $script = "
            scriptmain LevelScript;

            entity 
                LiftLoonie01(hunter) : et_name;

            script OnCreate;
                begin
                    GetEntity('LiftLoonie01(hunter)');
                end;

            end.
        ";

        $expected = [
            // script start
            '10000000',
            '0a000000',
            '11000000',
            '0a000000',
            '09000000',

            '21000000',
            '04000000',
            '01000000',
            '00000000',

            '12000000',
            '02000000',
            '15000000', // LiftLoonie01(hunter) + 1
            '10000000',
            '01000000',

            '10000000',
            '02000000',

            '77000000', //getentity Call

            // script end
            '11000000',
            '09000000',
            '0a000000',
            '0f000000',
            '0a000000',
            '3b000000',
            '00000000'
        ];

        $compiler = new Compiler();
        list($sectionCode, $sectionDATA) = $compiler->parse($script);

        $this->assertEquals($sectionCode, $expected, 'The bytecode is not correct');
    }

}