<?php

namespace zkr\parser;


abstract class SiteParser {

    protected $ch = false;

    /*public function __construct() {
        $this->ch = curl_init();
        $this->set(CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:38.0) Gecko/20100101 Firefox/38.0")
            ->set(CURLOPT_FOLLOWLOCATION, true)// Автоматом идём по редиректам
            ->set(CURLOPT_SSL_VERIFYPEER, false)// Не проверять SSL сертификат
            ->set(CURLOPT_SSL_VERIFYHOST, false)// Не проверять Host SSL сертификата
            ->set(CURLOPT_HEADER, false)
            ->set(CURLOPT_RETURNTRANSFER, true); // Возвращаем, но не выводим на экран результат
    }*/

    public function exec(string $url, $pauseTime = 1, $retry = 5) {
        $error_page = [];

        curl_setopt($this->ch, CURLOPT_URL, $url); // Куда отправляем
        $response['html'] = curl_exec($this->ch);

        $info = curl_getinfo($this->ch);
        if ($info['http_code'] != 200 && $info['http_code'] != 404) {
            $error_page[] = [1, $url, $info['http_code']];
            if ($retry) {
                sleep($pauseTime);
                $response['html'] = curl_exec($this->ch);
                $info = curl_getinfo($this->ch);
                if ($info['http_code'] != 200 && $info['http_code'] != 404)
                    $error_page[] = [2, $url, $info['http_code']];
            }
        }
        $response['code'] = $info['http_code'];
        $response['errors'] = $error_page;

        return $response;
    }

    public function set($name, $value) {
        curl_setopt($this->ch, $name, $value);

        return $this;
    }

    public function get($name) {
        return $this->$name;
    }

    public function storeFile(string $dir, string $htmlFile, $content, $flags = 0) {
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . $htmlFile, $content, $flags);
    }

    public function __destruct() {
        curl_close($this->ch);
    }
}