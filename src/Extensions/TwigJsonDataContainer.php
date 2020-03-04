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

    private $dataStorage = [];

    /**
     * TwigStyleScriptTagFilter constructor.
     * @param TwigFactory $twig
     */
    public function __construct(TwigFactory $twig)
    {
        $this->twig = $twig;
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
            $this->twig->createSimpleFunction('get_json_data', [$this, 'getJsonData'], ['is_safe' => array('html')]),
            $this->twig->createSimpleFunction('get_private_json_data', [$this, 'getPrivateJsonData'], ['is_safe' => array('html')])
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

    public function getPrivateJsonData()
    {
        $result = [];
        foreach( $this->dataStorage as $uid => $data )
        {
            $result[] = "<script type=\"application/json\" privatized=\"no\" id=\"" . $uid . "\">" . $data . "</script>";
        }

        return implode("", $result);
    }

    public function getJsonData()
    {
        $result = [];
        foreach( $this->dataStorage as $uid => $data )
        {
            $result[] = "<script type=\"application/json\" privatized=\"yes\" id=\"" . $uid . "\">" .
                json_encode($this->privatize(json_decode($data))) .
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

    // BEGIN privatize

    private $privatizeSpec = array(
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
    );

    public function privatize($data)
    {
        return $this->_privatize($data, $this->privatizeSpec);
    }

    private function _privatize($data, array $spec)
    {
        foreach ($spec as $specKey => $subSpec) {
            if (is_string($subSpec) && 1 === preg_match('/^\$refs\[([^\]]+)\]$/', $subSpec, $matches))
            {
                $refKey = $matches[1];

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
                        $data[$specKey] = $this->_privatize($data[$specKey], $subSpec);
                    } else if(is_object($data) && property_exists($data, $specKey)) {
                        foreach ($data as $key => &$value) {
                            if ($key === $specKey)
                                $value = $this->_privatize($value, $subSpec);
                        }
                    }

                    continue 2;
            }

            switch ($specKey) {
                case '**':
                    # recursive descent
                    $data = $this->_privatize($data, $subSpec);
                    if (is_array($data) || is_object($data)) {
                        foreach ($data as $key => &$value)
                            $value = $this->_privatize($this->_privatize($value, $subSpec), $spec);
                    }
                    break;

                case '*':
                    # match all fields
                    if (is_array($data) || is_object($data)) {
                        foreach ($data as $key => &$value)
                            $value = $this->_privatize($value, $subSpec);
                    }
                    break;

                case '$value':
                    return $subSpec;

                case '$refs':
                    # ignore; this is used at the top of the function
                    break;

                default:
                    break;
            }
        }

        return $data;
    }
}
