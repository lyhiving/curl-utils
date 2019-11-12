# CurlUtils
curl multi for spider and more



## How to use it

```php
use Xxtime\CurlUtils\CurlUtils
$curlUtils = new CurlUtils();

// get method
$curlUtils->get('https://www.xxtime.com');

// post method
$curlUtils->post('https://www.xxtime.com', ['title' => 'XT curlUtils']);

// set custom curl options
$curlUtils->setOptions([
    "CURLOPT_TIMEOUT"   => 20,
    "CURLOPT_USERAGENT" => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.113 Safari/537.36'
]);        
```

## Use for multi request

```php
use Xxtime\CurlUtils\CurlUtils

class Demo{

    protected $curlUtils;

    public function run(){
        $this->curlUtils = new CurlUtils();
        $this->curlUtils->setOptions([
            "CURLOPT_USERAGENT" => 'Mozilla/5.0 (Macintosh; Intel Mac OS X)',
        ]);

        $urls = [
            'https://www.xxtime.com',
        ];
        $this->curlUtils->add(
            $urls,                  // urls 
            null,                   // set custom curl options for every url
            [$this, 'callback'],    // callback function
            ['depth' => 5]          // custom argv will be use in callback function
        );
        $this->curlUtils->run();
    }

    public function callback($content, $header, $argv){
        // do something

        // no Content-Type then ignore
        if (empty($header['Content-Type'])) {
            return false;
        }

        // not a html page, save content or ignore
        if (strpos($header['Content-Type'], 'text/html' === false)) {
            return true;
        }

        // limit the request depth
        if ($argv['depth'] == 0) {
            return true;
        }

        // analysis the html content
        // continue to add new tasks into the task pool
        $options = ["CURLOPT_REFERER" => $header['url']];
        $this->curlUtils->add(
            $urls,
            $options,
            [$this, 'callback'],
            ['depth' => $argv['depth'] - 1]
        );
    }
}

```



