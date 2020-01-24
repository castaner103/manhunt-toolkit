<?php
namespace App\Tests\CompilerV2\LevelScripts\Manhunt1;

use App\MHT;
use App\Service\Compiler\Compiler;
use App\Service\Resources;
use App\Tests\ValidateMls;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AsylumTest extends ValidateMls
{

    public function testLevelScript()
    {
        echo "\n* MLS: Testing Manhunt 1 PC (compile Asylum) ==> ";

        $this->process('/Archive/Mls/Manhunt1/PC/asylum.mls', MHT::GAME_MANHUNT, MHT::PLATFORM_PC);
    }
}