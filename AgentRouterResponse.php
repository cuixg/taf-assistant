<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 16/7/20
 * Time: 下午4:56
 */

namespace Taf;

class AgentRouterResponse  extends \TJCE_Struct
{

    public $type = \TJCE::SHORT;

    public $sObj = \TJCE::STRING;

    public $sSet = \TJCE::STRING;
    public $vResult = NULL;

    public $iExpireInterval = \TJCE::INT32;

    public function __construct() {

        parent::__construct(0,'tafagent.AgentRouterResponse');

        $this->vResult = new \TJCE_Vector(new RouterNodeInfo());

    }
}
