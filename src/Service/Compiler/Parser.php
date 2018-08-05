<?php
namespace App\Service\Compiler;

class Parser {
    /**
     * @param $tokens
     * @return array
     */
    public function toAST( $tokens ){

        $current = 0;
        $ast = [
            'type' => 'root',
            'body' => [],
        ];

        $node = null;

        while ($current < count($tokens)) {

            list($current, $node) = $this->parseToken($tokens, $current);

            if ($node !== false){
                $ast['body'][] = $node;
            }
        }

        $ast = $this->handleForward($ast);

        return $ast;
    }

    private function handleForward( $ast ){

        foreach ($ast['body'] as &$token) {
            if ($token['type'] == Token::T_FORWARD){

                foreach ($ast['body'] as $index => $tokenInner) {
                    if (
                        isset($token['section']) &&
                        $tokenInner['type'] == $token['section'] &&
                        $tokenInner['value'] == $token['to']
                    ){

                        $token = $tokenInner;

                        unset($ast['body'][$index]);
                        $ast['body'] = array_values($ast['body']);
                    }
                }
            }
        }

        return $ast;
    }

    private function parseToken($tokens, $current) {

        if (!isset($tokens[$current])) return [$current, false];

        $token = $tokens[$current];

        switch ($token['type']){

            /**********************************
             *
             *
             * Ignore this tokens, we do not need them
             *
             *
             *********************************/
            case Token::T_DEFINE :
            case Token::T_DO :
            case Token::T_LINEEND :
            case Token::T_BEGIN :
            case Token::T_SCRIPTMAIN:
            case Token::T_SCRIPTMAIN_NAME:
            case Token::T_IF_END:
            case Token::T_WHILE_END:
            case Token::T_CASE_END:
            case Token::T_SCRIPT_END:
            case Token::T_PROCEDURE_END:
            case Token::T_END_CODE:
                 //just go to the next position
                return $this->parseToken($tokens, $current + 1);


            /**********************************
             *
             *
             * simple types, just return the token
             *
             *
             *********************************/
            case Token::T_NIL :
            case Token::T_TRUE :
            case Token::T_IS_EQUAL :
            case Token::T_IS_NOT_EQUAL :
            case Token::T_IS_SMALLER :
            case Token::T_IS_GREATER :
            case Token::T_FALSE :
            case Token::T_STRING:
            case Token::T_INT:
            case Token::T_FLOAT:
            case Token::T_TYPE_VAR:
            case Token::T_SELF:
            case Token::T_NOT:
            case Token::T_FORWARD:
            case Token::T_ADDITION:
            case Token::T_SUBSTRACTION:
            case Token::T_OR:
            case Token::T_AND:
//            case Token::T_CASE:
            case Token::T_OF:
                return [
                    $current + 1, $tokens[$current]
                ];

            /**********************************
             *
             *
             * some complex types
             *
             *
             *********************************/

            case Token::T_PROCEDURE:
                return $this->parseProcedure($tokens, $current);


            case Token::T_BRACKET_OPEN :
                return $this->parseBracketOpen($tokens, $current);

            case Token::T_IF:
            case Token::T_WHILE:
                list($current, $nodes) = $this->parseIfStatement($tokens, $current);

                // Todo: wrap code into a function
                foreach ($nodes['cases'] as &$case) {


                    if (count($case['condition'])){
                        $firstNode = $case['condition'][0];
                        $lastNode = end($case['condition']);


                        $doWrap = false;
                        if (
                            $firstNode['type'] == Token::T_BRACKET_OPEN &&
                            $lastNode['type'] !== Token::T_BRACKET_CLOSE
                        ) {
                            $doWrap = true;
                        }else if (
                            $firstNode['type'] !== Token::T_BRACKET_OPEN &&
                            $lastNode['type'] !== Token::T_BRACKET_CLOSE
                        ){
                            $doWrap = true;
                        }else if (
                            $firstNode['type'] !== Token::T_BRACKET_OPEN &&
                            $lastNode['type'] == Token::T_BRACKET_CLOSE
                        ){
                            $doWrap = true;
                        }

                        //wrap if statements always in brackets
                        if ($doWrap){

                            array_unshift($case['condition'], [
                                'type' => Token::T_BRACKET_OPEN
                            ]);

                            array_push($case['condition'], [
                                'type' => Token::T_BRACKET_CLOSE
                            ]);
                        }
                    }


                    $parsedConditions = [];
                    $innerCurrent = 0;
                    $innerTokens = $case['condition'];

                    while($innerCurrent < count($innerTokens)){
                        list($innerCurrent, $tree)= $this->parseToken($innerTokens,$innerCurrent);

                        if (
                            $tree['type'] == Token::T_AND ||
                            $tree['type'] == Token::T_OR
                        ){
                            continue;
                        }


                        $tree = [$tree];
                        $this->remapCondition( $tree );
                        $this->extendConditionInformation( $tree );

                        $parsedConditions[] = current($tree);
                    }

                    $case['condition'] = $parsedConditions;

                    $parsedIsTrue = [];
                    $innerCurrent = 0;
                    $innerTokens = $case['isTrue'];

                    while($innerCurrent < count($innerTokens)){
                        list($innerCurrent, $tree)= $this->parseToken($innerTokens, $innerCurrent);

                        if ($tree) $parsedIsTrue[] = $tree;

                    }

                    $case['isTrue'] = $parsedIsTrue;
                }

                return [$current, $nodes];

            case Token::T_DEFINE_SECTION_VAR:
                return $this->parseDefineVarRecursive($tokens, $current);

            case Token::T_DEFINE_SECTION_CONST:
                return $this->parseDefineConstRecursive($tokens, $current);

            case Token::T_DEFINE_SECTION_ENTITY:
                return $this->parseDefineEntityRecursive($tokens, $current);

            case Token::T_DEFINE_SECTION_TYPE:
                return $this->parseDefineTypeRecursive($tokens, $current);

            case Token::T_FUNCTION :
                return $this->parseFunction($tokens, $current);

            /**
             * A variable can be used or assigned
             * Return T_ASSIGN when its a define otherwise just the token
             */
            case Token::T_VARIABLE :
                return $this->parseVariable($tokens, $current);

            case Token::T_SCRIPT :
                return $this->parseScript($tokens, $current);

            case Token::T_CASE :
                return $this->parseSwitchCase($tokens, $current);


            /**********************************
             *
             *
             * per default any other types are nested
             *
             *
             *********************************/
            default:
                return $this->parseRecursive($tokens, $current);

        }

    }


    public function parseSwitchCase($tokens, $current){

        //skip T_CASE
        $current++;

        $switchBy = $tokens[$current];

        //skip swicth var
        $current++;

        //skip T_OF
        $current++;

        $switch = [
            'type' => Token::T_SWITCH,
            'switch' => $switchBy,
            'cases' => []
        ];

        while ($current < count($tokens)) {
            if ($tokens[$current]['type'] == Token::T_SWITCH_END){
                return [
                    $current + 1, $switch
                ];

            }
            $case = [
                'index' => $tokens[$current],
                'body' => []
            ];


            $current++;
            //skip T_DEFINE
            $current++;

            $shortCase = true;
            if ($tokens[$current]['type'] == Token::T_BEGIN){
                $current++;
                $shortCase = false;
            }


            while ($current < count($tokens)) {
                if (
                    (
                        $shortCase &&
                        $tokens[$current]['type'] == Token::T_LINEEND
                    ) || (
                        $shortCase == false &&
                        $tokens[$current]['type'] == Token::T_CASE_END
                    )
                ) {

                    $current++;


                    $innerCurrent = 0;
                    $innerTokens = $case['body'];

                    $case['body'] = [];
                    while($innerCurrent < count($innerTokens)){

                        list($innerCurrent, $node) = $this->parseToken($innerTokens, $innerCurrent);

                        if ($node !== false){
                            $case['body'][] = $node;

                        }
                    }

                    $switch['cases'][] = $case;


//
//                    list($innerCurrent, $node) = $this->parseToken($case['body'], 0);
//                    $case['body'] = $node;
//                    $switch['cases'][] = $case;
                    break;
                }else{
                    $case['body'][] = $tokens[$current];
                }

                $current++;
            }
        }

        throw new \Exception('Parser: parseSwitchCase not handeld correct');
    }

    public function parseProcedure($tokens, $current){

        $starCurrent = $current;

        $isForward = true;

        while ($current < count($tokens)) {
            $token = $tokens[$current];

            if ($token['type'] == Token::T_BEGIN){
                $isForward = false;
                break;
            }

            if ($token['type'] == Token::T_FORWARD){
                $isForward = true;
                break;
            }

            $current++;

        }

        $current = $starCurrent;

        /**
         * we have a forward define section
         */
        if ($isForward == true){

            $current++;

            $node = [
                'type' => Token::T_FORWARD,
                'to' => $tokens[$current]['value'],
                'section' => Token::T_PROCEDURE,
                'params' => [],
            ];

            $current++;

            if ($tokens[$current]['type'] == Token::T_BRACKET_OPEN){

                $current++;

                while ($current < count($tokens)) {

                    if ($tokens[$current]['type'] == Token::T_BRACKET_CLOSE){
                        $current++;
                        break;
                    }else{
                        $node['params'][] = $tokens[$current];
                    }

                    $current++;
                }
            }

            if ($tokens[$current]['type'] !== Token::T_LINEEND){
                throw new \Exception('Parser: parseForward T_LINEEND expected');
            }

            $current++;

            if (strtolower($tokens[$current]['value']) != "forward"){
                throw new \Exception('Parser: parseForward FORWARD expected');
            }

            $current++;

        /**
         * we have a procedure define section
         */
        }else{
            return $this->parseScript($tokens, $current);
        }

        return [
            $current, $node
        ];

    }

    public function parseScript($tokens, $current){
        $token = $tokens[$current];

        $node = [
            'type' => $token['type'],
            'value' => false,
            'body' => [],
        ];

        $current++;

        while ($current < count($tokens)) {

            switch ($tokens[$current]['type']){

                case Token::T_PROCEDURE_NAME:
                case Token::T_SCRIPT_NAME:
                    $node['value'] = $tokens[$current]['value'];
                    $current++;
                    continue;
                    break;

                case Token::T_LINEEND:
                case Token::T_BEGIN:
                    $current++;
                    continue;
                    break;

                case Token::T_PROCEDURE_END:
                case Token::T_SCRIPT_END:
                    return [
                        $current, $node
                    ];
                default:

                    list($current, $token) = $this->parseToken($tokens, $current);

                    if ($token !== false){
                        $node['body'][] = $token;
                    }
                    break;
            }

        }

        throw new \Exception('Parser: parseScript not handeld correct');
    }


//    private function remapCondition( &$tokens ){
//        $current = 0;
//        $outerCount = count($tokens);
//        while($current < $outerCount){
//            $token = $tokens[$current];
//            if ($token['type'] == Token::T_BRACKET_OPEN) {
//                $this->remapCondition( $token['params']);
//                $current++;
//                continue;
//            }
//
//            file_put_contents('a', $current, FILE_APPEND);
//            $isNot = false;
//            if ($token['type'] == Token::T_NOT) {
//                $isNot = true;
//                unset($tokens[ $current ]);
//            }
//
//
//            $node = [
//                'type' => Token::T_CONDITION,
//                'isNot' => $isNot,
//                'body' => $tokens,
//            ];
//
//            $tokens = $node;
//            break;
//
//        }
//    }
    /**
     * remap / regroup statements
     *
     * input : T_VARIABLE T_IS_EQUAL T_INT
     * output : T_IS_EQUAL[T_VARIABLE] = T_INT
     *
     * @param $tokens
     * @throws \Exception
     */
    private function remapCondition( &$tokens ){

        foreach ($tokens as $current => $token) {

            // this can happend because of the unset calls
            if (!isset($tokens[ $current ])) continue;


            if ($tokens[ $current ]['type'] == Token::T_BRACKET_OPEN) {
                $this->remapCondition( $tokens[ $current ]['params']);
                continue;
            }

            $isNot = false;
            if ($tokens[ $current ]['type'] == Token::T_NOT) {
                $isNot = true;
                unset($tokens[ $current ]);

                $tokens = array_values($tokens);
            }




            $node = [
                'type' => Token::T_CONDITION,
                'isNot' => $isNot,
                'body' => [],
            ];

            if (count($tokens) == 1){
                $opertation = [
                    'type' => Token::T_OPERATION,
                    'operation' => [ 'type' => 'default' ],
                    'params' => [
                        $tokens[0]
                    ]
                ];

            }else{


                $opertation = false;

                /**
                 *
                 * convert any condition into operation tokens
                 */
                $innerTokens = $tokens;
                $innerCurrent = 0;
                $innerTokenCount = count($innerTokens);
                while($innerCurrent < $innerTokenCount){
                    if (!isset($innerTokens[ $innerCurrent ])){
                        $innerCurrent++;
                        continue;
                    }

                    $innerToken = $innerTokens[ $innerCurrent ];

                    if (
                        $innerToken['type'] == Token::T_IS_EQUAL ||
                        $innerToken['type'] == Token::T_IS_NOT_EQUAL ||
                        $innerToken['type'] == Token::T_IS_GREATER ||
                        $innerToken['type'] == Token::T_IS_SMALLER
                    ){
                        $opertation = [
                            'type' => Token::T_OPERATION,
                            'operator' => $innerToken,
                            'operation' => [ 'type' => 'default' ],
                            'params' => [
                                $innerTokens[ $innerCurrent - 1 ],
                                $innerTokens[ $innerCurrent  + 1]
                            ]
                        ];

                        unset($innerTokens[ $innerCurrent - 1]);
                        unset($innerTokens[ $innerCurrent]);
                        unset($innerTokens[ $innerCurrent  + 1]);
                    }

                    $innerCurrent++;
                }

                if ($opertation == false){

                    var_dump("operator not found", $innerTokens);
                    exit;
                }
                $innerTokens = array_values($innerTokens);
                $innerCurrent = 0;
                $innerTokenCount = count($innerTokens);


                while($innerCurrent < $innerTokenCount){
                    if (!isset($innerTokens[ $innerCurrent ])){
                        $innerCurrent++;
                        continue;
                    }

                    $innerToken = $innerTokens[ $innerCurrent ];


                    if (
                        $innerToken['type'] == Token::T_AND ||
                        $innerToken['type'] == Token::T_OR
                    ){
                        $opertation['operation'] = [ 'type' => $innerToken['type'] ];
                        $opertation['params'][] = $innerTokens[ $innerCurrent  + 1];

                        unset($innerTokens[ $innerCurrent ]);
                        unset($innerTokens[ $innerCurrent  + 1]);

                    }

                    $innerCurrent++;

                }
            }

            if ($opertation){
                $node['body'][] = $opertation;
            }

            //remove all old token entries
            foreach ($tokens as $index => $token2) {
                unset($tokens[$index]);
            }

            $tokens[] = $node;
            $tokens = array_values($tokens);

            //            $tokens = $node;

//
//            if (count($tokens) == 3){
//
//                list($leftHand, $operator, $rightHand) = $tokens;
//
//                $operation = [
//                    'type' => Token::T_OPERATION,
//                    'operator' => $operator,
//                    'params' => [
//                        $leftHand,
//                        $rightHand
//                    ]
//                ];
//
//                $node = [
//                    'type' => Token::T_CONDITION,
//                    'isNot' => $isNot,
//                    'body' => [
//                        $operation
//                    ],
//                ];
//
//                $tokens[ $current] = $node;
//                unset($tokens[ $current + 1]);
//                unset($tokens[ $current + 2]);
//
//
//            }else if (count($tokens) == 1){
//
//                list($leftHand) = $tokens;
//
//                $node = [
//                    'type' => Token::T_CONDITION,
//                    'isNot' => $isNot,
//                    'body' => [
//                        $leftHand
//                    ],
//                ];
//
//
//                $tokens[ $current] = $node;
//            }else if (count($tokens) == 4){
//
//                list($leftHand, $operator, $rightHand, $addon) = $tokens;
//
//                $node = [
//                    'type' => Token::T_CONDITION,
//                    'isNot' => $isNot,
//                    'body' => [
//                        $leftHand,
//                        $operator,
//                        $rightHand,
//                        $addon
//                    ],
//                ];
//
//                $tokens[ $current] = $node;
//                unset($tokens[ $current + 1]);
//                unset($tokens[ $current + 2]);
//                unset($tokens[ $current + 3]);
//            }else{
//                throw new \Exception('Parser: remapCondition not handeld correct');
//            }
        }
    }


    private function extendConditionInformation( &$tokens ){

        foreach ($tokens as $current => &$token) {

            if (isset($tokens[ $current ]['params'])) {
                $this->extendConditionInformation( $tokens[ $current ]['params']);
            }

            if ($token['type'] == Token::T_BRACKET_OPEN){

                if ($current + 1 == count($tokens)){
                    $token['last'] = true;
                }
            }
        }
    }

    private function parseRecursive($tokens, $current){
        $token = $tokens[$current];

        $node = [
            'type' => $token['type'],
            'value' => isset($token['value']) ? $token['value'] : false,
            'body' => [],
        ];

        $current++;

        while ($current < count($tokens)) {

            list($current, $token) = $this->parseToken($tokens, $current);

            if ($token !== false){
                $node['body'][] = $token;
            }
        }

        return [
            $current, $node
        ];
    }

    /**
     * @param $tokens
     * @param $current
     * @return array
     * @throws \Exception
     */
    private function parseVariable($tokens, $current ){

        $token = $tokens[$current];

        if (isset($tokens[$current + 1])){

            $nextToken = $tokens[$current + 1];

            if ($nextToken['type'] == Token::T_ASSIGN){

                $node = [
                    'type' => $nextToken['type'],
                    'value' => $token['value'],
                    'body' => [],
                ];

                $current++;
                $current++;
                while ($current < count($tokens)) {
                    $token = $tokens[$current];

                    if ($token['type'] == Token::T_LINEEND){
                        return [
                            $current, $node
                        ];
                    }else{
                        list($current, $param) = $this->parseToken($tokens, $current);
                        $node['body'][] = $param;

                    }
                }

                return [
                    $current, $node
                ];
            }
        }

        return [
            $current + 1, $token
        ];
    }

    private function parseIfLastElse( $tokens, $current  ){

        $case = [
            'condition' => [],
            'isTrue'=> []
        ];

        while ($current < count($tokens)) {
            $token = $tokens[$current];

            if ($token['type'] == Token::T_IF_END) {
                return [$current, $case] ;
            }else {
                $case[ 'isTrue' ][] = $token;
            }

            $current++;
        }

        throw new \Exception('Parser: parseIfLastElse not handeld correct');
    }

    private function parseIfStatement( $tokens, $current ){

        $token = $tokens[$current];

        $node = [
            'type' => $token['type'],
            'value' => $token['value'],

            'cases' => []
        ];

        $case = [
            'condition' => [],
            'isTrue'=> []
        ];

        $current++;

        $shortStatement = true;

        /**
         * parse the condition
         */
        while ($current < count($tokens)) {
            $token = $tokens[$current];

            if ($token['type'] == Token::T_THEN || $token['type'] == Token::T_DO) {
                $current++;

                if ($tokens[$current]['type'] == Token::T_BEGIN) {
                    $shortStatement = false;
                    $current++;
                }

                break;
            }else{
                $case['condition'][] = $token;
            }

            $current++;
        }

//
//        if (
//            count($case['condition']) &&
//            $case['condition'][0]['type'] !== Token::T_BRACKET_OPEN
//        ){
//
//            array_unshift($case['condition'], [
//                'type' => Token::T_BRACKET_OPEN
//            ]);
//
//            array_push($case['condition'], [
//                'type' => Token::T_BRACKET_CLOSE
//            ]);
//        }
//
//        foreach ($case['condition'] as $index =>  $entry) {
//
//            if ($entry['type'] == Token::T_AND || $entry['type'] == Token::T_OR){
//                if ($case['condition'][$index - 1]['type'] != Token::T_BRACKET_CLOSE){
//                    array_splice($case['condition'], $index, 0, [[
//                        'type' => Token::T_BRACKET_CLOSE
//                    ]]);
//
//                    $case['condition'] = array_values($case['condition']);
//                }
//            }
//        }
//
//
//        foreach ($case['condition'] as $index =>  $entry) {
//
//            if ($entry['type'] == Token::T_AND || $entry['type'] == Token::T_OR){
//                if ($case['condition'][$index + 1]['type'] != Token::T_BRACKET_OPEN){
//                    array_splice($case['condition'], $index + 1, 0, [[
//                        'type' => Token::T_BRACKET_OPEN
//                    ]]);
//
//                    $case['condition'] = array_values($case['condition']);
//                }
//            }
//        }

        /**
         * parse SHORT true code
         */
        if ($shortStatement){
            while ($current < count($tokens)) {
                $token = $tokens[$current];

                if ($token['type'] == Token::T_LINEEND) {
                    $node['cases'][] = $case;

                    return [$current + 1, $node];
                }

                $case['isTrue'][] = $token;

                $current++;
            }

        /**
         * parse regular true code
         */
        }else{

            $deep = 0;

            while ($current < count($tokens)) {
                $token = $tokens[$current];

                if ($token['type'] == Token::T_THEN || $token['type'] == Token::T_DO) {

                    if ($tokens[$current + 1]['type'] == Token::T_BEGIN) {
                        $deep++;
                    }

                    // we have another If-statement
                }else if ($token['type'] == Token::T_END_ELSE) {
                    $node['cases'][] = $case;


                    if ($tokens[$current + 2]['type'] == Token::T_IF || $tokens[$current + 2]['type'] == Token::T_WHILE) {
                        list($current, $innerIf) = $this->parseIfStatement(
                            $tokens, $current + 2
                        );

                        foreach ($innerIf['cases'] as $case) {
                            $node['cases'][] = $case;
                        }

                        // the else statment (without if)
                    }else{

                        list($current, $innerIf) =  $this->parseIfLastElse(
                            $tokens, $current + 3
                        );

                        $node['cases'][] = $innerIf;
                    }

                    return [$current + 1, $node];

                    break;

                }else if (
                    $token['type'] == Token::T_IF_END ||
                    $token['type'] == Token::T_WHILE_END
                ) {

                    if ($deep == 0){
                        $node['cases'][] = $case;

                        return [$current + 1, $node];
                    }

                    $deep--;
                }

                $case['isTrue'][] = $token;

                $current++;
            }

        }

        throw new \Exception('Parser: parseIfStatement unable to handle');
    }

    private function parseDefineVarRecursive( $tokens, $current ){

        $token = $tokens[$current];
        $current++;

        $node = [
            'type' => $token['type'],
            'value' => $token['value'],
            'body' => []
        ];

        while ($current < count($tokens)) {

            $token = $tokens[$current];

            if (
                $token['type'] == Token::T_DEFINE_SECTION_TYPE ||
                $token['type'] == Token::T_DEFINE_SECTION_ENTITY ||
                $token['type'] == Token::T_PROCEDURE ||
                $token['type'] == Token::T_SCRIPT ||
                $token['type'] == Token::T_BEGIN
            ){
                return [$current, $node];

            }else{

                if ($token['type'] !== Token::T_DEFINE && $token['type'] !== Token::T_LINEEND) {
                    $node['body'][] = $token;
                }
            }

            $current++;
        }

        return [++$current, $node];
    }

    private function parseDefineConstRecursive( $tokens, $current ){

        $token = $tokens[$current];
        $current++;

        $node = [
            'type' => $token['type'],
            'value' => $token['value'],
            'body' => []
        ];

        while ($current < count($tokens)) {

            $token = $tokens[$current];

            if (
                $token['type'] == Token::T_DEFINE_SECTION_TYPE ||
                $token['type'] == Token::T_DEFINE_SECTION_ENTITY ||
                $token['type'] == Token::T_PROCEDURE ||
                $token['type'] == Token::T_SCRIPT ||
                $token['type'] == Token::T_BEGIN
            ){

                return [$current, $node];

            }else{

                if ($token['type'] !== Token::T_DEFINE && $token['type'] !== Token::T_LINEEND) {
                    $node['body'][] = $token;
                }
            }
            $current++;
        }

        return [++$current, $node];
    }

    private function parseDefineEntityRecursive( $tokens, $current ){

        $token = $tokens[$current];
        $current++;

        $node = [
            'type' => $token['type'],
            'value' => $token['value'],
            'body' => []
        ];

        while ($current < count($tokens)) {

            $token = $tokens[$current];

            if (
                $token['type'] == Token::T_DEFINE_SECTION_TYPE ||
                $token['type'] == Token::T_DEFINE_SECTION_VAR ||
                $token['type'] == Token::T_DEFINE_SECTION_CONST ||
                $token['type'] == Token::T_PROCEDURE ||
                $token['type'] == Token::T_SCRIPT ||
                $token['type'] == Token::T_BEGIN
            ){
                return [$current, $node];

            }else{
                if ($token['type'] !== Token::T_DEFINE && $token['type'] !== Token::T_LINEEND) {
                    $node['body'][] = $token;
                }
            }

            $current++;
        }

        return [++$current, $node];
    }

    private function parseDefineTypeRecursive( $tokens, $current ){

        $token = $tokens[$current];
        $current++;

        $node = [
            'type' => $token['type'],
            'value' => $token['value'],
            'body' => []
        ];

        while ($current < count($tokens)) {

            $token = $tokens[$current];

            if (
                $token['type'] == Token::T_IS_EQUAL ||
                $token['type'] == Token::T_BRACKET_OPEN
            ){
                $current++;
                continue;
            }

            if (
                $token['type'] == Token::T_BRACKET_CLOSE
            ){
                return [++$current, $node];

            }else{
                $node['body'][] = $token;
            }

            $current++;
        }


        return [++$current, $node];
    }

    /**
     * @param $tokens
     * @param $current
     * @return array
     * @throws \Exception
     */
    private function parseFunction($tokens, $current){

        $token = $tokens[$current];

        $current++;

        $node = [
            'type' => $token['type'],
            'value' => $token['value'],
            'nested' => isset($token['nested']) ? $token['nested'] : false,
            'params' => []
        ];

        if (count($tokens) == $current + 1) return [$current, $node];
        if (!isset($tokens[$current])) return [$current, $node];
        $token = $tokens[$current];

        if ($token['type'] != Token::T_BRACKET_OPEN){
            return [$current, $node];
        }

        $current++;

        while ($current < count($tokens)) {

            $token = $tokens[$current];

            if ($token['type'] === Token::T_BRACKET_CLOSE) {
                return [$current + 1 , $node];
            }else{

                list($current, $param) = $this->parseToken($tokens, $current);

                if ($token['type'] == Token::T_FUNCTION){
                    $param['nested'] = true;
                }

                $node['params'][] = $param;
            }
        }

        throw new \Exception('Parser: parseFunction unable to handle');
    }

    private function parseBracketOpen($tokens, $current){

        $token = $tokens[$current];

        $operator = false;
        if (isset($tokens[$current - 1])){
            if ($tokens[$current - 1]['type'] == Token::T_AND) $operator = Token::T_AND;
            if ($tokens[$current - 1]['type'] == Token::T_OR) $operator = Token::T_OR;
        }

        $current++;

        $node = [
            'type' => $token['type'],
            'nested' => isset($token['nested']) ? $token['nested'] : false,
            'operator' => $operator,
            'params' => []
        ];

        if (count($tokens) == $current + 1) return [$current, $node];

        while ($current < count($tokens)) {

            $token = $tokens[$current];

            if ($token['type'] === Token::T_BRACKET_CLOSE) {

                return [$current + 1 , $node];
            }else if (
                ($token['type'] === Token::T_AND || $token['type'] === Token::T_OR) &&
                $tokens[$current - 1]['type'] == Token::T_BRACKET_CLOSE
            ) {

                    $current++;
                    continue;
            }else{

                list($current, $param) = $this->parseToken($tokens, $current);
                if (
                    $token['type'] == Token::T_BRACKET_OPEN ||
                    isset(end($node['params'])['nested']) && end($node['params'])['nested'] == true

                ){
                    $param['nested'] = true;
                }

                $node['params'][] = $param;

            }
        }

        throw new \Exception('Parser: parseBracketOpen unable to handle');
    }
}