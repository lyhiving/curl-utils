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
    public $curlOptions;

    // timeout for connect
    public $connectTimeout = 10;

    // timeout
    public $timeout = 30;

    // max deep
    public $maxredirs = 5;

    // user cookie
    public $cookies;

    // headers
    public $headers = ['accept-language: en-US,en;q=0.8', 'Cookie: locale=en_US'];

    // user agent [mobile iphone]
    public $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 9_1 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13B143 Safari/601.1';


    /**
     * curl function
     * @param string $url
     * @param string $post
     * @return mixed
     */
    public function curl($url = '', $post = '')
    {
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
    public function curlMulti($urls = [], $callback = '')
    {
        if (!$urls || !is_array($urls)) {
            return false;
        }

        foreach ($urls as $url) {
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
                    $output = curl_multi_getcontent($info['handle']);
                    curl_multi_remove_handle($mh, $info['handle']);
                    $callback($output);
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
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, $this->maxredirs);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookies);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookies);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        return $ch;
    }

}