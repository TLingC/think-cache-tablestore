<?php
declare(strict_types = 1);

namespace tlingc\think\cache\driver;

use think\App;
use think\cache\Driver;

use Aliyun\OTS\OTSClient;
use Aliyun\OTS\Consts\ColumnTypeConst;
use Aliyun\OTS\Consts\RowExistenceExpectationConst;

/**
 * 阿里云表格存储Tablestore缓存类
 */
class Tablestore extends Driver
{
    /**
     * 配置参数
     * @var array
     */
    protected $options = [
        // 表格存储实例访问地址
        'endpoint'           => '',
        // 阿里云access key id
        'access_key_id'      => '',
        // 阿里云access key secret
        'access_key_secret'  => '',
        // 表格存储实例名称
        'instance_name'      => '',
        // 表格存储数据表名
        'table_name'         => '',
        // 缓存有效期 默认为86400秒（1天）
        'expire'             => 86400,
        // 缓存前缀
        'prefix'             => '',
        // 启用gzip压缩
        'data_compress'      => false,
        // 缓存标签前缀
        'tag_prefix'         => 'tag:',
        // 序列化机制 例如 ['serialize', 'unserialize']
        'serialize'          => [],
        // 连接超时时间
        'connection_timeout' => 2.0,
        // socket超时时间
        'socket_timeout'     => 2.0,
    ];

    /**
     * 架构函数
     * @param App   $app
     * @param array $options 参数
     */
    public function __construct(App $app, array $options = [])
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        $this->handler = new OTSClient([
            'EndPoint' => $this->options['endpoint'],
            'AccessKeyID' => $this->options['access_key_id'],
            'AccessKeySecret' => $this->options['access_key_secret'],
            'InstanceName' => $this->options['instance_name'],
            'DebugLogHandler' => '',
            'ErrorLogHandler' => '',
            'ConnectionTimeout' => $this->options['connection_timeout'],
            'SocketTimeout' => $this->options['socket_timeout']
        ]);
    }

    /**
     * 获取缓存数据
     * @param string $name 缓存标识名
     * @return array|null
     */
    protected function getRaw(string $name)
    {
        $key = $this->getCacheKey($name);

        $content = $this->handler->getRow([
            'table_name' => $this->options['table_name'],
            'primary_key' => [
                ['key', $key]
            ],
            'max_versions' => 1
        ]);

        if (false == empty($content['attribute_columns'])) {
            $timestamp = 0;
            $columns = [];
            foreach ($content['attribute_columns'] as $item) {
                $columns[$item[0]] = $item[1];
                if ($item[0] == 'value') {
                    $timestamp = $item[3];
                }
            }

            $expire = (int) $columns['expire'];
            if (0 != $expire && getMicroTime() > $timestamp + ($expire * 1000)) {
                //缓存过期删除缓存
                $this->delete($key);
                return;
            }

            if ($this->options['data_compress'] && function_exists('gzcompress')) {
                //启用数据压缩
                $columns['value'] = gzuncompress($columns['value']);
            }

            return ['content' => $columns['value'], 'expire' => $expire];
        }
    }

    /**
     * 判断缓存是否存在
     * @access public
     * @param string $name 缓存变量名
     * @return bool
     */
    public function has($name): bool
    {
        return $this->getRaw($name) !== null;
    }

    /**
     * 读取缓存
     * @access public
     * @param string $name    缓存变量名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get($name, $default = null)
    {
        $this->readTimes++;

        $raw = $this->getRaw($name);

        return is_null($raw) ? $default : $this->unserialize($raw['content']);
    }

    /**
     * 写入缓存
     * @access public
     * @param string        $name   缓存变量名
     * @param mixed         $value  存储数据
     * @param int|\DateTime $expire 有效时间 0为永久
     * @return bool
     */
    public function set($name, $value, $expire = null): bool
    {
        $this->writeTimes++;

        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }

        $key        = $this->getCacheKey($name);
        $expire     = $this->getExpireTime($expire);
        $value      = $this->serialize($value);
        $value_type = ColumnTypeConst::CONST_STRING;
        $timestamp  = getMicroTime();

        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            //数据压缩
            $value = gzcompress($value, 3);
            $value_type = ColumnTypeConst::CONST_BINARY;
        }

        $this->handler->putRow([
            'table_name' => $this->options['table_name'],
            'primary_key' => [
                ['key', $key]
            ],
            'attribute_columns' => [
                ['value', $value, $value_type, $timestamp],
                ['expire', $expire, ColumnTypeConst::CONST_INTEGER, $timestamp]
            ]
        ]);

        return true;
    }

    /**
     * 自增缓存（针对数值缓存）
     * @access public
     * @param string $name 缓存变量名
     * @param int    $step 步长
     * @return false|int
     */
    public function inc(string $name, int $step = 1)
    {
        if ($raw = $this->getRaw($name)) {
            $value  = $this->unserialize($raw['content']) + $step;
            $expire = $raw['expire'];
        } else {
            $value  = $step;
            $expire = 0;
        }

        return $this->set($name, $value, $expire) ? $value : false;
    }

    /**
     * 自减缓存（针对数值缓存）
     * @access public
     * @param string $name 缓存变量名
     * @param int    $step 步长
     * @return false|int
     */
    public function dec(string $name, int $step = 1)
    {
        return $this->inc($name, -$step);
    }

    /**
     * 删除缓存
     * @access public
     * @param string $name 缓存变量名
     * @return bool
     */
    public function delete($name): bool
    {
        $this->writeTimes++;

        $this->handler->deleteRow([
            'table_name' => $this->options['table_name'],
            'condition' => RowExistenceExpectationConst::CONST_IGNORE,
            'primary_key' => [
                ['key', $this->getCacheKey($name)]
            ]
        ]);

        return true;
    }

    /**
     * 清除缓存
     * @access public
     * @return bool
     */
    public function clear(): bool
    {
        throw new \BadFunctionCallException('not support: clear');

        return true;
    }

    /**
     * 删除缓存标签
     * @access public
     * @param array $keys 缓存标识列表
     * @return void
     */
    public function clearTag(array $keys): void
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
    }
}
