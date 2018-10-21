<?php

namespace zkr\parser;

interface Parsing {

    public function login();

    public function storeData(string $dataDir);

    public function parsing(array $htmlFiles);

    public function prepareMessage($data);
}