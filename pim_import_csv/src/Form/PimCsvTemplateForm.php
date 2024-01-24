<?php

namespace Drupal\pim_import_csv\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\Entity\Media;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for CSV template file upload.
 */
class PimCsvTemplateForm extends ConfigFormBase {


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * PimICsvTemplateForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityManager) {
    $this->entityManager = $entityManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'pim_import_csv.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pim_csv_template_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('pim_import_csv.settings');
    $form['csv_file_template'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload CSV File Template'),
      '#description' => $this->t('Upload a CSV file template.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
      '#upload_location' => 'public://csv_files/template/',
      '#required' => TRUE,
      '#default_value' => $config->get('csv_file.template_id'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    // Get the uploaded file from the form state.
    $uploaded_file = $form_state->getValue('csv_file_template');
    // Save config items.
    $this->config('pim_import_csv.settings')
      ->set('csv_file.template_id', $form_state->getValue('csv_file_template'))
      ->save();
    if (!empty($uploaded_file)) {
      // Load the file entity.
      $file = $this->entityManager->getStorage('file')->load($uploaded_file[0]);
      if ($file) {
        // Create a media entity.
        $media = Media::create([
          'bundle' => 'document',
          'name' => $file->getFilename(),
          'field_media_document' => [
            'target_id' => $file->id(),
          ],
        ]);
        $media->save();
        // Set the uploaded file's value to the media entity's ID.
        $form_state->setValue('csv_file_template', $media->id());
        $this->config('pim_import_csv.settings')
          ->set('csv_file.media_id', $form_state->getValue('csv_file_template'))
          ->save();
      }
    }
  }

}
