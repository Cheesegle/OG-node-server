<?php

class Magenet
{
    private $cache_time = 3600;
    private $api_host = "http://api.magenet.com";
    private $api_get = "/wordpress/get";

    private $links_db_file;
    private $links_array;
    private $page_links;
    private $verbose = false;
    private $version = '0.3';
    private $links_counter = 0;


    public function __construct()
    {
        if (class_exists('MagenetLinkAutoinstall')) {
            return '<!-- Please turn off WordPress MageNet Monetization Plugin -->';
        }

        $this->links_db_file = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . _MN_USER . DIRECTORY_SEPARATOR . 'mn-links.db';
        $this->getLinksArray();
        $this->getLinksForPage();
    }


    public function getLinks($show = 0)
    {
        $mads_block = '<div class="mads-block">';

        $counter = 1;
        if ($this->page_links) {
            foreach ($this->page_links as $link) {
                $mads_block .= "\n" . "<span class='mads{$this->links_counter}'>" . $link . "</span>";

                $this->links_counter++;

                if ($show != 0 && is_int($show) && $show > 0 && $show <= count($this->page_links) && $show == $counter) {
                    break;
                }

                $counter++;
            }

            $this->page_links = array_slice($this->page_links, $counter);
        }

        $mads_block .= '</div>';

        return $mads_block;
    }


    public function getLinksArray()
    {
        if ($this->getLinksFileTime() < 0 || ($this->getLinksFileTime() > 0 && $this->getLinksFileTime() + $this->cache_time < time())) {
            $this->getLinksFromMn();
        }

        $links = $this->getFileContent($this->links_db_file);
        $links_array = array();

        if ($links) {
            $links_array = json_decode($links, true);
        }

        $this->links_array = count($links_array) > 0 ? $links_array : false;

        return $this->links_array;
    }


    public function getLinksForPage()
    {
        if (!$this->links_array) {
            return false;
        }

        $page_links = array();
        $page_url = $this->getPageUrl();

        foreach ($this->links_array as $value) {
            if ($this->compareURLs($value['page_url'], $page_url)) {
                $page_links[] = $value['issue_html'];
            }
        }

        $this->page_links = count($page_links) > 0 ? $page_links : false;

        return $this->page_links;
    }


    public function getLinksFromMn()
    {
        $res = $this->sendRequest($this->api_host . $this->api_get);

        if ($res) {
            $this->setFileContent($this->links_db_file, $res);
        }
    }


    public function getPageUrl()
    {
        return $this->full_url($_SERVER);
    }


    public function compareURLs($links_page_url, $page_url)
    {
        $links_page_url = $this->purge(urldecode($links_page_url));
        $page_url = $this->purge(urldecode($page_url));

        if ($links_page_url == $page_url) {
            return true;
        }

        if (strpos($links_page_url, '?') > -1 && strpos($page_url, '?') > -1) {

            $links_page_url_array = parse_url("http://" . $links_page_url);
            $page_url_array = parse_url("http://" . $page_url);

            if ($links_page_url_array['path'] == $page_url_array['path']) {

                if ($this->compareQueries($page_url_array['query'], $links_page_url_array['query'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function compareQueries($a = null, $b = null)
    {
        if ($a && strlen($a) > 0 && $b && strlen($b) > 0) {

            parse_str($a, $a_array);
            parse_str($b, $b_array);

            if (count(array_diff_assoc($a_array, $b_array)) == 0 && count(array_diff_assoc($b_array, $a_array)) == 0) {
                return true;
            }
        }

        return false;
    }


    private function getFileContent($filename)
    {
        $fp = @fopen($filename, 'r');
        @flock($fp, LOCK_SH);

        if ($fp) {
            clearstatcache();
            $length = @filesize($filename);

            if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                $mqr = @get_magic_quotes_runtime();
                @set_magic_quotes_runtime(0);
            }

            if ($length) {
                $data = @fread($fp, $length);
            } else {
                $data = '';
            }

            if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                @set_magic_quotes_runtime($mqr);
            }

            @flock($fp, LOCK_UN);
            @fclose($fp);

            return $data;
        }

        return $this->showError("Can't read data from file: " . $filename);
    }


    function setFileContent($filename, $data)
    {
        $fp = @fopen($filename, 'w');

        if ($fp) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                ftruncate($fp, 0);

                if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                    $mqr = @get_magic_quotes_runtime();
                    @set_magic_quotes_runtime(0);
                }

                if ($data != '') {
                    @fwrite($fp, $data);
                }

                if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                    @set_magic_quotes_runtime($mqr);
                }

                @flock($fp, LOCK_UN);
                @fclose($fp);

                if (md5($this->getFileContent($filename)) != md5($data)) {
                    @unlink($filename);

                    return $this->showError('File corrupt: ' . $filename);
                }
            } else {
                return false;
            }

            return true;
        }

        return $this->showError("Can't write data to file: " . $filename);
    }


    public function sendRequest($url)
    {
        $params = http_build_query(array(
            'url' => $this->url_origin($_SERVER),
            'key' => _MN_USER,
            'version' => $this->version,
            'type' => '1'
        ));

        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_ENCODING, "gzip,deflate");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

            $curl_result = curl_exec($ch);

            if (!curl_errno($ch)) {
                $result = $curl_result;
            } else {
                $result = false;
            }

            curl_close($ch);
        } else {
            $url = $url . "?" . $params;
            $result = @file_get_contents($url, false);
        }

        return $result;
    }

    public function getLinksFileTime()
    {
        if (file_exists($this->links_db_file)) {
            return filemtime($this->links_db_file);
        }

        return -1;
    }

    public function setVerbose()
    {
        $this->verbose = true;
    }


    public function showError($e)
    {
        if ($this->verbose) {
            echo '<p style="color: red; font-weight: bold;">Magenet error: ' . $e . '</p>';
        }

        return false;
    }

    public function url_origin($s, $use_forwarded_host = false)
    {
        $ssl = (!empty($s['HTTPS']) && $s['HTTPS'] == 'on');
        $sp = strtolower($s['SERVER_PROTOCOL']);
        $protocol = substr($sp, 0, strpos($sp, '/')) . (($ssl) ? 's' : '');
        $port = $s['SERVER_PORT'];
        $port = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
        $host = ($use_forwarded_host && isset($s['HTTP_X_FORWARDED_HOST'])) ? $s['HTTP_X_FORWARDED_HOST'] : (isset($s['HTTP_HOST']) ? $s['HTTP_HOST'] : null);
        $host = isset($host) ? $host : $s['SERVER_NAME'] . $port;

        return $protocol . '://' . $host;
    }


    public function full_url($s, $use_forwarded_host = false)
    {
        return $this->url_origin($s, $use_forwarded_host) . $s['REQUEST_URI'];
    }

    private function purge($url)
    {
        $url = strtolower($url);
        $url = $this->str_replace_first("https://www.", '', $url);
        $url = $this->str_replace_first("http://www.", '', $url);
        $url = $this->str_replace_first("https://", '', $url);
        $url = $this->str_replace_first("http://", '', $url);

        $pos = strrpos($url, '/');

        if ($pos !== false && $pos == strlen($url) - 1) {
            $url = substr($url, 0, -1);
        }

        return $url;
    }

    public function str_replace_first($search, $replace, $subject)
    {
        $search = '/' . preg_quote($search, '/') . '/';
        return preg_replace($search, $replace, $subject, 1);
    }

}
