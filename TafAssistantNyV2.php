<?php

namespace Taf;

use Taf\WsdMonitor;
use Taf\AgentRouterRequest;
use Taf\AgentRouterResponse;
use Taf\RouterNodeInfo;
use Taf\LocalSwooleTable;


class TafAssistantNyV2
{

    const SOCKET_MODE_UDP = 1;
    const SOCKET_MODE_TCP = 2;
    const SOCKET_TCP_MAX_PCK_SIZE = 65536; /* 64*1024 */

    // 错误码定义（需要从扩展开始规划）
    const TAF_SUCCESS = 0; // taf
    const TAF_FAILED = 1; // taf失败（通用失败）
    const TAF_MALLOC_FAILED = -1; // 内存分配失败

    const ROUTE_FAIL = -100;

    const TAF_SOCKET_SET_NONBLOCK_FAILED = -1002; // socket设置非阻塞失败
    const TAF_SOCKET_SEND_FAILED = -1003; // socket发送失败
    const TAF_SOCKET_RECEIVE_FAILED = -1004; // socket接收失败
    const TAF_SOCKET_SELECT_TIMEOUT = -1005; // socket的select超时，也可以认为是svr超时
    const TAF_SOCKET_TIMEOUT = -1006; // socket超时，一般是svr后台没回包，或者seq错误
    const TAF_SOCKET_CONNECT_FAILED = -1007; // socket tcp 连接失败
    const TAF_SOCKET_CLOSED = -1008; // socket tcp 服务端连接关闭
    const TAF_SOCKET_CREATE_FAILED = -1070;


    const TAF_PUT_STRUCT_FAILED = -9;
    const TAF_PUT_VECTOR_FAILED = -10;
    const TAF_PUT_INT64_FAILED = -11;
    const TAF_PUT_INT32_FAILED = -12;
    const TAF_PUT_STRING_FAILED = -13;
    const TAF_PUT_MAP_FAILED = -14;
    const TAF_PUT_BOOL_FAILED = -15;
    const TAF_PUT_FLOAT_FAILED = -16;
    const TAF_PUT_CHAR_FAILED = -17;
    const TAF_PUT_UINT8_FAILED = -18;
    const TAF_PUT_SHORT_FAILED = -19;
    const TAF_PUT_UINT16_FAILED = -20;
    const TAF_PUT_UINT32_FAILED = -21;
    const TAF_PUT_DOUBLE_FAILED = -22;


    const TAF_ENCODE_FAILED = -25;
    const TAF_DECODE_FAILED = -26;
    const TAF_GET_INT64_FAILED = -31;
    const TAF_GET_MAP_FAILED = -32;
    const TAF_GET_STRUCT_FAILED = -33;
    const TAF_GET_STRING_FAILED = -34;
    const TAF_GET_VECTOR_FAILED = -35;
    const TAF_GET_INT32_FAILED = -36;
    const TAF_GET_BOOL_FAILED = -37;
    const TAF_GET_CHAR_FAILED = -38;
    const TAF_GET_UINT8_FAILED = -39;
    const TAF_GET_SHORT_FAILED = -40;
    const TAF_GET_UINT16_FAILED = -41;
    const TAF_GET_UINT32_FAILED = -42;
    const TAF_GET_DOUBLE_FAILED = -43;
    const TAF_GET_FLOAT_FAILED = -44;

    // taf服务端可能返回的错误码
    const JCESERVERSUCCESS       = 0; //服务器端处理成功
    const JCESERVERDECODEERR     = -1; //服务器端解码异常
    const JCESERVERENCODEERR     = -2; //服务器端编码异常
    const JCESERVERNOFUNCERR     = -3; //服务器端没有该函数
    const JCESERVERNOSERVANTERR = -4;//服务器端五该Servant对象
    const JCESERVERRESETGRID     = -5; //服务器端灰度状态不一致
    const JCESERVERQUEUETIMEOUT = -6; //服务器队列超过限制
    const JCEASYNCCALLTIMEOUT    = -7; //异步调用超时
    const JCEPROXYCONNECTERR     = -8; //proxy链接异常
    const JCESERVERUNKNOWNERR    = -99; //服务器端未知异常



    private $tafRequestBuf;
    private $tafResponseBuf;
    private $tafDecodeData;
    private $sIp;
    private $iPort;
    private $iVersion;
    private $socketMode;

    private $servantName;
    private $funcName;

    private $encodeBufs = array();

    private static $iRequestId = 1;

    public $_callerName = 'qdPcSite';

    public $cPacketType=0;
    public $iMessageType=0;
    public $iTimeout=2;
    public $contexts=[];
    public $statuses=[];


    /**
     * 构造函数
     */
    public function __construct($callerName='qdPcSite')
    {
        $this->_callerName = $callerName;
    }

    public function setRequest($servantName,$funcName,$ip="", $port=0,$mode=self::SOCKET_MODE_TCP,
        $iVersion=3,$cPacketType=0,$iMessageType=0,$iTimeout=2,$contexts=[],$statuses=[])
    {
        if (empty($ip)) {
            $ret = $this->getRouteSwooleTable($servantName);
            if ($ret['code'] != self::TAF_SUCCESS) {
                $this->sIp = "";
            } else {
                $this->sIp = $ret['data']['sIp'];
                $this->iPort = $ret['data']['iPort'];

                if(isset($ret['data']['bTcp'])) {
                    if($ret['data']['bTcp'])
                        $this->socketMode = self::SOCKET_MODE_TCP;
                    else $this->socketMode = self::SOCKET_MODE_UDP;
                }
                else {
                    $this->socketMode = self::SOCKET_MODE_TCP;
                }
            }

        } else {
            $this->sIp = $ip;
            $this->iPort = $port;
        }

        $this->servantName = $servantName;
        $this->funcName = $funcName;
        $this->iVersion = $iVersion;
        $this->socketMode = $mode;
        $this->iTimeout = $iTimeout;

        if ($cPacketType) {
            $this->cPacketType = $cPacketType;
        }
        if ($iMessageType) {
            $this->iMessageType = $iMessageType;
        }
        if (!empty($contexts)) {
            $this->contexts = $contexts;
        }
        if (!empty($statuses)) {
            $this->statuses = $statuses;
        }
    }


    /**
     * @param $sObj
     * @desc
     */
    private function getRouteSwooleTable($sObj) {
        $obj = WsdMonitor::startActive("qdPcSite", "swoole", "", "sobj:".$sObj);

        $swooleTableIns = LocalSwooleTable::getInstance();
        $routeInfo = $swooleTableIns->get($sObj);

        // 如果swoole table中有命中,那么需要看看时间
        if($routeInfo) {

            $timestamp = $routeInfo['timestamp'];
            // 过了60s之后,重新拉取
            if((time() - $timestamp) > 60) {
                $ret = $this->getRouteByAgent($sObj);

                if($ret['code'] == self::TAF_SUCCESS && isset($ret['data']['sIp'])) {
                    $routeInfo['timestamp'] = time();
                    $routeInfo['ip'] = $ret['data']['sIp'];
                    $routeInfo['port'] = intval($ret['data']['iPort']);
                    $routeInfo['bTcp'] = intval($ret['data']['bTcp']);

                    $flag = $swooleTableIns->set($sObj,$routeInfo);
                    if(!$flag) {
                        $obj->endTiming(LocalSwooleTable::SWOOLE_TABLE_SET_FAILED);
                    }
                    else $obj->endTiming(0);

                    return $ret;
                }
                else {
                    $obj->endTiming(self::ROUTE_FAIL);
                    return [
                        'code' => self::TAF_SUCCESS,
                        'data' => [
                            'sIp' => $routeInfo['ip'],
                            'iPort' => $routeInfo['port'],
                            'bTcp' => $routeInfo['bTcp']
                        ]
                    ];
                }
            }
            // 未过期,直接使用缓存的数据
            else {
                $obj->endTiming(0);
                return [
                    'code' => self::TAF_SUCCESS,
                    'data' => [
                        'sIp' => $routeInfo['ip'],
                        'iPort' => $routeInfo['port'],
                        'bTcp' => $routeInfo['bTcp']
                    ]
                ];
            }
        }
        // 如果swoole table中没有命中,需要从agent拉取
        else {
            $ret = $this->getRouteByAgent($sObj);

            if($ret['code'] == self::TAF_SUCCESS && isset($ret['data']['sIp'])) {
                $routeInfo['timestamp'] = time();
                $routeInfo['ip'] = $ret['data']['sIp'];
                $routeInfo['port'] = intval($ret['data']['iPort']);
                $routeInfo['bTcp'] = intval($ret['data']['bTcp']);

                $flag = $swooleTableIns->set($sObj,$routeInfo);
                if(!$flag) {
                    $obj->endTiming(LocalSwooleTable::SWOOLE_TABLE_SET_FAILED);
                }
                else $obj->endTiming(0);

                return $ret;
            }
            else {
                $obj->endTiming(self::ROUTE_FAIL);
                return [
                    'code' => self::ROUTE_FAIL,
                ];
            }
        }
    }

    /**
     * 从agent获取主控
     */
    private function getRouteByAgent($sObj)
    {
        $agentRouterRequest = new AgentRouterRequest();

        $agentRouterRequest->sObj = $sObj;
        $agentRouterRequest->type = 0;

        $iVersion = 3;
        $iRequestId = self::$iRequestId;
        $servantName = "taf.tafagent.RouterNewObj";
        $funcName = "getRouterNodes";

        $obj = WsdMonitor::startActive($this->_callerName, $servantName, "127.0.0.18865", $funcName);


        $structBuffer = \Taf\PHPTAF::putStruct_v2("req", $agentRouterRequest);

        $inbuf_arr = [
            'req' => $structBuffer
        ];

        $this->tafRequestBuf = \Taf\PHPTAF::encode_v2($iVersion, $iRequestId, $servantName, $funcName,
            $this->cPacketType,$this->iMessageType,1,$this->contexts,$this->statuses,
            $inbuf_arr);

        $this->sIp = "127.0.0.1";
        $this->iPort = "8865";
        $this->servantName = $servantName;
        $this->funcName = $funcName;
        $ret = $this->udpSocket(5);
        if ($ret['code'] != self::TAF_SUCCESS) {
            $obj->endTiming($ret, WsdMonitor::RESULT_FAILED);
            return array(
                'code' => $ret
            );
        }

        // 解包
        $agentRouterResponse = new AgentRouterResponse;
        $decodeData = \Taf\PHPTAF::decode_v2($this->tafResponseBuf);
        if ($decodeData['status'] != self::TAF_SUCCESS) {
            $obj->endTiming($decodeData['status'], WsdMonitor::RESULT_FAILED);
            return array(
                'code' => $decodeData['status']
            );
        }

        $respArr = \Taf\PHPTAF::getStruct_v2("rsp", $agentRouterResponse, $decodeData['buf']);

        // 选合适的ip和port todo
        if(empty($respArr['vResult'])) {
            return array(
                'code' => self::ROUTE_FAIL
            );
        }
        $count = count($respArr['vResult'])-1;
        $index = rand(0,$count);

        $route = $respArr['vResult'][$index];

        $obj->endTiming(self::TAF_SUCCESS);

        return array(
            'code' => self::TAF_SUCCESS,
            'data' => $route
        );
    }


    public function setEncodeBufs($encodeBufs)
    {
        $this->encodeBufs = $encodeBufs;
    }

    public function getTafDecodeData() {
        return $this->tafDecodeData;
    }

    public function putBool($paramName,$bool) {
        try {
            $boolBuffer = \Taf\PHPTAF::putBool_v2($paramName,$bool);
            if(!is_string($boolBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_BOOL_FAILED), self::TAF_PUT_BOOL_FAILED);
            }
            $this->encodeBufs[$paramName] = $boolBuffer;

            return  self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_BOOL_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }
    public function getBool($name) {
        try {
            $result = \Taf\PHPTAF::getBool_v2($name,$this->tafDecodeData);

            return $result;

        } catch (\Exception $e) {
            $code = self::TAF_GET_BOOL_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function putChar($paramName,$char) {
        try {
            $charBuffer = \Taf\PHPTAF::putChar_v2($paramName,$char);
            if(!is_string($charBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_CHAR_FAILED), self::TAF_PUT_CHAR_FAILED);
            }
            $this->encodeBufs[$paramName] = $charBuffer;

            return  self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_CHAR_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getChar($name) {
        try {
            $result = \Taf\PHPTAF::getChar_v2($name,$this->tafDecodeData);

            return  $result;

        } catch (\Exception $e) {
            $code = self::TAF_GET_CHAR_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function putUInt8($paramName,$uint8) {
        try {
            $uint8Buffer = \Taf\PHPTAF::putUint8_v2($paramName,$uint8);
            if(!is_string($uint8Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_UINT8_FAILED), self::TAF_PUT_UINT8_FAILED);
            }
            $this->encodeBufs[$paramName] = $uint8Buffer;

            return self::TAF_SUCCESS;

        } catch (\Exception $e) {
            $code = self::TAF_PUT_UINT8_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getUint8($name) {
        try {
            $result = \Taf\PHPTAF::getUint8_v2($name,$this->tafDecodeData);

            return  $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_UINT8_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function putShort($paramName,$short) {
        try {
            $shortBuffer = \Taf\PHPTAF::putShort_v2($paramName,$short);
            if(!is_string($shortBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_SHORT_FAILED), self::TAF_PUT_SHORT_FAILED);
            }
            $this->encodeBufs[$paramName] = $shortBuffer;

            return  self::TAF_SUCCESS;

        } catch (\Exception $e) {
            $code = self::TAF_PUT_SHORT_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }

    public function getShort($name) {
        try {
            $result = \Taf\PHPTAF::getShort_v2($name,$this->tafDecodeData);

            return $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_SHORT_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function putUInt16($paramName,$uint16) {
        try {
            $uint16Buffer = \Taf\PHPTAF::putUint16_v2($paramName,$uint16);
            if(!is_string($uint16Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_UINT16_FAILED), self::TAF_PUT_UINT16_FAILED);
            }
            $this->encodeBufs[$paramName] = $uint16Buffer;

            return self::TAF_SUCCESS;

        } catch (\Exception $e) {
            $code = self::TAF_PUT_UINT16_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getUint16($name) {
        try {
            $result = \Taf\PHPTAF::getUint16_v2($name,$this->tafDecodeData);

            return  $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_UINT16_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function putInt32($paramName,$int) {
        try {
            $int32Buffer = \Taf\PHPTAF::putInt32_v2($paramName,$int);
            if(!is_string($int32Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_INT32_FAILED), self::TAF_PUT_INT32_FAILED);
            }
            $this->encodeBufs[$paramName] = $int32Buffer;

            return  self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_INT32_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getInt32($name) {
        try {
            $result = \Taf\PHPTAF::getInt32_v2($name,$this->tafDecodeData);
            return $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_INT32_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }

    public function putUint32($paramName,$uint) {
        try {
            $uint32Buffer = \Taf\PHPTAF::putInt32_v2($paramName,$uint);
            if(!is_string($uint32Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_UINT32_FAILED), self::TAF_PUT_UINT32_FAILED);
            }
            $this->encodeBufs[$paramName] = $uint32Buffer;

            return self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_UINT32_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }


    public function getUint32($name) {
        try {
            $result = \Taf\PHPTAF::getUint32_v2($name,$this->tafDecodeData);
            return  $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_UINT32_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function putInt64($paramName,$bigint) {
        try {
            $int64Buffer = \Taf\PHPTAF::putInt64_v2($paramName,$bigint);
            if(!is_string($int64Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_INT64_FAILED), self::TAF_PUT_INT64_FAILED);
            }
            $this->encodeBufs[$paramName] = $int64Buffer;
            return self::TAF_SUCCESS;

        } catch (\Exception $e) {
            $code = self::TAF_PUT_INT64_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getInt64($name) {
        try {
            $result = \Taf\PHPTAF::getInt64_v2($name,$this->tafDecodeData);

            return  $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_INT64_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }

    public function putDouble($paramName,$double) {
        try {
            $doubleBuffer = \Taf\PHPTAF::putDouble_v2($paramName,$double);
            if(!is_string($doubleBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_DOUBLE_FAILED), self::TAF_PUT_DOUBLE_FAILED);
            }
            $this->encodeBufs[$paramName] = $doubleBuffer;
            return  self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_DOUBLE_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }

    public function getDouble($name) {
        try {
            $result = \Taf\PHPTAF::getDouble_v2($name,$this->tafDecodeData);

            return  $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_DOUBLE_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }


    }

    public function putFloat($paramName,$float) {
        try {
            $floatBuffer = \Taf\PHPTAF::putFloat_v2($paramName,$float);
            if(!is_string($floatBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_FLOAT_FAILED), self::TAF_PUT_FLOAT_FAILED);
            }
            $this->encodeBufs[$paramName] = $floatBuffer;
            return  self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_FLOAT_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getFloat($name) {
        try {
            $result = \Taf\PHPTAF::getFloat_v2($name,$this->tafDecodeData);

            return $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_FLOAT_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }

    public function putString($paramName,$string) {
        try {
            $stringBuffer = \Taf\PHPTAF::putString_v2($paramName,$string);
            if(!is_string($stringBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_STRING_FAILED), self::TAF_PUT_STRING_FAILED);
            }
            $this->encodeBufs[$paramName] = $stringBuffer;
            return self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_STRING_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getString($name) {
        try {
            $result = \Taf\PHPTAF::getString_v2($name,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_STRING_FAILED), self::TAF_GET_STRING_FAILED);
            }
            return  $result;

        } catch (\Exception $e) {
            $code = self::TAF_GET_STRING_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function putVector($paramName,$vec) {
        try {
            $vecBuffer = \Taf\PHPTAF::putVector_v2($paramName,$vec);
            if(!is_string($vecBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_VECTOR_FAILED), self::TAF_PUT_VECTOR_FAILED);
            }
            $this->encodeBufs[$paramName] = $vecBuffer;
            return self::TAF_SUCCESS;

        } catch (\Exception $e) {
            $code = self::TAF_PUT_VECTOR_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getVector($name,$vec) {
        try {
            $result = \Taf\PHPTAF::getVector_v2($name,$vec,$this->tafDecodeData);

            return  $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_VECTOR_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }


    public function putMap($paramName,$map) {
        try {
            $mapBuffer = \Taf\PHPTAF::putMap_v2($paramName,$map);
            if(!is_string($mapBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_MAP_FAILED), self::TAF_PUT_MAP_FAILED);
            }
            $this->encodeBufs[$paramName] = $mapBuffer;

            return self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_MAP_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getMap($name,$obj) {
        try {
            $result = \Taf\PHPTAF::getMap_v2($name,$obj,$this->tafDecodeData);
            if(!is_array($result)) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_MAP_FAILED), self::TAF_GET_MAP_FAILED);
            }
            return  $result;
        } catch (\Exception $e) {
            $code = self::TAF_GET_MAP_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }

    public function putStruct($paramName,$obj) {
        try {
            $structBuffer = \Taf\PHPTAF::putStruct_v2($paramName,$obj);
            if(!is_string($structBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_STRUCT_FAILED), self::TAF_PUT_STRUCT_FAILED);
            }
            $this->encodeBufs[$paramName] = $structBuffer;
            return self::TAF_SUCCESS;
        } catch (\Exception $e) {
            $code = self::TAF_PUT_STRUCT_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }

    }

    public function getStruct($name,&$obj) {
        try {

            $result = \Taf\PHPTAF::getStruct_v2($name,$obj,$this->tafDecodeData);

            if(!is_array($result)) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_STRUCT_FAILED), self::TAF_GET_STRUCT_FAILED);
            }
            $this->fromArray($result,$obj);

            return $result;

        } catch (\Exception $e) {
            $code = self::TAF_GET_STRUCT_FAILED;
            throw new \Exception(self::getErrMsg($code), $code);
        }
    }

    public function sendAndReceive($timeout=3) {
        // 首先尝试encode
        try {

            // 增加本地开发的代理层
            if(APP_ENV === 'local') {

                $hc = new \tsf\client\Coroutine\Http("http://proxy.qidian.com/taf/proxy2");
                $hc->setConfig([
                    'timeout' => 10
                ]);
                $proxyData['iVersion'] = $this->iVersion;
                $proxyData['iRequestId'] = self::$iRequestId;
                $proxyData['servantName'] = $this->servantName;
                $proxyData['funcName'] = $this->funcName;
                $proxyData['encodeBufs'] = $this->encodeBufs;
                $proxyData['socketMode'] = $this->socketMode;
                $proxyData['ip'] = $this->sIp;
                $proxyData['iPort'] = $this->iPort;

                $this->encodeBufs = [];

                $header = array(
                    'User-Agent' => 'tsf2.0-agent',
                    'Accept' => 'application/json'
                );

                $res = $hc->post($proxyData, $header);

                $this->tafDecodeData = $res['data']['body'];

            }else{

                $this->tafRequestBuf = \Taf\PHPTAF::encode_v2($this->iVersion,self::$iRequestId,$this->servantName,
                    $this->funcName,$this->cPacketType,$this->iMessageType,$timeout,$this->contexts,$this->statuses,$this->encodeBufs);
                $this->encodeBufs = [];
                if(!is_string($this->tafRequestBuf)) {
                    $code = self::TAF_ENCODE_FAILED;
                    throw new \Exception(self::getErrMsg($code), $code);
                }

                $ret = self::TAF_SUCCESS;

                if ($this->socketMode === self::SOCKET_MODE_UDP) {
                    $ret = $this->udpSocket($timeout);
                } else if($this->socketMode === self::SOCKET_MODE_TCP) {
                    $ret = $this->tcpAs($timeout);
                }

                // 收发包失败了
                if ($ret !== self::TAF_SUCCESS) {
                    throw new \Exception(self::getErrMsg($ret), $ret);
                }

                // 其次尝试decode
                try {
                    $decodeRet = \Taf\PHPTAF::decode_v2($this->tafResponseBuf);
                    if($decodeRet['status'] !== self::TAF_SUCCESS) {
                        $msg = self::getErrMsg($decodeRet['status']);
                        throw new \Exception($msg, $decodeRet['status']);
                    }
                    $this->tafDecodeData = $decodeRet['buf'];

                    return array('code' => self::TAF_SUCCESS);
                }
                catch (\Exception $e) {
                    throw new \Exception($e->getMessage(), $e->getCode());
                }

            }
            return array('code' => self::TAF_SUCCESS);

        } catch (\Exception $e) {
            throw new \Exception($e->getMessage(), $e->getCode());
        }
        self::$iRequestId++;

    }

    private static function getErrMsg($code) {
        $errMap = [
            self::JCESERVERSUCCESS => '服务器端处理成功',
            self::JCESERVERDECODEERR => '服务器端解码异常',
            self::JCESERVERENCODEERR => '服务器端编码异常',
            self::JCESERVERNOFUNCERR => '服务器端没有该函数',
            self::JCESERVERNOSERVANTERR => '服务器端无该Servant对象',
            self::JCESERVERRESETGRID => '服务器端灰度状态不一致',
            self::JCESERVERQUEUETIMEOUT => '服务器队列超过限制',
            self::JCEASYNCCALLTIMEOUT => '异步调用超时',
            self::JCEPROXYCONNECTERR => 'proxy链接异常',
            self::JCESERVERUNKNOWNERR => '服务器端未知异常',
            self::ROUTE_FAIL => '路由失败，请检查环境是否匹配，agent是否配置正确',
            self::TAF_PUT_BOOL_FAILED => 'bool类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_STRUCT_FAILED => 'struct类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_VECTOR_FAILED => 'vector类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_INT64_FAILED => 'int64类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_INT32_FAILED => 'int32类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_STRING_FAILED => 'sting类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_MAP_FAILED => 'map类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_FLOAT_FAILED => 'float类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_CHAR_FAILED => 'char类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_UINT8_FAILED => 'uint8类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_SHORT_FAILED => 'uint8类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_UINT16_FAILED => 'uint8类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_UINT32_FAILED => 'uint8类型打包失败，请检查是否传入了正确值',
            self::TAF_PUT_DOUBLE_FAILED => 'uint8类型打包失败，请检查是否传入了正确值',


            self::TAF_ENCODE_FAILED => 'taf编码失败，请检查数据类型，传入字段长度',
            self::TAF_DECODE_FAILED => 'taf解码失败，请检查传入的数据类型，是否从服务端接收到了正确的结果',

            self::TAF_GET_BOOL_FAILED => 'bool类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_STRUCT_FAILED => 'struct类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_VECTOR_FAILED => 'vector类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_INT64_FAILED => 'int64类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_INT32_FAILED => 'int32类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_STRING_FAILED => 'sting类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_MAP_FAILED => 'map类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_FLOAT_FAILED => 'float类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_CHAR_FAILED => 'char类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_UINT8_FAILED => 'uint8类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_SHORT_FAILED => 'uint8类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_UINT16_FAILED => 'uint8类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_UINT32_FAILED => 'uint8类型解包失败，请检查是否传入了正确值',
            self::TAF_GET_DOUBLE_FAILED => 'uint8类型解包失败，请检查是否传入了正确值',

            self::TAF_SOCKET_SET_NONBLOCK_FAILED => 'socket设置非阻塞失败',
            self::TAF_SOCKET_SEND_FAILED => 'socket发送失败',
            self::TAF_SOCKET_RECEIVE_FAILED => 'socket接收失败',
            self::TAF_SOCKET_SELECT_TIMEOUT => 'socket的select超时，也可以认为是svr超时',
            self::TAF_SOCKET_TIMEOUT => 'socket超时，一般是svr后台没回包，或者seq错误',
            self::TAF_SOCKET_CONNECT_FAILED => 'socket tcp 连接失败',
            self::TAF_SOCKET_CLOSED => 'socket tcp 服务端连接关闭',
            self::TAF_SOCKET_CREATE_FAILED => 'socket 创建失败'
        ];

        return isset($errMap[$code])?$errMap[$code]:'未定义异常';
    }

    /**
     *  udp收發包
     * @param $timeout
     * @return int
     */
    private function udpSocket($timeout)
    {
        $obj = WsdMonitor::startActive($this->_callerName, $this->servantName, $this->sIp . $this->iPort, $this->funcName);

        $client = new \tsf\client\Coroutine\Udp($this->sIp, $this->iPort, $this->tafRequestBuf, $timeout);
        $res = $client->send();
        if ($res['r'] === self::TAF_SUCCESS) {
            $obj->endTiming($res['r']);
            $this->tafResponseBuf = $res['data'];
            return self::TAF_SUCCESS;
        } else {
            $obj->endTiming($res['r'], WsdMonitor::RESULT_FAILED);
            return self::TAF_SOCKET_RECEIVE_FAILED;
        }
    }

    /**
     * tcp收發包
     * @param $timeout
     * @return int
     */
    private function tcpAs($timeout)
    {
        $obj = WsdMonitor::startActive($this->_callerName, $this->servantName, $this->sIp . $this->iPort, $this->funcName);

        if(empty($this->sIp) || empty($this->iPort)) {
            $obj->endTiming(self::ROUTE_FAIL, WsdMonitor::RESULT_FAILED);
            return  self::ROUTE_FAIL;
        }
        $client = new \tsf\client\Coroutine\Tcp($this->sIp, $this->iPort, $this->tafRequestBuf, $timeout);
        $res = $client->send();
        if ($res['r'] === self::TAF_SUCCESS) {
            $obj->endTiming($res['r']);
            $this->tafResponseBuf = $res['data'];
            return  self::TAF_SUCCESS;
        } else {
            $obj->endTiming($res['r'], WsdMonitor::RESULT_FAILED);
            return self::TAF_SOCKET_RECEIVE_FAILED;
        }
    }

    // 将数组转换成对象
    public function fromArray($data,&$structObj)
    {
        if(!empty($data)) {
            foreach ($data as $key => $value) {
                if (method_exists($structObj, 'set' . ucfirst($key))){
                    call_user_func_array([$this, 'set' . ucfirst($key)], [$value]);
                } else if ($structObj->$key instanceOf \Taf\TJCE_Struct) {
                    $this->fromArray($value,$structObj->$key);
                } else {
                    $structObj->$key = $value;
                }
            }
        }
    }

}
