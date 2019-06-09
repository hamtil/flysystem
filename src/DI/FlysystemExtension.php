<?php declare(strict_types = 1);

namespace Contributte\Flysystem\DI;

use League\Flysystem\Filesystem;
use League\Flysystem\MountManager;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Definition;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Utils\Strings;
use stdClass;

/**
 * @property-read stdClass $config
 */
class FlysystemExtension extends CompilerExtension
{

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'filesystem' => Expect::arrayOf(Expect::structure([
				'adapter' => Expect::type('string|array|' . Statement::class),
				'config' => Expect::array(),
				'plugins' => Expect::arrayOf(Expect::type('string|array|' . Statement::class)),
				'autowired' => Expect::bool(false),
			])),
			'mountManager' => Expect::structure([
				'plugins' => Expect::arrayOf(Expect::type('string|array|' . Statement::class)),
			]),
			'plugins' => Expect::arrayOf(Expect::type('string|array|' . Statement::class)),
		]);
	}

	/**
	 * Used in loadConfiguration phase when definition of service defined in services cannot be get
	 *
	 * @param string|mixed[]|Statement $config
	 * @return Definition|string
	 */
	private function getDefinitionFromConfig($config, string $preferredPrefix)
	{
		if (is_string($config) && Strings::startsWith($config, '@')) {
			return $config;
		}

		$prefix = $preferredPrefix;
		$this->compiler->loadDefinitionsFromConfig([$prefix => $config]);
		return $this->getContainerBuilder()->getDefinition($prefix);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;

		$globalPluginDefinitions = [];

		foreach ($config->plugins as $pluginName => $pluginConfig) {
			$pluginPrefix = $this->prefix('plugin.' . $pluginName);
			$pluginDefinition = $this->getDefinitionFromConfig($pluginConfig, $pluginPrefix);
			if ($pluginDefinition instanceof Definition) {
				$pluginDefinition->setAutowired(false);
			}
			$globalPluginDefinitions[] = $pluginDefinition;
		}

		$filesystemsDefinitions = [];

		// Register filesystems
		foreach ($config->filesystem as $filesystemName => $filesystemConfig) {
			$filesystemPrefix = $this->prefix('filesystem.' . $filesystemName);

			$adapterPrefix = $filesystemPrefix . '.adapter';
			$adapterDefinition = $this->getDefinitionFromConfig($filesystemConfig->adapter, $adapterPrefix);
			if ($adapterDefinition instanceof Definition) {
				$adapterDefinition->setAutowired(false);
			}

			$filesystemsDefinitions[$filesystemName] = $filesystem = $builder->addDefinition($filesystemPrefix)
				->setType(Filesystem::class)
				->setArguments(
					[
						$adapterDefinition,
						$filesystemConfig->config,
					]
				);

			if (!$filesystemConfig->autowired) {
				$filesystem->setAutowired(false);
			}

			foreach ($globalPluginDefinitions as $pluginDefinition) {
				$filesystem->addSetup('addPlugin', [$pluginDefinition]);
			}

			foreach ($filesystemConfig->plugins as $pluginName => $pluginConfig) {
				$pluginPrefix = $filesystemPrefix . '.plugin.' . $pluginName;
				$pluginDefinition = $this->getDefinitionFromConfig($pluginConfig, $pluginPrefix);
				if ($pluginDefinition instanceof Definition) {
					$pluginDefinition->setAutowired(false);
				}
				$filesystem->addSetup('addPlugin', [$pluginDefinition]);
			}
		}

		// Register mount manager
		$mountManagerPrefix = $this->prefix('mountManager');
		$mountManager = $builder->addDefinition($mountManagerPrefix)
			->setType(MountManager::class)
			->setArguments([$filesystemsDefinitions]);

		foreach ($globalPluginDefinitions as $pluginDefinition) {
			$mountManager->addSetup('addPlugin', [$pluginDefinition]);
		}

		foreach ($config->mountManager->plugins as $pluginName => $pluginConfig) {
			$pluginPrefix = $mountManagerPrefix . '.plugin.' . $pluginName;
			$pluginDefinition = $this->getDefinitionFromConfig($pluginConfig, $pluginPrefix);
			if ($pluginDefinition instanceof Definition) {
				$pluginDefinition->setAutowired(false);
			}
			$mountManager->addSetup('addPlugin', [$pluginDefinition]);
		}
	}

}
