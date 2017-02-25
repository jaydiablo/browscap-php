<?php
/**
 * Copyright (c) 1998-2015 Browser Capabilities Project
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @category   Browscap-PHP
 * @copyright  1998-2015 Browser Capabilities Project
 * @license    http://www.opensource.org/licenses/MIT MIT License
 * @link       https://github.com/browscap/browscap-php/
 * @since      added with version 3.0
 */

namespace BrowscapPHP\Parser\Helper;

use BrowscapPHP\Cache\BrowscapCacheInterface;
use BrowscapPHP\IniParser\IniParser;
use Psr\Log\LoggerInterface;

/**
 * extracts the pattern and the data for theses pattern from the ini content, optimized for PHP 5.5+
 *
 * @category   Browscap-PHP
 * @author     Christoph Ziegenberg <christoph@ziegenberg.com>
 * @author     Thomas Müller <t_mueller_stolzenhain@yahoo.de>
 * @copyright  Copyright (c) 1998-2015 Browser Capabilities Project
 * @version    3.0
 * @license    http://www.opensource.org/licenses/MIT MIT License
 * @link       https://github.com/browscap/browscap-php/
 */
class GetPattern implements GetPatternInterface
{
    /**
     * The cache instance
     *
     * @var \BrowscapPHP\Cache\BrowscapCacheInterface
     */
    private $cache = null;

    /**
     * a logger instance
     *
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /**
     * class contructor
     *
     * @param \BrowscapPHP\Cache\BrowscapCacheInterface $cache
     * @param \Psr\Log\LoggerInterface                  $logger
     */
    public function __construct(BrowscapCacheInterface $cache, LoggerInterface $logger)
    {
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    /**
     * Gets some possible patterns that have to be matched against the user agent. With the given
     * user agent string, we can optimize the search for potential patterns:
     * - We check the first characters of the user agent (or better: a hash, generated from it)
     * - We compare the length of the pattern with the length of the user agent
     *   (the pattern cannot be longer than the user agent!)
     *
     * @param string $userAgent
     *
     * @return \Generator
     */
    public function getPatterns($userAgent)
    {
        $starts = Pattern::getHashForPattern($userAgent, true);
        $length = strlen($userAgent);

        // add special key to fall back to the default browser
        $starts[] = str_repeat('z', 32);
        $starts = array_flip($starts);

        $subKeys = [];
        $patterns = [];

        $j = 0;

        // get patterns, first for the given browser and if that is not found,
        // for the default browser (with a special key)
        foreach (array_keys($starts) as $tmpStart) {
            $tmpSubkey = SubKey::getPatternCacheSubkey($tmpStart);

            // It's possible that our start hashes contain duplicate subkeys, we'll capture all applicable
            // hashes in the subkey's file below, so no need to re-process a subkey.
            if (in_array($tmpSubkey, $subKeys)) {
                continue;
            }

            if (!$this->cache->hasItem('browscap.patterns.' . $tmpSubkey, true)) {
                $this->logger->debug('cache key "browscap.patterns.' . $tmpSubkey . '" not found');

                continue;
            }
            $success = null;

            $file = $this->cache->getItem('browscap.patterns.' . $tmpSubkey, true, $success);

            if (!$success) {
                $this->logger->debug('cache key "browscap.patterns.' . $tmpSubkey . '" not found');

                continue;
            }

            if (!is_array($file) || !count($file)) {
                $this->logger->debug('cache key "browscap.patterns.' . $tmpSubkey . '" was empty');

                continue;
            }

            foreach ($file as $line) {
                list($hash, $sortLen, $minLen, $word, $pattern) = explode("\t", $line, 5);

                // Ignore patterns that don't match our hashes, or that are too long
                if ($minLen <= $length && isset($starts[$hash])) {

                    // Ignore patterns that contain a word that our useragent does not
                    if (!empty($word)) {
                        if (strpos($userAgent, $word) === false) {
                            continue;
                        }
                    }

                    if (isset($patterns[$sortLen])) {
                        $patterns[$sortLen] .= "\t" . $pattern;
                    } else {
                        $patterns[$sortLen] = $pattern;
                    }
                }
            }

            $subKeys[] = $tmpSubkey;
        }

        krsort($patterns);

        foreach ($patterns as $group) {
            // Have to make this back into an array so we can sort by the INI position that precedes each pattern
            // and also so we can re-chunk since the groups may be too large for preg_match
            $group = explode("\t", $group);

            sort($group, SORT_NATURAL);

            foreach (array_chunk($group, IniParser::COUNT_PATTERN) as $chunk) {
                // Need to remove our INI position from the start of the patterns
                yield trim(preg_replace('/(?:^|\t)[\d]+\|\|/', "\t", implode("\t", $chunk)));
            }
        }

        yield '';
    }
}
