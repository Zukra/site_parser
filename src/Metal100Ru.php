<?php

namespace zkr\parser;

class Metal100Ru extends SiteParser implements Parsing {
    private $baseUrl = "http://metal100.ru";
    private $authUrl = "http://metal100.ru/login";
    private $authData = [];
    private $cookieFile = '/metal100.ru.txt';
    private $links = [
        "orders" => ["/orders/"]
    ];
    private $domDocument;

    public function __construct($params) {
        $this->domDocument = $params['domDocument'];
        $this->authData = $params['authData'];
        $this->cookieFile = $params['workDir'] . $this->cookieFile;

        $this->ch = curl_init();
        if (!empty($params['proxy'])) {
            // use proxy
            $this->set(CURLOPT_PROXY, $params['proxy']['proxy'])
                ->set(CURLOPT_PROXYUSERPWD, $params['proxy']['auth']);
//                ->set(CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);// If expected to call with specific PROXY type
        }
        $this->set(CURLOPT_COOKIEJAR, $this->cookieFile)
            ->set(CURLOPT_COOKIEFILE, $this->cookieFile)
            ->set(CURLOPT_REFERER, $this->baseUrl)// Откуда пришли
            ->set(CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:38.0) Gecko/20100101 Firefox/38.0")
            ->set(CURLOPT_FOLLOWLOCATION, true)// Автоматом идём по редиректам
            ->set(CURLOPT_SSL_VERIFYPEER, false)// Не проверять SSL сертификат
            ->set(CURLOPT_SSL_VERIFYHOST, false)// Не проверять Host SSL сертификата
            ->set(CURLOPT_HEADER, false)
            ->set(CURLOPT_RETURNTRANSFER, true); // Возвращаем, но не выводим на экран результат
    }

    public function login() {
        $this->set(CURLOPT_POST, true)
            ->set(CURLOPT_URL, $this->authUrl)
            ->set(CURLOPT_POSTFIELDS, http_build_query($this->authData));

        $response = $this->exec($this->authUrl);

        if ($response['code'] == '200') {
            $loginDocument = $this->domDocument;
            $loginDocument->loadHtml($response['html'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
            $isLogin = $loginDocument->find('#loginBlock a[href=/logout]');
            $isLogin = $isLogin[0] ? true : false;
            if (!$isLogin) {
                $response['errors'][] = ['error login'];
                $response['html'] = '';
            }
        }

        return $response;
    }

    public function getRequestListItems($orderTable) {
        $items = [];
        $tbodyItems = $orderTable->find('tbody tr');
        foreach ($tbodyItems as $tbodyItem) {
            $item = $tbodyItem->find('td');
            $tmp = [];
            foreach ($item as $value) {
                $tmp[] = $value->text();
            }
            $items[] = [
                'n'      => str_replace('-', '', $tmp[0]),
                'name'   => str_replace('-', '', $tmp[1]),
                'count'  => str_replace('-', '', $tmp[2]),
                'length' => str_replace('-', '', $tmp[3]),
                'steel'  => str_replace('-', '', $tmp[4]),
                'price'  => str_replace('-', '', $tmp[5]),
                'prim'   => str_replace('-', '', $tmp[6])
            ];
        }

        return $items;
    }

    public function getUserInfo($contentBlock) {
        $arUserInfo = [];
        $clientCard = $contentBlock->first('ul.clientCard');
        if (isset($clientCard)) {
            foreach ($clientCard->find('li') as $li) {
                $tmp = trim(preg_replace('/\s+/', ' ', $li->text()));
                if (strpos($tmp, 'Контактное лицо: ') !== false) {
                    $arUserInfo['name'] = str_replace(['Контактное лицо: '], [''], $tmp);
                } elseif (strpos($tmp, 'Регион поставки: ') !== false) {
                    $arUserInfo['region'] = str_replace(['Регион поставки: '], [''], $tmp);
                } elseif (strpos($tmp, 'Организация: ') !== false) {
                    $arUserInfo['company'] = str_replace(['Организация: '], [''], $tmp);
                } elseif (strpos($tmp, 'Телефон: ') !== false) {
                    $arUserInfo['contact'] = str_replace(['Телефон: '], [''], $tmp);
                }
            }
        }

        return $arUserInfo['contact'] === 'не указан' ? [] : $arUserInfo;
    }

    /**
     * Получаю и сохраняю страницы
     * @param string $path
     * @return array
     */
    public function storeData(string $path) {
        $htmlFiles = [];
        foreach ($this->links as $categoryId => $category) {
            $dir = $path . $categoryId;
            foreach ($category as $link) {
                $url = $this->baseUrl . $link . '?city=all';
                $page = $this->exec($url);

                $files = $this->storeOrdersPage($page, $category, $dir);
                $htmlFiles = array_merge($htmlFiles, $files);

                // пагинация
                $paginationUrls = $this->getPagination($page['html']);
                if (!empty($paginationUrls)) {
                    foreach ($paginationUrls as $key => $url) {
                        $page = $this->exec($url);
                        $files = $this->storeOrdersPage($page, $category, $dir);
                        $htmlFiles = array_merge($htmlFiles, $files);
                    }
                }
            }
        }

        return $htmlFiles;
    }

    public function parsing(array $htmlFiles) {
        $document = $this->domDocument;
        $data = [];
        foreach ($htmlFiles as $file) {
            $document->loadHtmlFile($file, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
            $contentBlock = $document->find('#contentBlock');
            $user = $this->getUserInfo($contentBlock[0]);
            if (empty($user)) continue; // если нет телефона

            $tmp = isset($contentBlock[0]) ? $contentBlock[0]->find('h1 > span::text') : '';
            $tmp = str_replace(['заявка ', '(', ')'], ['', '', ''], $tmp[0]);
            $arTmp = explode(' от ', $tmp);
            $number = $arTmp[0];
            $date = date('d.m.Y', strtotime($arTmp[1]));
            $currentDate = date('d.m.Y', time());

            if ($date != $currentDate) continue; // только за сегодня

            $orderTable = $contentBlock[0]->find('.orderTable')[0];
            $data[$number] = [
                "number"  => $number,
                "date"    => $date,
                "items"   => $this->getRequestListItems($orderTable),
                "comment" => $orderTable->nextSibling('p')
                    ? trim(preg_replace('/\s+/', ' ', $orderTable->nextSibling('p')->text()))
                    : '',
                "user"    => $user,
            ];
        }

        return $data;
    }

    private function getPagination($html) {
        $result = [];
        $doc = $this->domDocument;
        $doc->loadHtml($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
        $paginationBlock = $doc->find('#pager .pages');
        if (isset($paginationBlock[0])) {
            $paginationUrls = $paginationBlock[0]->find('a::attr(href)');
            $paginationUrls = array_unique($paginationUrls);
            array_walk($paginationUrls, function (&$val) {
                $val = $this->baseUrl . $val;

                return $val;
            });
            $paginationUrls = array_slice($paginationUrls, 0, 4); // get 4 first element
            $i = 2;
            foreach ($paginationUrls as $url) {
                $result[$i++] = $url;
            }
        }

        return $result;
    }

    public function prepareMessage($data) {
        $messages = [];
        foreach ($data as $number => $item) {
            $productStr = '';
            $title = $item['items'][0]['name'];
            foreach ($item['items'] as $product) {
                $productStr .= $product['n'] . ' ' . $product['name']
                    . ($product["count"] ? ' ' . $product["count"] : '')
                    . ($product["length"] ? ' ' . $product["length"] : '')
                    . ($product["steel"] ? ' ' . $product["steel"] : '')
                    . ($product["price"] ? ' ' . $product["price"] : '')
                    . ($product["prim"] ? ' ' . $product["prim"] : '')
                    . "\n";
            }
            $messages[$number] = trim(
                '<b>' . $title . '</b>'
                . "\n" . $item['date'] . "\n"
                . ($item['user']['region'] ? $item['user']['region'] . "\n" : '')
                . $productStr
                . ($item['user']['comment'] ?: '')
                . ($item['user']['company'] ?: '')
                . ($item['user']['name'] ? ' ' . $item['user']['name'] : '')
                . ($item['user']["contact"] ? ' ' . $item['user']["contact"] : '')
            );
        }

        return $messages;
    }

    /**
     * Сохраняю каждую страницу заявки
     * @param $page
     * @param $category
     * @param $dir
     * @return array
     */
    private function storeOrdersPage($page, $category, $dir) {
        $htmlFiles = [];
        $order = $this->domDocument;
        $order->loadHtml($page['html'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
        $orderUrls = array_unique($order->find('.orderList .orderRow a::attr(href)'));
        if (!empty($orderUrls)) {
            foreach ($orderUrls as $orderUrl) {
                $orderPage = $this->exec($this->baseUrl . $orderUrl);
                $htmlFile = '/' . str_replace($category, '', $orderUrl) . '.html';
                $this->storeFile($dir, $htmlFile, $orderPage['html']);
                $htmlFiles[] = $dir . $htmlFile;
            }
        }

        return $htmlFiles;
    }
}