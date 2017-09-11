<?php


class WebClone
{

    protected $baseUrl;

    protected $depth;

    protected $curlUtils;


    public function __construct($url = '', $depth = 0)
    {
        // baseUrl must be end with '/'
        $this->baseUrl = $url;
        $this->depth = $depth;
        $this->curlUtils = new \Xxtime\CurlUtils\CurlUtils();
    }


    public function run()
    {
        $this->curlUtils->setOptions([
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.113 Safari/537.36'
        ]);
        //$html = $this->curlUtils->get($this->baseUrl);

        $this->curlUtils->add(
            $this->baseUrl,
            null,
            [$this, 'callback'],
            ['depth' => $this->depth]
        );
        $this->curlUtils->run();
        print_r($this->curlUtils->getTaskInfo());
    }


    public function callback($content, $curlInfo = null, $argv = null)
    {
        $url = $curlInfo['url'];


        // save file
        $this->saveFile($url, $content);


        if (strpos($curlInfo['content_type'], 'text/css')) {
            return true;
        }

        if (strpos($curlInfo['content_type'], 'application/x-javascript')) {
            return true;
        }

        if (strpos($curlInfo['content_type'], 'text/html' === false)) {
            return true;
        }


        usleep(100000);


        // limit depth
        if ($argv['depth'] == 0) {
            return true;
        }


        // analysis html
        $html = str_get_html($content);


        if (!$html) {
            return false;
        }


        // find url
        $urls = [];
        $tagA = $html->find('a');
        foreach ($tagA as $tag) {
            if (!$tag->href) {
                continue;
            }
            $urls[] = $this->uri2url($tag->href, $url);
        }
        if ($urls) {
            $urls = array_unique($urls);
            $this->curlUtils->add(
                $urls,
                null,
                [$this, 'callback'],
                ['depth' => $argv['depth'] - 1]
            );
        }


        // find css
        $urls = [];
        $tagLink = $html->find('link');
        foreach ($tagLink as $tag) {
            if (!$tag->href) {
                continue;
            }
            $urls[] = $this->uri2url($tag->href, $url);
        }
        if ($urls) {
            $urls = array_unique($urls);
            $this->curlUtils->add(
                $urls,
                null,
                [$this, 'callback'],
                ['depth' => 0]
            );
        }


        // find js
        $urls = [];
        $tagScript = $html->find('script');
        foreach ($tagScript as $tag) {
            if (!$tag->src) {
                continue;
            }
            $urls[] = $this->uri2url($tag->src, $url);
        }
        if ($urls) {
            $urls = array_unique($urls);
            $this->curlUtils->add(
                $urls,
                null,
                [$this, 'callback'],
                ['depth' => 0]
            );
        }


        // image
        $urls = [];
        $tagImg = $html->find('img');
        foreach ($tagImg as $tag) {
            if (!$tag->src) {
                continue;
            }
            $urls[] = $this->uri2url($tag->src, $url);
        }
        if ($urls) {
            $urls = array_unique($urls);
            $this->curlUtils->add(
                $urls,
                null,
                [$this, 'callback'],
                ['depth' => 0]
            );
        }
    }


    protected function saveFile($url = '', $content = '')
    {
        $cacheTime = 3600;

        $uri = $this->url2uri($url);

        if (!$uri || !$content) {
            return false;
        }

        if (strpos($uri, '/')) {
            $dirPath = __DIR__ . '/' . md5($this->baseUrl) . '/' . substr($uri, 0, strrpos($uri, '/'));
        }
        else {
            $dirPath = __DIR__ . '/' . md5($this->baseUrl) . '/';
        }
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $path = $dirPath . substr($uri, strrpos($uri, '/'));

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
     * check URL
     * @param string $url
     * @return bool
     */
    protected function isUrl($url = '')
    {
        return in_array(substr($url, 0, 7), ['http://', 'https:/']);
    }


    /**
     * URI to URL
     * @param string $uri
     * @param string $locateUrl
     * @return bool|string
     */
    protected function uri2url($uri = '', $locateUrl = '')
    {
        if (strpos($uri, '#')) {
            $uri = substr($uri, 0, strpos($uri, '#'));
        }
        if (strpos($uri, '?')) {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }

        if ($this->isUrl($uri)) {
            return $uri;
        }
        if (!$this->isUrl($locateUrl)) {
            return false;
        }

        $parseUrl = parse_url($locateUrl);
        if (!isset($parseUrl['path'])) {
            $parseUrl['path'] = '/';
        }
        if ('/' == substr($parseUrl['path'], -1)) {
            $dir = $parseUrl['path'];
        }
        else {
            $dir = dirname($parseUrl['path']);
        }

        if ('/' != substr($dir, -1)) {
            $dir .= '/';
        }

        if (0 === strpos($uri, '/')) {
            $path = $uri;
        }
        // start with '../' './' ''
        else {
            $uri = rtrim($uri, '/');
            $depth = substr_count($uri, '../');
            if (strrpos($uri, '/') === false) {
                $file = $uri;
            }
            else {
                $file = substr($uri, strrpos($uri, '/') + 1);
            }

            if ($depth != 0) {
                $dirArray = array_slice(array_filter(explode('/', $dir)), 0, -$depth);
            }
            else {
                $dirArray = array_filter(explode('/', $dir));
            }
            if ($dirArray) {
                $path = '/' . implode('/', $dirArray) . '/' . $file;
            }
            else {
                $path = '/' . $file;
            }

        }

        if (isset($parseUrl['port'])) {
            return $parseUrl['scheme'] . '://' . $parseUrl['host'] . ':' . $parseUrl['port'] . $path;
        }

        return $parseUrl['scheme'] . '://' . $parseUrl['host'] . $path;
    }


    /**
     * @param $url
     * @return null|string
     */
    protected function url2uri($url)
    {
        if (!strstr($url, $this->baseUrl)) {
            return null;
        }
        if ($this->baseUrl == $url) {
            return 'index.html';
        }
        $uri = ltrim(str_replace($this->baseUrl, '', $url), '/');

        // TODO :: important bug case fopen error, bug when dir path with '.'
        if (!strpos($uri, '.')) {
            $uri = trim($uri, '/') . '/index.html';
        }
        return $uri;
    }


}

/**
 * @link http://simplehtmldom.sourceforge.net/
 */
include __DIR__ . '/../../../autoload.php';
include __DIR__ . '/simple_html_dom.php';
$baseUrl = 'http://localhost:8080';
$class = new WebClone($baseUrl, 2);
$class->run();