<?php
namespace App\Tests\Command;

use App\Service\Archive\Glg;
use App\Service\Archive\Mls;
use App\Service\Compiler\Compiler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScriptVec3dTest extends KernelTestCase
{

    public function test()
    {

        $script = "
            scriptmain LevelScript;

            script OnCreate;
                var
                    pos : Vec3D;
                begin
            		SetVector(pos);
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

            '22000000',
            '04000000',
            '01000000',
            '0c000000',

            '10000000',
            '01000000',
            '84010000', // SetVector

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