<?php
/**
 * Manager.php
 *
 * PHP Version 5
 *
 * @category  Bragento_MagentoComposerInstaller
 * @package   Bragento\Magento\Composer\Installer\Deploy
 * @author    David Verholen <david.verholen@brandung.de>
 * @copyright 2014 Brandung GmbH & Co. KG
 * @license   http://opensource.org/licenses/OSL-3.0 OSL-3.0
 * @link      http://www.brandung.de
 */

namespace Bragento\Magento\Composer\Installer\Deploy;

use Bragento\Magento\Composer\Installer\Deploy\Manager\Actions;
use Bragento\Magento\Composer\Installer\Deploy\Manager\Entry;
use Bragento\Magento\Composer\Installer\Deploy\Manager\PackageTypes;
use Bragento\Magento\Composer\Installer\Deploy\Strategy\AbstractStrategy;
use Bragento\Magento\Composer\Installer\Deploy\Strategy\Factory;
use Bragento\Magento\Composer\Installer\Exception\NotInitializedException;
use Bragento\Magento\Composer\Installer\Project\Config;
use Bragento\Magento\Composer\Installer\Util\Filesystem;
use Composer\Composer;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Package\PackageInterface;
use Composer\Script\PackageEvent;
use Composer\Script\ScriptEvents;
use Symfony\Component\Finder\SplFileInfo;


/**
 * Class Manager
 *
 * @category  Bragento_MagentoComposerInstaller
 * @package   Bragento\Magento\Composer\Installer\Deploy
 * @author    David Verholen <david.verholen@brandung.de>
 * @copyright 2014 Brandung GmbH & Co. KG
 * @license   http://opensource.org/licenses/OSL-3.0 OSL-3.0
 * @link      http://www.brandung.de
 */
class Manager implements EventSubscriberInterface
{
    /**
     * instance
     *
     * @var Manager
     */
    protected static $_instance;

    /**
     * _composer
     *
     * @var Composer
     */
    protected static $_composer;

    /**
     * entries
     *
     * @var Entry[]
     */
    protected $_moduleEntries;

    /**
     * entries
     *
     * @var Entry[]
     */
    protected $_themeEntries;

    /**
     * _coreEntry
     *
     * @var Entry
     */
    protected $_coreEntry;

    /**
     * _fs
     *
     * @var Filesystem
     */
    protected $_fs;

    /**
     * private construct for singleton
     */
    private function __construct()
    {
        $this->_moduleEntries = array();
        $this->_themeEntries = array();
    }

    /**
     * init
     *
     * @param Composer $composer
     *
     * @return void
     */
    public static function init(Composer $composer)
    {
        self::$_composer = $composer;
    }

    /**
     * getInstance
     *
     * @return Manager
     */
    public static function getInstance()
    {
        if (null === self::$_instance) {
            self::$_instance = new Manager();
        }

        return self::$_instance;
    }

    /**
     * addEntry
     *
     * only the first package with type
     * magento-core will be added
     *
     * @param Entry $entry the Entry to add
     *
     * @return void
     */
    public function addEntry(Entry $entry)
    {
        switch ($entry->getDeployStrategy()->getPackage()->getType()) {
            case PackageTypes::MAGENTO_CORE:
                $this->_coreEntry = $entry;
                break;

            case PackageTypes::MAGENTO_MODULE:
                $this->_moduleEntries[] = $entry;
                break;

            case PackageTypes::MAGENTO_THEME:
                $this->_themeEntries[] = $entry;
                break;
        }
    }

    /**
     * doDeploy
     *
     * deploy core, then modules, then themes
     *
     * @return void
     */
    public function doDeploy()
    {
        $this->addAllPackages();

        if (null !== $this->_coreEntry) {
            $this->_coreEntry->getDeployStrategy()->doDeploy();
            $this->_coreEntry = null;
        }

        while (count($this->_moduleEntries)) {
            /** @var Entry $moduleEntry */
            $moduleEntry = array_pop($this->_moduleEntries);
            $moduleEntry->getDeployStrategy()->doDeploy();
        }

        while (count($this->_themeEntries)) {
            /** @var Entry $themeEntry */
            $themeEntry = array_pop($this->_moduleEntries);
            $themeEntry->getDeployStrategy()->doDeploy();
        }
    }

    /**
     * addAllPackages
     *
     * @return void
     */
    protected function addAllPackages()
    {
        foreach ($this->getPackages() as $package) {
            $this->addEntry(
                $this->getDeployManagerEntry(
                    $package,
                    Actions::UPDATE
                )
            );
        }
    }

    /**
     * onPostPackageUninstall
     *
     * add Entries of uninstalled packages, since they are not
     * in local repository anymore
     *
     * @param PackageEvent $event
     *
     * @return void
     */
    public function onPostPackageUninstall(PackageEvent $event)
    {
        /** @var UninstallOperation $operation */
        $operation = $event->getOperation();
        $this->addEntry(
            $this->getDeployManagerEntry(
                $operation->getPackage(),
                Actions::UNINSTALL
            )
        );
    }

    /**
     * getDeployStrategy
     *
     * @param PackageInterface $package
     * @param string           $action
     *
     * @return AbstractStrategy
     */
    protected function getDeployStrategy(PackageInterface $package, $action)
    {
        return Factory::get(
            $package,
            $action,
            $this->getSourceDir($package),
            $this->getTargetDir()
        );
    }

    /**
     * getDeployManagerEntry
     *
     * @param PackageInterface $package package to deploy
     * @param string           $action
     *
     * @return Entry
     */
    protected function getDeployManagerEntry(PackageInterface $package, $action)
    {
        return new Entry(
            $this->getDeployStrategy($package, $action)
        );
    }

    /**
     * getSourceDir
     *
     * @param PackageInterface $package
     *
     * @return SplFileInfo
     */
    protected function getSourceDir(PackageInterface $package)
    {
        return $this->getFs()->getDir($this->getInstallPath($package));
    }

    /**
     * getTargetDir
     *
     * @return SplFileInfo
     */
    protected function getTargetDir()
    {
        return Config::getInstance()->getMagentoRootDir();
    }

    /**
     * getInstallPath
     *
     * @param PackageInterface $package
     *
     * @return string
     */
    public function getInstallPath(PackageInterface $package)
    {
        $targetDir = $package->getTargetDir();
        return $this->getPackageBasePath($package)
        . ($targetDir ? '/' . $targetDir : '');
    }

    /**
     * getPackageBasePath
     *
     * @param PackageInterface $package
     *
     * @return string
     */
    protected function getPackageBasePath(PackageInterface $package)
    {
        return $this->getFs()->joinFileUris(
            Config::getInstance()->getVendorDir(),
            $package->getPrettyName()
        );
    }

    /**
     * getPackages
     *
     * @return PackageInterface[]
     */
    protected function getPackages()
    {
        return $this->getComposer()
            ->getRepositoryManager()
            ->getLocalRepository()
            ->getCanonicalPackages();
    }

    /**
     * getComposer
     *
     * @return Composer
     * @throws NotInitializedException
     */
    protected function getComposer()
    {
        if (null === self::$_composer) {
            throw new NotInitializedException($this);
        }

        return self::$_composer;
    }

    /**
     * getFs
     *
     * @return Filesystem
     */
    protected function getFs()
    {
        if (null == $this->_fs) {
            $this->_fs = new Filesystem();
        }

        return $this->_fs;
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     * * The method name to call (priority defaults to 0)
     * * An array composed of the method name to call and the priority
     * * An array of arrays composed of the method names to call and respective
     *   priorities, or 0 if unset
     *
     * For instance:
     *
     * * array('eventName' => 'methodName')
     * * array('eventName' => array('methodName', $priority))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_PACKAGE_UNINSTALL => 'onPostPackageUninstall'
        );
    }
}
