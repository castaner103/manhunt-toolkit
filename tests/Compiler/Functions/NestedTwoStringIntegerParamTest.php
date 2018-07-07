<?php
namespace App\Tests\Command;

use App\Service\Archive\Glg;
use App\Service\Archive\Mls;
use App\Service\Compiler\Compiler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NestedTwoStringIntegerParamTest extends KernelTestCase
{

    public function test()
    {

        $script = "
            scriptmain LevelScript;

            script OnCreate;

                begin
                    writedebug(writedebug('test'), 1)
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

            '21000000', // init string
            '04000000', // init string
            '01000000', // init string
            '00000000', // string offset (pointer)

            '12000000', // init parameter
            '02000000', // init parameter
            '05000000', // value int 5 (test +1)
            '10000000', // assign
            '01000000', // assign

            '10000000', // move pointer
            '02000000', // move pointer

            '73000000', // writedebug call (hidden call)
            '74000000', // writedebug call

            '10000000', // nested call return
            '01000000', // nested call return

            '12000000', // init parameter
            '01000000', // init parameter
            '01000000', // value int 1
            '10000000', // assign
            '01000000', // assign

            '73000000', // writedebug call (hidden call)
            '74000000', // writedebug call

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