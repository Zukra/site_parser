<?php

namespace zkr\parser;


use DateTime;
use DiDom\Document;

class SuplBiz extends SiteParser implements Parsing {
    private $baseUrl = "https://supl.biz/api/v1.0";
    private $authUrl = "https://supl.biz/api/v1.0/auth/";
    private $authData = [];
    private $cookieFile = '/supl.biz.txt';
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
        $response['result'] = json_decode($response['html'], true);
        if ($response['code'] != '200') {
            $isLogin = $response['result']['id'] ? true : false;
            if (!$isLogin) {
                $response['errors'] = $response['result'];
                $response['result'] = '';
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

    public function getUserInfo($url) {
        $userInfo = [];
        if (!empty($url)) {
            $contacts = $this->exec($url, 20, 2);
            if ($contacts['code'] == 200) {
                $contact = json_decode($contacts['html'], true);
                $user = $contact['user'];
                $userInfo = [
                    'id'           => $user['id'],
                    'name'         => $user['name'],
                    'email'        => $user['email'],
                    'phone'        => $user['phone'],
                    'region'       => $user['origin'],
                    'company_name' => $user['company_name'],
                ];
            }
        }

        return $userInfo;
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
                $url = $this->baseUrl . $link . 'elsearch/?completed=all&rubrics=6152,6149,3550,6150,6151,2233';
                $this->set(CURLOPT_POST, false);
                $page = $this->exec($url);

                if ($page['code'] != 200) continue;

                $files = $this->storeOrdersPage($page, $link, $dir);
                $htmlFiles = array_merge($htmlFiles, $files);

                // пагинация
//                $paginationUrls = $this->getPagination($page['html']);

//                var_dump($paginationUrls);

                /*
                if (!empty($paginationUrls)) {
                    foreach ($paginationUrls as $key => $url) {
                        $page = $this->exec($url);
                        $files = $this->storeOrdersPage($page, $category, $dir);
                        $htmlFiles = array_merge($htmlFiles, $files);
                    }
                }
                */
            }
        }

        return $htmlFiles;
    }

    public function parsing(array $htmlFiles) {
        $document = new Document();
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
        //var_dump('2018-10-10T11:26:00.070994%2B00:00',urlencode("2018-10-10T11:26:00.070994+00:00")
//,urlencode("2018-10-08T05:28:58.785984+00:00") );

        $data = json_decode($html, true);

        $lastOrder = $data[29];
        $currentDate = (new DateTime())->format('d-m-Y');
        $orderDate = (new DateTime($lastOrder['published_at']))->format('d-m-Y');

        return $currentDate != $orderDate ? '' : urlencode($lastOrder['published_at']);
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
        $orders = json_decode($page['html'], true);

        $data = []; // list page's orders
        $currentDate = (new DateTime())->format('d-m-Y');
        foreach ($orders['hits'] as $order) {
            $orderDate = (new DateTime($order["published_at"]))->format('d-m-Y');
            if ($currentDate != $orderDate) continue;
            $data[$order['id']] = [
                'id'            => $order['id'],
                'url'           => $order['url'],
                'api'           => $this->baseUrl . $category . 'detail/' . $order['id'] . '/',
                'status'        => $order['status'],
                'created_at'    => $order["created_at"],
                'data'          => $orderDate,
                "actualized_at" => $order["actualized_at"]
            ];
        }
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $order) {
                if ($i >= 3) {
                    break;
                    sleep(15);
                    $i = 0;
                }
                $i++;
                $orderPage = $this->exec($order['api'], 20, 1);
                if ($orderPage['code'] == 200) {
                    $htmlFile = '/' . str_replace($category, '', $order['id']) . '.json';
                    $orderData = $this->getOrderData(json_decode($orderPage['html'], true));
                    $this->storeFile($dir, $htmlFile, json_encode($orderData, JSON_UNESCAPED_UNICODE));
                    $htmlFiles[] = $dir . $htmlFile;
                }
            }
        }

        return $htmlFiles;
    }

    private function getOrderData($data) {
        $result = [];
        $contactUrl = $this->baseUrl . '/orders/detail/' . $data['id'] . '/contacts/';
        $result[$data['id']] = [
            'id'           => $data['id'],
            'url'          => $data['url'],
            'title'        => $data['meta_title'],
            'published_at' => $data['published_at'],
            'description'  => $data['description'],
            'url_contact'  => $contactUrl,
            'user'         => $this->getUserInfo($contactUrl)
        ];

        var_dump($result);

        return $result;
    }
}