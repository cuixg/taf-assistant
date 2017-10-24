<?php


namespace Taf;


class RouterNodeInfo extends \Taf\TJCE_Struct {
	const SIP = 1;
	const IPORT = 2;
	const BTCP = 3;
	const IWEIGHT = 4;
	const SSET = 5;


	public $sIp; 
	public $iPort; 
	public $bTcp; 
	public $iWeight; 
	public $sSet; 


	protected static $fields = array(
		self::SIP => array(
			'name'=>'sIp',
			'required'=>false,
			'type'=>\Taf\TJCE::STRING,
),
		self::IPORT => array(
			'name'=>'iPort',
			'required'=>false,
			'type'=>\Taf\TJCE::SHORT,
),
		self::BTCP => array(
			'name'=>'bTcp',
			'required'=>false,
			'type'=>\Taf\TJCE::BOOL,
),
		self::IWEIGHT => array(
			'name'=>'iWeight',
			'required'=>false,
			'type'=>\Taf\TJCE::INT32,
),
		self::SSET => array(
			'name'=>'sSet',
			'required'=>false,
			'type'=>\Taf\TJCE::STRING,
),
	);

	public function __construct() {
		parent::__construct('taf_tafagent_RouterNewObj.RouterNodeInfo', self::$fields);
	}
}
