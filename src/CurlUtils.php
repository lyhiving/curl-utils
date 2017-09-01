<?php

namespace Xxtime\CurlUtils;


/**
 * CurlUtils for spider
 */
class CurlUtils
{

    // curl multi thread
    public $thread = 5;

    // curl options
    public $options = null;

    // default curl options
    private $defaultOptions = [
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
    private $info = [
        'size_download' => 0,
        'task_total'    => 0,
        'task_success'  => 0,
        'task_failed'   => 0,
        'time_total'    => 0,
    ];


    /**
     * get task info
     * @return array
     */
    public function getInfo()
    {
        $this->info['task_failed'] = $this->info['task_total'] - $this->info['task_success'];
        return $this->info;
    }


    /**
     * curl function
     * @param string $url
     * @param string $post
     * @return mixed
     */
    public function curl($url = '', $post = '')
    {
        if ($this->options === null) {
            $this->options = $this->defaultOptions;
        }
        $ch = $this->curlInit($url, $post);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }


    /**
     * curl multi
     * @link http://php.net/manual/zh/function.curl-multi-select.php
     * @param array $urls
     * @param string $callback
     * @return bool
     */
    public function curlMulti($urls = [], $callback = null)
    {
        if (!$urls || !is_array($urls)) {
            return false;
        }

        if ($this->options === null) {
            $this->options = $this->defaultOptions;
        }

        foreach ($urls as $url) {
            $this->info['task_total'] += 1;
            $handles[md5($url)] = $this->curlInit($url);
        }


        $mh = curl_multi_init();
        foreach ($handles as $ch) {
            curl_multi_add_handle($mh, $ch);
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) == -1) {
                usleep(100);
            }

            do { // echo '|';
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($mh)) != false) {
                if ($info["result"] == CURLE_OK) {

                    // stats download size
                    $i = curl_getinfo($info['handle']);
                    $this->info['size_download'] += $i['size_download'];

                    // get content and callback
                    $output = curl_multi_getcontent($info['handle']);
                    curl_multi_remove_handle($mh, $info['handle']);

                    // callback
                    if ($callback) {
                        $callback($output);
                    }

                    $this->info['task_success'] += 1;
                }
            }
        }

        curl_multi_close($mh);
    }


    /**
     * curl init
     * @param string $url
     * @param string $post
     * @return resource
     */
    private function curlInit($url = '', $post = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $this->options);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        return $ch;
    }

}