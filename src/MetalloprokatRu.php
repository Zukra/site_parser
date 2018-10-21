<?php

namespace zkr\parser;

class MetalloprokatRu extends SiteParser implements Parsing {

    private $domDocument;
    private $baseUrl = "https://www.metalloprokat.ru";
    private $authUrl = "https://www.metalloprokat.ru/login_check";
    private $authData = [];
    private $cookieFile = '/metalloprokat.ru.txt';
    private $addLink = '/demands';
    private $links = [
        // Продукция черной металлургии
        "1062" => [
            "/truba/",
            "/nerzhavejushhij-prokat-i-truby/",
            "/sort_nerz/",
            "/list_nerz/",
            "/truba/trubanerzh/",
            "/list/",
            "/sort/",
            //        "/truboprovod/",
            //        "/metiz/",
            //        "/metallokonst/",
            //        "/krepez/",
            //        "/truboprovod_nerz/",
            //        "/metiz_nerz/",
            //        "/krepez_nerz/",
        ],
        // Продукция цветной металлургии
        "1066" => [
            "/tsvetmet_prokat/",
            //        "/tsvetmet_metiz/",
            //        "/tsvetmet/",
            //        "/tsvetmet_splavi/"
        ]
    ];

    public function __construct(array $params = []) {
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
            ->set(CURLOPT_SSL_VERIFYPEER, false)// false for https
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
            $this->domDocument->loadHtml($response['html'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
            $userName = $this->domDocument->find('.login-user .user-name');
            $userName = $userName[0] ? $userName[0]->text() : false;
            if (!$userName) {
                $response['errors'][] = ['error login'];
                $response['html'] = '';
            }
        }

        return $response;
    }

    public function getRequestListItems($request) {
        $result = [];
        $items = $request->find('.demand_product-list tr.row');
        foreach ($items as $item) {
            $count = $item->first('.count .product-count')->text();
            $count = trim(preg_replace('/\s+/', ' ', $count));
            $result[$item->first('.item .product-item')->text()] = [
                'item'  => $item->first('.item .product-item')->text(),
                'title' => trim(str_replace('(подробный перечень в прикрепленном файле)', '', $item->first('.title .product-title')->text())),
                'count' => $count,
            ];
        }

        return $result;
    }

    public function getUserInfo($urlContact) {
        $arUserInfo = [
            'company' => '',
            'name'    => '',
            'contact' => [
                'phones' => '',
                'emails' => '',
            ]
        ];
        $page = $this->exec($this->baseUrl . $urlContact);
        $userInfoDocument = $this->domDocument;
        $userInfoBlock = $userInfoDocument->loadHtml($page["html"], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
        $userInfo = $userInfoBlock->find('.user-info-block')[0] ?? false;
        if ($userInfo) {
            $company = $userInfo->find('p.user')[0];
            $name = $userInfo->find('p.user')[1];
            $phones = $userInfo->find('p.user-contact')[0]->find('.phone-text')[0];
            $emails = $userInfo->find('p.user-contact')[0]->find('a')[0];
            $arUserInfo = [
                'company' => $company ? $company->text() : '',
                'name'    => $name ? $name->text() : '',
                'contact' => [
                    'phones' => $phones ? $phones->text() : '',
                    'emails' => $emails ? $emails->text() : '',
                ]
            ];
        }

        return $arUserInfo;
    }

    /**
     * Получаю и сохраняю страницы
     * @param string $path
     * @return array
     */
    public function storeData(string $path) {
        $period = 'day'; // week
        $htmlFiles = [];
        foreach ($this->links as $categoryId => $category) {
            foreach ($category as $link) {
                $url = $this->baseUrl . $this->addLink . $link . '?period=' . $period;
                $page = $this->exec($url);

                $dir = $path . $categoryId;
                $htmlFile = '/' . str_replace('/', '', $this->addLink) . '_' . str_replace('/', '', $link) . '.html';

                $this->storeFile($dir, $htmlFile, $page['html']);

                $htmlFiles[] = $dir . $htmlFile;

                $paginationUrls = $this->getPagination($page['html']);
                if (!empty($paginationUrls)) {
                    foreach ($paginationUrls as $key => $url) {
                        $page = $this->exec($url);
                        $htmlFile = '/' . str_replace('/', '', $this->addLink)
                            . '_' . str_replace('/', '', $link)
                            . '_' . $key . '.html';

                        $this->storeFile($dir, $htmlFile, $page);

                        $htmlFiles[] = $dir . $htmlFile;
                    }
                }
            }
        }

        return $htmlFiles;
    }

    public function parsing(array $htmlFiles) {
        $data = [];
        foreach ($htmlFiles as $file) {
            $this->domDocument->loadHtmlFile($file, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
            $scope = $this->domDocument->find('.breadcrumbs_item.last .breadcrumbs_link');
            $scope = isset($scope[0]) ? $scope[0]->text() : '';
            $requests = $this->domDocument->find('#demand ul.demands li.demand_item');
            foreach ($requests as $request) {
                $title = $request->find('.demand-title')[0];
                $date = $title->find('.demand-date')[0]->text();
                $region = $title->find('.demand-region')[0]->text();
                $str = str_replace(' ', '', $title->find('a')[0]->text());
                $arStr = explode("\n", $str);
                $arStr = array_values(array_diff($arStr, ['']));
                $urlContact = $request->find('.links_contacts')[0]->find('span.contacts::attr(data-view-url)')[0];
                $data[$arStr[0]] = [
                    'scope'       => $scope,
                    'city'        => $arStr[1],
                    'region'      => $region,
                    'date'        => $date,
                    'url_contact' => $urlContact,
                    'user'        => $this->getUserInfo($urlContact),
                    'items'       => $this->getRequestListItems($request)
                ];
            }
        }

        return $data;
    }

    private function getPagination($html) {
        $result = [];
        $this->domDocument->loadHtml($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
        $paginationBlock = $this->domDocument->find('#demand .pagination');
        if (isset($paginationBlock[0])) {
            $paginationUrls = $paginationBlock[0]->find('a::attr(href)');
            $paginationUrls = array_unique($paginationUrls);
            $i = 2;
            foreach ($paginationUrls as $url) {
                $result[$i++] = $this->baseUrl . $url;
            }
        }

        return $result;
    }

    public function prepareMessage($data) {
        $messages = [];
        foreach ($data as $number => $item) {
            $productStr = '';
            foreach ($item['items'] as $product) {
                $productStr .= $product['item'] . ' ' . $product['title'] . ' ' . $product['count'] . "\n";
            }
            $messages[$number] = trim('<b>' . $item['scope'] . '</b>'
                . "\n" . $item['date'] . "\n"
                . $item['city'] . ' ' . $item['region'] . "\n"
                . $productStr
                . $item['user']['company'] . ' ' . $item['user']['name']
                . ' ' . $item['user']["contact"]['phones']
                . ' ' . $item['user']["contact"]['emails']);
        }

        return $messages;
    }

}