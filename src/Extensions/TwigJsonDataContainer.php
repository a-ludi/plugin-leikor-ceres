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

    public function getJsonData(boolean $isAuthorized)
    {
        $result = [];
        foreach( $this->dataStorage as $uid => $data )
        {
            $json = $isAuthorized ? $data : json_encode(privatize(json_decode($data)))

            $result[] = "<script type=\"application/json\" id=\"" . $uid . "\">" . $json . "</script>";
        }

        return implode("", $result);
    }

    private $forbiddenKeys = array(
        'basePrice' => 1,
        'basePriceNet' => 1,
        'baseSinglePrice' => 1,
        'categoryDiscount' => 1,
        'categoryDiscountNet' => 1,
        'categoryDiscountPercent' => 1,
        'contactClassDiscount' => 1,
        'customerClassDiscount' => 1,
        'customerClassDiscountNet' => 1,
        'customerClassDiscountPercent' => 1,
        'graduatedPrices' => 1,
        'mayShowUnitPrice' => 1,
        'price' => 1,
        'priceNet' => 1,
        'prices' => 1,
        'salesPriceId' => 1,
        'unitPrice' => 1,
        'unitPriceNet' => 1,
    );

    private function privatize($data, $shouldHide = false)
    {
        if (is_object($data)) {
            if ($shouldHide)
                return NULL;

            foreach (get_object_vars($privatized) as $key => $value) {
                if (array_key_exists($key, $this->$forbiddenKeys))
                    $data->$key = privatize($value, true);
                else
                    $data->$key = privatize($value);
            }
        } else if (is_array($data)) {
            if ($shouldHide)
                return array();

            foreach ($data as $key => $value) {
                if (array_key_exists($key, $this->$forbiddenKeys))
                    $data[$key] = privatize($value, true);
                else
                    $data[$key] = privatize($value);
            }
        } else if (is_float($data)) {
            if ($shouldHide)
                return NAN;
        } else if (is_int($data)) {
            if ($shouldHide)
                return 0;
        } else if (is_string($data)) {
            if ($shouldHide)
                return NULL;
        } else if (is_bool($data)) {
            if ($shouldHide)
                return false;
        } else {
            return NULL;
        }

        return $data
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
