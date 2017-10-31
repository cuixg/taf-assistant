# tafAssistant

## 说明
主要进行网络收发的是TafAssistant*系列

后面带As的版本,标识是异步版本
后面带V2的版本,标识使用了phptaf.so,不带意味着使用了twup.so
后面带NyV2的版本,意味着使用非yield

WsdMonitor是上报到米格的类

LocalSwooleTable: 用来调用swoole table的api,进行寻址的临时存储。只适用于使用了swoole扩展的版本;

AgentRouterRequest/AgentRouterResponse/RouterNodeInfo: 使用了agent进行主控寻址的,必须使用这几个类


## 使用方式

对于使用phptaf.so的版本:
在对应项目的composer.json中添加 :

```
"require": {
      "phptaf/taf-assistant": "2.0.3"
}
```

对于使用twup.so的版本:
在对应项目的composer.json中添加 :

```
"require": {
      "phptaf/taf-assistant": "1.0.6"
}
```