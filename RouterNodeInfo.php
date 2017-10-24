<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 16/7/20
 * Time: 下午7:26
 */

namespace Taf;

class RouterNodeInfo  extends \TJCE_Struct
{

    public $sIp = \TJCE::STRING;

    public $iPort = \TJCE::SHORT;

    public $bTcp = \TJCE::BOOL;

    public $iWeight = \TJCE::INT32;

    public function __construct() {
        parent::__construct(0,'tafagent.RouterNodeInfo');

    }
}
