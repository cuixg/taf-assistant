<?php

namespace Taf;

use Taf\WsdMonitor;

class TafAssistantAs

{

    const SOCKET_MODE_UDP = 1;
    const SOCKET_MODE_TCP = 2;
    const SOCKET_TCP_MAX_PCK_SIZE = 65536; /* 64*1024 */

    // 错误码定义（需要从扩展开始规划）
    const TAF_SUCCESS = 0; // taf
    const TAF_FAILED = 1; // taf失败（通用失败）
    const TAF_MALLOC_FAILED = -1; // 内存分配失败

    const ROUTE_FAIL = -100;


    const TAF_SOCKET_SET_NONBLOCK_FAILED = -2; // socket设置非阻塞失败
    const TAF_SOCKET_SEND_FAILED = -3; // socket发送失败
    const TAF_SOCKET_RECEIVE_FAILED = -4; // socket接收失败
    const TAF_SOCKET_SELECT_TIMEOUT = -5; // socket的select超时，也可以认为是svr超时
    const TAF_SOCKET_TIMEOUT = -6; // socket超时，一般是svr后台没回包，或者seq错误
    const TAF_SOCKET_CONNECT_FAILED = -7; // socket tcp 连接失败
    const TAF_SOCKET_CLOSED = -8; // socket tcp 服务端连接关闭

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


    /**
     * 构造函数
     */
    public function __construct($callerName='qdPcSite')
    {
        $this->_callerName = $callerName;
    }

    public function setRequest($servantName, $funcName, $ip = "", $port = 0, $mode = self::SOCKET_MODE_TCP, $iVersion = 3)
    {
        if (empty($ip)) {
            $ret = (yield $this->getRouteSwooleTable($servantName));
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
        $this->iVersion = $iVersion;
        $this->socketMode = $mode;
        $this->servantName = $servantName;
        $this->funcName = $funcName;
    }

    /*
    向agent上报调用服务的情况 todo
     */
    public function updateServiceToAgent()
    {

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
                $ret = (yield $this->getRouteByAgent($sObj));


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

                    yield $ret;
                }
                else {
                    $obj->endTiming(self::ROUTE_FAIL);
                    yield [
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
                yield [
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
            $ret = (yield $this->getRouteByAgent($sObj));


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

                yield $ret;
            }
            else {
                $obj->endTiming(self::ROUTE_FAIL);
                yield [
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

            $arr = array(
                'type' => 0,
                'sObj' => $sObj
            );

            $iVersion = 3;
            $iRequestId = self::$iRequestId;
            $servantName = "taf.tafagent.RouterNewObj";
            $funcName = "getRouterNodes";


            $structBuffer = \TWUP::putStruct("req", $agentRouterRequest, $arr);

            $inbuf_arr = [
                'req' => $structBuffer
            ];

            $this->tafRequestBuf = \TWUP::encode($iVersion, $iRequestId, $servantName, $funcName, $inbuf_arr);

            $this->sIp = "127.0.0.1";
            $this->iPort = "8865";
            $this->servantName = $servantName;
            $this->funcName = $funcName;
            $ret = (yield $this->udpSocket(5));
            if ($ret['code'] != self::TAF_SUCCESS) {
                yield array(
                    'code' => $ret
                );
            }

            // 解包
            $agentRouterResponse = new AgentRouterResponse;
            $decodeData = \TWUP::decode($this->tafResponseBuf);
            if ($decodeData['code'] != self::TAF_SUCCESS) {
                yield array(
                    'code' => $decodeData['code']
                );
            }

            $respArr = \TWUP::getStruct("rsp", $agentRouterResponse, $decodeData['buf']);

            // 选合适的ip和port todo
            if(empty($respArr['vResult'])) {
                yield array(
                    'code' => self::ROUTE_FAIL
                );
            }
            $count = count($respArr['vResult'])-1;
            $index = rand(0,$count);

            $route = $respArr['vResult'][$index];
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $route
            );
    }

    public function setEncodeBufs($encodeBufs)
    {
        $this->encodeBufs = $encodeBufs;
    }

    public function getTafDecodeData() {
        yield $this->tafDecodeData;
    }

    public function putBool($paramName,$bool) {
        try {
            $boolBuffer = \TWUP::putBool($paramName,$bool);
            if(!is_string($boolBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_BOOL_FAILED), self::TAF_PUT_BOOL_FAILED);
            }
            $this->encodeBufs[$paramName] = $boolBuffer;

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $boolBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_BOOL_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }
    public function getBool($name) {
        try {
            $result = \TWUP::getBool($name,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_BOOL_FAILED), self::TAF_GET_BOOL_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_BOOL_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function putChar($paramName,$char) {
        try {
            $charBuffer = \TWUP::putChar($paramName,$char);
            if(!is_string($charBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_CHAR_FAILED), self::TAF_PUT_CHAR_FAILED);
            }
            $this->encodeBufs[$paramName] = $charBuffer;

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $charBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_CHAR_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getChar($name) {
        try {
            $result = \TWUP::getChar($name,$this->tafDecodeData);
        if($result < 0) {
            throw new \Exception(self::getErrMsg(self::TAF_GET_CHAR_FAILED), self::TAF_GET_CHAR_FAILED);
        }
        yield array(
            'code' => self::TAF_SUCCESS,
            'data' => $result
        );
        } catch (\Exception $e) {
            $code = self::TAF_GET_CHAR_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function putUInt8($paramName,$uint8) {
        try {
            $uint8Buffer = \TWUP::putUint8($paramName,$uint8);
        if(!is_string($uint8Buffer)) {
            throw new \Exception(self::getErrMsg(self::TAF_PUT_UINT8_FAILED), self::TAF_PUT_UINT8_FAILED);
        }
        $this->encodeBufs[$paramName] = $uint8Buffer;

        yield array(
            'code' => self::TAF_SUCCESS,
            'data' => $uint8Buffer
        );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_UINT8_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getUint8($name) {
        try {
            $result = \TWUP::getUint8($name,$this->tafDecodeData);
        if($result < 0) {
            throw new \Exception(self::getErrMsg(self::TAF_GET_UINT8_FAILED), self::TAF_GET_UINT8_FAILED);
        }
        yield array(
            'code' => self::TAF_SUCCESS,
            'data' => $result
        );
        } catch (\Exception $e) {
            $code = self::TAF_GET_UINT8_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function putShort($paramName,$short) {
        try {
            $shortBuffer = \TWUP::putShort($paramName,$short);
            if(!is_string($shortBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_SHORT_FAILED), self::TAF_PUT_SHORT_FAILED);
            }
            $this->encodeBufs[$paramName] = $shortBuffer;

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $shortBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_SHORT_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }
    }

    public function getShort($name) {
        try {
            $result = \TWUP::getShort($name,$this->tafDecodeData);
        if($result < 0) {
            throw new \Exception(self::getErrMsg(self::TAF_GET_SHORT_FAILED), self::TAF_GET_SHORT_FAILED);
        }
        yield array(
            'code' => self::TAF_SUCCESS,
            'data' => $result
        );
        } catch (\Exception $e) {
            $code = self::TAF_GET_SHORT_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function putUInt16($paramName,$uint16) {
        try {
            $uint16Buffer = \TWUP::putUint16($paramName,$uint16);
            if(!is_string($uint16Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_UINT16_FAILED), self::TAF_PUT_UINT16_FAILED);
            }
            $this->encodeBufs[$paramName] = $uint16Buffer;

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $uint16Buffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_UINT16_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getUint16($name) {
        try {
            $result = \TWUP::getUint16($name,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_UINT16_FAILED), self::TAF_GET_UINT16_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_UINT16_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function putInt32($paramName,$int) {
        try {
            $int32Buffer = \TWUP::putInt32($paramName,$int);
        if(!is_string($int32Buffer)) {
            throw new \Exception(self::getErrMsg(self::TAF_PUT_INT32_FAILED), self::TAF_PUT_INT32_FAILED);
        }
        $this->encodeBufs[$paramName] = $int32Buffer;

        yield array(
            'code' => self::TAF_SUCCESS,
            'data' => $int32Buffer
        );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_INT32_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getInt32($name) {
        try {
            $result = \TWUP::getInt32($name,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_INT32_FAILED), self::TAF_GET_INT32_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_INT32_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function putUint32($paramName,$uint) {
        try {
            $uint32Buffer = \TWUP::putInt32($paramName,$uint);
            if(!is_string($uint32Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_UINT32_FAILED), self::TAF_PUT_UINT32_FAILED);
            }
            $this->encodeBufs[$paramName] = $uint32Buffer;

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $uint32Buffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_UINT32_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }
    }


    public function getUint32($name) {
        try {
            $result = \TWUP::getUint32($name,$this->tafDecodeData);

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_UINT32_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function putInt64($paramName,$bigint) {
        try {
            $int64Buffer = \TWUP::putInt64($paramName,$bigint);
            if(!is_string($int64Buffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_INT64_FAILED), self::TAF_PUT_INT64_FAILED);
            }
            $this->encodeBufs[$paramName] = $int64Buffer;
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $int64Buffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_INT64_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getInt64($name) {
        try {
            $result = \TWUP::getInt64($name,$this->tafDecodeData);

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_INT64_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }
    }

    public function putDouble($paramName,$double) {
        try {
            $doubleBuffer = \TWUP::putDouble($paramName,$double);
            if(!is_string($doubleBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_DOUBLE_FAILED), self::TAF_PUT_DOUBLE_FAILED);
            }
            $this->encodeBufs[$paramName] = $doubleBuffer;
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $doubleBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_DOUBLE_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }
    }

    public function getDouble($name) {
        try {
            $result = \TWUP::getDouble($name,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_DOUBLE_FAILED), self::TAF_GET_DOUBLE_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_DOUBLE_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }


    }

    public function putFloat($paramName,$float) {
        try {
            $floatBuffer = \TWUP::putFloat($paramName,$float);
            if(!is_string($floatBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_FLOAT_FAILED), self::TAF_PUT_FLOAT_FAILED);
            }
            $this->encodeBufs[$paramName] = $floatBuffer;
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $floatBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_FLOAT_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getFloat($name) {
        try {
            $result = \TWUP::getFloat($name,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_FLOAT_FAILED), self::TAF_GET_FLOAT_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_FLOAT_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }
    }

    public function putString($paramName,$string) {
        try {
            $stringBuffer = \TWUP::putString($paramName,$string);
            if(!is_string($stringBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_STRING_FAILED), self::TAF_PUT_STRING_FAILED);
            }
            $this->encodeBufs[$paramName] = $stringBuffer;
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $stringBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_STRING_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getString($name) {
        try {
            $result = \TWUP::getString($name,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_STRING_FAILED), self::TAF_GET_STRING_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_STRING_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

      public function putVector($paramName,$vec) {
        try {
            $vecBuffer = \TWUP::putVector($paramName,$vec);
            if(!is_string($vecBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_VECTOR_FAILED), self::TAF_PUT_VECTOR_FAILED);
            }
            $this->encodeBufs[$paramName] = $vecBuffer;
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $vecBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_VECTOR_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getVector($name,$vec) {
        try {
            $result = \TWUP::getVector($name,$vec,$this->tafDecodeData);
            if($result < 0) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_VECTOR_FAILED), self::TAF_GET_VECTOR_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_VECTOR_FAILED;
                throw new \Exception($e->getMessage(), $code);
        }

    }


    public function putMap($paramName,$map) {
        try {
            $mapBuffer = \TWUP::putMap($paramName,$map);
            if(!is_string($mapBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_MAP_FAILED), self::TAF_PUT_MAP_FAILED);
            }
            $this->encodeBufs[$paramName] = $mapBuffer;

            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $mapBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_MAP_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }

    public function getMap($name,$obj) {
        try {
            $result = \TWUP::getMap($name,$obj,$this->tafDecodeData);
            if(!is_array($result)) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_MAP_FAILED), self::TAF_GET_MAP_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_MAP_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }
    }

    public function putStruct($paramName,$obj,$objVal) {
        try {
            $structBuffer = \TWUP::putStruct($paramName,$obj,$objVal);
            if(!is_string($structBuffer)) {
                throw new \Exception(self::getErrMsg(self::TAF_PUT_STRUCT_FAILED), self::TAF_PUT_STRUCT_FAILED);
            }
            $this->encodeBufs[$paramName] = $structBuffer;
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $structBuffer
            );
        } catch (\Exception $e) {
            $code = self::TAF_PUT_STRUCT_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }

    }


    public function getStruct($name,$obj) {
        try {
            $result = \TWUP::getStruct($name,$obj,$this->tafDecodeData);
            if(!is_array($result)) {
                throw new \Exception(self::getErrMsg(self::TAF_GET_STRUCT_FAILED), self::TAF_GET_STRUCT_FAILED);
            }
            yield array(
                'code' => self::TAF_SUCCESS,
                'data' => $result
            );
        } catch (\Exception $e) {
            $code = self::TAF_GET_STRUCT_FAILED;
            throw new \Exception($e->getMessage(), $code);
        }
    }

    public function getRetCode() {

    }


    public function sendAndReceiveAs($timeout = 2)
    {
        try {
            $this->tafRequestBuf = \TWUP::encode($this->iVersion, self::$iRequestId, $this->servantName, $this->funcName, $this->encodeBufs);

            if (!is_string($this->tafRequestBuf)) {
                yield array(
                    'code' => self::TAF_ENCODE_FAILED);
            }

            $this->encodeBufs = [];

            if ($this->socketMode === self::SOCKET_MODE_UDP) {
                $ret = (yield $this->udpSocket($timeout));
            } else if ($this->socketMode === self::SOCKET_MODE_TCP) {
                $ret = (yield $this->tcpAs($timeout));
            }
            else $ret['code'] = -1;

            if ($ret['code'] !== self::TAF_SUCCESS) {
                yield array('code' => $ret['code']);
            }

            self::$iRequestId++;
            $this->tafDecodeData = \TWUP::decode($ret['data']);

            if ($this->tafDecodeData['code'] !== self::TAF_SUCCESS) {
                $msg = self::getErrMsg($this->tafDecodeData['code']);
                yield array('code' => self::TAF_DECODE_FAILED,'msg' => $msg);
            }
            $this->tafDecodeData = $this->tafDecodeData['buf'];


            yield array('code' => self::TAF_SUCCESS);
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage(), self::TAF_FAILED);
        }
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

        ];

        return isset($errMap[$code])?$errMap[$code]:'未定义异常';
    }

    /**
     *  udp收發包
     * @param $timeout
     * @yield Generator
     */
    private function udpSocket($timeout)
    {
        $obj = WsdMonitor::startActive($this->_callerName, $this->servantName, $this->sIp . $this->iPort, $this->funcName);

        $res = (yield new \tsf\client\Udp($this->sIp, $this->iPort, $this->tafRequestBuf, $timeout));
        if ($res['r'] === self::TAF_SUCCESS) {
            $obj->endTiming($res['r']);
            $this->tafResponseBuf = $res['data'];
            yield array('code' => self::TAF_SUCCESS, 'data' => $res['data']);
        } else {
            $obj->endTiming($res['r'], WsdMonitor::RESULT_FAILED);
            yield array('code' => self::TAF_SOCKET_RECEIVE_FAILED);
        }
    }

    /**
     * tcp收發包
     * @param $timeout
     * @yield Generator
     */
    private function tcpAs($timeout)
    {
        $obj = WsdMonitor::startActive($this->_callerName, $this->servantName, $this->sIp . $this->iPort, $this->funcName);
        if(empty($this->sIp) || empty($this->iPort)) {
            $obj->endTiming(self::ROUTE_FAIL, WsdMonitor::RESULT_FAILED);
            yield array('code' => self::ROUTE_FAIL);
        }
        $res = (yield new \tsf\client\TcpAs($this->sIp, $this->iPort, $this->tafRequestBuf, $timeout));
        if ($res['r'] === self::TAF_SUCCESS) {
            $obj->endTiming($res['r']);
            $this->tafResponseBuf = $res['data'];
            yield array('code' => self::TAF_SUCCESS, 'data' => $res['data']);
        } else {
            $obj->endTiming($res['r'], WsdMonitor::RESULT_FAILED);
            yield array('code' => self::TAF_SOCKET_RECEIVE_FAILED);
        }
    }


}
