<?php

class Worker
{
    /**
     * 当前进程ID
     *
     * @var int
     */
    public $id = 0;

    /**
     * @var swoole_server
     */
    public $server;

    /**
     * 是否多服务器
     *
     * @var bool
     */
    public $multipleServer = false;

    /**
     * 任务列表
     *
     * ```
     *      [
     *          'table1' => ...,
     *          'table2' => ...
     *      ]
     * ```
     *
     * @var array
     */
    public $tasks = [];

    /**
     * ssdb 对象
     *
     * @var redis
     */
    public $redis;

    /**
     * 是否采用的ssdb
     *
     * @see http://ssdb.io/
     * @var bool
     */
    public $isSSDB = false;

    /**
     * SimpleSSDB 对象
     *
     * @var SimpleSSDB
     */
    public $ssdb;

    /**
     * 需要刷新的任务数据
     *
     * @var array
     */
    protected $flushData = [];

    /**
     * 计算中的任务数据
     *
     * @var array
     */
    protected $flushDataRunTime = [];

    protected $dumpFile = '';

    /**
     * 记录数据的数组
     *
     * @var array
     */
    protected $buffer = [];

    /**
     * 记录数据的最后时间
     *
     * @var array
     */
    protected $bufferTime = [];

    protected $bufferLen = [];

    /**
     * 是否完成了初始化
     *
     * @var bool
     */
    private $isInit = false;

    protected static $packKey;

    public static $timed;

    public static $serverName;

    public function __construct(swoole_server $server, $id)
    {
        $this->server   = $server;
        $this->id       = $id;
        $this->dumpFile = (FluentServer::$config['server']['dump_path'] ?: '/tmp/') . 'total-dump-'. substr(md5(FluentServer::$configFile), 16, 8) . '-'. $id .'.txt';

        # 包数据的key
        self::$packKey  = [
            chr(146).chr(206).chr(85) => 1,
            chr(146).chr(206).chr(86) => 1,
            chr(146).chr(206).chr(87) => 1,
        ];

        self::$timed = time();

        self::$serverName = FluentServer::$config['server']['host'].':'.FluentServer::$config['server']['port'];
    }

    /**
     * 初始化后会调用
     */
    public function init()
    {
        if ($this->isInit)return true;

//        $this->parseSql('select *,asdf, count(aaa) as value, sum(bbb), dist(fff),avg(ccc) from mytable for a,d,bb where (id =1 and c!=3) or d%3=33 group by def,abc group time 3m save to testtable');
//        $this->parseSql('select *,count(abc), dist(value) as distTotal, last(value) as lastValue, first(value) as firstValue  from test where id > 3 group time 10m');

        if (!$this->reConnectRedis())
        {
            # 如果没有连上, 则每秒重试一次
            $id = null;
            $id = swoole_timer_tick(1000, function() use (& $id)
            {
                if ($this->reConnectRedis())
                {
                    swoole_timer_clear($id);
                }
            });
            unset($id);
        }

        # 标记成已经初始化过
        $this->isInit = true;

        # 每3秒执行1次
        swoole_timer_tick(3000, function()
        {
            # 更新时间戳
            self::$timed = time();

            # 检查redis
            if (!$this->redis)
            {
                # 重连
                $this->reConnectRedis();
            }

            try
            {
                if (false === @$this->redis->ping())
                {
                    throw new Exception('redis closed');
                }
            }
            catch(Exception $e)
            {
                $this->redis = null;
            }
        });

        # 刷新间隔时间, 单位毫秒
        $limit = intval(FluentServer::$config['server']['merge_time_ms'] ?: 3000);

        if ($this->id > 0)
        {
            # 将每个worker进程的刷新时间平均隔开
            usleep(min(10000, intval(1000 * $limit * $this->id / $this->server->setting['worker_num'])));
        }

        # 推送到task进行数据汇总处理
        swoole_timer_tick($limit, function()
        {
            try
            {
                $this->flush();
            }
            catch (Exception $e)
            {
                # 避免正好在处理数据时redis连接失败抛错导致程序终止, 系统会自动重连
                if ($this->redis && false === @$this->redis->ping())
                {
                    $this->redis = null;
                    if ($this->ssdb)
                    {
                        $this->ssdb = null;
                    }
                }
            }
        });

        # 读取未处理完的数据
        if (is_file($this->dumpFile))
        {
            $data = @unserialize(file_get_contents($this->dumpFile));
            if (false !== $data)
            {
                $this->flushData = $data;
                unlink($this->dumpFile);
            }
        }

        if ($this->redis)
        {
            # 加载task
            $this->reloadTasks();

            # 注册服务
            $this->updateServerStatus();
        }
        else
        {
            $id = null;
            $id = swoole_timer_tick(3000, function() use (& $id)
            {
                if ($this->redis)
                {
                    $this->reloadTasks();
                    $this->updateServerStatus();

                    # 退出循环
                    swoole_timer_clear($id);
                    unset($id);
                }
            });
            unset($id);
        }

        # 每10分钟处理1次
        swoole_timer_tick(1000 * 600, function()
        {
            # 清理老数据
            if ($this->buffer)
            {
                foreach ($this->buffer as $k => $v)
                {
                    if (self::$timed - $this->bufferTime[$k] > 180)
                    {
                        # 超过1分钟没有更新数据, 则移除
                        info('clear expired data len: '. $this->bufferLen[$k]);

                        unset($this->buffer[$k]);
                        unset($this->bufferTime[$k]);
                        unset($this->bufferLen[$k]);
                    }
                }
            }

            if ($this->redis)
            {
                $this->reloadTasks();

                # 设置内存数
                $this->redis->hSet('server.memory', self::$serverName.'_'.$this->id, serialize([memory_get_usage(true), self::$timed, self::$serverName]));
            }
        });


        if ($this->id == 0)
        {
            # 每分钟推送1次数据输出
            $limit = intval(FluentServer::$config['output']['output_time_ms'] ?: 60000);
            swoole_timer_tick($limit, function()
            {
                $this->server->task('output');
            });

            # 没分钟处理
            swoole_timer_tick(60000, function()
            {
                # 更新服务器信息
                $this->updateServerStatus();
            });


            foreach ($this->tasks as $task)
            {
                foreach ($task as $key => $item)
                {
                    foreach ($item['sql'] as $sql)
                    {
                        info("fork sql({$key}): {$sql}");
                    }
                }
            }

            # 每天清理数据
            swoole_timer_tick(86400000, function()
            {

                # 清理每天的统计数据, 只保留10天内的
                self::$timed = $time = time();
                $k1  = date('Y-m-d', $time - 86400 * 11);
                $k2  = date('Y-m-d', $time - 86400 * 12);
                if ($this->isSSDB)
                {
                    while ($keys = $this->ssdb->hlist("counter.total.$k1", "counter.total.$k2", 100))
                    {
                        # 列出key
                        foreach ($keys as $k)
                        {
                            # 清除
                            $this->ssdb->hclear($k);
                        }
                    }
                    while ($keys = $this->ssdb->hlist("counter.time.$k1", "counter.time.$k2", 100))
                    {
                        # 列出key
                        foreach ($keys as $k)
                        {
                            # 清除
                            $this->ssdb->hclear($k);
                        }
                    }
                }
                else
                {
                    # 获取所有key
                    $keys = $this->redis->keys("counter.total.$k1.*");
                    if ($keys)foreach ($keys as $k)
                    {
                        $this->redis->delete($k);
                    }

                    $keys = $this->redis->keys("counter.time.$k1.*");
                    if ($keys)foreach ($keys as $k)
                    {
                        $this->redis->delete($k);
                    }

                    $keys = $this->redis->keys("counter.total.$k2.*");
                    if ($keys)foreach ($keys as $k)
                    {
                        $this->redis->delete($k);
                    }

                    $keys = $this->redis->keys("counter.time.$k2.*");
                    if ($keys)foreach ($keys as $k)
                    {
                        $this->redis->delete($k);
                    }
                }
            });
        }

        return true;
    }


    /**
     * 接受到数据
     *
     * @param swoole_server $server
     * @param $fd
     * @param $fromId
     * @param $data
     * @return bool
     */
    public function onReceive(swoole_server $server, $fd, $fromId, $data)
    {
        if (substr($data, -3) !== "==\n")
        {
            $this->buffer[$fromId]    .= $data;
            $this->bufferTime[$fromId] = self::$timed;
            $this->bufferLen[$fromId] += strlen($data);

            # 支持json格式
            if ($this->buffer[$fromId][0] === '[')
            {
                $arr = @json_decode($this->buffer[$fromId], true);
                if ($arr)
                {
                    # 能够解析出json, 直接跳转到处理json的地方
                    unset($this->buffer[$fromId]);
                    unset($this->bufferTime[$fromId]);
                    unset($this->bufferLen[$fromId]);

                    goto jsonFormat;
                }
            }

            if ($this->bufferLen[$fromId] > 10000000)
            {
                # 超过10MB
                unset($this->buffer[$fromId]);
                unset($this->bufferTime[$fromId]);
                unset($this->bufferLen[$fromId]);

                $server->close($fd);
            }

            return true;
        }
        elseif (isset($this->buffer[$fromId]) && $this->buffer[$fromId])
        {
            # 拼接 buffer
            $data = $this->buffer[$fromId] . $data;

            unset($this->buffer[$fromId]);
            unset($this->bufferTime[$fromId]);
            unset($this->bufferLen[$fromId]);

            debug("accept data length ". strlen($data));
        }

        if ($data[0] === '[')
        {
            # 解析数据
            $arr = @json_decode(rtrim($data, "/=\n\r "), true);

            jsonFormat:
            $msgPack           = false;
            $delayParseRecords = false;
        }
        else
        {
            # msgPack方式解析
            $msgPack           = true;
            $arr               = @msgpack_unpack($data);
            $delayParseRecords = false;

            if (!is_array($arr))
            {
                debug("close client {$fd}.");
                $server->close($fd);
                return false;
            }

            if ($arr && is_array($arr) && !is_array($arr[1]))
            {
                # 标记成需要再解析数据, 暂时不解析
                $delayParseRecords = true;
            }
        }

        if (!$arr || !is_array($arr))
        {
            debug('error data: ' . $data);

            # 把客户端关闭了
            $server->close($fd);
            return false;
        }

        $tag = $arr[0];
        if (!$tag)
        {
            debug('data not found tag: ' . $data);

            # 把客户端关闭了
            $server->close($fd);
            return false;
        }

        if (IS_DEBUG)
        {
            debug("worker: $this->id, tag: $tag, data length: " . strlen($data));
        }

        # example: xd.game.hsqj.consume : $app = hsqj, $table = consume
        # example: consume: $app = '', $table = consume
        list($app, $table) = array_splice(explode('.', $tag), -2);
        if (!$table)
        {
            $table = $app;
            $app   = 'default';
        }

        if (isset($this->tasks[$table]) && $this->tasks[$table])
        {
            # 没有相应tag的任务, 直接跳过
            $haveTask = true;
        }
        else
        {
            $haveTask = false;
        }

        if ($delayParseRecords || is_array($arr[1]))
        {
            # 多条数据
            # [tag, [[time,record], [time,record], ...], option]
            $option  = $arr[2] ?: [];
            $records = $arr[1];
        }
        else
        {
            # 单条数据
            # [tag, time, record, option]
            $option  = $arr[3] ?: [];
            $records = [[$arr[1], $arr[2]]];
        }

        if ($option['chunk'])
        {
            $ackData = ['ack' => $option['chunk']];
            $isSend  = false;
        }
        else
        {
            $ackData = null;
            $isSend  = true;
        }

        if ($haveTask)
        {
            # 有任务需要处理

            if ($delayParseRecords)
            {
                # 解析数据
                $this->parseRecords($records);
            }

            $jobs = [];
            foreach ($this->tasks[$table] as $jobKey => $job)
            {
                if ($job['for'] && !$job['for'][$app])
                {
                    # 这个任务是为某个APP定制的
                    continue;
                }

                $jobs[$jobKey] = $job;
            }

            if ($jobs)
            {
                $this->flushDataRunTime = $this->flushData;

                $count = count($records);

                if (IS_DEBUG)
                {
                    debug("worker: $this->id, tag: $tag, records count: " . $count);
                }

                $k = date('Y-m-d,H:i');
                foreach ($jobs as $jobKey => $job)
                {
                    $beginTime = microtime(1);

                    # 处理数据
                    foreach ($records as $record)
                    {
                        $this->doJob($jobKey, $job, $app, $table, isset($record[1]['time']) && $record[1]['time'] > 0 ? $record[1]['time'] : $record[0], $record[1]);
                    }

                    # 记录统计信息
                    $this->flushDataRunTime['counter'][$jobKey][$k]['total'] += $count;
                    $this->flushDataRunTime['counter'][$jobKey][$k]['time']  += 1000000 * (microtime(1) - $beginTime);
                }
            }
        }
        else
        {
            $jobs = null;
        }

        if ($ackData)
        {
            # ACK 确认
            if ($msgPack)
            {
                $isSend = $server->send($fd, msgpack_pack($ackData));
            }
            else
            {
                $isSend = $server->send($fd, json_encode($ackData));
            }
        }

        # 将运算中的数据更新过去
        if ($isSend && $jobs)
        {
            # 发送成功, 数据置换
            $this->flushData = $this->flushDataRunTime;
            unset($this->flushDataRunTime);

            # 计数器增加
            $count = count($records);
            if ($count > 0)
            {
                FluentServer::$counter->add($count);
            }
        }

        return true;
    }

    /**
     * @param swoole_server $server
     * @param $fromWorkerId
     * @param $message
     * @return null
     */
    public function onPipeMessage(swoole_server $server, $fromWorkerId, $message)
    {
        if (substr($message, 0, 1) === '{')
        {
            $data = @json_decode($message, true);
            if ($data)
            {
                switch ($data['type'])
                {
                    case 'task.update':
                        # 添加一个任务
                        $option = @unserialize($this->redis->hGet('queries', $data['key']));
                        if ($option)
                        {
                            # 更新配置
                            $this->tasks[$option['table']][$option['key']] = $option;
                        }
                        break;

                    case 'task.remove':
                        # 移除一个任务
                        $key = $data['key'];
                        foreach ($this->tasks as $table => $op)
                        {
                            foreach ($op  as $k => $st)
                            {
                                if ($k === $key)
                                {
                                    unset($this->tasks[$table][$k]);
                                }
                            }

                            if (!$this->tasks[$table])
                            {
                                unset($this->tasks[$table]);
                            }
                        }

                        $this->clearFlushDataByKey($key);

                        break;

                }
            }
        }

        return;
    }

    public function onFinish($server, $task_id, $data)
    {
        return true;
    }

    protected function parseRecords(& $recordsData)
    {
        if (!is_array($recordsData))
        {
            # 解析里面的数据
            $tmpArr = [];
            $tmp    = $recordsData[0];
            $length = strlen($recordsData);

            for ($i = 1; $i < $length; $i++)
            {
                $isEnd = $length == $i + 1;
                if ($isEnd || isset(self::$packKey[substr($recordsData, $i, 3)]))
                {
                    if ($isEnd)
                    {
                        $tmp .= $recordsData[$i];
                    }

                    $tmpRecord = @msgpack_unpack($tmp);
                    if (false !== $tmpRecord)
                    {
                        $tmpArr[] = $tmpRecord;

                        # 重置临时字符串
                        $tmp = '';
                    }
                }
                $tmp .= $recordsData[$i];
            }

            $recordsData = $tmpArr;
        }
    }


    protected function reConnectRedis()
    {
        try
        {
            $redis = new redis();
            $host  = FluentServer::$config['redis']['host'];
            $port  = FluentServer::$config['redis']['port'];

            $redis->pconnect($host, $port);
            $this->redis = $redis;

            if (false === $redis->time())
            {
                # 大部分用redis的操作, 部分不兼容的用这个对象来处理
                $this->isSSDB = true;
                require_once __DIR__ . '/SSDB.php';

                $this->ssdb = new SimpleSSDB($host, $port);
            }

            $id = null;
            unset($id);

            return true;
        }
        catch (Exception $e)
        {
            if ($this->id == 0 && time() % 10 == 0)
            {
                info('redis server is not start, wait start redis://' . FluentServer::$config['redis']['host'] . ':' . FluentServer::$config['redis']['port']);
            }

            return false;
        }
    }

    protected function doJob($jobKey, $job, $app, $table, $time, $item)
    {
        if ($job['where'])
        {
            if (false === self::checkWhere($job['where'], $item))
            {
                # 不符合
                return;
            }
        }

        # 分组数据
        $groupValue = [
            $job['groupTime']['limit'] . $job['groupTime']['type'],
            self::getTimeKey($time, $job['groupTime']['type'], $job['groupTime']['limit'])
        ];

        if ($job['groupBy'])foreach ($job['groupBy'] as $group)
        {
            $groupValue[] = $item[$group];
        }

        $id  = implode('_', $groupValue);
        $fun = $job['function'];
        $key = "{$jobKey},{$app},{$id}";

        if (strlen($key) > 140)
        {
            # 防止key太长
            $key = substr($key, 0 , 120) .'_'. md5($key);
        }

        # 分组记录唯一值
        if (isset($fun['dist']))
        {
            # 唯一数据
            foreach ($fun['dist'] as $field => $t)
            {
                $this->flushDataRunTime['dist']["dist,{$key},{$field}"][$item[$field]] = 1;
            }
        }

        # 更新统计数据
        $total = $this->totalData($this->flushDataRunTime['total'][$key], $item, $fun, isset($item['microtime']) && $item['microtime'] ? $item['microtime'] : $time);
        if ($total)
        {
            $this->flushDataRunTime['total'][$key] = $total;
        }

        if ($job['allField'])
        {
            $this->flushDataRunTime['value'][$key] = $item;
        }
        elseif (isset($fun['value']))
        {
            foreach ($fun['value'] as $field => $t)
            {
                $this->flushDataRunTime['value'][$key][$field] = $item[$field];
            }
        }

        # 标记
        $this->flushDataRunTime['jobs'][$key] = [$id, $time, $app, $table, $jobKey];

        return;
    }

    /**
     * 在程序退出时保存数据
     */
    public function dumpData()
    {
        if (FluentServer::$config['server']['flush_at_shutdown'])
        {
            $this->flush();
        }

        if ($this->flushData['dist'] || $this->flushData['total'] || $this->flushData['value'] || $this->flushData['jobs'])
        {
            # 有数据
            file_put_contents($this->dumpFile, serialize($this->flushData));
        }
    }

    /**
     * 清理redis中的数据
     *
     * @param $key
     */
    public function clearDataByKey($key)
    {
        if ($this->isSSDB)
        {
            while ($keys = $this->ssdb->hlist("total,{$key},", "total,{$key},z", 100))
            {
                foreach ($keys as $k)
                {
                    $this->ssdb->hclear($k);
                }
            }

            while ($keys = $this->ssdb->hlist("dist,{$key},", "dist,{$key},z", 100))
            {
                foreach ($keys as $k)
                {
                    $this->ssdb->hclear($k);
                }
            }

            while ($keys = $this->ssdb->hlist("join,{$key},", "dist,{$key},z", 100))
            {
                foreach ($keys as $k)
                {
                    $this->ssdb->hclear($k);
                }
            }
        }
        else
        {
            $keys = $this->redis->keys("total,{$key},*");
            if ($keys)
            {
                $this->redis->delete($keys);
            }

            $keys = $this->redis->keys("dist,{$key},*");
            if ($keys)
            {
                $this->redis->delete($keys);
            }

            $keys = $this->redis->keys("join,{$key},*");
            if ($keys)
            {
                $this->redis->delete($keys);
            }
        }
    }

    public function clearFlushDataByKey($key)
    {
        # 清理内存中的数据
        unset($this->flushData['jobs'][$key]);
        unset($this->flushData['value'][$key]);
        unset($this->flushData['total'][$key]);
        unset($this->flushData['dist'][$key]);
        unset($this->flushData['counter'][$key]);
    }

    protected function reloadTasks()
    {
        $tasks = [];
        $opts  = $this->redis->hGetAll('queries');
        foreach ($opts as $key => $item)
        {
            $opt = @unserialize($item);
            if ($opt && is_array($opt))
            {
                if (!$opt['use'])
                {
                    if ($this->id == 0)
                    {
                        info("query not use, key: {$opt['key']}, table: {$opt['table']}");
                    }
                    continue;
                }

                $tasks[$opt['table']][$opt['key']] = $opt;
            }
            else
            {
                if ($this->id == 0)
                {
                    warn("error query option: {$item}");
                }
            }
        }

        if ($tasks)
        {
            $this->tasks = $tasks;
        }
        elseif (IS_DEBUG && $this->id == 0)
        {
            info("not found any task");
        }
    }

    protected function updateServerStatus()
    {
        if (!$this->redis)return false;

        $data = [
            'stats'      => $this->server->stats(),
            'updateTime' => self::$timed,
        ];
        return $this->redis->hSet('servers', self::$serverName, json_encode($data)) ? true : false;
    }

    /**
     * 刷新数据到ssdb, ssdb
     *
     * 刷新间隔默认3秒
     *
     * @return bool
     */
    protected function flush()
    {
        if ($this->flushData['jobs'] && $this->redis)
        {
            # 更新唯一值
            if ($this->flushData['dist'])
            {
                foreach ($this->flushData['dist'] as $key => $v)
                {
                    if ($this->redis->hMSet($key, $v))
                    {
                        # 成功
                        unset($this->flushData['dist'][$key]);
                    }
                }
            }

            $tryNum = 0;
            while (true)
            {
                if (!$this->flushData['jobs'])break;

                foreach ($this->flushData['jobs'] as $key => $opt)
                {
                    $lockKey = "lock,{$key}";

                    # 没用 $redis->set($lockKey, microtime(1), ['nx', 'ex' => 10]); 这样过期设置是因为ssdb不支持
                    if ($this->redis->setNx($lockKey, microtime(1)))
                    {
                        # 抢锁成功

                        # $this->flushData['jobs'][$key] = [$id, $time, $app, $table, $jobKey];
                        list($id, $time, $app, $fromTable, $jobKey) = $opt;

                        # 获取所有统计相关数据
                        $totalKey = "total,{$key}";
                        $total    = $this->redis->get($totalKey);
                        if (!$total)
                        {
                            $total = [];
                        }
                        else
                        {
                            $total = @unserialize($total) ?: [];
                        }

                        # 任务的设置
                        $job = $this->tasks[$fromTable][$jobKey];

                        # 更新统计数据
                        if ($this->flushData['total'][$key])
                        {
                            # 合并统计数据
                            $total = $this->totalDataMerge($total, $this->flushData['total'][$key], $job['function']);

                            # 更新数据
                            if ($this->redis->set($totalKey, serialize($total)))
                            {
                                unset($this->flushData['total'][$key]);
                            }
                        }

                        $limit     = date('YmdHi');
                        $saveData  = [];
                        $distCache = [];
                        $value     = $this->flushData['value'][$key] ?: [];

                        foreach ($job['saveAs'] as $table => $st)
                        {
                            $data = [
                                '_id' => $id,
                            ];

                            if ($st['allField'])
                            {
                                $data += $value;
                            }

                            # 排除字段
                            if (isset($job['function']['exclude']))
                            {
                                # 示例: select *, exclude(test), exclude(abc) from ...
                                foreach ($job['function']['exclude'] as $field => $t)
                                {
                                    unset($data[$field]);
                                }
                            }

                            foreach ($st['field'] as $as => $saveOpt)
                            {
                                $field = $saveOpt['field'];
                                switch ($saveOpt['type'])
                                {
                                    case 'count':
                                    case 'sum':
                                    case 'min':
                                    case 'max':
                                        $data[$as] = $total[$saveOpt['type']][$field];
                                        break;

                                    case 'first':
                                    case 'last':
                                        $data[$as] = $total[$saveOpt['type']][$field][0];
                                        break;

                                    case 'dist':
                                        if (!isset($distCache[$field]))
                                        {
                                            # 获取唯一值的长度
                                            $distCache[$field] = (int)$this->redis->hLen("dist,{$key},{$field}");
                                        }
                                        $data[$as] = $distCache[$field];
                                        break;

                                    case 'exclude':
                                        # 排除
                                        unset($data[$as]);
                                        break;

                                    case 'value':
                                    default:
                                        $data[$as] = $value[$field];
                                        break;
                                }
                            }

                            $saveKey = "list,{$app},{$table},{$limit}";

                            $saveData[$saveKey][$id] = json_encode([$time, $data], JSON_UNESCAPED_UNICODE);
                        }

                        # 更新数据
                        $error = false;
                        foreach ($saveData as $saveKey => $data)
                        {
                            if (false === $this->redis->hMset($saveKey, $data))
                            {
                                $error = true;
                                break;
                            }
                        }

                        if (!$error && !isset($this->flushData['total'][$key]))
                        {
                            unset($this->flushData['jobs'][$key]);
                            unset($this->flushData['value'][$key]);
                        }

                        # 释放锁
                        $this->redis->delete($lockKey);
                    }
                    else if ($tryNum % 100 === 0)
                    {
                        if (microtime(1) - $this->redis->get($lockKey) > 10)
                        {
                            # 10 秒还没解锁, 直接删除, 防止死锁
                            $this->redis->delete($lockKey);
                        }
                    }
                }

                if ($this->flushData['jobs'])
                {
                    # 重试
                    $tryNum++;
                    usleep(mt_rand(1, 100));
                }
                else
                {
                    break;
                }
            }
        }

        # 同步统计信息
        if ($this->flushData['counter'])
        {
            # 按每分钟分开
            foreach ($this->flushData['counter'] as $key => $value)
            {
                if (is_array($value))
                {
                $allCount = 0;
                foreach ($value as $timeKey => $v)
                {
                    list($k1, $k2) = explode(',', $timeKey);

                    $allCount += $v['total'];

                    # 更新当前任务的当天统计信息
                    $this->redis->hIncrBy("counter.total.$k1.$key", $k2, $v['total']);
                    $this->redis->hIncrBy("counter.time.$k1.$key", $k2, $v['time']);
                }

                # 更新任务总的统计信息
                $this->redis->hIncrBy('counter', $key, $allCount);
                }
                unset($this->flushData['counter'][$key]);
            }
        }

        return true;
    }

    protected function totalData($total, $current, $fun, $time)
    {
        if (isset($fun['sum']))
        {
            # 相加的数值
            foreach ($fun['sum'] as $field => $t)
            {
                $total['sum'][$field] += $current[$field];
            }
        }

        if (isset($fun['count']))
        {
            foreach ($fun['count'] as $field => $t)
            {
                $total['count'][$field] += 1;
            }
        }

        if (isset($fun['last']))
        {
            foreach ($fun['last'] as $field => $t)
            {
                $tmp = $total['last'][$field];

                if (!$tmp || $tmp[1] < $time)
                {
                    $total['last'][$field] = [$current[$field], $time];
                }
            }
        }

        if (isset($fun['first']))
        {
            foreach ($fun['first'] as $field => $t)
            {
                $tmp = $total['first'][$field];

                if (!$tmp || $tmp[1] > $time)
                {
                    $total['first'][$field] = [$current[$field], $time];
                }
            }
        }

        if (isset($fun['min']))
        {
            foreach ($fun['min'] as $field => $t)
            {
                if (isset($total['min'][$field]))
                {
                    $total['min'][$field] = min($total['min'][$field], $current[$field]);
                }
                else
                {
                    $total['min'][$field] = $current[$field];
                }
            }
        }

        if (isset($fun['max']))
        {
            foreach ($fun['max'] as $field => $t)
            {
                if (isset($total['max'][$field]))
                {
                    $total['max'][$field] = max($total['max'][$field], $current[$field]);
                }
                else
                {
                    $total['max'][$field] = $current[$field];
                }
            }
        }

        return $total;
    }

    protected function totalDataMerge($total, $totalMerge, $fun)
    {
        if (isset($fun['sum']))
        {
            # 相加的数值
            foreach ($fun['sum'] as $field => $t)
            {
                $total['sum'][$field] += $totalMerge['sum'][$field];
            }
        }

        if (isset($fun['count']))
        {
            foreach ($fun['count'] as $field => $t)
            {
                $total['count'][$field] += $totalMerge['count'][$field];
            }
        }

        if (isset($fun['last']))
        {
            foreach ($fun['last'] as $field => $t)
            {
                $tmp = $total['last'][$field];

                if (!$tmp || $tmp[1] < $totalMerge['last'][$field][1])
                {
                    $total['last'][$field] = $totalMerge['last'][$field];
                }
            }
        }

        if (isset($fun['first']))
        {
            foreach ($fun['first'] as $field => $t)
            {
                $tmp = $total['first'][$field];

                if (!$tmp || $tmp[1] > $totalMerge['first'][$field][1])
                {
                    $total['first'][$field] = $totalMerge['first'][$field];
                }
            }
        }

        if (isset($fun['min']))
        {
            foreach ($fun['min'] as $field => $t)
            {
                if (isset($totalMerge['min'][$field]))
                {
                    $total['min'][$field] = min($totalMerge['min'][$field], $total['min'][$field]);
                }
                else
                {
                    $total['min'][$field] = $totalMerge['min'][$field];
                }
            }
        }

        if (isset($fun['max']))
        {
            foreach ($fun['max'] as $field => $t)
            {
                if (isset($totalMerge['max'][$field]))
                {
                    $total['max'][$field] = max($totalMerge['max'][$field], $total['max'][$field]);
                }
                else
                {
                    $total['max'][$field] = $totalMerge['max'][$field];
                }
            }
        }

        return $total;
    }

    protected static function checkWhere($opt, $data)
    {
        if (isset($opt['$type']))
        {
            # 当前的类型: && 或 ||
            $type = $opt['$type'];

            foreach ($opt['$item'] as $item)
            {
                if (is_array($item) && isset($item['$type']))
                {
                    # 子分组条件
                    $rs = self::checkWhere($opt, $data);
                }
                else
                {
                    $rs    = false;
                    $isIn  = false;
                    $value = $data[$item['field']];
                    if ($item['typeM'])
                    {
                        switch ($item['typeM'])
                        {
                            case '%':
                            case 'mod':
                                $value = $value % $item['mValue'];
                                break;
                            case '>>';
                                $value = $value >> $item['mValue'];
                                break;
                            case '<<';
                                $value = $value << $item['mValue'];
                                break;
                            case '-';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value - $item['mValue'];
                                }
                                else
                                {
                                    $value = $value - $data[$item['mValue']];
                                }
                                break;
                            case '+';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value + $item['mValue'];
                                }
                                else
                                {
                                    $value = $value + $data[$item['mValue']];
                                }
                                break;
                            case '*';
                            case 'x';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value * $item['mValue'];
                                }
                                else
                                {
                                    $value = $value * $data[$item['mValue']];
                                }
                                break;

                            case '/';
                                if (is_numeric($item['mValue']))
                                {
                                    $value = $value / $item['mValue'];
                                }
                                else
                                {
                                    $value = $value / $data[$item['mValue']];
                                }
                                break;

                            case 'func':
                                switch ($item['fun'])
                                {
                                    case 'time_format':
                                    case 'from_unixtime':
                                        $arg = str_replace(['%D', '%'], ['d', ''], $item['arg'] ?: 'Y-m-d');
                                        $value = @date($arg, $value);
                                        break;

                                    case 'unix_timestamp':
                                        $value = @strtotime($value);
                                        break;

                                    case 'in':
                                        $isIn = true;
                                        $rs = in_array($item['arg'], $data[$item['field']]);
                                        break;

                                    case 'not_in':
                                        $isIn = true;
                                        $rs = !in_array($item['arg'], $data[$item['field']]);
                                        break;

                                    default:
                                        if (is_callable($item['fun']))
                                        {
                                            try
                                            {
                                                $value = @call_user_func($item['fun'], $value, $item['arg']);
                                            }
                                            catch (Exception $e)
                                            {
                                                $value = false;
                                            }
                                        }
                                        break;
                                }
                                break;
                        }
                    }

                    if (!$isIn)
                    {
                        $rs = self::checkWhereEx($value, $item['value'], $item['type']);
                    }
                }

                if ($type === '&&')
                {
                    # 并且的条件, 返回了 false, 则不用再继续判断了
                    if ($rs === false)return false;
                }
                else
                {
                    # 或, 返回成功则不用再判断了
                    if ($rs === true)return true;
                }
            }
        }

        return true;
    }

    protected static function checkWhereEx($v1, $v2, $type)
    {
        switch ($type)
        {
            case '>';
                if ($v1 > $v2)
                {
                    return true;
                }
                break;
            case '<';
                if ($v1 < $v2)
                {
                    return true;
                }
                break;
            case '>=';
                if ($v1 >= $v2)
                {
                    return true;
                }
                break;
            case '<=';
                if ($v1 <= $v2)
                {
                    return true;
                }
                break;
            case '!=';
                if ($v1 != $v2)
                {
                    return true;
                }
                break;
            case '=';
            default :
                if ($v1 == $v2)
                {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * 获取按时间分组的key
     * 
     * @param $time
     * @param $type
     * @param $limit
     * @return int
     */
    protected static function getTimeKey($time, $type, $limit)
    {

        # 按时间处理分组
        switch ($type)
        {
            case 'd':
                # 天
                $timeKey   = 1000 * date('Y', $time);
                # 当年中的第N天, 0-365
                $timeLimit = date('z', $time);
                break;

            case 'm':
                # 分钟
                $timeKey   = 100 * date('YmdH', $time);
                $timeLimit = date('i', $time);
                break;

            case 's':
                # 秒
                $timeKey   = 100 * date('YmdHi', $time);
                $timeLimit = date('s', $time);
                break;

            case 'W':
                # 当年中第N周
                $timeKey   = 100 * date('Y', $time);
                $timeLimit = date('W', $time) - 1;
                break;

            case 'h':
            default:
                # 小时
                $timeKey   = 100 * date('Ymd', $time);
                $timeLimit = date('H', $time);
                break;
        }

        if ($timeLimit > 0 && $limit > 1)
        {
            # 按 $job['groupTime']['limit'] 中的数值分组
            # $timeLimit + 1 是因为所有的数值都是从0开始
            $timeKey = $timeKey + $limit * floor(($timeLimit + 1) / $limit);
        }
        else
        {
            $timeKey += $timeLimit;
        }
        
        return $timeKey;
    }
}
