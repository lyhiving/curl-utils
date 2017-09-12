<?php

namespace Xxtime\CurlUtils;


/**
 * CurlUtils for spider
 */

class CurlUtils
{

    // curl multi thread
    protected $thread = 5;

    // default curl options
    protected $options = [
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEFILE     => null,
        CURLOPT_COOKIEJAR      => null,
        CURLOPT_HTTPHEADER     => ['accept-language: en-US,en;q=0.8', 'Cookie: locale=en_US'],
        CURLOPT_USERAGENT      => 'CurlUtils (XT) https://github.com/xxtime/curl-utils',
    ];

    // curl output info
    protected $info = [
        'size_download' => 0,
        'task_total'    => 0,
        'task_unique'   => 0,
        'task_success'  => 0,
        'task_fail'     => 0,
        'time_total'    => 0,
    ];

    // cache time
    protected $cacheTime = 3600;

    // task pool
    protected $taskPool = [];

    // task Set
    protected $taskSet = [];

    // task fail
    protected $taskFail = [];

    // running task number
    protected $running = null;

    // curl multi handle
    protected $mh;


    /**
     * set curl default options
     * @param array $options
     * @return bool
     */
    public function setOptions($options = [])
    {
        if (!is_array($options)) {
            return false;
        }
        foreach ($options as $opt => $value) {
            if (is_int($opt)) {
                $this->options[$opt] = $value;
            }
            elseif (defined($opt)) {
                $this->options[constant($opt)] = $value;
            }
        }
    }


    /**
     * set thread number
     * @param int $number
     */
    public function setThread($number = 0)
    {
        $this->thread = $number;
    }


    /**
     * get curl options
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }


    /**
     * get task info
     * @return array
     */
    public function getTaskInfo()
    {
        $this->info['task_unique'] = count($this->taskSet);
        return $this->info;
    }


    /**
     * curl get
     * @param string $url
     * @param array $data
     * @return mixed
     */
    public function get($url = '', $data = [])
    {
        if ($data) {
            if (strpos($url, '?')) {
                $url .= '&' . http_build_query($data);
            }
            else {
                $url .= '?' . http_build_query($data);
            }
        }
        $options = $this->options;
        $options[CURLOPT_HTTPGET] = true;
        $ch = $this->curlInit($url, $options);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }


    /**
     * curl post
     * @param string $url
     * @param null $data
     * @return mixed
     */
    public function post($url = '', $data = null)
    {
        $options = $this->options;
        $options[CURLOPT_POST] = true;
        if ($data) {
            $options[CURLOPT_POSTFIELDS] = $data;
        }
        $ch = $this->curlInit($url, $options);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }


    /**
     * add task into task pool
     * @param array $urls
     * @param array $options
     * @param null $callback
     * @param null $argv
     * @return bool
     */
    public function add($urls = [], $options = [], $callback = null, $argv = null)
    {
        if (!is_array($urls)) {
            $urls = [$urls];
        }
        if ($callback && !is_callable($callback)) {
            return false;
        }

        foreach ($urls as $url) {
            $url = trim($url, '/ ');
            $hash = $this->urlHash($url);
            if (array_key_exists($hash, $this->taskSet)) {
                continue;
            }
            $this->taskSet[$hash] = [
                'callback' => $callback,
                'argv'     => $argv,
            ];

            $this->taskPool[$hash] = [
                'url'     => $url,
                'options' => $options,
            ];
        }

        $this->info['task_total'] += count($urls);
    }


    /**
     * curl multi run
     * @return bool
     */
    public function run()
    {
        $startTime = microtime(true);

        $this->mh = curl_multi_init();

        $this->checkTask();

        // $this->running && $mrc == CURLM_OK
        while ($this->running) {
            if (curl_multi_select($this->mh) == -1) {
                usleep(100);
            }

            $this->curlMultiExec();

            while (($info = curl_multi_info_read($this->mh)) != false) {

                $i = curl_getinfo($info['handle']);

                if ($info["result"] == CURLE_OK) {

                    // stats download size
                    $this->info['size_download'] += $i['size_download'];

                    // get content and close handle
                    $output = curl_multi_getcontent($info['handle']);
                    curl_multi_remove_handle($this->mh, $info['handle']);
                    curl_close($info['handle']);

                    // http code == 200
                    $hash = $this->urlHash(rtrim($i['url'], '/'));
                    if ($i['http_code'] == 200) {

                        // set cache
                        if ($this->cacheTime) {
                            $this->setCache($i['url'], $output);
                        }

                        if (isset($this->taskSet[$hash])) {
                            $callback = $this->taskSet[$hash]['callback'];
                            call_user_func_array($callback, [$output, $i, $this->taskSet[$hash]['argv']]);
                        }
                        else {
                            // TODO :: default callback function
                        }
                        $this->info['task_success'] += 1;
                    }

                    // http code != 200
                    else {
                        if (array_key_exists($hash, $this->taskFail)) {
                            $this->taskFail[$hash]['failed'] += 1;
                        }
                        else {
                            $this->taskFail[$hash] = [
                                'url'      => $i['url'],
                                'callback' => isset($this->taskSet[$hash]) ? $this->taskSet[$hash]['callback'] : null,
                                'failed'   => 1,
                            ];
                        }

                        // TODO :: error callback function

                        $this->logger($i['http_code'] . ' ' . $i['url']);
                        $this->info['task_fail'] += 1;
                    }
                }

                if ($info["result"] != CURLE_OK) {
                    $this->logger('CurlError: ' . $info["result"] . ' ' . $i['url']);
                    $this->info['task_fail'] += 1;
                }

                $this->checkTask();
            }
        }

        curl_multi_close($this->mh);

        $this->info['time_total'] = microtime(true) - $startTime;
    }


    /**
     * check and add task into pool
     */
    protected function checkTask()
    {
        if (!$this->taskPool) {
            return false;
        }

        $count = min($this->thread - $this->running, count($this->taskPool));

        while ($count > 0) {
            $task = array_shift($this->taskPool);
            if (!$task['options']) {
                $task['options'] = &$this->options;
            }
            curl_multi_add_handle($this->mh, $this->curlInit($task['url'], $task['options']));

            $count--;
        }

        $this->curlMultiExec();
    }


    /**
     * curl_multi_exec
     */
    protected function curlMultiExec()
    {
        // echo '|';
        do {
            $mrc = curl_multi_exec($this->mh, $this->running);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }


    /**
     * curl init
     * @param string $url
     * @param array $options
     * @return resource
     */
    protected function curlInit($url = '', $options = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, str_replace(' ', '+', trim($url)));
        curl_setopt_array($ch, $options);
        return $ch;
    }


    /**
     * check from cache
     * @param string $url
     * @return bool|string
     */
    protected function getCache($url = '')
    {
        $hash = $this->urlHash($url);
        $dir = __DIR__ . '/../cache/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $path = $dir . '/' . $hash;

        if (file_exists($path)) {
            if (time() - filemtime($path) < $this->cacheTime) {
                $fp = fopen($path, 'r');
                $file = fread($fp, filesize($path));
                fclose($fp);
                return $file;
            }
        }
        return false;
    }


    /**
     * cache file
     * @param string $url
     * @param string $content
     * @return bool|int
     */
    protected function setCache($url = '', $content = '')
    {
        if (!$url || !$content) {
            return false;
        }
        $hash = $this->urlHash($url);
        $dir = __DIR__ . '/../cache/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $path = $dir . '/' . $hash;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_exists($path)) {
            if (time() - filemtime($path) < $this->cacheTime) {
                return false;
            }
        }

        $fp = fopen($path, 'w');
        flock($fp, LOCK_EX);
        fwrite($fp, $content);
        flock($fp, LOCK_UN);
        return true;
    }


    /**
     * logger
     * @param string $log
     */
    protected function logger($log = '')
    {
        $fileLogs = __DIR__ . '/../logs.txt';
        $fp = fopen($fileLogs, 'a');
        flock($fp, LOCK_EX);
        fwrite($fp, date('Y-m-d H:i:sO') . ' | ' . $log . "\r\n");
        flock($fp, LOCK_UN);
    }


    /**
     * url hash
     * @param $url
     * @return string
     */
    protected function urlHash($url)
    {
        return md5(substr(strstr($url, '://'), 3));
    }

}