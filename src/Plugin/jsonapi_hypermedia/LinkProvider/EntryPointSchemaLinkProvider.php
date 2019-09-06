<?php

namespace Drupal\jsonapi_schema\Plugin\jsonapi_hypermedia\LinkProvider;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\jsonapi_hypermedia\AccessRestrictedLink;
use Drupal\jsonapi_hypermedia\Annotation\JsonapiHypermediaLinkProvider;
use Drupal\jsonapi_hypermedia\Plugin\LinkProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class EntryPointSchemaLinkProvider.
 *
 * @JsonapiHypermediaLinkProvider(
 *   id = "jsonapi_shema.top_level.entrypoint",
 *   link_context = {
 *     "top_level_object" = "entrypoint",
 *   },
 *   deriver = "Drupal\jsonapi_schema\Plugin\Derivative\EntryPointSchemaLinkProviderDeriver",
 * )
 *
 * @internal
 */
final class EntryPointSchemaLinkProvider extends LinkProviderBase implements ContainerFactoryPluginInterface {

  /**
   * The JSON:API resource type repository.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface
   */
  protected $resourceTypeRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The schema route name.
   *
   * @var string
   */
  protected $schemaRouteName;

  /**
   * The schema type.
   *
   * @var string
   */
  protected $schemaType;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, string $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->schemaRouteName = $configuration['schema_route_name'];
    $this->schemaType = $configuration['schema_type'];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $provider = new static($configuration, $plugin_id, $plugin_definition);
    $provider->setResourceTypeRepository($container->get('jsonapi.resource_type.repository'));
    $provider->setEntityTypeManager($container->get('entity_type.manager'));
    return $provider;
  }

  /**
   * Sets the JSON:API resource type repository.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface $resource_type_repository
   */
  public function setResourceTypeRepository(ResourceTypeRepositoryInterface $resource_type_repository) {
    $this->resourceTypeRepository = $resource_type_repository;
  }

  /**
   * Sets the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getLink($context) {
    assert($context instanceof JsonApiDocumentTopLevel);
    $links = iterator_to_array($context->getLinks());
    $link_key = $this->getLinkKey();
    assert(!empty($links[$link_key]));
    $link = reset($links[$link_key]);
    $schema_url = Url::fromRoute($this->schemaRouteName);
    $schema_href = $schema_url->setAbsolute()->toString(TRUE);
    $schema_title = (string) $this->getSchemaTitle($this->resourceTypeRepository->getByTypeName($link_key), $this->schemaType);
    return AccessRestrictedLink::createLink(AccessResult::allowed(), CacheableMetadata::createFromObject($link)->addCacheableDependency($schema_href), $link->getUri(), $link->getLinkRelationTypes(), [
      'title' => $schema_title,
      'describedby' => $schema_href->getGeneratedUrl(),
    ]);
  }

  /**
   * Gets a schema title.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   A JSON:API resource type for which to generate a title.
   * @param $schema_type
   *   The type of schema. Either 'collection' or 'item'.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The schema title.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getSchemaTitle(ResourceType $resource_type, $schema_type) {
    $entity_type = $this->entityTypeManager->getDefinition($resource_type->getEntityTypeId());
    $entity_type_label = $schema_type === 'collection' ? $entity_type->getPluralLabel() : $entity_type->getSingularLabel();
    if ($bundle_type = $entity_type->getBundleEntityType()) {
      $bundle = $this->entityTypeManager->getStorage($bundle_type)->load($resource_type->getBundle());
      return $this->t(rtrim('@bundle_label @entity_type_label'), [
        '@bundle_label' => Unicode::ucfirst($bundle->label()),
        '@entity_type_label' => $entity_type_label,
      ]);
    }
    else {
      return $this->t(rtrim('@entity_type_label'), [
        '@entity_type_label' => Unicode::ucfirst($entity_type_label),
      ]);
    }
  }

}
