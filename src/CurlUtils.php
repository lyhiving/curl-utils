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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 9_1 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13B143 Safari/601.1',
    ];

    // curl output info
    protected $info = [
        'size_download' => 0,
        'task_unique'   => 0,
        'task_total'    => 0,
        'task_success'  => 0,
        'task_fail'     => 0,
        'time_total'    => 0,
    ];

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
     * set thread number
     * @param int $number
     */
    public function setThread($number = 0)
    {
        $this->thread = $number;
    }


    /**
     * set curl default options
     * @param array $options
     * @param null $type
     * @return bool
     */
    public function setOptions($options = [], $type = null)
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
    public function getInfo()
    {
        $this->info['task_unique'] = count($this->taskSet);
        return $this->info;
    }


    /**
     * @param array $urls
     * @param array $options
     * @param null $callback [$class, 'function']
     * @param array $argv [callback argv]
     */
    public function addTask($urls = [], $options = [], $callback = null, $argv = null)
    {
        if (!is_array($urls)) {
            $urls = [$urls];
        }

        foreach ($urls as $url) {
            $hash = md5($url);
            if (array_key_exists($hash, $this->taskSet)) {
                $this->info['task_success'] += 1;
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
     * curl multi
     * @return bool
     */
    public function curlMultiRun()
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
                if ($info["result"] == CURLE_OK) {

                    // stats download size
                    $i = curl_getinfo($info['handle']);
                    $this->info['size_download'] += $i['size_download'];

                    // get content and close handle
                    $output = curl_multi_getcontent($info['handle']);
                    curl_multi_remove_handle($this->mh, $info['handle']);
                    curl_close($info['handle']);

                    // http code 200
                    $hash = md5($i['url']);
                    if ($i['http_code'] == 200) {
                        $callback = $this->taskSet[$hash]['callback'];
                        if (is_callable($callback)) {
                            call_user_func_array($callback, [$output, $this->taskSet[$hash]['argv']]);
                        }

                        $this->info['task_success'] += 1;
                    }

                    else {
                        if (array_key_exists($hash, $this->taskFail)) {
                            $this->taskFail[$hash]['failed'] += 1;
                        }
                        else {
                            $this->taskFail[$hash] = [
                                'url'      => $i['url'],
                                'callback' => $this->taskSet[$hash]['callback'],
                                'failed'   => 1,
                            ];
                        }

                        $this->saveFile($i['url'], $output);

                        $this->logger($i['http_code'] . ' ' . $i['url']);
                        $this->info['task_fail'] += 1;
                    }
                }

                if ($info["result"] != CURLE_OK) {
                    $this->logger('Failed: ' . $i['url']);
                    $this->info['task_fail'] += 1;
                }

                $this->checkTask();
            }
        }

        curl_multi_close($this->mh);

        $this->info['time_total'] = microtime(true) - $startTime;
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
     * cache file
     * @param string $url
     * @param string $content
     * @return bool|int
     */
    protected function saveFile($url = '', $content = '')
    {
        $cacheTime = 3600;
        if (!$url || !$content) {
            return false;
        }
        $hash = md5($url);
        $dir = __DIR__ . '/../cache/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $path = $dir . '/' . $hash;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_exists($path)) {
            if (time() - filemtime($path) < $cacheTime) {
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
        fwrite($fp, date('Y-m-d H:i:s') . ' | ' . $log . "\r\n");
        flock($fp, LOCK_UN);
    }

}