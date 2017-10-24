<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 16/7/20
 * Time: 下午4:55
 */
namespace Taf;

define('LB_TYPE_LOOP,',0);
define('LB_TYPE_RANDOM,',1);
define('LB_TYPE_HASH,',2);
define('LB_TYPE_CST_HASH,',3);
define('LB_AGENT_IDC,',0);
define('LB_AGENT_SET,',1);
define('LB_AGENT_ALL,',2);



class AgentRouterRequest  extends \TJCE_Struct
{

    public $type = \TJCE::SHORT;

    public $sObj = \TJCE::STRING;

    public $sSet = \TJCE::STRING;

    public $iTid = \TJCE::INT64;

    public $sApiVer = \TJCE::STRING;

    public function __construct() {
        $startZero = 0;

        parent::__construct($startZero,'tafagent.AgentRouterRequest');

    }
}
