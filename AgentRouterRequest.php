<?php

namespace weblib\taf;

class AgentRouterRequest extends \Taf\TJCE_Struct {
	const TYPE = 1;
	const SOBJ = 2;
	const SSET = 3;
	const ITID = 4;
	const SAPIVER = 5;


	public $type; 
	public $sObj; 
	public $sSet; 
	public $iTid; 
	public $sApiVer; 


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
		self::ITID => array(
			'name'=>'iTid',
			'required'=>false,
			'type'=>\Taf\TJCE::INT64,
),
		self::SAPIVER => array(
			'name'=>'sApiVer',
			'required'=>false,
			'type'=>\Taf\TJCE::STRING,
),
	);

	public function __construct() {
		parent::__construct('taf_tafagent_RouterNewObj.AgentRouterRequest', self::$fields);
	}
}
