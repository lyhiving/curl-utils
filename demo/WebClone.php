<?php


class WebClone
{

    protected $baseUrl;

    protected $domain;

    protected $depth;

    protected $curlUtils;


    public function __construct($url = '', $depth = 0)
    {
        $this->baseUrl = $url;
        $this->depth = $depth;
        $this->curlUtils = new \Xxtime\CurlUtils\CurlUtils();
    }


    public function run()
    {
        $this->curlUtils->setOptions([
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.113 Safari/537.36'
        ]);

        $this->domain = $this->domain($this->baseUrl);
        $this->curlUtils->add(
            $this->baseUrl,
            null,
            [$this, 'callback'],
            ['depth' => $this->depth]
        );
        $this->curlUtils->run();
        print_r($this->curlUtils->getTaskInfo());
    }


    public function callback($content, $header = null, $argv = null)
    {
        $url = $header['url'];


        // save file
        $this->saveFile($header, $content);


        if (strpos($header['Content-Type'], 'text/css')) {
            return true;
        }

        if (strpos($header['Content-Type'], 'application/x-javascript')) {
            return true;
        }

        if (strpos($header['Content-Type'], 'text/html' === false)) {
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
            // TODO :: filter URL
            $urls[] = $this->uri2url($tag->href, $url);
        }
        if ($urls) {
            $urls = array_unique(array_filter($urls));
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
            $urls = array_unique(array_filter($urls));
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
            $urls = array_unique(array_filter($urls));
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
            $urls = array_unique(array_filter($urls));
            $this->curlUtils->add(
                $urls,
                null,
                [$this, 'callback'],
                ['depth' => 0]
            );
        }
    }


    protected function saveFile($header = '', $content = '')
    {
        $cacheTime = 3600;

        if (!$header || !$content) {
            return false;
        }

        $parseUrl = parse_url($header['url']);
        if (!isset($parseUrl['path'])) {
            $parseUrl['path'] = '/';
        }

        $mimeType = ['html', 'htm'];
        if ((strpos($header['Content-Type'], 'html') !== false)
            && !in_array(substr($parseUrl['path'], strrpos($parseUrl['path'], '.') + 1), $mimeType)
        ) {
            $parseUrl['path'] = rtrim($parseUrl['path'], '/') . '/index.html';
        }

        $dirPath = __DIR__ . '/' . $parseUrl['host'] . dirname($parseUrl['path']);
        $path = __DIR__ . '/' . $parseUrl['host'] . $parseUrl['path'];

        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
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
     * get domain
     * @param string $url
     * @return mixed
     */
    protected function domain($url = '')
    {
        return parse_url($url)['host'];
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