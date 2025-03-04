<?php

declare(strict_types=1);

namespace HTML\Sourceopt\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SvgStore.
 *
 * @author Marcus Förster ; https://github.com/xerc
 */
class SvgStoreService implements \TYPO3\CMS\Core\SingletonInterface
{
    /**
     * SVG-Sprite storage directory.
     *
     * @var string
     */
    protected $outputDir = '/typo3temp/assets/svg/';

    public function __construct()
    {
        //$this->styl = []; # https://stackoverflow.com/questions/39583880/external-svg-fails-to-apply-internal-css
        //$this->defs = []; # https://bugs.chromium.org/p/chromium/issues/detail?id=751733#c14
        $this->svgs = [];

        $this->sitePath = \TYPO3\CMS\Core\Core\Environment::getPublicPath(); // [^/]$
        $this->svgCache = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->getCache('svgstore');
    }

    public function process(string $html): string
    {
        $this->spritePath = $this->svgCache->get('spritePath');
        $this->svgFileArr = $this->svgCache->get('svgFileArr');

        if (empty($this->spritePath) && !$this->populateCache()) {
            throw new \Exception('could not write file: '.$this->sitePath.$this->spritePath);
        }

        if (!file_exists($this->sitePath.$this->spritePath)) {
            throw new \Exception('file does not exists: '.$this->sitePath.$this->spritePath);
        }

        if (!preg_match('/(?<head>.+?<\/head>)(?<body>.+)/s', $html, $html) && 5 == \count($html)) {
            throw new \Exception('fix HTML!');
        }

        // https://developer.mozilla.org/en-US/docs/Web/HTML/Element/img#attributes
        $html['body'] = preg_replace_callback('/<img(?<pre>[^>]*)src="(?<src>\/[^"]+\.svg)"(?<post>[^>]*?)[\s\/]*>(?!\s*<\/picture>)/s', function (array $match): string { // ^[/]
            if (!isset($this->svgFileArr[$match['src']])) { // check usage
                return $match[0];
            }
            $attr = preg_replace('/\s(?:alt|ismap|loading|title|sizes|srcset|usemap|crossorigin|decoding|referrerpolicy)="[^"]*"/', '', $match['pre'].$match['post']); // cleanup

            return sprintf('<svg %s %s><use href="%s#%s"/></svg>', $this->svgFileArr[$match['src']]['attr'], trim($attr), $this->spritePath, $this->convertFilePath($match['src']));
        }, $html['body']);

        // https://developer.mozilla.org/en-US/docs/Web/HTML/Element/object#attributes
        $html['body'] = preg_replace_callback('/<object(?<pre>[^>]*)data="(?<data>\/[^"]+\.svg)"(?<post>[^>]*?)[\s\/]*>(?:<\/object>)/s', function (array $match): string { // ^[/]
            if (!isset($this->svgFileArr[$match['data']])) { // check usage
                return $match[0];
            }
            $attr = preg_replace('/\s(?:form|name|type|usemap)="[^"]*"/', '', $match['pre'].$match['post']); // cleanup

            return sprintf('<svg %s %s><use href="%s#%s"/></svg>', $this->svgFileArr[$match['data']]['attr'], trim($attr), $this->spritePath, $this->convertFilePath($match['data']));
        }, $html['body']);

        return $html['head'].$html['body'];
    }

    private function convertFilePath(string $path): string
    {
        return preg_replace('/.svg$|[^\w\-]/', '', str_replace('/', '-', ltrim($path, '/'))); // ^[^/]
    }

    private function addFileToSpriteArr(string $hash, string $path): ?array
    {
        if (!file_exists($this->sitePath.$path)) {
            return null;
        }
        
        if (1 === preg_match('/(?:;base64|i:a?i?pgf)/', $svg = file_get_contents($this->sitePath.$path))) { // noop!
            return null;
        }

        if (1 === preg_match('/<(?:style|defs)|url\(/', $svg)) {
            return null; // check links @ __construct
        }

        //$svg = preg_replace('/((?:id|class)=")/', '$1'.$hash.'__', $svg); // extend  IDs
        //$svg = preg_replace('/(href="|url\()#/', '$1#'.$hash.'__', $svg); // recover IDs

        //$svg = preg_replace_callback('/<style[^>]*>(?<styl>.+?)<\/style>|<defs[^>]*>(?<defs>.+?)<\/defs>/s', function(array $match) use($hash): string {
        //
        //    if(isset($match['styl']))
        //    {
        //        $this->styl[] = preg_replace('/\s*(\.|#){1}(.+?)\s*\{/', '$1'.$hash.'__$2{', $match['styl']); // patch CSS # https://mathiasbynens.be/notes/css-escapes
        //    }
        //    if(isset($match['defs']))
        //    {
        //        $this->defs[] = trim($match['defs']);
        //    }
        //    return '';
        //}, $svg);

        // https://developer.mozilla.org/en-US/docs/Web/SVG/Attribute/xlink:href
        $svg = preg_replace('/^.*?<svg|\s*(<\/svg>)(?!.*\1).*$|xlink:|\s(?:(?:version|xmlns)|(?:[a-z\-]+\:[a-z\-]+))="[^"]*"/s', '', $svg); // cleanup

        // https://developer.mozilla.org/en-US/docs/Web/SVG/Element/svg#attributes
        $svg = preg_replace_callback('/([^>]*)\s*(?=>)/s', function (array $match) use (&$attr): string {
            if (false === preg_match_all('/(?!\s)(?<attr>[\w\-]+)="\s*(?<value>[^"]+)\s*"/', $match[1], $matches)) {
                return $match[0];
            }
            foreach ($matches['attr'] as $index => $attribute) {
                switch ($attribute) {
                  case 'id':
                  case 'width':
                  case 'height':
                      unset($matches[0][$index]);
                      break;

                  case 'viewBox':
                      if (false !== preg_match('/\S+\s\S+\s\+?(?<width>[\d\.]+)\s\+?(?<height>[\d\.]+)/', $matches['value'][$index], $match)) {
                          $attr[] = sprintf('%s="0 0 %s %s"', $attribute, $match['width'], $match['height']); // save!
                      }
                }
            }

            return implode(' ', $matches[0]);
        }, $svg, 1);

        if ($attr) { // TODO; beautify
            $this->svgs[] = sprintf('id="%s" %s', $this->convertFilePath($path), $svg); // prepend ID

            return ['attr' => implode(' ', $attr), 'hash' => $hash];
        }

        return null;
    }

    private function populateCache(): bool
    {
        $storageArr = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\StorageRepository::class)->findAll();
        foreach ($storageArr as $storage) {
            if ('relative' == $storage->getConfiguration()['pathType']) {
                $storageArr[$storage->getUid()] = rtrim($storage->getConfiguration()['basePath'], '/'); // [^/]$
            }
        }
        unset($storageArr[0]); // keep!

        $svgFileArr = GeneralUtility::makeInstance(\HTML\Sourceopt\Resource\SvgFileRepository::class)->findAllByStorageUids(\array_keys($storageArr));
        foreach ($svgFileArr as $index => $row) {
            if (!$this->svgFileArr[($row['path'] = '/'.$storageArr[$row['storage']].$row['identifier'])] = $this->addFileToSpriteArr($row['sha1'], $row['path'])) { // ^[/]
                unset($this->svgFileArr[$row['path']]);
            }
        }
        unset($storageArr, $svgFileArr); // save MEM

        $svg = preg_replace_callback(
            '/<use(?<pre>.*?)(?:xlink:)?href="(?<href>\/.+?\.svg)#[^"]+"(?<post>.*?)[\s\/]*>(?:<\/use>)?/s',
            function (array $match): string {
                if (!isset($this->svgFileArr[$match['href']])) { // check usage
                    return $match[0];
                }

                return sprintf('<use%s href="#%s"/>', $match['pre'].$match['post'], $this->convertFilePath($match['href']));
            },
            '<svg xmlns="http://www.w3.org/2000/svg">'
            //."\n<style>\n".implode("\n", $this->styl)."\n</style>"
            //."\n<defs>\n".implode("\n", $this->defs)."\n</defs>"
            ."\n<symbol ".implode("</symbol>\n<symbol ", $this->svgs)."</symbol>\n"
            .'</svg>'
        );

        //unset($this->styl); // save MEM
        //unset($this->defs); // save MEM
        unset($this->svgs); // save MEM

        if (\is_int($var = $GLOBALS['TSFE']->config['config']['sourceopt.']['formatHtml']) && 1 == $var) {
            $svg = preg_replace('/[\n\r\t\v\0]|\s{2,}/', '', $svg);
        }

        $svg = preg_replace('/<([a-z]+)\s*(\/|>\s*<\/\1)>\s*/i', '', $svg); // remove emtpy
        $svg = preg_replace('/<((circle|ellipse|line|path|polygon|polyline|rect|stop|use)\s[^>]+?)\s*>\s*<\/\2>/', '<$1/>', $svg); // shorten/minify

        if (!is_dir($this->sitePath.$this->outputDir)) {
            GeneralUtility::mkdir_deep($this->sitePath.$this->outputDir);
        }

        $this->spritePath = $this->outputDir.hash('sha1', serialize($this->svgFileArr)).'.svg';
        if (false === file_put_contents($this->sitePath.$this->spritePath, $svg)) {
            return false;
        }

        $this->svgCache->set('spritePath', $this->spritePath);
        $this->svgCache->set('svgFileArr', $this->svgFileArr);

        return true;
    }
}
