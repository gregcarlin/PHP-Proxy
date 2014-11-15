<?php

require_once "url_to_absolute.php";

$host = $_SERVER['SERVER_NAME'];
//$host = "gregorycarlin.com";

// http://stackoverflow.com/questions/834303/startswith-and-endswith-functions-in-php
function startsWith($haystack, $needle) {
    return $needle === "" || strpos($haystack, $needle) === 0;
}

function endsWith($haystack, $needle) {
    return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
}

function startsWithHttp($url) { // who needs regex?
    return startsWith($url, "http://") || startsWith($url, "https://");
}

// returns 'http://127.0.0.1/Personal/p/?url=' or 'http://gregorycarlin.com/p/?url='
function currentPrefix() {
    global $host;
    $name = $host . "/";
    if($name == "127.0.0.1/" || $name == "localhost/") {
        $name .= "Personal/p/?url=";
    } else { // assume gregorycarlin.com
        $name .= "p/url/";
    }
    //$name .= "/?url=";
    //$name .= "/url/";
    return "http://" . $name;
}

function fixLink($dom, $tag, $attr, $url) {
    $links = $dom->getElementsByTagName($tag);

    for($i = 0; $i < $links->length; $i++) {
        $link = $links->item($i);
        if(!$link->hasAttribute($attr)) continue;
        $href = $links->item($i)->getAttribute($attr);
        //echo "before: " . $href . "<br />";
        /*if(startsWithHttp($href)) {
            $href = currentPrefix() . $href;
            //$href = "http://gregorycarlin.com/p/?url=" . $href;
        } else if(startsWith($href, "//")) {
            $href = currentPrefix() . "http:" . $href;
            //$href = "http://gregorycarlin.com/p/?url=http:" . $href;
        } else {
            $href = currentPrefix() . $url . $href;
            //$href = "http://gregorycarlin.com/p/?url=" . $url . $href;
        }*/
        $href = currentPrefix() . encode(url_to_absolute($url, $href));
        $link->setAttribute($attr, $href);
        //echo "now: " . $href . "<br /><br />";
    }

}

//$key = "hello";

function encode($url) {
    //global $key;
    //return mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $url, MCRYPT_MODE_CBC);
    //return $url;
    return base64_encode($url);
}

function decode($url) {
    //global $key;
    //return mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key, $url, MCRYPT_MODE_CBC);
    //return $url;
    return base64_decode($url);
}

function isHome() {
    global $host;
    return $host == "127.0.0.1" || $host == "localhost";
}

if(isset($_GET['url']) && strlen($_GET['url']) > 0) {

    //echo $_SERVER['REQUEST_URI']; die();

    // easier to access variable
    $url = $_GET['url'];

    // decode url. used when url is encoded (stylesheets, images, etc.)
    if(!isset($_GET['encoded']) || $_GET['encoded'] != "false") {
        $url = decode($url);
    } else {
        //header("Location: url/" . encode($url));
        header("Location: " . (isHome() ? "?url=" : "url/") . encode($url));
        die();
    }

    // allow users to type 'youtube.com' instead of 'http://youtube.com'
    if(!startsWithHttp($url)) {
        $url = "http://" . $url;
    }

    $request = $_SERVER['REQUEST_URI'];
    //echo "request = " . $request . "<br />";
    $index = strrpos($request, "?");
    if($index !== FALSE) {
        $url .= substr($request, $index);
    }

    // replace spaces with '%20'
    //$url = str_replace(' ', '%20', $url);

    /*if(!endsWith($url, "/")) {
        $url = $url . "/";
    }*/

    // TODO: headers: http://stackoverflow.com/questions/2107759/php-file-get-contents-and-headers AND http://www.php.net/manual/en/function.getallheaders.php

    $all = getallheaders();
    $opts = array(
      'http' => array(
            'Connection' => $all['Connection'],
            'Cache-Control' => $all['Cache-Control'],
            'Accept' => $all['Accept'],
            'User-Agent' => $all['User-Agent'],
            'Accept-Encoding' => $all['Accept-Encoding'],
            'Accept-Language' => $all['Accept-Language'],
            'Cookie' => $all['Cookie']
        )
    );

    /*echo var_dump($all);
    echo '<br /><br />';
    echo var_dump($opts);
    return;*/

    $context = stream_context_create($opts);

    // retrieve the desired url
    @$html = file_get_contents($url, false, $context);

    if($html === FALSE) {
        echo "Unable to retrieve " . $url;
        die();
    }

    // create a DOM object to parse the website
    $DOM = new DOMDocument;
    @$DOM->loadHTML($html);

    // detect if the loaded url was html
    if($DOM->getElementsByTagName("head")->length >= 1) {

        //echo "html";

        // everything that needs to be fixed
        $stuff = array(
                "a" => "href",
                "img" => "src",
                "script" => "src",
                "link" => "href",
                "iframe" => "src",
                "form" => "action"
            );

        // rewrite certain attributes for certain tags so everything references the proxy
        foreach($stuff as $tag => $attr) {
            fixLink($DOM, $tag, $attr, $url);
        }

        $tracking = $DOM->createElement("script", "(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
            (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
            m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
            })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

            ga('create', 'UA-51503078-1', 'gregorycarlin.com');
            ga('send', 'pageview');");
        $head = $DOM->getElementsByTagName("head")->item(0);
        $head->appendChild($tracking);

        // save the modified html back into the $html variable
        $html = $DOM->saveHTML();

        foreach ($http_response_header as $header) {
            //echo $header . "\n";
            if(startsWith($header, "Set-Cookie")) {
                $indexA = strpos($header, "domain=");
                $indexB = strpos($header, ";", $indexA);
                if($indexB === false) {$indexB = strlen($header) - 1;}
                $h = substr($header, 0, $indexA) . "domain=" . $host . ";" . substr($header, $indexB + 1);
                //$h = $indexB ? substr($header, $indexA, $indexB) : substr($header, $indexA);
                //echo $h . " <-- MOD\n";
                header($header);
            }
        }

    /*} else if(endsWith($url, ".css")) {

        $last = 0;
            //$index = strpos($html, 'url(', $last);
            while(($index = strpos($html, 'url(', $last)) !== false) {
                echo "last = " . $last . "\n";
                echo "index = " . $index . "\n";
                echo "\n";
                $start = min(strpos($html, "'", $last), strpos($html, '"', $last));
                $stop = min(strpos($html, "'", $start), strpos($html, '"', $start));
                $contents = currentPrefix() . urlencode(url_to_absolute($url, substr($html, $start + 1, $stop)));
                $html = substr($html, 0, $start + 1) . $contents . substr($html, $stop);
                $last = $index + 4;
                //$index = strpos($html, 'url(', $last);

                //echo $html . "\n----";
            }*/

    } else {

        // the url was not html or css, pass all headers along so end user understands the actual file type
        foreach ($http_response_header as $header) {
            header($header);
        }

    }

    //echo var_dump($http_response_header);

    // show the new html
    echo $html;
    return;

}

?>
<html>
    <head>
        <link rel="stylesheet" href="style.css" />
        <script>
            (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
            (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
            m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
            })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

            ga('create', 'UA-51503078-1', 'gregorycarlin.com');
            ga('send', 'pageview');
        </script>
        <title>Enter an address</title>
    </head>
    <body>
        <div class="main-container">
            <form method="get" action="#">
                <input type="hidden" name="encoded" id="encoded" value="false" />
                <input type="text" name="url" id="url" size="75" placeholder="google.com" autofocus />
            </form>
            <div class="links">
                <!--<a href="#">Report a problem</a>-->
            </div>
        </div>
    </body>
</html>