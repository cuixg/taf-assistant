<?php

namespace weblib\taf;

class AgentRouterResponse extends \Taf\TJCE_Struct {
	const TYPE = 1;
	const SOBJ = 2;
	const SSET = 3;
	const VRESULT = 4;
	const IEXPIREINTERVAL = 5;


	public $type; 
	public $sObj; 
	public $sSet; 
	public $vResult; 
	public $iExpireInterval; 


	protected static $fields = array(
		self::TYPE => array(
			'name'=>'type',
			'required'=>false,
			'type'=>\Taf\TJCE::UINT8,
),
		self::SOBJ => array(
			'name'=>'sObj',
			'required'=>false,
			'type'=>\Taf\TJCE::STRING,
),
		self::SSET => array(
			'name'=>'sSet',
			'required'=>false,
			'type'=>\Taf\TJCE::STRING,
),
		self::VRESULT => array(
			'name'=>'vResult',
			'required'=>false,
			'type'=>\Taf\TJCE::VECTOR,
),
		self::IEXPIREINTERVAL => array(
			'name'=>'iExpireInterval',
			'required'=>false,
			'type'=>\Taf\TJCE::INT32,
),
	);

	public function __construct() {
		parent::__construct('taf_tafagent_RouterNewObj.AgentRouterResponse', self::$fields);
        $this->vResult = new \Taf\TJCE_VECTOR(new RouterNodeInfo());
	}
}
