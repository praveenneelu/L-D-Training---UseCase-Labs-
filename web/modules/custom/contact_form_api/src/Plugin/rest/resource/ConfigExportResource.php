<?php

namespace Drupal\contact_form_api\Plugin\rest\resource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides a resource to export configurations.
 *
 * @RestResource(
 *   id = "config_export_resource",
 *   label = @Translation("Config Export Resource"),
 *   uri_paths = {
 *     "canonical" = "/api/config-export/{config_name}"
 *   }
 * )
 */
class ConfigExportResource extends ResourceBase implements ContainerFactoryPluginInterface
{

    /**
     * Config factory service.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * Constructs a new ConfigExportResource object.
     *
     * @param array $configuration
     *   A configuration array containing information about the plugin instance.
     * @param string $plugin_id
     *   The plugin_id for the plugin instance.
     * @param mixed $plugin_definition
     *   The plugin implementation definition.
     * @param array $serializer_formats
     *   The available serialization formats.
     * @param \Psr\Log\LoggerInterface $logger
     *   A logger instance.
     * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
     *   The config factory.
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        array $serializer_formats,
        LoggerInterface $logger,
        ConfigFactoryInterface $config_factory
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
        $this->configFactory = $config_factory;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->getParameter('serializer.formats'),
            $container->get('logger.factory')->get('rest'),
            $container->get('config.factory')
        );
    }

    /**
     * Responds to GET requests.
     *
     * @param mixed $config_name
     *   The name of the configuration.
     *
     * @return \Drupal\rest\ModifiedResourceResponse
     *   The HTTP response object.
     */
    public function get($config_name = NULL)
    {
        if (!$config_name) {
            return new ModifiedResourceResponse([
                'error' => 'Config name parameter is missing.',
            ], 400);
        }

        $config = $this->configFactory->get($config_name);
        if ($config->isNew()) {
            return new ModifiedResourceResponse([
                'error' => "Configuration '$config_name' does not exist.",
            ], 404);
        }

        return new ModifiedResourceResponse($config->get(), 200);
    }
}
