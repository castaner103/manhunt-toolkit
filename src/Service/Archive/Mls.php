<?php
namespace App\Service\Archive;

use App\Service\Archive\Mls\Build;
use App\Service\Archive\Mls\Extract;
use App\Service\Compiler\Compiler;
use App\Service\NBinary;
use Symfony\Component\Finder\Finder;

class Mls extends Archive {

    public $name = 'Levelscript';

    public static $supported = 'mls';


    /**
     * @param $pathFilename
     * @param Finder $input
     * @param null $game
     * @return bool
     */
    public static function canPack( $pathFilename, $input, $game = null ){

        if (!$input instanceof Finder) return false;

        foreach ($input as $file) {
            if ($file->getExtension() == "srce") return true;
        }

        return false;
    }

    /**
     * @param $binary
     * @param string $game
     * @return array
     */
    public function unpack(NBinary $binary, $game = "mh2"){

        $extractor = new Extract($binary, $game);

        return $extractor->get();
    }


    private function prepareData( Finder $finder, $game = "mh2" ){

        $scripts = [];

        foreach ($finder as $file) {

            preg_match('/code|data|dataraw|dmem|entt|line|nameremain|scpt|smem|stab|srce/', $file->getExtension(), $validFile);

            if (count($validFile) == 0) continue;

            list($index, $filename) = explode("#", $file->getFilename());
            $index = (int) $index;

            list($scriptName, $section) = explode(".", $filename);

            if (!isset($scripts[$index])) $scripts[$index] = [ "NAME" => $scriptName ];

            $content = $file->getContents();

            if (strtoupper($section) !== "SRCE"){
                $content = \json_decode($content, true);
            }

            $scripts[$index][ strtoupper($section) ] = $content;

        }

        //for the supported files, we need to compile the src and generate the needed sections
        $compiler = new Compiler();

        $levelScriptCompiled = $compiler->parse($scripts[0]['SRCE'], false, $game);

        foreach ($scripts as &$script) {
            if (!isset($script['CODE'])){
                $compiler = new Compiler();
                $name = $script['NAME'];
                $script = $compiler->parse($script['SRCE'], $levelScriptCompiled);

                $script['NAME'] = $name;
            }
        }

        return $scripts;
    }

    /**
     * @param Finder $scripts
     * @param null $game
     * @return string
     */
    public function pack( $scripts, $game = null ){

        $scripts = $this->prepareData( $scripts );

        $builder = new Build();
        return $builder->build( $scripts );

    }

    public function getValidatedResults( $data ){

        $levelScript = false;

        $results = [];

        foreach ($data as $index => $mhsc) {

            $compiler = new Compiler();
            try{

                $compiled = $compiler->parse($mhsc['SRCE'], $levelScript);

                if ($index == 0){
                    $levelScript = $compiled;
                }

                if ($compiled['CODE'] != $mhsc['CODE']) throw new \Exception('CODE did not match');

                $results[ 'supported/' . $index . "#" . $mhsc['NAME'] . '.srce' ] = $mhsc['SRCE'];

            }catch(\Exception $e){

                $results[ 'not-supported/' . $index . "#" . $mhsc['NAME'] . '.code' ] = $mhsc['CODE'];
                $results[ 'not-supported/' . $index . "#" . $mhsc['NAME'] . '.srce' ] = $mhsc['SRCE'];
                $results[ 'not-supported/' . $index . "#" . $mhsc['NAME'] . '.scpt' ] = $mhsc['SCPT'];
                $results[ 'not-supported/' . $index . "#" . $mhsc['NAME'] . '.smem' ] = $mhsc['SMEM'];
                $results[ 'not-supported/' . $index . "#" . $mhsc['NAME'] . '.entt' ] = $mhsc['ENTT'];

                if (isset($mhsc['DATA'])){
                    $results[ 'not-supported/' . $index . "#" . $mhsc['NAME'] . '.data' ] = implode("\n", $mhsc['DATA']);
                }

                if (isset($mhsc['STAB'])) {
                    $results[ 'not-supported/' . $index . "#" . $mhsc['NAME'] . '.stab' ] = \json_encode( $mhsc['STAB']);
                }
            }
        }

        return $results;
    }
}