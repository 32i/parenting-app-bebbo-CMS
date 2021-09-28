<?php

namespace Drupal\pb_custom_field\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Action description.
 *
 * @Action(
 *   id = "pb_custom_field_publish_to_senioreditor",
 *   label = @Translation("Publish to Archive then Senior Editor Review"),
 *   type = "node",
 *   confirm = FALSE
 * )
 */
class MovefrompublishtosenioreditorAction extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;
  /**
   * Get the total translated count.
   *
   * @var int
   */
  public $assigned = 0;
  /**
   * Get the total non translated count.
   *
   * @var int
   */
  public $nonAssigned = 0;
  /**
   * Get the total items processed.
   *
   * @var int
   */
  public $processItem = 0;

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {
    $uid = \Drupal::currentUser()->id();
    $context = $this->context;
    $total_selected = $context['sandbox']['total'];

    $this->processItem = $this->processItem + 1;
    $message = "";
    $error_message = "";
    $current_language = $entity->get('langcode')->value;
    $nid = $entity->get('nid')->getString();
    $node = node_load($nid);

    $node_lang = $node->getTranslation($current_language);
    $current_state = $node_lang->moderation_state->value;
    if ($current_state == 'published') {
      /* Change status from publish to archive. */
      $node_lang->set('moderation_state', 'archive');
      $node_lang->set('uid', $uid);
      $node_lang->set('content_translation_source', $current_language);
      $node_lang->set('changed', time());
      $node_lang->set('created', time());
      $node_lang->save();
      $node->save();

      /* Change status from archive to senior editor review. */
      $node = node_load($nid);
      /* Create new revision. */
      $node->setNewRevision();
      $node->revision_log = 'Content Bulk updated from Achieve to Senior editor review state' . " " . $nid;
      $node->setRevisionCreationTime(REQUEST_TIME);
      $node->isDefaultRevision(FALSE);
      $node->setRevisionAuthorId($uid);
      $node->save();
      $storage = \Drupal::entityTypeManager()->getStorage($node->getEntityTypeId());
      $revision_id = $storage->getLatestTranslationAffectedRevisionId($node->id(), $current_language);
      $revision_node = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($revision_id);
      $revision_node->isDefaultRevision(TRUE);
      $revision_node->status = 1;
      $revision_node->revision = 1;
      $revision_node->revision_uid = $uid;
      $revision_node->log = "Reason why we are resetting";
      $revision_node->setPublished(TRUE);
      $revision_node->set('moderation_state', "senior_editor_review");
      $revision_node->save();
      $node_lang = $node->getTranslation($current_language);
      $node_lang->set('moderation_state', 'senior_editor_review');
      $node_lang->set('uid', $uid);
      $node_lang->set('content_translation_source', $current_language);
      $node_lang->set('changed', time());
      $node_lang->set('created', time());
      $node_lang->save();
      $node->save();
      $this->assigned = $this->assigned + 1;
    }
    else {
      $this->nonAssigned = $this->nonAssigned + 1;
    }

    if ($this->nonAssigned > 0) {
      $error_message = $this->t("Please Select Published Content ( @nonassigned ) <br/>", ['@nonassigned' => $this->nonAssigned]);
    }
    else {
      $message = $this->t("Content Changed Into Senior Editor Review Successfully ( @assigned ) <br/>", ['@assigned' => $this->assigned]);
    }

    /* $message.="Please visit Country content page to view.";*/
    if ($total_selected == $this->processItem) {
      if (!empty($message)) {
        drupal_set_message($message, 'status');
      }
      if (!empty($error_message)) {
        drupal_set_message($error_message, 'error');
      }
    }

    return $this->t("Total content selected");
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    if ($object->getEntityType() === 'node') {
      $access = $object->access('update', $account, TRUE)
        ->andIf($object->status->access('edit', $account, TRUE));
      return $return_as_object ? $access : $access->isAllowed();
    }

    return TRUE;
  }

}
