<?php
/**
 * PHP Domain Parser: Public Suffix List based URL parsing.
 *
 * @see http://github.com/jeremykendall/php-domain-parser for the canonical source repository
 *
 * @copyright Copyright (c) 2017 Jeremy Kendall (http://jeremykendall.net)
 * @license   http://github.com/jeremykendall/php-domain-parser/blob/master/LICENSE MIT License
 */
declare(strict_types=1);

namespace Pdp;

use Pdp\Http\HttpAdapter;
use Psr\SimpleCache\CacheInterface;
use SplTempFileObject;

/**
 * Public Suffix List Manager.
 *
 * This class obtains, writes, caches, and returns text and PHP representations
 * of the Public Suffix List
 */
class PublicSuffixListManager
{
    const PUBLIC_SUFFIX_LIST_URL = 'https://raw.githubusercontent.com/publicsuffix/list/master/public_suffix_list.dat';

    /**
     * @var string Public Suffix List Source URL
     */
    private $sourceUrl;

    /**
     * @var CacheInterface PSR-16 cache adapter
     */
    private $cacheAdapter;

    /**
     * @var HttpAdapter Http adapter
     */
    private $httpAdapter;

    /**
     * Public constructor.
     *
     * @param CacheInterface $cacheAdapter
     * @param HttpAdapter    $httpAdapter
     * @param string         $sourceUrl
     */
    public function __construct(
        CacheInterface $cacheAdapter,
        HttpAdapter $httpAdapter,
        string $sourceUrl = self::PUBLIC_SUFFIX_LIST_URL
    ) {
        $this->cacheAdapter = $cacheAdapter;
        $this->httpAdapter = $httpAdapter;
        $this->sourceUrl = $sourceUrl;
    }

    /**
     * Gets Public Suffix List.
     *
     * @param string $type the Public Suffix List type
     *
     * @return PublicSuffixList
     */
    public function getList($type = PublicSuffixList::ALL_DOMAINS): PublicSuffixList
    {
        static $type_lists = [
            PublicSuffixList::ALL_DOMAINS => PublicSuffixList::ALL_DOMAINS,
            PublicSuffixList::ICANN_DOMAINS => PublicSuffixList::ICANN_DOMAINS,
            PublicSuffixList::PRIVATE_DOMAINS => PublicSuffixList::PRIVATE_DOMAINS,
        ];

        $type = $type_lists[$type] ?? PublicSuffixList::ALL_DOMAINS;
        $list = $this->cacheAdapter->get($type);
        if ($list === null) {
            $this->refreshPublicSuffixList();
            $list = $this->cacheAdapter->get($type);
        }

        return new PublicSuffixList($type, json_decode($list, true));
    }

    /**
     * Downloads Public Suffix List and writes text cache and PHP cache. If these files
     * already exist, they will be overwritten.
     *
     * Returns true if all list are correctly refreshed
     *
     * @return bool
     */
    public function refreshPublicSuffixList(): bool
    {
        $publicSuffixList = $this->httpAdapter->getContent($this->sourceUrl);
        $publicSuffixListTypes = $this->convertListToArray($publicSuffixList);

        return $this->cacheAdapter->setMultiple(array_map('json_encode', $publicSuffixListTypes));
    }

    /**
     * Parses text representation of list to associative, multidimensional array.
     *
     * @param string $publicSuffixList
     *
     * @return array Associative, multidimensional array representation of the
     *               public suffx list
     */
    private function convertListToArray(string $publicSuffixList): array
    {
        $addDomain = [
            PublicSuffixList::ICANN_DOMAINS => false,
            PublicSuffixList::PRIVATE_DOMAINS => false,
        ];

        $publicSuffixListTypes = [
            PublicSuffixList::ALL_DOMAINS => [],
            PublicSuffixList::ICANN_DOMAINS => [],
            PublicSuffixList::PRIVATE_DOMAINS => [],
        ];

        $data = new SplTempFileObject();
        $data->fwrite($publicSuffixList);
        $data->setFlags(SplTempFileObject::DROP_NEW_LINE | SplTempFileObject::READ_AHEAD | SplTempFileObject::SKIP_EMPTY);
        foreach ($data as $line) {
            $addDomain = $this->validateDomainAddition($line, $addDomain);
            if (strstr($line, '//') !== false) {
                continue;
            }
            $publicSuffixListTypes = $this->convertLineToArray($line, $publicSuffixListTypes, $addDomain);
        }

        return $publicSuffixListTypes;
    }

    /**
     * Update the addition status for a given line against the domain list (ICANN and PRIVATE).
     *
     * @param string $line      the current file line
     * @param array  $addDomain the domain addition status
     *
     * @return array
     */
    private function validateDomainAddition(string $line, array $addDomain): array
    {
        foreach ($addDomain as $section => $status) {
            $addDomain[$section] = $this->isValidSection($status, $line, $section);
        }

        return $addDomain;
    }

    /**
     * Tell whether the line can be converted for a given domain.
     *
     * @param bool   $previousStatus the previous status
     * @param string $line           the current file line
     * @param string $section        the section to be considered
     *
     * @return bool
     */
    private function isValidSection(bool $previousStatus, string $line, string $section): bool
    {
        if (!$previousStatus && strpos($line, '// ===BEGIN ' . $section . ' DOMAINS===') === 0) {
            return true;
        }

        if ($previousStatus && strpos($line, '// ===END ' . $section . ' DOMAINS===') === 0) {
            return false;
        }

        return $previousStatus;
    }

    /**
     * Convert a line from the Public Suffix list.
     *
     * @param string $rule                  Public Suffix List text line
     * @param array  $publicSuffixListTypes Associative, multidimensional array representation of the
     *                                      public suffx list
     * @param array  $validTypes            Tell which section should be converted
     *
     * @return array Associative, multidimensional array representation of the
     *               public suffx list
     */
    private function convertLineToArray(string $line, array $publicSuffixListTypes, array $validTypes): array
    {
        $ruleParts = explode('.', $line);
        $validTypes = array_keys(array_filter($validTypes));
        $validTypes[] = PublicSuffixList::ALL_DOMAINS;
        foreach ($validTypes as $type) {
            $publicSuffixListTypes[$type] = $this->buildArray($publicSuffixListTypes[$type], $ruleParts);
        }

        return $publicSuffixListTypes;
    }

    /**
     * Recursive method to build the array representation of the Public Suffix List.
     *
     * This method is based heavily on the code found in generateEffectiveTLDs.php
     *
     * @see https://github.com/usrflo/registered-domain-libs/blob/master/generateEffectiveTLDs.php
     * A copy of the Apache License, Version 2.0, is provided with this
     * distribution
     *
     * @param array $publicSuffixList Initially an empty array, this eventually
     *                                becomes the array representation of the Public Suffix List
     * @param array $ruleParts        One line (rule) from the Public Suffix List
     *                                exploded on '.', or the remaining portion of that array during recursion
     */
    private function buildArray(array $publicSuffixList, array $ruleParts): array
    {
        $isDomain = true;

        $part = array_pop($ruleParts);

        // Adheres to canonicalization rule from the "Formal Algorithm" section
        // of https://publicsuffix.org/list/
        // "The domain and all rules must be canonicalized in the normal way
        // for hostnames - lower-case, Punycode (RFC 3492)."

        $part = idn_to_ascii($part, 0, INTL_IDNA_VARIANT_UTS46);
        if (strpos($part, '!') === 0) {
            $part = substr($part, 1);
            $isDomain = false;
        }

        $publicSuffixList[$part] = $publicSuffixList[$part] ?? ($isDomain ? [] : ['!' => '']);

        if ($isDomain && !empty($ruleParts)) {
            $publicSuffixList[$part] = $this->buildArray($publicSuffixList[$part], $ruleParts);
        }

        return $publicSuffixList;
    }
}
