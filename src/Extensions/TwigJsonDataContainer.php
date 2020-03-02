<?php
namespace Ceres\Extensions;

use Plenty\Plugin\Templates\Extensions\Twig_Extension;
use Plenty\Plugin\Templates\Factories\TwigFactory;

/**
 * Class TwigStyleScriptTagFilter
 * @package Ceres\Extensions
 */
class TwigJsonDataContainer extends Twig_Extension
{
    /**
     * @var TwigFactory
     */
    private $twig;

    private $privatizer;

    private $dataStorage = [];

    /**
     * TwigStyleScriptTagFilter constructor.
     * @param TwigFactory $twig
     */
    public function __construct(TwigFactory $twig)
    {
        $this->twig = $twig;
        $this->privatizer = new JsonDataPrivatizer(array(
            '**' => array(
                'prices' => array(
                    'graduatedPrices' => array(
                        '*' => '$refs[priceCompound]',
                    ),
                    '*' => '$refs[priceCompound]',
                ),
            ),
            '$refs' => array(
                'priceCompound' => array(
                    'price' => '$refs[singlePrice]',
                    'unitPrice' => '$refs[singlePrice]',
                    'basePrice' => array('$value' => ''),
                    'baseSinglePrice' => array('$value' => null),
                    'contactClassDiscount' => '$refs[singleDiscount]',
                    'categoryDiscount' => '$refs[singleDiscount]',
                    'data' => array(
                        'salesPriceId' => array('$value' => 1),
                        'price' => array('$value' => -0.00),
                        'priceNet' => array('$value' => -0.00),
                        'basePrice' => array('$value' => -0.00),
                        'basePriceNet' => array('$value' => -0.00),
                        'unitPrice' => array('$value' => -0.00),
                        'unitPriceNet' => array('$value' => -0.00),
                        'customerClassDiscountPercent' => array('$value' => 0),
                        'customerClassDiscount' => array('$value' => 0),
                        'customerClassDiscountNet' => array('$value' => 0),
                        'categoryDiscountPercent' => array('$value' => 0),
                        'categoryDiscount' => array('$value' => 0),
                        'categoryDiscountNet' => array('$value' => 0),
                    ),
                ),
                'singlePrice' => array(
                    'value' => array('$value' => -0.00),
                    'formatted' => array('$value' => '-0,00 EUR'),
                ),
                'singleDiscount' => array(
                    'percent' => array('$value' => 0),
                    'amount' => array('$value' => 0),
                ),
            ),
        ));
    }

    /**
     * Return the name of the extension. The name must be unique.
     *
     * @return string The name of the extension
     */
    public function getName(): string
    {
        return "Ceres_Extension_TwigJsonDataContainer";
    }

    /**
     * Return a list of filters to add.
     *
     * @return array The list of filters to add.
     */
    public function getFilters(): array
    {
        return [
            $this->twig->createSimpleFilter('json_data', [$this, 'storeJsonData'], ['is_safe' => array('html')])
        ];
    }

    /**
     * Return a list of functions to add.
     *
     * @return array the list of functions to add.
     */
    public function getFunctions(): array
    {
        return [
            $this->twig->createSimpleFunction('get_json_data', [$this, 'getJsonData'], ['is_safe' => array('html')])
        ];
    }

    public function storeJsonData($data, $uid = null)
    {
        if ( is_null($uid) )
        {
            $uid = uniqid();
        }

        $this->dataStorage[$uid] = json_encode($data);
        return $uid;
    }

    public function getJsonData($isAuthorized)
    {
        $result = [];
        foreach( $this->dataStorage as $uid => $data )
        {
            $result[] = "<script type=\"application/json\" id=\"" . $uid . "\">" .
                ($isAuthorized
                    ? $data
                    : json_encode($this->privatizer->privatize(json_decode($data)))) .
                "</script>";
        }

        return implode("", $result);
    }

    /**
     * Return a map of global helper objects to add.
     *
     * @return array the map of helper objects to add.
     */
    public function getGlobals(): array
    {
        return [];
    }
}


class JsonDataPrivatizer
{
    private $privatizeSpec;

    private $_debug = false;

    public function __construct(array $privatizeSpec)
    {
        $this->privatizeSpec = $privatizeSpec;
    }

    public function privatize($data)
    {
        return $this->_privatize($data, $this->privatizeSpec);
    }

    private function _privatize(
        $data,
        array $spec,
        string $dataBreadcrumbs = '.',
        string $specBreadcrumbs = '.'
    )
    {
        $indent = str_repeat('  ', substr_count($dataBreadcrumbs, '.') - 1);
        $this->debugf("%sEntering at %s with %s (%s)\n", $indent, $dataBreadcrumbs, $specBreadcrumbs, json_encode($spec));
        if ($dataBreadcrumbs == '.')
            $dataBreadcrumbs = '';
        if ($specBreadcrumbs == '.')
            $specBreadcrumbs = '';

        foreach ($spec as $specKey => $subSpec) {
            if (is_string($subSpec) && 1 === preg_match('/^\$refs\[([^\]]+)\]$/', $subSpec, $matches))
            {
                $refKey = $matches[1];

                if (!array_key_exists('$refs', $this->privatizeSpec))
                    throw new Exception('no $refs defined in privatizeSpec');
                if (!array_key_exists($refKey, $this->privatizeSpec['$refs']))
                    throw new Exception('undefined reference in privatizeSpec: ' . $refKey);

                // $this->debugf("Inserting reference spec %s: %s\n", $refKey, json_encode($subSpec));
                $subSpec = $this->privatizeSpec['$refs'][$refKey];
            }

            switch ($specKey) {
                case '**':
                case '*':
                case '$value':
                case '$refs':
                    # these cases will be handled below
                    break;
                default:
                    # named field
                    if(is_array($data) && array_key_exists($specKey, $data)) {
                        $this->debugf("%sMatching field %s at %s\n", $indent, $specKey, $dataBreadcrumbs);
                        $data[$specKey] = $this->_privatize(
                            $data[$specKey],
                            $subSpec,
                            $dataBreadcrumbs . '.' . $specKey,
                            $specBreadcrumbs . '.' . $specKey,
                        );
                    } else if(is_object($data) && property_exists($data, $specKey)) {
                        $this->debugf("%sMatching field %s at %s\n", $indent, $specKey, $dataBreadcrumbs);
                        foreach ($data as $key => &$value) {
                            if ($key === $specKey)
                                $value = $this->_privatize(
                                    $value,
                                    $subSpec,
                                    $dataBreadcrumbs . '.' . $specKey,
                                    $specBreadcrumbs . '.' . $specKey,
                                );
                        }
                    }

                    continue 2;
            }

            switch ($specKey) {
                case '**':
                    # recursive descent
                    if (is_array($data) || is_object($data)) {
                        $this->debugf("%sRecursive Descent at %s\n", $indent, $dataBreadcrumbs);
                        foreach ($data as $key => &$value)
                            $value = $this->_privatize(
                                $this->_privatize(
                                    $value,
                                    $subSpec,
                                    $dataBreadcrumbs . '.' . $key,
                                    $specBreadcrumbs . '.' . $specKey,
                                ),
                                $spec,
                                $dataBreadcrumbs . '.' . $key,
                                $specBreadcrumbs,
                            );
                    }
                    break;

                case '*':
                    # match all fields
                    if (is_array($data) || is_object($data)) {
                        $this->debugf("%sMatching all fields at %s (%s)\n", $indent, $dataBreadcrumbs, json_encode($data));
                        foreach ($data as $key => &$value)
                            $value = $this->_privatize(
                                $value,
                                $subSpec,
                                $dataBreadcrumbs . '.' . $key,
                                $specBreadcrumbs . '.' . $specKey,
                            );
                    }
                    break;

                case '$value':
                    $this->debugf("%sReplacing value at %s\n", $indent, $dataBreadcrumbs);
                    return $subSpec;

                case '$refs':
                    # ignore
                    break;

                default:
                    throw new Exception('unreachable; should be handled above');
            }
        }

        return $data;
    }

    private function debugf(string $format, ...$args)
    {
        if ($this->_debug)
        {
            array_unshift($args, $format);
            call_user_func_array('printf', $args);
        }
    }

    public static function runTests()
    {
        $privatizer = new JsonDataPrivatizer(array(
            '**' => array(
                'prices' => array(
                    'graduatedPrices' => array(
                        '*' => '$refs[priceCompound]',
                    ),
                    '*' => '$refs[priceCompound]',
                ),
            ),
            '$refs' => array(
                'priceCompound' => array(
                    'price' => '$refs[singlePrice]',
                    'unitPrice' => '$refs[singlePrice]',
                    'basePrice' => array('$value' => ''),
                    'baseSinglePrice' => array('$value' => null),
                    'contactClassDiscount' => '$refs[singleDiscount]',
                    'categoryDiscount' => '$refs[singleDiscount]',
                    'data' => array(
                        'salesPriceId' => array('$value' => 1),
                        'price' => array('$value' => -0.00),
                        'priceNet' => array('$value' => -0.00),
                        'basePrice' => array('$value' => -0.00),
                        'basePriceNet' => array('$value' => -0.00),
                        'unitPrice' => array('$value' => -0.00),
                        'unitPriceNet' => array('$value' => -0.00),
                        'customerClassDiscountPercent' => array('$value' => 0),
                        'customerClassDiscount' => array('$value' => 0),
                        'customerClassDiscountNet' => array('$value' => 0),
                        'categoryDiscountPercent' => array('$value' => 0),
                        'categoryDiscount' => array('$value' => 0),
                        'categoryDiscountNet' => array('$value' => 0),
                    ),
                ),
                'singlePrice' => array(
                    'value' => array('$value' => -0.00),
                    'formatted' => array('$value' => '-0,00 EUR'),
                ),
                'singleDiscount' => array(
                    'percent' => array('$value' => 0),
                    'amount' => array('$value' => 0),
                ),
            ),
        ));
        $data = json_decode('{"took":0,"total":1,"maxScore":1,"documents":[{"id":15,"data":{"barcodes":[{"referrers":[-1],"id":1,"name":"ischiofibular","createdAt":"2016-11-23 08:34:41","updatedAt":"2022-11-23 19:59:36","type":"karyomitosis","code":"11a39711683f"}],"defaultCategories":[{"id":19,"parentCategoryId":57,"level":2,"type":"item","linklist":true,"right":"all","sitemap":true,"updatedAt":"2028-08-23 00:13:33","manually":false,"plentyId":49}],"prices":{"default":{"price":{"value":8.99,"formatted":"8,99 EUR"},"unitPrice":{"value":8.99,"formatted":"8,99 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":1,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":1,"price":8.99,"priceNet":10.19,"basePrice":8.89,"basePriceNet":10.19,"unitPrice":9.13,"unitPriceNet":10.19,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"1.00","updatedAt":"2016-04-21 23:52:15"}},"rrp":{"price":{"value":0,"formatted":"0,00 EUR"},"unitPrice":{"value":0,"formatted":"0,00 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":0,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":120,"price":0,"priceNet":0,"basePrice":0,"basePriceNet":0,"unitPrice":0,"unitPriceNet":0,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"0.00","updatedAt":"2021-07-24 16:16:00"}},"specialOffer":null,"graduatedPrices":[{"price":{"value":5.6,"formatted":"5,60 EUR"},"unitPrice":{"value":5.6,"formatted":"5,60 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":230,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":33,"price":6.784,"priceNet":5.91,"basePrice":6.784,"basePriceNet":5.91,"unitPrice":6.784,"unitPriceNet":5.91,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"300.00","updatedAt":"2030-04-13 10:35:26"}},{"price":{"value":5.2,"formatted":"5,20 EUR"},"unitPrice":{"value":5.2,"formatted":"5,20 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":215,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":30,"price":7.06,"priceNet":6.11,"basePrice":7.06,"basePriceNet":6.11,"unitPrice":7.06,"unitPriceNet":6.11,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"200.00","updatedAt":"2016-08-15 06:31:23"}}]}}}],"success":true,"error":null}');
        $expected = '{"took":0,"total":1,"maxScore":1,"documents":[{"id":15,"data":{"barcodes":[{"referrers":[-1],"id":1,"name":"ischiofibular","createdAt":"2016-11-23 08:34:41","updatedAt":"2022-11-23 19:59:36","type":"karyomitosis","code":"11a39711683f"}],"defaultCategories":[{"id":19,"parentCategoryId":57,"level":2,"type":"item","linklist":true,"right":"all","sitemap":true,"updatedAt":"2028-08-23 00:13:33","manually":false,"plentyId":49}],"prices":{"default":{"price":{"value":-0,"formatted":"-0,00 EUR"},"unitPrice":{"value":-0,"formatted":"-0,00 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":1,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":1,"price":-0,"priceNet":-0,"basePrice":-0,"basePriceNet":-0,"unitPrice":-0,"unitPriceNet":-0,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"1.00","updatedAt":"2016-04-21 23:52:15"}},"rrp":{"price":{"value":-0,"formatted":"-0,00 EUR"},"unitPrice":{"value":-0,"formatted":"-0,00 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":0,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":1,"price":-0,"priceNet":-0,"basePrice":-0,"basePriceNet":-0,"unitPrice":-0,"unitPriceNet":-0,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"0.00","updatedAt":"2021-07-24 16:16:00"}},"specialOffer":null,"graduatedPrices":[{"price":{"value":-0,"formatted":"-0,00 EUR"},"unitPrice":{"value":-0,"formatted":"-0,00 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":230,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":1,"price":-0,"priceNet":-0,"basePrice":-0,"basePriceNet":-0,"unitPrice":-0,"unitPriceNet":-0,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"300.00","updatedAt":"2030-04-13 10:35:26"}},{"price":{"value":-0,"formatted":"-0,00 EUR"},"unitPrice":{"value":-0,"formatted":"-0,00 EUR"},"basePrice":"","baseLot":null,"baseUnit":null,"baseSinglePrice":null,"minimumOrderQuantity":215,"contactClassDiscount":{"percent":0,"amount":0},"categoryDiscount":{"percent":0,"amount":0},"currency":"EUR","vat":{"id":0,"value":19},"isNet":true,"data":{"salesPriceId":1,"price":-0,"priceNet":-0,"basePrice":-0,"basePriceNet":-0,"unitPrice":-0,"unitPriceNet":-0,"customerClassDiscountPercent":0,"customerClassDiscount":0,"customerClassDiscountNet":0,"categoryDiscountPercent":0,"categoryDiscount":0,"categoryDiscountNet":0,"vatId":0,"vatValue":19,"currency":"EUR","interval":"none","conversionFactor":1,"minimumOrderQuantity":"200.00","updatedAt":"2016-08-15 06:31:23"}}]}}}],"success":true,"error":null}';

        if (json_encode($privatizer->privatize($data)) === $expected) {
            printf("Tests successful\n");
            exit(0);
        } else {
            printf("Tests failed:\n");
            printf("%s\n", json_encode($data));
            exit(1);
        }
    }
}
