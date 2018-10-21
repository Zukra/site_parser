"# Site parser" 

sites: metalloprokat.ru, metal100.ru

use DiDom\Document;


$cfgFile = __DIR__ . '/config.php';
$config = Config::getInstance($cfgFile)->getConfig();

$workDir = $_SERVER['DOCUMENT_ROOT'] . $config->workDir;

$dataDir = $workDir . $config->metalloprokatru['dataDir'];
$params = [
    'domDocument' => new Document(),
    'proxy'       => $config->proxy,
    'authData'    => $config->authData,
    'workDir'     => $workDir
];
$site = new MetalloprokatRu($params);
...