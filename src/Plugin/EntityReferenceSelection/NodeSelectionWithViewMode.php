<?php

declare(strict_types=1);

namespace Drupal\custom_er_selection\Plugin\EntityReferenceSelection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom Entity Reference plugin
 *
 * @EntityReferenceSelection(
 *   id = "custom_er_selection_node_selection_view_mode",
 *   label = @Translation("Node selection with view mode"),
 *   group = "custom_er_selection_node_selection_view_mode",
 *   entity_types = {"node"},
 * )
 */
class NodeSelectionWithViewMode extends SelectionPluginBase implements 
ContainerFactoryPluginInterface {

    /**
   * Constructs a new DefaultSelection object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entityDisplayRepository
   *   The entity display repository.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager, protected EntityRepositoryInterface $entityRepository, protected EntityDisplayRepositoryInterface $entityDisplayRepository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('entity_display.repository')
    );
  }

  
  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'view_modes' => NULL,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $configuration = $this->getConfiguration();
    $target_type = $configuration['target_type'];
    $view_modes = $this->entityDisplayRepository->getViewModeOptions($target_type);
    $view_mode_options = [];
    foreach ($view_modes as $view_mode_id => $view_mode_label) {
      $view_mode_options[$view_mode_id] = $view_mode_label;
    }
    natsort($view_mode_options);
    $form['view_modes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('View modes'),
      '#options' => $view_mode_options,
      '#default_value' => (array) $configuration['view_modes'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * Builds an EntityQuery to get referenceable entities.
   *
   * @param string|null $match
   *   (Optional) Text to match the label against. Defaults to NULL.
   * @param string $match_operator
   *   (Optional) The operation the matching should be done with. Defaults
   *   to "CONTAINS".
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The EntityQuery object with the basic conditions and sorting applied to
   *   it.
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS'): QueryInterface {
    $configuration = $this->getConfiguration();
    $target_type = $configuration['target_type'];
    $entity_type = $this->entityTypeManager->getDefinition($target_type);
    // Get query object.
    $query = $this->entityTypeManager->getStorage($target_type)->getQuery();
    $query->accessCheck(TRUE);

    $selected_view_modes = $configuration['view_modes'];
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $node_types_with_selected_view_mode = [];

    foreach ($selected_view_modes as $selected_view_modes) {
      foreach ($node_types as $node_type_id => $node_type) {
        $view_modes = $this->entityDisplayRepository->getViewModeOptionsByBundle($target_type, $node_type_id);
        if (array_key_exists($selected_view_modes, $view_modes)) {
          $node_types_with_selected_view_mode[$node_type_id] = $node_type_id;
        }
      }
    }
  
    if (is_array($node_types_with_selected_view_mode)) {
      if ($entity_type->hasKey('bundle')) {
        $query->condition($entity_type->getKey('bundle'), $node_types_with_selected_view_mode, 'IN');
        $query->condition('status', NodeInterface::PUBLISHED);
      }
    }

    if (isset($match) && $label_key = $entity_type->getKey('label')) {
      $query->condition($label_key, $match, $match_operator);
    }

    // Add entity-access tag.
    $query->addTag($target_type . '_access');

    // Add the Selection handler for system_query_entity_reference_alter().
    $query->addTag('entity_reference');
    $query->addMetaData('entity_reference_selection_handler', $this);
    return $query;
  }

    /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $target_type = $this->getConfiguration()['target_type'];

    $query = $this->buildEntityQuery($match, $match_operator);
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $result = $query->execute();

    if (empty($result)) {
      return [];
    }

    $options = [];
    $entities = $this->entityTypeManager->getStorage($target_type)->loadMultiple($result);
    foreach ($entities as $entity_id => $entity) {
      $bundle = $entity->bundle();
      $options[$bundle][$entity_id] = Html::escape($this->entityRepository->getTranslationFromContext($entity)->label() ?? '');
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function countReferenceableEntities($match = NULL, $match_operator = 'CONTAINS') {
    $query = $this->buildEntityQuery($match, $match_operator);
    return $query
      ->count()
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    $result = [];
    if ($ids) {
      $target_type = $this->configuration['target_type'];
      $entity_type = $this->entityTypeManager->getDefinition($target_type);
      $query = $this->buildEntityQuery();
      $result = $query
        ->condition($entity_type->getKey('id'), $ids, 'IN')
        ->execute();
    }

    return $result;
  }

}
