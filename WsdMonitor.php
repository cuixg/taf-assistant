<?php
/**
 * Created by PhpStorm.
 * User: ZhangYong
 * Date: 2016/4/11
 * Time: 18:18
 *
 * 米格上报用 php类库
 * @version 3.1
 */
namespace Taf;

class WsdMonitor
{
    CONST IS_TIME_OUT_TIME_OUT = 1;
    CONST IS_TIME_OUT_NORMAL   = 0;

    CONST RESULT_SUCCESS = 1;
    CONST RESULT_FAILED  = 0;

    /** @var array 默认配置 */
    protected static $defaultConfig = [
        //如果你申请的监控项为opp_mm_qdHomesite 这里只需要输入mm_qdHomesite，采用redis 方式上报的 redis key 为 mm_qdHomesite_key 中的 “_key” 会自动添加
        'opp_key' => '',// todo 需要修改
        'redis' => [
            'ip' => '0.0.0.0',// todo 如果用到redis,需要修改
            'port' => '6379', // todo
            'timeOut' => 1,
        ],
    ];

    /** @var string local server ip address */
    protected static $serverAddr = null;

    /** @var array 当前配置 */
    protected $config = [];

    /** @var null | \Redis redis 连接实例 */
    protected $redis = null;

    /** @var null | int 开始时间 */
    protected $startTime   = null;
    /** @var string 服务方ip，主动模式为调用的ip，被动模式为自己ip */
    protected $serviceIp   = false;
    /** @var string 服务方名字，主动模式为调用的名字，被动模式为自己名字 */
    protected $serviceName = '';
    /** @var string 接口名称 */
    protected $interface   = '';
    /** @var string 调用方ip，主动模式为自己的ip， 被动模式为来源ip */
    protected $callerIp    = false;
    /** @var string 调用方名字，主动模式为自己的名字， 被动模式为来源名字 */
    protected $callerName  = '';

    public static function setDefaultConfig($config)
    {
        self::$defaultConfig = array_merge(self::$defaultConfig, $config);
    }

    /**
     * 主动调用模式，发起计时统计
     * @param string $callerName 自身服务的名字
     * @param string $serviceName 被调用的服务名字 （推荐票网关统一服务名qdRcmticketGateway，后续公共服务均统一服务名）
     * @param string $serviceIp 被调用的服务ip
     * @param string $interface 接口，提供服务的接口名
     * @param array $config 配置文件 包括redis 配置 和 key前缀
     * @return self
     */
    public static function startActive($callerName, $serviceName, $serviceIp, $interface, $config = array())
    {
        $obj = new static($config);
        $obj->serviceName = $serviceName;
        $obj->serviceIp   = $serviceIp;
        $obj->interface   = $interface;
        $obj->callerName  = $callerName;
        $obj->startTime   = (int) (microtime(true) * 1000);
        return $obj;
    }

    /**
     * 主动调用模式，不计时，直接向米格发送监控日志
     * @param string $callerName 自身服务的名字
     * @param string $serviceName 被调用的服务名字 （推荐票网关统一服务名qdRcmticketGateway，后续公共服务均统一服务名）
     * @param string $serviceIp 被调用的服务ip
     * @param string $interface 接口，提供服务的接口名
     * @param string $resultCode 服务接口返回code，按照接口返回，调用接口返回多少就多少
     * @param null|integer $useTime 耗时，调用的耗时，单位为毫秒， null 表示 0 毫秒
     * @param int $result 成功/失败，调用结果，成功还是失败？ 成功为self::RESULT_SUCCESS ，失败为self::RESULT_FAILED
     * @param int $isTimeOut 是否超时，超时为self::IS_TIME_OUT_TIME_OUT，正常为self::IS_TIME_OUT_NORMAL
     * @param array $config 配置文件 包括redis 配置 和 key前缀
     * @return int|false The new length of the list in case of success, FALSE in case of Failure.
     */
    public static function sendActive($callerName, $serviceName, $serviceIp, $interface, $resultCode, $useTime = null, $result = self::RESULT_SUCCESS, $isTimeOut = self::IS_TIME_OUT_NORMAL, $config = array())
    {
        if ($useTime === null) {
            $useTime = 0;
        }

        $obj = static::startActive($callerName, $serviceName, $serviceIp, $interface, $config);
        return $obj->endTiming($resultCode, $result, $isTimeOut, $useTime);
    }

    /**
     * 发送事件统计信息
     * 在米格监控中呈现为callerName 写死为event， callerIp 和 serviceIp 都为服务器ip，serverName 为 module name， interface name 为 event name
     * @param string $eventName 事件名称 例如 login
     * @param string $resultCode 根据业务发送相关的result code
     * @param string $moduleName 事件模块 例如 indexPage 可以为空
     * @param null $useTime 耗时，调用的耗时，单位为毫秒， null 表示 0 毫秒
     * @param int $result 成功/失败，调用结果，成功还是失败？ 成功为self::RESULT_SUCCESS ，失败为self::RESULT_FAILED
     * @param int $isTimeOut 是否超时，超时为self::IS_TIME_OUT_TIME_OUT，正常为self::IS_TIME_OUT_NORMAL
     * @param array $config 配置文件 包括redis 配置 和 key前缀
     * @return int|false The new length of the list in case of success, FALSE in case of Failure.
     */
    public static function sendEvent($eventName, $resultCode = '0', $moduleName = '', $useTime = null, $result = self::RESULT_SUCCESS, $isTimeOut = self::IS_TIME_OUT_NORMAL, $config = array())
    {
        if ($useTime === null) {
            $useTime = 0;
        }

        $obj = static::startActive('event', $moduleName, '', $eventName, $config);
        return $obj->endTiming($resultCode, $result, $isTimeOut, $useTime);
    }

    /**
     * 发送事件统计信息 V2 版本， 和之前版本区别在于 moduleName 和 resultCode 位置换了一下。方便不发送resultCode的情况使用
     * 在米格监控中呈现为callerName 写死为event， callerIp 和 serviceIp 都为服务器ip，serverName 为 module name， interface name 为 event name
     * @param string $eventName 事件名称 例如 login
     * @param string $moduleName 事件模块 例如 indexPage 可以为空
     * @param string $resultCode 根据业务发送相关的result code
     * @param null $useTime 耗时，调用的耗时，单位为毫秒， null 表示 0 毫秒
     * @param int $result 成功/失败，调用结果，成功还是失败？ 成功为self::RESULT_SUCCESS ，失败为self::RESULT_FAILED
     * @param int $isTimeOut 是否超时，超时为self::IS_TIME_OUT_TIME_OUT，正常为self::IS_TIME_OUT_NORMAL
     * @param array $config 配置文件 包括redis 配置 和 key前缀
     * @return int|false The new length of the list in case of success, FALSE in case of Failure.
     */
    public static function sendEventV2($eventName, $moduleName = '', $resultCode = '0', $useTime = null, $result = self::RESULT_SUCCESS, $isTimeOut = self::IS_TIME_OUT_NORMAL, $config = array())
    {
        if ($useTime === null) {
            $useTime = 0;
        }

        $obj = static::startActive('event', $moduleName, '', $eventName, $config);
        return $obj->endTiming($resultCode, $result, $isTimeOut, $useTime);
    }

    /**
     * 被动调用模式，发起计时统计
     * @param string $callerName 调用方服务的名字
     * @param string $callerIp 调用方ip，即来源ip
     * @param string $serviceName 提供服务的名字 （推荐票网关统一服务名qdRcmticketGateway，后续公共服务均统一服务名）
     * @param string $interface 接口，提供服务的接口名
     * @param array $config 配置文件 包括redis 配置 和 key前缀
     * @return self
     */
    public static function startPassive($callerName, $callerIp, $serviceName, $interface, $config = array())
    {
        $obj = new static($config);
        $obj->serviceName = $serviceName;
        $obj->interface   = $interface;
        $obj->callerName  = $callerName;
        $obj->callerIp    = $callerIp;
        $obj->startTime   = (int) (microtime(true) * 1000);
        return $obj;
    }

    /**
     * 被动调用模式，不计时，直接向米格发送监控日志
     * @param string $callerName 自身服务的名字
     * @param string $callerIp 调用方ip，即来源ip
     * @param string $serviceName 被调用的服务名字 （推荐票网关统一服务名qdRcmticketGateway，后续公共服务均统一服务名）
     * @param string $interface 接口，提供服务的接口名
     * @param string $resultCode 服务接口返回code，按照接口返回，调用接口返回多少就多少
     * @param null|integer $useTime 耗时，调用的耗时，单位为毫秒， null 表示 0 毫秒
     * @param int $result 成功/失败，调用结果，成功还是失败？ 成功为self::RESULT_SUCCESS ，失败为self::RESULT_FAILED
     * @param int $isTimeOut 是否超时，超时为self::IS_TIME_OUT_TIME_OUT，正常为self::IS_TIME_OUT_NORMAL
     * @param array $config 配置文件 包括redis 配置 和 key前缀
     * @return int|false The new length of the list in case of success, FALSE in case of Failure.
     */
    public static function sendPassive($callerName, $callerIp, $serviceName, $interface, $resultCode, $useTime = null, $result = self::RESULT_SUCCESS, $isTimeOut = self::IS_TIME_OUT_NORMAL, $config = array())
    {
        if ($useTime === null) {
            $useTime = 0;
        }

        $obj = static::startPassive($callerName, $callerIp, $serviceName, $interface, $config);
        $obj->endTiming($resultCode, $result, $isTimeOut, $useTime);
    }

    /**
     * WsdMonitor constructor.
     * @param array $config 配置文件 包括redis 配置 和 key前缀
     */
    protected function __construct($config = array())
    {
        if (empty($config)) {
            $this->config = static::$defaultConfig;
        } else {
            $this->config = $config;
        }
    }

    /**
     * 设置调用方ip，主动模式为自己的ip， 被动模式为来源ip
     * @param $ip
     */
    public function setCallerIp($ip)
    {
        $this->callerIp = $ip;
    }

    /**
     * 设置服务方ip，主动模式为调用的ip，被动模式为自己ip
     * @param $ip
     */
    public function setServiceIP($ip)
    {
        $this->serviceIp = $ip;
    }

    /**
     * @param int $resultCode 服务接口返回code，按照接口返回，调用接口返回多少就多少
     * @param int $result 成功/失败，调用结果，成功还是失败？ 成功为self::RESULT_SUCCESS ，失败为self::RESULT_FAILED
     * @param int $isTimeOut 是否超时，超时为self::IS_TIME_OUT_TIME_OUT，正常为self::IS_TIME_OUT_NORMAL
     * @param null|integer $useTime 耗时，调用的耗时，单位为毫秒， null 表示 0 毫秒
     * @return int|false The new length of the list in case of success, FALSE in case of Failure.
     */
    public function endTiming($resultCode, $result = self::RESULT_SUCCESS, $isTimeOut = self::IS_TIME_OUT_NORMAL, $useTime = null)
    {
        $time = time();

        $timestampStr = date('Y-m-d H:i:s', $time);

        if ($this->callerIp === false) {
            $this->callerIp = static::getLocalIpAddr();
        }

        if ($this->serviceIp === false) {
            $this->serviceIp = static::getLocalIpAddr();
        }

        if ($useTime === null) {
            $useTime = ((int) (microtime(true) * 1000) - $this->startTime);
        }

        if (function_exists('wsdMonitorReport')) {
            //use extension
            $ret = wsdMonitorReport($this->config['opp_key'], array($this->callerIp, $this->callerName, $this->serviceIp, $this->serviceName, $this->interface, $resultCode), array($result, $useTime, $isTimeOut, 1));

            return empty($ret) ? true : false;
        } else {
            $str = "$timestampStr|{$this->callerIp}|{$this->callerName}|{$this->serviceIp}|{$this->serviceName}|{$this->interface}|$result|$useTime|$isTimeOut|$resultCode|1";

            return $this->sendToRedis($time, $str);
        }
    }

    /**
     * @param int $timestamp 当前时间戳
     * @param string $str 存入redis的格式字符串，“timestamp|caller_ip|caller_name|service_ip|service_name|interface|result|use_time|is_timeout|resultcode|1”
     * @return int|false The new length of the list in case of success, FALSE in case of Failure.
     */
    protected function sendToRedis($timestamp, $str)
    {
        $key = isset($this->config['opp_key']) ? $this->config['opp_key'] . '_key' : $this->config['key_prefix']; //key_prefix为了兼容老版本
        $key .= $timestamp;

        return $this->redisConnect()->lPush($key, $str);
    }

    /**
     * @return null|\Redis
     */
    protected function redisConnect()
    {
        if ($this->redis == null) {
            $redis = new \Redis();

            if (isset($this->config['redis']['persistent_id'])) {
                $redis->pconnect($this->config['redis']['ip'], $this->config['redis']['port'], $this->config['redis']['timeOut'], $this->config['redis']['persistent_id']);
            } else {
                $redis->connect($this->config['redis']['ip'], $this->config['redis']['port'], $this->config['redis']['timeOut']);
            }

            if (isset($this->config['redis']['auth'])) {
                $redis->auth($this->config['redis']['auth']);
            }

            if (isset($this->config['redis']['db'])){
                $redis->auth($this->config['redis']['db']);
            }

            $this->redis = $redis;
        }

        return $this->redis;
    }

    /**
     * @return string local server ip address, if empty will return ''
     */
    protected static function getLocalIpAddr()
    {
        if (static::$serverAddr !== null) {
            return static::$serverAddr;
        }

        if (function_exists('swoole_get_local_ip')) {
            $localIP = swoole_get_local_ip();
            if(!empty($localIP["eth1"])) {
                static::$serverAddr = $localIP["eth1"];
            } else if(!empty($localIP["eth0"])) {
                static::$serverAddr = $localIP["eth0"];
            }
        }

        if (empty($serverAddr) && isset($_SERVER['SERVER_ADDR'])) {
            static::$serverAddr = $_SERVER['SERVER_ADDR'];
        }

        return static::$serverAddr;
    }
}
