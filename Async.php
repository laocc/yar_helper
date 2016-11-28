<?php
namespace laocc\plugs;

class Async
{
    private $_timeout = 10;
    private $_url = [];
    private $_index = 0;
    private $_post_key = ['__async_action_', '__async_data_'];
    private $_token = 'myToken';
    private $_isServer = false;
    private $_isAsync = true;
    private $_server;

    public function __construct($sev = true)
    {
        if (is_object($sev)) {
            $this->_isServer = true;
            $this->_server = $sev;
        } elseif (is_bool($sev) or is_int($sev)) {
            $this->_isAsync = boolval($sev);
        } elseif (preg_match('/^https?\:\/\/[\w\.]+\.[a-z]+\/.+$/i', $sev)) {
            $this->_url[0] = $sev;
        } else {
            throw new \Exception('若需要客户端请提供布尔型或一个网址URL参数，若需要服务器端请提供实例类对象参数，而当前参数不是这三种类型的值。');
        }
    }


    /**
     * 服务器端侦听请求
     */
    public function listen()
    {
        if (empty($_POST)) return;
        if (!$this->_isServer) throw new \Exception('当前Async对象是客户端，不可调用send方法');

        $action = isset($_POST[$this->_post_key[0]]) ? $_POST[$this->_post_key[0]] : null;
        $data = isset($_POST[$this->_post_key[1]]) ? $_POST[$this->_post_key[1]] : null;
        if (is_null($data)) return;

        $agent = getenv('HTTP_USER_AGENT');
        if (!$agent) return;
        $host = getenv('HTTP_HOST');
        if (!hash_equals(md5("{$host}:{$data}/{$this->_token}"), $agent)) return;
        if (!method_exists($this->_server, $action) or !is_callable([$this->_server, $action])) {
            throw new \Exception("{$action} 方法不存在");
        }
        $data = unserialize($data);
        if (!is_array($data)) $data = [$data];


        ob_start();
        $v = $this->_server->{$action}(...$data + array_fill(0, 10, null));
        if (!is_null($v)) {//优先取return值
            ob_end_clean();
            echo serialize($v);
        } else {
            $v = ob_get_contents();
            ob_end_clean();
            echo serialize($v);
        }
        ob_flush();
    }

    /*===========================================client=============================================================*/

    public function timeout($sec)
    {
        $this->_timeout = $sec;
    }

    /**
     * 调用服务器端类方法的魔术方法
     */
    public function __call($name, $arguments)
    {
        if ($this->_isServer) throw new \Exception('当前是服务器端，只可以调用listen方法，若需要客户端的功能，请创建为客户端对象。');

        $success = function ($index, $value) use (&$data) {
            $data = unserialize($value);
        };
        $error = function ($index, $err_no, $err_str) {
            throw new \Exception($err_str, $err_no);
        };
        $this->_isAsync = false;
        $url = $this->_url[0];
        $this->_url = [];
        $this->_url[0] = $this->realUrl($url, $name, $arguments) + ['success_call' => $success, 'error_call' => $error];
        $this->send();
        return $data;
    }


    /**
     * 添加请求，但不会立即执行，send()时一并发送执行
     * @param $url
     * @param $action
     * @param array $data
     * @param callable|null $success_call
     * @param callable|null $error_call
     * @return int
     * @throws \Exception
     */
    public function call($url, $action, $data = [], callable $success_call = null, callable $error_call = null)
    {
        if ($this->_isServer) throw new \Exception('当前Async对象是服务器端，不可调用call方法');
        $this->_url[++$this->_index] = $this->realUrl($url, $action, $data) + ['success_call' => $success_call, 'error_call' => $error_call];
        return $this->_index;
    }

    private function realUrl($url, $action, $data)
    {
        $info = explode('/', $url, 4);
        if (count($info) < 4) throw new \Exception("请求调用地址不是一个合法的URL");
        list($host, $port) = explode(':', $info[2] . ':80');
        $_data = [$this->_post_key[0] => $action, $this->_post_key[1] => $data = serialize($data)];
        return [
            'version' => (strtoupper($info[0]) === 'HTTPS') ? 'HTTP/2.0' : 'HTTP/1.1',
            'host' => $host,
            'port' => intval($port),
            'uri' => "/{$info[3]}",
            'url' => $url,
            'agent' => md5("{$host}:{$data}/{$this->_token}"),
            'data' => $_data,
        ];
    }

    /**
     * 发请所有请求
     * @param callable|null $success_call
     * @param callable|null $error_call
     * @return bool
     * @throws \Exception
     */
    public function send(callable $success_call = null, callable $error_call = null)
    {
        if ($this->_isServer) throw new \Exception('当前Async对象是服务器端，不可调用send方法');

        foreach ($this->_url as $index => $item) {
            $success_call = $item['success_call'] ?: $success_call;
            $error_call = $item['error_call'] ?: $error_call;
            if (is_null($success_call) and $this->_isAsync === false) throw new \Exception('非异步请求，必须提供处理返回数据的回调函数');

            $fp = fsockopen($item['host'], $item['port'], $err_no, $err_str, $this->_timeout);
            if (!$fp) {
                if (!is_null($error_call)) {
                    $error_call($index, $err_no, $err_str);
                } else {
                    throw new \Exception($err_str, $err_no);
                }
            } else {
                $_data = http_build_query($item['data']);
                $data = "POST {$item['uri']} {$item['version']}\r\n";
                $data .= "Host:{$item['host']}\r\n";
                $data .= "Content-type:application/x-www-form-urlencoded\r\n";
                $data .= "User-Agent:{$item['agent']}\r\n";
                $data .= "Content-length:" . strlen($_data) . "\r\n";
                $data .= "Connection:Close\r\n\r\n{$_data}";

                fwrite($fp, $data);
                if ($this->_isAsync) {
                    if (!is_null($success_call)) {
                        $success_call($index, null);
                    }
                } else {
                    if (!is_null($success_call)) {
                        $value = $tmpValue = '';
                        $len = null;
                        while (!feof($fp)) {
                            $line = fgets($fp);
                            if ($line == "\r\n" and is_null($len)) {
                                $len = 0;//已过信息头区
                            } elseif ($len === 0) {
                                $len = hexdec($line);//下一行的长度
                            } elseif (is_int($len)) {
                                $tmpValue .= $line;//中转数据，防止收到的一行不是一个完整包
                                if (strlen($tmpValue) >= $len) {
                                    $value .= substr($tmpValue, 0, $len);
                                    $tmpValue = '';
                                    $len = 0;//收包后归0
                                }
                            }
                        }
                        $success_call($index, $value);
                    }
                }
                fclose($fp);
            }
        }
        return true;
    }

    /**
     * 清空一个或全部
     * @param null $index
     */
    public function flush($index = null)
    {
        if ($this->_isServer) throw new \Exception('当前Async对象是服务器端，不可调用flush方法');

        if (is_null($index)) {
            $this->_url = [];
            $this->_index = 0;
        } elseif (is_array($index)) {
            array_map('self::flush', $index);
        } else {
            unset($this->_url[$index]);
        }
    }


}
