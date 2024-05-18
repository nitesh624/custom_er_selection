<?php

declare(strict_types=1);

namespace Drupal\custom_er_selection\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\node\Plugin\EntityReferenceSelection\NodeSelection;

/**
 * @todo Add plugin description here.
 *
 * @EntityReferenceSelection(
 *   id = "custom_er_selection_node_selection_id",
 *   label = @Translation("Extended node selection with id"),
 *   group = "custom_er_selection_node_selection_id",
 *   entity_types = {"node"},
 * )
 */
final class NodeSelectionWithID extends NodeSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS'): QueryInterface {
    $configuration = $this->getConfiguration();
    $target_type = $configuration['target_type'];
    $entity_type = $this->entityTypeManager->getDefinition($target_type);
    $label_key = $entity_type->getKey('label');
    $id_key = $entity_type->getKey('id');

    //  Add OR condition group for label and nid.
    if ($match && $id_key && $label_key) {
      $query = parent::buildEntityQuery(NULL, $match_operator);
      $group = $query->orConditionGroup()
        ->condition($label_key, $match, $match_operator)
        ->condition($id_key, $match);
      $query->condition($group);
      return $query;
    }
    return parent::buildEntityQuery($match, $match_operator);
  }

}
