<?php

namespace FriendsOfTYPO3\TtAddress\Controller;

/**
 * This file is part of the "tt_address" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
use FriendsOfTYPO3\TtAddress\Domain\Model\Dto\Demand;
use FriendsOfTYPO3\TtAddress\Domain\Repository\AddressRepository;
use FriendsOfTYPO3\TtAddress\Seo\AddressTitleProvider;
use FriendsOfTYPO3\TtAddress\Utility\CacheUtility;
use FriendsOfTYPO3\TtAddress\Utility\TypoScript;
use TYPO3\CMS\Core\Database\QueryGenerator;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;

/**
 * AddressController
 */
class AddressController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    /** @var AddressRepository */
    protected $addressRepository;

    /** @var QueryGenerator */
    protected $queryGenerator;

    public function initializeAction()
    {
        $this->queryGenerator = GeneralUtility::makeInstance(QueryGenerator::class);
    }

    /**
     * @param \FriendsOfTYPO3\TtAddress\Domain\Model\Address $address
     */
    public function showAction(\FriendsOfTYPO3\TtAddress\Domain\Model\Address $address = null)
    {
        if ($address === null) {
            $this->redirectToUri($this->uriBuilder->reset()->setTargetPageUid((int)$GLOBALS['TSFE']->id)->build());
        } else {
            $provider = GeneralUtility::makeInstance(AddressTitleProvider::class);
            $provider->setTitle($address, (array)$this->settings['seo']['pageTitle']);
        }

        $this->view->assign('address', $address);

        CacheUtility::addCacheTagsByAddressRecords([$address]);
    }

    /**
     * Lists addresses by settings in waterfall principle.
     * singleRecords take precedence over categories which take precedence over records from pages
     *
     * @param array $override Optional overriding demand
     * @throws InvalidQueryException
     */
    public function listAction(array $override = [])
    {
        $demand = $this->createDemandFromSettings();

        if (!empty($override) && $this->settings['allowOverride']) {
            $this->overrideDemand($demand, $override);
        }

        if ($demand->getSingleRecords()) {
            $addresses = $this->addressRepository->getAddressesByCustomSorting($demand);
        } else {
            $addresses = $this->addressRepository->findByDemand($demand);
        }

        $this->view->assignMultiple([
            'demand' => $demand,
            'addresses' => $addresses
        ]);

        CacheUtility::addCacheTagsByAddressRecords(
            $addresses instanceof QueryResultInterface ? $addresses->toArray() : $addresses
        );
    }

    /**
     * Injects the Configuration Manager and is initializing the framework settings
     *
     * @param ConfigurationManagerInterface $configurationManager Instance of the Configuration Manager
     */
    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager)
    {
        $this->configurationManager = $configurationManager;

        // get the whole typoscript (_FRAMEWORK does not work anymore, don't know why)
        $tsSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
            '',
            ''
        );

        // correct the array to be in same shape like the _SETTINGS array
        $tsSettings = $this->removeDots((array)$tsSettings['plugin.']['tx_ttaddress.']);

        // get original settings
        // original means: what extbase does by munching flexform and TypoScript together, but leaving empty flexform-settings empty ...
        $originalSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS
        );

        $propertiesNotAllowedViaFlexForms = ['orderByAllowed'];
        foreach ($propertiesNotAllowedViaFlexForms as $property) {
            $originalSettings[$property] = $tsSettings['settings'][$property];
        }

        // start override
        if (isset($tsSettings['settings']['overrideFlexformSettingsIfEmpty'])) {
            $typoScriptUtility = GeneralUtility::makeInstance(TypoScript::class);
            $originalSettings = $typoScriptUtility->override($originalSettings, $tsSettings);
        }
        // Re-set global settings
        $this->settings = $originalSettings;
    }

    protected function createDemandFromSettings(): Demand
    {
        $calculatedSettings = $this->settings;
       
        // Use stdWrap for given defined settings
        if (isset($calculatedSettings['useStdWrap']) && !empty($calculatedSettings['useStdWrap'])) {
            $typoScriptService = GeneralUtility::makeInstance(\TYPO3\CMS\Core\TypoScript\TypoScriptService::class);
            $typoScriptArray = $typoScriptService->convertPlainArrayToTypoScriptArray($calculatedSettings);
            $stdWrapProperties = GeneralUtility::trimExplode(',', $calculatedSettings['useStdWrap'], true);
            foreach ($stdWrapProperties as $key) {
                if (is_array($typoScriptArray[$key . '.'])) {
                    $calculatedSettings[$key] = $this->configurationManager->getContentObject()->stdWrap(
                        $typoScriptArray[$key],
                        $typoScriptArray[$key . '.']
                    );
                }
            }
        }

        $demand = $this->objectManager->get(Demand::class);
        $demand->setCategories((string)$calculatedSettings['groups']);
        $categoryCombination = (int)$calculatedSettings['groupsCombination'] === 1 ? 'or' : 'and';
        $demand->setCategoryCombination($categoryCombination);
        $demand->setIncludeSubCategories((bool)$calculatedSettings['includeSubcategories']);

        if ($calculatedSettings['pages']) {
            $demand->setPages($this->getPidList());
        }
        $demand->setSingleRecords((string)$calculatedSettings['singleRecords']);
        $demand->setSortBy((string)$calculatedSettings['sortBy']);
        $demand->setSortOrder((string)$calculatedSettings['sortOrder']);
        $demand->setIgnoreWithoutCoordinates((bool)$calculatedSettings['ignoreWithoutCoordinates']);

        return $demand;
    }

    protected function overrideDemand(Demand $demand, array $override): Demand
    {
        $ignoredValues = ['singleRecords', 'sortBy', 'pages'];
        $ignoredValuesLower = array_map('strtolower', $ignoredValues);

        foreach ($ignoredValues as $property) {
            unset($override[$property]);
        }

        foreach ($override as $propertyName => $propertyValue) {
            if (in_array(strtolower($propertyName), $ignoredValuesLower, true)) {
                continue;
            }
            if ($propertyValue !== '' || $this->settings['allowEmptyStringsForOverwriteDemand']) {
                ObjectAccess::setProperty($demand, $propertyName, $propertyValue);
            }
        }
        return $demand;
    }

    /**
     * @param AddressRepository $addressRepository
     */
    public function injectAddressRepository(AddressRepository $addressRepository)
    {
        $this->addressRepository = $addressRepository;
    }

    /**
     * Initializes the view before invoking an action method.
     *
     * @param ViewInterface $view The view to be initialized
     */
    protected function initializeView(ViewInterface $view)
    {
        $view->assign('contentObjectData', $this->configurationManager->getContentObject()->data);
        parent::initializeView($view);
    }

    /**
     * Removes dots at the end of a configuration array
     *
     * @param array $settings the array to transformed
     * @return array $settings the transformed array
     */
    protected function removeDots(array $settings): array
    {
        $conf = [];
        foreach ($settings as $key => $value) {
            $conf[$this->removeDotAtTheEnd($key)] = \is_array($value) ? $this->removeDots($value) : $value;
        }
        return $conf;
    }

    /**
     * Removes a dot in the end of a String
     *
     * @param string $string
     * @return string
     */
    protected function removeDotAtTheEnd($string): string
    {
        return preg_replace('/\.$/', '', $string);
    }

    /**
     * Retrieves subpages of given pageIds recursively until reached $this->settings['recursive']
     *
     * @return array an array with all pageIds
     */
    protected function getPidList(): array
    {
        $rootPIDs = explode(',', $this->settings['pages']);
        $pidList = $rootPIDs;

        // iterate through root-page ids and merge to array
        foreach ($rootPIDs as $pid) {
            $result = $this->queryGenerator->getTreeList($pid, $this->settings['recursive'], 0, 1);
            if ($result) {
                $subtreePids = explode(',', $result);
                $pidList = array_merge($pidList, $subtreePids);
            }
        }
        return $pidList;
    }
}
