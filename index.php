<?php

/**
 * Archivarix Content Loader
 *
 * See README.txt for instructions with NGINX and Apache 2.x web servers
 *
 * PHP version 5.6 and newer
 * Required extensions: PDO_SQLITE
 *
 * LICENSE:
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author     Archivarix Team <hello@archivarix.com>
 * @telegram   https://t.me/archivarixsupport
 * @copyright  2017-2018 Archivarix LLC
 * @license    https://www.gnu.org/licenses/gpl.html GNU GPLv3
 * @version    Release: 20181009
 * @link       https://archivarix.com
 */

@ini_set('display_errors', 0);

/**
 * Enable CMS mode.
 * 0 = Disabled
 * 1 = Enabled
 * 2 = Enabled and homepage / path is passed to CMS
 */
const ARCHIVARIX_CMS_MODE = 0;
/**
 * Return 1px.png if image does not exist.
 */
const ARCHIVARIX_FIX_MISSING_IMAGES = 1;
/**
 * Return empty.css if css does not exist.
 */
const ARCHIVARIX_FIX_MISSING_CSS = 1;
/**
 * Return empty.js if javascript does not exist.
 */
const ARCHIVARIX_FIX_MISSING_JS = 1;
/**
 * Return empty.ico if favicon.ico does not exist.
 */
const ARCHIVARIX_FIX_MISSING_ICO = 1;
/**
 * Redirect missing html pages.
 */
const ARCHIVARIX_REDIRECT_MISSING_HTML = '/';
/**
 * Replace a custom key-phrase with a text file or php script.
 * You can do multiple custom replaces at once by adding more
 * array element.
 */
const ARCHIVARIX_INCLUDE_CUSTOM = array(
  [
    'FILE' => '',
    'KEYPHRASE' => '',
    'LIMIT' => 1, // how many matches to replace; -1 for unlimited
    'REGEX' => 0, // 1 to enable perl regex (important: escape ~ symbol); 0 - disabled
    'POSITION' => 1, // -1 to place before KEYPHRASE, 0 to replace, 1 to place after KEYPHRASE
  ],
  /**
   * Here are two most common predefined rules you may use.
   * Just fill out FILE to activate.
   */

  // before closing </head> rule
  [
    'FILE' => '',
    'KEYPHRASE' => '</head>',
    'LIMIT' => 1,
    'REGEX' => 0,
    'POSITION' => -1,
  ],

  // before closing </body> rule
  [
    'FILE' => '',
    'KEYPHRASE' => '</body>',
    'LIMIT' => 1,
    'REGEX' => 0,
    'POSITION' => -1,
  ],
);
/**
 * Custom source directory name. By default this script searches
 * for .content.xxxxxxxx folder. Set the different value if you
 * renamed that directory.
 */
const ARCHIVARIX_CONTENT_PATH = '';
/**
 * Set Cache-Control header for static files.
 * By default set to 0 and Etag is used for caching.
 */
const ARCHIVARIX_CACHE_CONTROL_MAX_AGE = 2592000;

/**
 * Website can run on another domain by default.
 * Set a custom domain if it is not recognized automatically.
 */
const ARCHIVARIX_CUSTOM_DOMAIN = '';

/**
 * XML Sitemap path. Example: /sitemap.xml
 * Do not use query in sitemap path as it will be ignored
 */
const ARCHIVARIX_SITEMAP_PATH = '';

/**
 * @return string
 *
 * @throws Exception
 */
function getSourceRoot()
{
  if (ARCHIVARIX_CONTENT_PATH) {
    $path = ARCHIVARIX_CONTENT_PATH;
  } else {
    $path = '';
    $list = scandir(dirname(__FILE__));
    foreach ($list as $item) {
      if (preg_match('~^\.content\.[0-9a-zA-Z]+$~', $item) && is_dir(__DIR__ . '/' . $item)) {
        $path = $item;
        break;
      }
    }
    if (!$path) {
      header('X-Error-Description: Folder .content.xxxxxxxx not found');
      throw new \Exception('Folder .content.xxxxxxxx not found');
    }
  }
  $absolutePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . $path;
  if (!realpath($absolutePath)) {
    header('X-Error-Description: Directory does not exist');
    throw new \Exception(sprintf('Directory %s does not exist', $absolutePath));
  }

  return $absolutePath;
}

/**
 * @param string $dsn
 * @param string $url
 *
 * @return array|false
 */
function getFileMetadata($dsn, $url)
{
  if (ARCHIVARIX_CUSTOM_DOMAIN) {
    $url = preg_replace('~' . preg_quote(ARCHIVARIX_CUSTOM_DOMAIN, '~') . '~', ARCHIVARIX_ORIGINAL_DOMAIN, $url, 1);
  } elseif (substr($_SERVER['HTTP_HOST'], -strlen(ARCHIVARIX_ORIGINAL_DOMAIN)) !== ARCHIVARIX_ORIGINAL_DOMAIN) {
    $url = preg_replace('~' . preg_quote($_SERVER['HTTP_HOST'], '~') . '~', ARCHIVARIX_ORIGINAL_DOMAIN, $url, 1);
  }

  if (substr($url, -1) != '/' && !parse_url($url, PHP_URL_QUERY) && !parse_url($url, PHP_URL_FRAGMENT)) {
    $urlAlt = $url . '/';
  } elseif (substr($url, -1) == '/') {
    $urlAlt = substr($url, 0, -1);
  } else {
    $urlAlt = $url;
  }

  $pdo = new PDO($dsn);
  $sth = $pdo->prepare('SELECT * FROM structure WHERE (url = :url COLLATE NOCASE OR url = :urlAlt COLLATE NOCASE) AND enabled = 1 ORDER BY filetime DESC LIMIT 1');
  $sth->execute(['url' => $url, 'urlAlt' => $urlAlt]);
  $metadata = $sth->fetch(PDO::FETCH_ASSOC);

  return $metadata;
}

/**
 * @param array $metaData
 * @param string $sourcePath
 * @param string $url
 */
function render(array $metaData, $sourcePath, $url = '')
{
  if (isset($metaData['redirect']) && $metaData['redirect']) {
    header('Location: ' . $metaData['redirect']);
    http_response_code(301);
    exit(0);
  }
  $sourceFile = $sourcePath . DIRECTORY_SEPARATOR . $metaData['folder'] . DIRECTORY_SEPARATOR . $metaData['filename'];
  if (!file_exists($sourceFile)) {
    handle404($sourcePath, $url);
    exit(0);
  }
  header('Content-Type:' . $metaData['mimetype']);
  if (in_array($metaData['mimetype'], ['text/html', 'text/css', 'text/xml', 'application/javascript', 'application/x-javascript'])) {
    header('Content-Type:' . $metaData['mimetype'] . '; charset=' . $metaData['charset'], true);
  }
  if (in_array($metaData['mimetype'], ['application/x-javascript', 'application/font-woff', 'application/javascript', 'image/gif', 'image/jpeg', 'image/png', 'image/svg+xml', 'image/tiff', 'image/webp', 'image/x-icon', 'image/x-ms-bmp', 'text/css', 'text/javascript'])) {
    $etag = md5_file($sourceFile);
    header('Etag: "' . $etag . '"');
    if (ARCHIVARIX_CACHE_CONTROL_MAX_AGE) {
      header('Cache-Control: public, max-age=' . ARCHIVARIX_CACHE_CONTROL_MAX_AGE);
    }
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag) {
      http_response_code(304);
      exit(0);
    }
  }
  if (0 === strpos($metaData['mimetype'], 'text/html')) {
    echo prepareContent($sourceFile, $sourcePath);
  } else {
    $fp = fopen($sourceFile, 'rb');
    fpassthru($fp);
    fclose($fp);
  }
}

/**
 * @param $file
 * @param $path
 *
 * @return bool|mixed|string
 */
function prepareContent($file, $path)
{
  $content = file_get_contents($file);

  foreach (ARCHIVARIX_INCLUDE_CUSTOM as $includeCustom) {
    if ($includeCustom['FILE']) {
      ob_start();
      include $path . DIRECTORY_SEPARATOR . $includeCustom['FILE'];
      $includedContent = preg_replace('/\$(\d)/', '\\\$$1', ob_get_clean());

      if ($includeCustom['REGEX']) {
        $includeCustom['KEYPHRASE'] = str_replace('~', '\~', $includeCustom['KEYPHRASE']);
      } else {
        $includeCustom['KEYPHRASE'] = preg_quote($includeCustom['KEYPHRASE'], '~');
      }

      switch ($includeCustom['POSITION']) {
        case -1 :
          $includedContent = $includedContent . '$0';
          break;
        case 1 :
          $includedContent = '$0' . $includedContent;
          break;
      }

      $content = preg_replace('~' . $includeCustom['KEYPHRASE'] . '~is', $includedContent, $content, $includeCustom['LIMIT']);
    }
  }
  if (function_exists('mb_strlen')) header('Content-Length: ' . mb_strlen($content, '8bit'), true);

  return $content;
}

/**
 * @param string $sourcePath
 * @param string $url
 */
function handle404($sourcePath, $url)
{
  $fileType = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
  switch (true) {
    case (in_array($fileType, ['jpg', 'jpeg', 'gif', 'png', 'bmp']) && ARCHIVARIX_FIX_MISSING_IMAGES):
      $fileName = $sourcePath . DIRECTORY_SEPARATOR . '1px.png';
      $size = filesize($fileName);
      render(['folder' => '', 'filename' => '1px.png', 'mimetype' => 'image/png', 'charset' => 'binary', 'filesize' => $size], $sourcePath);
      break;
    case ($fileType === 'ico' && ARCHIVARIX_FIX_MISSING_ICO):
      $fileName = $sourcePath . DIRECTORY_SEPARATOR . 'empty.ico';
      $size = filesize($fileName);
      render(['folder' => '', 'filename' => 'empty.ico', 'mimetype' => 'image/x-icon', 'charset' => 'binary', 'filesize' => $size], $sourcePath);
      break;
    case($fileType === 'css' && ARCHIVARIX_FIX_MISSING_CSS):
      $fileName = $sourcePath . DIRECTORY_SEPARATOR . 'empty.css';
      $size = filesize($fileName);
      render(['folder' => '', 'filename' => 'empty.css', 'mimetype' => 'text/css', 'charset' => 'utf-8', 'filesize' => $size], $sourcePath);
      break;
    case ($fileType === 'js' && ARCHIVARIX_FIX_MISSING_JS):
      $fileName = $sourcePath . DIRECTORY_SEPARATOR . 'empty.js';
      $size = filesize($fileName);
      render(['folder' => '', 'filename' => 'empty.js', 'mimetype' => 'application/javascript', 'charset' => 'utf-8', 'filesize' => $size], $sourcePath);
      break;
    case (ARCHIVARIX_REDIRECT_MISSING_HTML && ARCHIVARIX_REDIRECT_MISSING_HTML !== $_SERVER['REQUEST_URI']):
      header('Location: ' . ARCHIVARIX_REDIRECT_MISSING_HTML);
      http_response_code(301);
      exit(0);
      break;
    default:
      http_response_code(404);
  }
}

/**
 * @param string $dsn
 *
 * @return bool
 */
function checkRedirects($dsn)
{
  $pdo = new PDO($dsn);
  $exit = false;
  $res = $pdo->query('SELECT * FROM settings');
  if ($res) {
    $settings = $res->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settings as $setting) {
      if ($setting['param'] == 'https' && $setting['value'] && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off')) {
        $exit = true;
        $location = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('Location: ' . $location);
        http_response_code(301);
        exit(0);
      }
      if ($setting['param'] == 'non-www' && $setting['value'] && 0 === strpos($_SERVER['HTTP_HOST'], 'www.')) {
        $exit = true;
        $host = preg_replace('~^www\.~', '', $_SERVER['HTTP_HOST']);
        $location = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host . $_SERVER['REQUEST_URI'];
        header('Location: ' . $location);
        http_response_code(301);
        exit(0);
      }
      if ($setting['param'] == 'domain') {
        define('ARCHIVARIX_ORIGINAL_DOMAIN', $setting['value']);
      }
    }
  }

  return $exit;
}

/**
 * @param string $dsn
 */
function renderSitemapXML($dsn)
{
  if (ARCHIVARIX_CUSTOM_DOMAIN) {
    $domain = preg_replace('~' . preg_quote(ARCHIVARIX_CUSTOM_DOMAIN, '~') . '$~', '', $_SERVER['HTTP_HOST']) . ARCHIVARIX_ORIGINAL_DOMAIN;
  } else {
    $domain = ARCHIVARIX_ORIGINAL_DOMAIN;
  }

  $pageProtocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';

  $pdo = new PDO($dsn);
  $res = $pdo->prepare('SELECT * FROM "structure" WHERE hostname = :domain AND mimetype = "text/html" AND enabled = 1 AND redirect = "" GROUP BY request_uri ORDER BY request_uri, filetime DESC');
  $res->execute(['domain' => $domain]);

  if ($res) {
    $pagesLimit = 50000;
    $pages = $res->fetchAll(PDO::FETCH_ASSOC);
    if (count($pages) >= $pagesLimit && !isset($_GET['id'])) {
      header('Content-type: text/xml; charset=utf-8');
      echo '<?xml version="1.0" encoding="UTF-8"?' . '><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
      for ($pageNum = 1; $pageNum <= ceil(count($pages) / $pagesLimit); $pageNum++) {
        echo '<sitemap><loc>' . htmlspecialchars("$pageProtocol://$_SERVER[HTTP_HOST]" . ARCHIVARIX_SITEMAP_PATH . "?id=$pageNum", ENT_XML1, 'UTF-8') . '</loc></sitemap>';
      }
      echo '</sitemapindex>';

    } else {
      if (isset($_GET['id'])) {
        $pageId = $_GET['id'];
        if (!ctype_digit($pageId) || $pageId < 1 || $pageId > ceil(count($pages) / $pagesLimit)) {
          http_response_code(404);
          exit(0);
        }
        $pagesOffset = ($pageId - 1) * $pagesLimit;
        $res = $pdo->prepare('SELECT * FROM "structure" WHERE hostname = :domain AND mimetype = "text/html" AND enabled = 1 AND redirect = "" GROUP BY request_uri ORDER BY request_uri, filetime DESC LIMIT :limit OFFSET :offset');
        $res->execute(['domain' => $domain, 'limit' => $pagesLimit, 'offset' => $pagesOffset]);
        $pages = $res->fetchAll(PDO::FETCH_ASSOC);
      }

      header('Content-type: text/xml; charset=utf-8');
      echo '<?xml version="1.0" encoding="UTF-8"?' . '><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
      foreach ($pages as $page) {
        echo '<url><loc>' . htmlspecialchars("$pageProtocol://$_SERVER[HTTP_HOST]$page[request_uri]", ENT_XML1, 'UTF-8') . '</loc></url>';
      }
      echo '</urlset>';
    }
  }
}

try {
  if (ARCHIVARIX_CMS_MODE == 2 && $_SERVER['REQUEST_URI'] == '/') {
    return;
  }

  if (!in_array('sqlite', PDO::getAvailableDrivers())) {
    header('X-Error-Description: PDO_SQLITE driver is not loaded');
    throw new \Exception('PDO_SQLITE driver is not loaded.');
  }
  if ('cli' === php_sapi_name()) {
    echo "OK" . PHP_EOL;
    exit(0);
  }

  $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
  $sourcePath = getSourceRoot();

  $dbm = new PDO('sqlite::memory:');
  if (version_compare($dbm->query('SELECT sqlite_version()')->fetch()[0], '3.7.0') >= 0) {
    $dsn = sprintf('sqlite:%s%s%s', $sourcePath, DIRECTORY_SEPARATOR, 'structure.db');
  } else {
    $dsn = sprintf('sqlite:%s%s%s', $sourcePath, DIRECTORY_SEPARATOR, 'structure.legacy.db');
  }
  $dbm = null;

  if (checkRedirects($dsn)) {
    exit(0);
  }

  if (ARCHIVARIX_SITEMAP_PATH && ARCHIVARIX_SITEMAP_PATH === parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    renderSitemapXML($dsn);
    exit(0);
  }

  $metaData = getFileMetadata($dsn, $url);
  if ($metaData) {
    render($metaData, $sourcePath, $url);
    if (ARCHIVARIX_CMS_MODE) {
      exit(0);
    }
  } else {
    if (!ARCHIVARIX_CMS_MODE) {
      handle404($sourcePath, $url);
    }
  }
  if (!ARCHIVARIX_CMS_MODE) {
    exit(0);
  }
} catch (\Exception $e) {
  http_response_code(503);
  //print($e); // comment this line on a production server
  error_log($e);
}