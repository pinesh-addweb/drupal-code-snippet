<?php

namespace Drupal\pim_import_csv\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\FileRepository;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for CSV file upload.
 */
class PimImportCsvForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The file_system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The file repository service.
   *
   * @var \Drupal\file\FileRepository
   */
  protected $fileRepository;

  /**
   * The file URL generator service.
   *
   * @var \Drupal\Core\File\FileUrlGenerator
   */
  protected $urlGenerator;

  /**
   * The config factory used by the config entity query.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * PimImportCSVForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityManager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger service.
   * @param \Drupal\Core\File\FileSystem $fileSystem
   *   The filesystem service.
   * @param \Drupal\file\FileRepository $fileRepository
   *   The file repository service.
   * @param \Drupal\Core\File\FileUrlGenerator $url_generator
   *   The file URL generator service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityManager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger,
    FileSystem $fileSystem,
    FileRepository $fileRepository,
    FileUrlGenerator $url_generator,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityManager = $entityManager;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->fileSystem = $fileSystem;
    $this->fileRepository = $fileRepository;
    $this->urlGenerator = $url_generator;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('logger.factory'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('file_url_generator'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pim_import_csv_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->get('pim_import_csv.settings');
    $template_media_id = $config->get('csv_file.media_id');
    // Check if the media ID is valid.
    if (!empty($template_media_id)) {
      // Load the media entity associated with the media ID.
      $media_entity = $this->entityManager->getStorage('media')->load($template_media_id);
      if ($media_entity) {
        $file = $media_entity->field_media_document->entity;
        if ($file) {
          $file_uri = $file->getFileUri();
          $file_url = $this->urlGenerator->generateAbsoluteString($file_uri);
          // Add a link to download the media file.
          $form['download_link'] = [
            '#markup' => '<a class="button" href="' . $file_url . '" target="_blank">' . $this->t('Click to Download CSV Template') . '</a>',
          ];
        }
      }
    }
    $form['csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload CSV File'),
      '#description' => $this->t('Upload a CSV file for import.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
      '#upload_location' => 'public://csv_files/',
      '#required' => TRUE,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and Import'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get the file ID from the form submission.
    $file_ids = $form_state->getValue('csv_file');
    // Ensure that we have a file ID.
    if (!empty($file_ids)) {
      // Load the file entity.
      $file = $this->entityManager->getStorage('file')->load($file_ids[0]);
      if ($file) {
        // Get the URI of the file.
        $file_uri = $file->getFileUri();
        // Parse the CSV file.
        $csv_rows = $this->parseCsvFile($file_uri);
        // Separate the header row from the data rows.
        $header_row = array_shift($csv_rows);
        if (!empty($csv_rows)) {
          // Batch process.
          $batch = [
            'operations' => [],
            'finished' => [$this, 'batchFinished'],
            'title' => t('PIM CSV Import'),
            'init_message' => t('Starting node creation or updation process.'),
            'progress_message' => t('Processed @current out of @total values.'),
            'error_message' => t('Error occurred during node creation/updation process.'),
          ];
          foreach ($csv_rows as $row) {
            // Map header values to keys for the current data row.
            $mapped_data = array_combine($header_row, $row);
            // Check if "product_sku" is a key in the mapped data.
            if (isset($mapped_data['product_sku'])) {
              $batch['operations'][] = array(
                [$this, 'processBatchRow'],
                [$mapped_data],
              );
            }
          }
          batch_set($batch);
          // Display a success message.
          $this->messenger->addMessage($this->t('CSV file processed. Nodes created or Updated.'));
        }
        else {
          // Display a error message.
          $this->messenger->addError($this->t('Uploaded File is empty'));
        }
      }
      else {
        // Display an error message if the file could not be loaded.
        $this->messenger->addError($this->t('Error loading the uploaded CSV file.'));
      }
    }
    else {
      // Display an error message if no file was uploaded.
      $this->messenger->addError($this->t('No file uploaded.'));
    }
  }

  /**
   * Batch operation callback for processing each row.
   */
  public function processBatchRow($mapped_data, &$context) {
    // Check if "product_sku" is a key in the mapped data.
    if (isset($mapped_data['product_sku'])) {
      $product_sku = $mapped_data['product_sku'];
      $product_name = $mapped_data['product_name'];

      // Check if a node with the same SKU already exists.
      $query = $this->entityManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'product')
        ->condition('field_product_code', $product_sku);
      $existing_nids = $query->execute();

      if (!empty($existing_nids)) {
        // Node with the same SKU exists, update the existing node.
        $existing_nid = reset($existing_nids);
        $node = $this->entityManager->getStorage('node')->load($existing_nid);
        $this->setNodeFieldValues($node, $mapped_data);
      } else {
        // Create a new node if it doesn't exist.
        $node = Node::create([
          'type' => 'product',
        ]);
        $this->setNodeFieldValues($node, $mapped_data);
      }
      // Update the batch progress.
      $context['message'] = t('Processed SKU @sku', ['@sku' => $product_sku]);
    }
  }

  protected function setNodeFieldValues(Node $node, array $mapped_data){
    $node->set('title',$mapped_data['product_name']);
    // Additional fields to handle blank cells.
    $fields_to_handle_blank = [
      'product_sku' => 'field_product_code',
      'type' => 'field_select_type',
      'linked_product_id' => 'field_accessories',
      'featured' => 'field_featured',
      'product_description' => 'body',
      'product_specification' => 'field_product_specification',
    ];
    foreach ($fields_to_handle_blank as $mapped_key => $field_name) {
      // Check if the value in the sheet is not empty, then update the field.
      if (isset($mapped_data[$mapped_key])) {
        if ($mapped_data[$mapped_key] !== '' && $mapped_data[$mapped_key] !== 'NULL') {
          $node->set($field_name, $mapped_data[$mapped_key]);
        }
        elseif ($mapped_data[$mapped_key] == 'NULL') {
          $node->set($field_name, NULL);
        }
      }
    }
    // Handle category field.
    if (isset($mapped_data['category.product_category'])) {
      if($mapped_data['category.product_category'] !== '' && $mapped_data['category.product_category'] !== 'NULL'){
        $category_value = $mapped_data['category.product_category'];
        // Check if the vocabulary exists.
        $vocabulary_machine_name = 'product_category';
        $vocabulary = $this->entityManager->getStorage('taxonomy_vocabulary')->load($vocabulary_machine_name);
        // Check if the term already exists in the vocabulary.
        $term_id = $this->findTermInVocabulary($category_value, $vocabulary);
        if (!$term_id) {
          // Term doesn't exist, create it.
          $term = Term::create([
            'vid' => $vocabulary->id(),
            'name' => $category_value,
          ]);
          $term->save();
          // Get the term ID after creating it.
          $term_id = $term->id();
        }
        // Set the category field.
        $node->set('field_category', $term_id);
      }
      elseif ($mapped_data['category.product_category'] == 'NULL'){
        $node->set('field_category', NULL);
      }
    }

    // Process the "field_product_attributes" text field.
    if (isset($mapped_data['product_features'])) {
      if ($mapped_data['product_features'] !== '' && $mapped_data['product_features'] !== 'NULL'){
        $features = explode(',', $mapped_data['product_features']);
        // Remove any leading whitespace from each feature.
        $features = array_map('trim', $features);
        // Assign the features to the text field.
        $node->set('field_product_features', $features);
      }
      elseif ($mapped_data['product_features'] == 'NULL'){
          $node->set('field_product_features', NULL);
      }
    }

    /* Start - Attributes Section */
    // Get the product attributes paragraph reference field.
    $product_attributes_field = $node->get('field_product_attributes');

    // Loop through the referenced paragraphs.
    foreach ($product_attributes_field as $item) {
      // Load the referenced paragraph.
      $existing_attributes_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_quantity_value = $existing_attributes_paragraph->get('field_quantity')->value;
      $existing_size_value = $existing_attributes_paragraph->get('field_size')->value;
      $existing_color_value = $existing_attributes_paragraph->get('field_color')->value;
      $existing_weight_value = $existing_attributes_paragraph->get('field_weight')->value;
    }
    // Check if the quantity field is not empty in the CSV data.
    if (isset($mapped_data['product_attributes.quantity'])){
      if ($mapped_data['product_attributes.quantity'] !== '' && $mapped_data['product_attributes.quantity'] !== 'NULL'){
        $quantity = $mapped_data['product_attributes.quantity'];
      } elseif ($mapped_data['product_attributes.quantity'] == 'NULL') {
        $quantity = '';
      } else {
        $quantity = $existing_quantity_value;
      }
    }
    // Check if the size field is not empty in the CSV data.
    if (isset($mapped_data['product_attributes.size'])){
      if($mapped_data['product_attributes.size'] !== '' && $mapped_data['product_attributes.size'] !== 'NULL'){
        $size = $mapped_data['product_attributes.size'];
      } elseif ($mapped_data['product_attributes.size'] == 'NULL') {
        $size = '';
      } else {
        $size = $existing_size_value;
      }
    }
    // Check if the color field is not empty in the CSV data.
    if (isset($mapped_data['product_attributes.color'])){
      if($mapped_data['product_attributes.color'] !== '' && $mapped_data['product_attributes.color'] !== 'NULL'){
        $color = $mapped_data['product_attributes.color'];
      } elseif ($mapped_data['product_attributes.color'] == 'NULL') {
        $color = '';
      } else {
        $color = $existing_color_value;
      }
    }
    // Check if the weught field is not empty in the CSV data.
    if (isset($mapped_data['product_attributes.weight'])){
      if($mapped_data['product_attributes.weight'] !== '' && $mapped_data['product_attributes.weight'] !== 'NULL'){
        $weight = $mapped_data['product_attributes.weight'];
      } elseif ($mapped_data['product_attributes.weight'] == 'NULL') {
        $weight = '';
      } else {
        $weight = $existing_weight_value;
      }
    }

    // Create a product attributes paragraph.
    $attribute_paragraph = Paragraph::create([
      'type' => 'product_attributes',
      'field_quantity' => $quantity,
      'field_size' => $size,
      'field_color' => $color,
      'field_weight' => $weight
    ]);

    $this->attachParagraphToNode($node, $attribute_paragraph, 'field_product_attributes');
    /* End - Attributes Section */

    /* Start - Marketing Copy Section */
    // Get the marketing copy paragraph reference field.
    $marketing_copy_field = $node->get('field_marketing_copy');
    // Loop through the referenced paragraphs.
    foreach ($marketing_copy_field as $item) {
      // Load the referenced paragraph.
      $existing_marketing_copy_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_sales_copy = $existing_marketing_copy_paragraph->get('field_sales_copy')->getValue();
      $existing_social_media_content = $existing_marketing_copy_paragraph->get('field_pim_social_media_content')->getValue();
      $existing_user_guide = $existing_marketing_copy_paragraph->get('field_pim_user_guides')->getValue();
    }
    // Process 'marketing.sales_copy' column.
    if (isset($mapped_data['marketing.sales_copy'])) {
      if ($mapped_data['marketing.sales_copy'] !== '' && $mapped_data['marketing.sales_copy'] !== 'NULL'){
        $sales_copy_urls = explode(',', $mapped_data['marketing.sales_copy']);
        $sales_copy_urls = array_map('trim', $sales_copy_urls);
        // Define the directory where you want to save the files.
        $sales_copy_directory = 'public://marketing_copy';
        // Call the reusable function to import media assets.
        $sales_copy_ids = $this->importMediaFromUrls($sales_copy_urls, $sales_copy_directory, 'document', 'field_media_document');
        if (!empty($sales_copy_ids)) {
          // Set 'field_sales_copy' to the media IDs.
          $sales_copy = $sales_copy_ids;
        }
      } elseif ($mapped_data['marketing.sales_copy'] == 'NULL'){
        $sales_copy = [];
      } else {
        $sales_copy = $existing_sales_copy;
      }
    }

    // Process 'marketing.social_media_content' column.
    if (isset($mapped_data['marketing.social_media_content'])) {
      if ($mapped_data['marketing.social_media_content'] !== '' && $mapped_data['marketing.social_media_content'] !== 'NULL'){
        $social_media_content_urls = explode(',', $mapped_data['marketing.social_media_content']);
        $social_media_content_urls = array_map('trim', $social_media_content_urls);
        // Define the directory where you want to save the files.
        $social_media_content_directory = 'public://marketing_copy';
        // Call the reusable function to import media assets.
        $social_media_ids = $this->importMediaFromUrls($social_media_content_urls, $social_media_content_directory, 'document', 'field_media_document');
        if (!empty($social_media_ids)) {
          // Set 'field_sales_copy' to the media IDs.
          $social_media_content = $social_media_ids;
        }
      } elseif ($mapped_data['marketing.social_media_content'] == 'NULL'){
        $social_media_content = [];
      } else {
        $social_media_content = $existing_social_media_content;
      }
    }

    // Process 'marketing.user_guides' column.
    if (isset($mapped_data['marketing.user_guides'])) {
      if ($mapped_data['marketing.user_guides'] !== '' && $mapped_data['marketing.user_guides'] !== 'NULL'){
        $user_guide_urls = explode(',', $mapped_data['marketing.user_guides']);
        $user_guide_urls = array_map('trim', $user_guide_urls);
        // Define the directory where you want to save the files.
        $user_guide_directory = 'public://september10/marketing_copy';
        // Call the reusable function to import media assets.
        $user_guide_ids = $this->importMediaFromUrls($user_guide_urls, $user_guide_directory, 'document', 'field_media_document');
        if (!empty($user_guide_ids)) {
          // Set 'field_pim_user_guides' to the media IDs.
          $user_guides = $user_guide_ids;
        }
      } elseif ($mapped_data['marketing.user_guides'] == 'NULL'){
        $user_guides = [];
      } else {
        $user_guides = $existing_user_guide;
      }
    }
    // Create a marketing copy paragraph.
    $marketing_copy_paragraph = Paragraph::create([
      'type' => 'marketing_copy',
      'field_sales_copy' => $sales_copy,
      'field_pim_social_media_content' => $social_media_content,
      'field_pim_user_guides' => $user_guides,
    ]);

    $this->attachParagraphToNode($node, $marketing_copy_paragraph, 'field_marketing_copy');
    /* End - Marketing Copy Section */

    /* Start - Assets Section */
    // Get the Assets paragraph reference field.
    $uploaded_assets = $node->get('field_upload_assets');
    // Loop through the referenced paragraphs.
    foreach ($uploaded_assets as $item) {
      // Load the referenced paragraph.
      $uploaded_assets_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_asset_image = $uploaded_assets_paragraph->get('field_asset_image')->getValue();
      $existing_asset_video = $uploaded_assets_paragraph->get('field_asset_video')->getValue();
      $existing_asset_audio = $uploaded_assets_paragraph->get('field_assets_audio')->getValue();
      $existing_asset_pdf = $uploaded_assets_paragraph->get('field_asset_pdf')->getValue();
    }

    // Process 'assets.image' column.
    if (isset($mapped_data['assets.image'])) {
      if ($mapped_data['assets.image'] !== '' && $mapped_data['assets.image'] !== 'NULL'){
        $image_urls = explode(',', $mapped_data['assets.image']);
        $image_urls = array_map('trim', $image_urls);
        // Define the directory where you want to save the files.
        $image_directory = 'public://assets/image/';
        // Alt text for image media.
        $alt_text = $mapped_data['assets.image.alt'] ?? 'Default Alt Text';
        // Call the reusable function to import media assets.
        $image_ids = $this->importMediaFromUrls($image_urls, $image_directory, 'image', 'field_media_image', $alt_text);
        // Check if there are media IDs to associate.
        if (!empty($image_ids)) {
          // Set 'field_pim_user_guides' to the media IDs.
          $asset_image = $image_ids;
        }
        else {
          $this->messenger->addWarning($this->t('Assets Image value is empty'));
        }
      }
      elseif ($mapped_data['assets.image'] == 'NULL'){
        $asset_image = [];
      }
      else {
        $asset_image = $existing_asset_image;
      }
    }

    // Process 'assets.video' column.
    if (isset($mapped_data['assets.video'])) {
      if ($mapped_data['assets.video'] !== '' && $mapped_data['assets.video'] !== 'NULL'){
        $video_urls = explode(',', $mapped_data['assets.video']);
        $video_urls = array_map('trim', $video_urls);
        // Define the directory where you want to save the files.
        $video_directory = 'public://assets/video/';
        // Call the reusable function to import media assets.
        $video_ids = $this->importMediaFromUrls($video_urls, $video_directory, 'video', 'field_media_video_file');
        // Check if there are media IDs to associate.
        if (!empty($video_ids)) {
          // Set 'field_asset_video' to the media IDs.
          $asset_video = $video_ids;
        }
        else {
          $this->messenger->addWarning($this->t('Assets Video value is empty'));
        }
      }
      elseif ($mapped_data['assets.video'] == 'NULL'){
        $asset_video = [];
      }
      else {
        $asset_video = $existing_asset_video;
      }
    }

    // Process 'assets.audio' column.
    if (isset($mapped_data['assets.audio'])) {
      if ($mapped_data['assets.audio'] !== '' && $mapped_data['assets.audio'] !== 'NULL'){
        $audio_urls = explode(',', $mapped_data['assets.audio']);
        $audio_urls = array_map('trim', $audio_urls);
        // Define the directory where you want to save the files.
        $audio_directory = 'public://assets/audio/';
        // Call the reusable function to import media assets.
        $audio_ids = $this->importMediaFromUrls($audio_urls, $audio_directory, 'audio', 'field_media_audio_file');
        // Check if there are media IDs to associate.
        if (!empty($audio_ids)) {
          // Set 'field_assets_audio' to the media IDs.
          $asset_audio = $audio_ids;
        }
        else {
          $this->messenger->addWarning($this->t('Assets Audio value is empty'));
        }
      }
      elseif ($mapped_data['assets.audio'] == 'NULL'){
        $asset_audio = [];
      }
      else {
        $asset_audio = $existing_asset_audio;
      }
    }

    // Process 'assets.document' column.
    if (isset($mapped_data['assets.pdf'])) {
      if($mapped_data['assets.pdf'] !== '' && $mapped_data['assets.pdf'] !== 'NULL'){
        $pdf_urls = explode(',', $mapped_data['assets.pdf']);
        $pdf_urls = array_map('trim', $pdf_urls);
        // Define the directory where you want to save the files.
        $pdf_directory = 'public://assets/pdf/';
        // Call the reusable function to import media assets.
        $pdf_ids = $this->importMediaFromUrls($pdf_urls, $pdf_directory, 'document', 'field_media_document');
        // Check if there are media IDs to associate.
        if (!empty($pdf_ids)) {
          // Set 'field_asset_pdf' to the media IDs.
          $asset_pdf = $pdf_ids;
        }
        else {
          $this->messenger->addWarning($this->t('Assets PDF value is empty'));
        }
      }
      elseif($mapped_data['assets.pdf'] == 'NULL'){
        $asset_pdf = [];
      }
      else {
        $asset_pdf = $existing_asset_pdf;
      }
    }

    // Create assets paragraph.
    $assets_paragraph = Paragraph::create([
      'type' => 'assets',
      'field_asset_image' => $asset_image,
      'field_asset_video' => $asset_video,
      'field_assets_audio' => $asset_audio,
      'field_asset_pdf' => $asset_pdf,
    ]);
    // Save and attach the assets paragraph entity
    $this->attachParagraphToNode($node, $assets_paragraph, 'field_upload_assets');
    /* End - Assets Section */

    /* Start - Product Group Section */
    // Get the product group paragraph reference field.
    $product_group = $node->get('field_product_group');
    // Loop through the referenced paragraphs.
    foreach ($product_group as $item) {
      // Load the referenced paragraph.
      $product_group_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_prd_model = $product_group_paragraph->get('field_model')->getValue();
      $existing_prd_part_number = $product_group_paragraph->get('field_part_number')->getValue();
      $existing_prd_description = $product_group_paragraph->get('field_description')->getValue();
      $existing_marketing_description = $product_group_paragraph->get('field_marketing_description')->getValue();
      $existing_prd_warranty = $product_group_paragraph->get('field_warranty')->getValue();
      $existing_prd_conformal_coating = $product_group_paragraph->get('field_conformal_coating')->getValue();
      $existing_prd_hdmi = $product_group_paragraph->get('field_hdmi')->getValue();
      $existing_prd_reliability_mtbf = $product_group_paragraph->get('field_reliability_mtbf')->getValue();
      $existing_prd_upc = $product_group_paragraph->get('field_upc')->getValue();
      $existing_prd_eccn = $product_group_paragraph->get('field_eccn')->getValue();
      $existing_prd_series = $product_group_paragraph->get('field_series')->getValue();
      $existing_prd_family = $product_group_paragraph->get('field_family')->getValue();
      $existing_prd_segment = $product_group_paragraph->get('field_segment')->getValue();
    }
    // Process 'prod_grp.model' column.
    $prod_grp_model = $this->getParagraphFieldValue($mapped_data['prod_grp.model'],$existing_prd_model);
    // Process 'prod_grp.part_number' column.
    $prod_grp_part_number = $this->getParagraphFieldValue($mapped_data['prod_grp.part_number'],$existing_prd_part_number);
    // Process 'prod_grp.description' column.
    $prod_grp_description = $this->getParagraphFieldValue($mapped_data['prod_grp.description'],$existing_prd_description);
    // Process 'prod_grp.marketing_description' column.
    $prod_grp_marketing_description = $this->getParagraphFieldValue($mapped_data['prod_grp.marketing_description'], $existing_marketing_description);

    // Process 'prod_grp.warranty' column.
    if (isset($mapped_data['prod_grp.warranty'])) {
      if($mapped_data['prod_grp.warranty'] !== '' && $mapped_data['prod_grp.warranty'] !== 'NULL'){
        $product_warranty_data = (int) $mapped_data['prod_grp.warranty'];
        // Check if the value is between 2 and 5.
        if ($product_warranty_data >= 2 && $product_warranty_data <= 5) {
          $product_warranty = $product_warranty_data;
        }
        else {
          $this->messenger->addWarning($this->t('Product Group Warranty value should be between 2 and 5'));
        }
      }
      elseif($mapped_data['prod_grp.warranty'] == 'NULL'){
        $product_warranty = '';
      }
      else {
        $product_warranty = $existing_prd_warranty;
      }
    }
    // Process 'prod_grp.conformal_coating' column.
    $product_conformal_coating = $this->getParagraphFieldValue($mapped_data['prod_grp.conformal_coating'], $existing_prd_conformal_coating);
    // Process 'prod_grp.hdmi' column.
    $product_hdmi = $this->getParagraphFieldValue($mapped_data['prod_grp.hdmi'], $existing_prd_hdmi);
    // Process 'prod_grp.reliability_mtbf' column.
    $product_reliability_mtbf = $this->getParagraphFieldValue($mapped_data['prod_grp.reliability_mtbf'], $existing_prd_reliability_mtbf);

    // Process 'prod_grp.upc' column.
    if (isset($mapped_data['prod_grp.upc'])) {
      if ($mapped_data['prod_grp.upc'] !== '' && $mapped_data['prod_grp.upc'] !== 'NULL'){
        $product_upc_value = (int) $mapped_data['prod_grp.upc'];
        // Check if a paragraph with the same UPC already exists.
        $query = $this->entityManager->getStorage('paragraph')->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'product')
          ->condition('field_upc', $product_upc_value)
          ->range(0, 1);
        $existing_paragraph_ids = $query->execute();
        if (empty($existing_paragraph_ids)) {
          // Set the field_upc value only if it's unique.
          $product_upc = $product_upc_value;
        }
        else {
          $product_upc = $existing_prd_upc;
          $this->messenger->addWarning($this->t('Product Group UPC value should be Unique and Integer Value.'));
        }
      }
      elseif ($mapped_data['prod_grp.upc'] == 'NULL'){
          $product_upc = '';
      }
      else {
          $product_upc = $existing_prd_upc;
      }
    }
    // Process 'prod_grp.eccn' column.
    $product_eccn = $this->getParagraphFieldValue($mapped_data['prod_grp.eccn'], $existing_prd_eccn);
    // Process 'prod_grp.series' column.
    $product_series = $this->setParagraphTaxonomyField('product_series', $mapped_data['prod_grp.series'], $existing_prd_series);
    // Process 'prod_grp.family' column.
    $product_family = $this->setParagraphTaxonomyField('product_family', $mapped_data['prod_grp.family'], $existing_prd_family);
    // Define the allowed values for the 'field_segment' field.
    $segment_allowed_values = [
      'access',
      'connect',
      'visualize',
    ];
    // Check if 'prod_grp.segment' set and it's a valid value.
    if (isset($mapped_data['prod_grp.segment'])) {
      if ($mapped_data['prod_grp.segment'] !== 'NULL' && $mapped_data['prod_grp.segment'] !== ''){
        if (in_array($mapped_data['prod_grp.segment'], $segment_allowed_values)){
          $product_segment = $mapped_data['prod_grp.segment'];
        } else {
          $this->messenger->addWarning($this->t('Empty / Invalid value(s) for prod_grp.segment'));
        }
      } elseif ($mapped_data['prod_grp.segment'] == 'NULL') {
        $product_segment = '';
      } else {
        $product_segment = $existing_prd_segment;
      }
    }

    // Create product group paragraph.
    $product_paragraph = Paragraph::create([
      'type' => 'product',
      'field_model' => $prod_grp_model,
      'field_part_number' => $prod_grp_part_number,
      'field_description' => $prod_grp_description,
      'field_marketing_description' => $prod_grp_marketing_description,
      'field_warranty' => $product_warranty,
      'field_conformal_coating' => $product_conformal_coating,
      'field_hdmi' => $product_hdmi,
      'field_reliability_mtbf' => $product_reliability_mtbf,
      'field_upc' => $product_upc,
      'field_eccn' => $product_eccn,
      'field_series' => $product_series,
      'field_family' => $product_family,
      'field_segment' =>$product_segment,
    ]);
    // Save and attach the product paragraph entity
    $this->attachParagraphToNode($node, $product_paragraph, 'field_product_group');
    /* End - Product Group Section */

    /* Start - Ports Group Section */
    // Get the ports group paragraph reference field.
    $ports_group = $node->get('field_ports_group');
    // Loop through the referenced paragraphs.
    foreach ($ports_group as $item) {
      // Load the referenced paragraph.
      $ports_group_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_ports_copper = $ports_group_paragraph->get('field_copper')->getValue();
      $existing_ports_ethernet = $ports_group_paragraph->get('field_ethernet')->getValue();
      $existing_ports_fiber = $ports_group_paragraph->get('field_fiber')->getValue();
      $existing_ports_serial = $ports_group_paragraph->get('field_serial_ports')->getValue();
      $existing_ports_usb = $ports_group_paragraph->get('field_usb')->getValue();

    }
    // Process 'ports_group.copper' column.
    $copper_allowed_values = [
      '100', '1000', '1000_baset_rj455', '10g_baset',
    ];
    $ports_copper = $this->setFieldWithAllowedValues($mapped_data, 'ports_group.copper', $copper_allowed_values, $existing_ports_copper);
    // Process 'ports_group.ethernet' column.
    $ports_ethernet = $this->getParagraphFieldValue($mapped_data['ports_group.ethernet'], $existing_ports_ethernet);
    // Process 'ports_group.fiber' column.
    $ports_fiber = $this->getParagraphFieldValue($mapped_data['ports_group.fiber'], $existing_ports_fiber);
    // Process 'ports_group.serial_ports' column.
    $serial_ports_allowed_values = ['rs232', 'rs485', 'sw_defined'];
    $ports_serial = $this->setFieldWithAllowedValues($mapped_data, 'ports_group.serial_ports', $serial_ports_allowed_values, $existing_ports_serial);
    // Process 'ports_group.usb' column.
    $ports_usb = $this->getParagraphFieldValue($mapped_data['ports_group.usb'], $existing_ports_usb);
    // Create ports group paragraph.
    $ports_paragraph = Paragraph::create([
      'type' => 'ports',
      'field_copper' => $ports_copper,
      'field_ethernet' => $ports_ethernet,
      'field_fiber' => $ports_fiber,
      'field_serial_ports' => $ports_serial,
      'field_usb' => $ports_usb,
    ]);
    $this->attachParagraphToNode($node, $ports_paragraph, 'field_ports_group');
    /* End - Ports Group Section */

    /* Start - Certifications Group Section */
    // Get the Certification group paragraph reference field.
    $certification_group = $node->get('field_certifications_group');
    // Loop through the referenced paragraphs.
    foreach ($certification_group as $item) {
      // Load the referenced paragraph.
      $certification_group_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_cert_ul = $certification_group_paragraph->get('field_ul')->getValue();
      $existing_cert_fc = $certification_group_paragraph->get('field_fc')->getValue();
      $existing_cert_ce = $certification_group_paragraph->get('field_ce')->getValue();
      $existing_cert_ukca = $certification_group_paragraph->get('field_ukca')->getValue();
      $existing_cert_ex = $certification_group_paragraph->get('field_ex')->getValue();
      $existing_cert_dnv = $certification_group_paragraph->get('field_dnv')->getValue();
      $existing_cert_shock_vibration = $certification_group_paragraph->get('field_shock_and_vibration')->getValue();

    }
    // Process 'cert.ul' column.
    $cert_ul = $this->getParagraphFieldValue($mapped_data['cert.ul'],$existing_cert_ul);
    // Process 'cert.fc' column.
    $cert_fc = $this->getParagraphFieldValue($mapped_data['cert.fc'], $existing_cert_fc);
    // Process 'cert.ce' column.
    $cert_ce = $this->getParagraphFieldValue($mapped_data['cert.ce'], $existing_cert_ce);
    // Process 'cert.ukca' column.
    $cert_ukca = $this->getParagraphFieldValue($mapped_data['cert.ukca'], $existing_cert_ukca);
    // Process 'cert.ukca' column.
    $cert_ex = $this->getParagraphFieldValue($mapped_data['cert.ex'], $existing_cert_ex);
    // Process 'cert.dnv' column.
    $cert_dnv = $this->getParagraphFieldValue($mapped_data['cert.dnv'], $existing_cert_dnv);
    // Process 'cert.shock_vibration' column.
    $shock_vibration_allowed_values = ['iec_68_2_6', 'iec_68_2_27'];
    $cert_shock_and_vibration = $this->setFieldWithAllowedValues($mapped_data, 'cert.shock_vibration', $shock_vibration_allowed_values, $existing_cert_shock_vibration);
    // Create certification group paragraph.
    $certification_paragraph = Paragraph::create([
      'type' => 'certifications',
      'field_ul' => $cert_ul,
      'field_fc' => $cert_fc,
      'field_ce' => $cert_ce,
      'field_ukca' => $cert_ukca,
      'field_ex' => $cert_ex,
      'field_dnv' => $cert_dnv,
      'field_shock_and_vibration' => $cert_shock_and_vibration,
    ]);
    $this->attachParagraphToNode($node, $certification_paragraph, 'field_certifications_group');
    /* End - Certifications Group Section */

    /* Start - Mechanical Group Section */
    // Get the mechanical group paragraph reference field.
    $mechanical_group = $node->get('field_mechanical_group');
    // Loop through the referenced paragraphs.
    foreach ($mechanical_group as $item) {
      // Load the referenced paragraph.
      $mechanical_group_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_mech_enclosure = $mechanical_group_paragraph->get('field_enclosure')->getValue();
      $existing_mech_mounting = $mechanical_group_paragraph->get('field_mounting')->getValue();
      $existing_mech_abs = $mechanical_group_paragraph->get('field_abs')->getValue();
      $existing_mech_front_wiring = $mechanical_group_paragraph->get('field_front_wiring_clearance')->getValue();
      $existing_mech_top_wiring = $mechanical_group_paragraph->get('field_top_wiring_clearance')->getValue();
      $existing_mech_packaging_depth = $mechanical_group_paragraph->get('field_packagine_depth')->getValue();
      $existing_mech_packaging_height = $mechanical_group_paragraph->get('field_packaging_height')->getValue();
      $existing_mech_packaging_weight = $mechanical_group_paragraph->get('field_packaging_weight')->getValue();
      $existing_mech_packaging_width = $mechanical_group_paragraph->get('field_packaging_width')->getValue();
      $existing_mech_product_depth = $mechanical_group_paragraph->get('field_product_depth')->getValue();
      $existing_mech_product_height = $mechanical_group_paragraph->get('field_product_height')->getValue();
      $existing_mech_product_weight = $mechanical_group_paragraph->get('field_product_weight')->getValue();
      $existing_mech_product_width = $mechanical_group_paragraph->get('field_product_width')->getValue();
    }
    // Process 'mech.enclosure' column.
    $mechanical_enclosure_allowed_values = ['metal', 'plastic'];
    $mech_enclosure = $this->setFieldWithAllowedValues($mapped_data, 'mech.enclosure', $mechanical_enclosure_allowed_values, $existing_mech_enclosure);
    // Process 'mechmounting' column.
    $mechanical_mounting_allowed_values = [
      'din_rail', 'rack', 'panel_mount',
    ];
    $mech_mounting = $this->setFieldWithAllowedValues($mapped_data, 'mech.mounting', $mechanical_mounting_allowed_values, $existing_mech_mounting);
    // Process 'mech.abs' column.
    $mech_abs = $this->getParagraphFieldValue($mapped_data['mech.abs'], $existing_mech_abs);
    // Process 'mech.front_wiring' column.
    $mech_front_wiring = $this->getParagraphFieldValue($mapped_data['mech.front_wiring'], $existing_mech_front_wiring);
    // Process 'mech.top_wiring' column.
    $mech_top_wiring = $this->getParagraphFieldValue($mapped_data['mech.top_wiring'], $existing_mech_top_wiring);
    // Process 'mech.package_depth'.
    $mech_packaging_depth = $this->getParagraphFieldValue($mapped_data['mech.package_depth'], $existing_mech_packaging_depth);
    // Process 'mech.package_height'.
    $mech_packaging_height = $this->getParagraphFieldValue($mapped_data['mech.package_height'], $existing_mech_packaging_height);
    // Process 'mech.package_weight'.
    $mech_packaging_weight = $this->getParagraphFieldValue($mapped_data['mech.package_weight'], $existing_mech_packaging_weight);
    // Process 'mech.package_width'.
    $mech_packaging_width = $this->getParagraphFieldValue($mapped_data['mech.package_width'], $existing_mech_packaging_width);
    // Process 'mech.product_depth'.
    $mech_product_depth = $this->getParagraphFieldValue($mapped_data['mech.product_depth'], $existing_mech_product_depth);
    // Process 'mech.product_height'.
    $mech_product_height = $this->getParagraphFieldValue($mapped_data['mech.product_height'], $existing_mech_product_height);
    // Process 'mech.product_weight'.
    $mech_product_weight = $this->getParagraphFieldValue($mapped_data['mech.product_weight'], $existing_mech_product_weight);
    // Process 'mech.product_width'.
    $mech_product_width = $this->getParagraphFieldValue($mapped_data['mech.product_width'], $existing_mech_product_width);
    // Create mechanical group paragraph.
    $mechanical_paragraph = Paragraph::create([
      'type' => 'mechanical',
      'field_enclosure' => $mech_enclosure,
      'field_mounting' =>$mech_mounting,
      'field_abs' => $mech_abs,
      'field_front_wiring_clearance' => $mech_front_wiring,
      'field_top_wiring_clearance' => $mech_top_wiring,
      'field_packagine_depth' => $mech_packaging_depth,
      'field_packaging_height' => $mech_packaging_height,
      'field_packaging_weight' => $mech_packaging_weight,
      'field_packaging_width' => $mech_packaging_width,
      'field_product_depth' => $mech_product_depth,
      'field_product_height' => $mech_product_height,
      'field_product_weight' => $mech_product_weight,
      'field_product_width' => $mech_product_width,
    ]);
    // Save and attach the mechanical paragraph entity.
    $this->attachParagraphToNode($node, $mechanical_paragraph, 'field_mechanical_group');
    /* End - Mechanical Group Section */

    /* Start - Environmental Group Section */
    // Get the environmental group paragraph reference field.
    $environmental_group = $node->get('field_environmental_group');
    // Loop through the referenced paragraphs.
    foreach ($environmental_group as $item) {
      // Load the referenced paragraph.
      $environmental_group_paragraph = Paragraph::load($item->target_id);
      // Get the existing data for the fields.
      $existing_env_ip_rating = $environmental_group_paragraph->get('field_ip_rating')->getValue();
      $existing_env_op_humanity = $environmental_group_paragraph->get('field_operating_humidity')->getValue();
      $existing_env_op_temp_max_rating = $environmental_group_paragraph->get('field_operating_temp_max_rating')->getValue();
      $existing_env_op_temp_min_rating = $environmental_group_paragraph->get('field_operating_temp_min_rating')->getValue();
      $existing_env_storage_temp_max_rating = $environmental_group_paragraph->get('field_storage_temp_max_rating')->getValue();
      $existing_env_storage_temp_min_rating = $environmental_group_paragraph->get('field_storage_temp_min_rating')->getValue();
    }
    // Process env.ip_rating'.
    $env_ip_rating = $this->setParagraphTaxonomyField('ip_rating', $mapped_data['env.ip_rating'],$existing_env_ip_rating);
    // Process env.operating_humidity'.
    $env_op_humanity = $this->getParagraphFieldValue($mapped_data['env.operating_humidity'],$existing_env_op_humanity);
    // Process env.op_temp_max_rate'.
    $env_op_temp_max_rating = $this->getParagraphFieldValue($mapped_data['env.op_temp_max_rate'],$existing_env_op_temp_max_rating);
    // Process env.op_temp_min_rate'.
    $env_op_temp_min_rating = $this->getParagraphFieldValue($mapped_data['env.op_temp_min_rate'], $existing_env_op_temp_min_rating);
    // Process env.st_temp_max_rate'.
    $env_storage_temp_max_rating = $this->getParagraphFieldValue($mapped_data['env.st_temp_max_rate'],$existing_env_storage_temp_max_rating);
    // Process env.st_temp_min_rate'.
    $env_storage_temp_min_rating = $this->getParagraphFieldValue($mapped_data['env.st_temp_min_rate'],$existing_env_storage_temp_min_rating);
    // Create environmental group paragraph.
    $environmental_paragraph = Paragraph::create([
      'type' => 'environmental',
      'field_ip_rating' => $env_ip_rating,
      'field_operating_humidity' => $env_op_humanity,
      'field_operating_temp_max_rating' => $env_op_temp_max_rating,
      'field_operating_temp_min_rating' => $env_op_temp_min_rating,
      'field_storage_temp_max_rating' => $env_storage_temp_max_rating,
      'field_storage_temp_min_rating' => $env_storage_temp_min_rating,
    ]);
    // Save and attach the environmental paragraph entity.
    $this->attachParagraphToNode($node, $environmental_paragraph, 'field_environmental_group');
    /* End - Environmental Group Section */
    // Save the node.
    $node->setPublished(TRUE);
    $node->set('moderation_state', 'published');
    $node->save();
  }

  /**
   * Parses a CSV file and returns the rows as an array.
   */
  protected function parseCsvFile($fileUri) {
    $csv_rows = [];
    if (($handle = fopen($fileUri, "r")) !== FALSE) {
      while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        $csv_rows[] = $data;
      }
      fclose($handle);
    }
    return $csv_rows;
  }

  /**
   * Function to find a term in a vocabulary by name.
   */
  protected function findTermInVocabulary($term_name, Vocabulary $vocabulary) {
    $query = $this->entityManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', $vocabulary->id())
      ->accessCheck(FALSE)
      ->condition('name', $term_name)
    // Limit the query to return only one result.
      ->range(0, 1);

    $tids = $query->execute();
    if (!empty($tids)) {
      // Load the first found term and return its ID.
      $term_id = reset($tids);
      $term = $this->entityManager->getStorage('taxonomy_term')->load($term_id);
      return $term->id();
    }
    return NULL;
  }

  /**
   * Import media assets from URLs.
   */
  protected function importMediaFromUrls(array $urls, $directory, $bundle, $media_field, $alt = NULL) {
    $media_ids = [];
    $media = NULL;
    // Check if the URLs array is empty.
    if ($urls[0] !== '') {
      foreach ($urls as $url) {
        $url = trim($url);
        $filename = pathinfo($url, PATHINFO_BASENAME);
        // Check if the file extension matches the expected extension.
        $validExtensions = [
          'image' => ['png', 'gif', 'jpg', 'jpeg'],
          'document' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv',
          ],
          'video' => ['mp4'],
          'audio' => ['mp3'],
        ];
        $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array($fileExtension, $validExtensions[$bundle])) {
          $this->messenger->addWarning(
            $this->t("@bundle doesn't match for URL: @url",
            ['@bundle' => $bundle, '@url' => $url]
          ));
          // Skip processing this URL.
          continue;
        }
        // To Check if a media entity already exists.
        $query = $this->entityManager->getStorage('media')->getQuery()
          ->accessCheck(FALSE)
          ->condition('bundle', $bundle)
          ->condition('name', $filename);
        $existing_media_ids = $query->execute();
        if (!empty($existing_media_ids)) {
          // If media entity with the same filename and bundle exists, reuse it.
          $media_id = reset($existing_media_ids);
          $media = $this->entityManager->getStorage('media')->load($media_id);
        }
        else {
          $file_contents = file_get_contents($url);
          if ($file_contents !== FALSE) {
            $destination = $directory . '/' . $filename;
            $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
            $destination = $directory . $filename;
            $file = $this->fileRepository->writeData($file_contents, $destination, FileSystemInterface::EXISTS_REPLACE);
            $file->save();
            // Create a media entity.
            $media = Media::create([
              'bundle' => $bundle,
              'name' => $filename,
              $media_field => [
                'target_id' => $file->id(),
              ],
            ]);
            // Set alt text for image media.
            if ($bundle === 'image' && !empty($alt)) {
              $media->set('field_media_image', [
                'target_id' => $file->id(),
                'alt' => $alt,
              ]);
            }
            $media->save();
          }
          else {
            $this->logger->get('pim_import_csv')->error($this->t('Failed to download file from URL: @url', ['@url' => $url]));
          }
        }
        // Check if $media is not NULL before adding its ID to the array.
        if ($media !== NULL) {
          $media_ids[] = $media->id();
        }
      }
    }
    return $media_ids;
  }

  public function getParagraphFieldValue($mapped_data, $existing_data){
    if (isset($mapped_data)){
      if ($mapped_data !== '' && $mapped_data !== 'NULL'){
        $return_data = $mapped_data;
      }
      elseif($mapped_data == 'NULL'){
        $return_data = '';
      }
      else {
        $return_data = $existing_data;
      }
      return $return_data;
    }
  }

  /**
   * Reusable function to set Paragraph Taxonomy field values.
   */
  public function setParagraphTaxonomyField($vocabulary_name, $field_value, $existing_term_value) {
    if (isset($field_value)) {
      if ($field_value !== '' && $field_value !== 'NULL') {
        // Check if the vocabulary exists.
        $vocabulary = $this->entityManager->getStorage('taxonomy_vocabulary')->load($vocabulary_name);
        // Split the comma-separated values into an array.
        $terms = explode(',', $field_value);
        $return_term_ids = [];
        foreach ($terms as $term_name) {
          // Trim each term to remove leading/trailing whitespaces.
          $term_name = trim($term_name);

          // Check if the term already exists in the vocabulary.
          $term_id = $this->findTermInVocabulary($term_name, $vocabulary);

          if (!$term_id) {
            // Term doesn't exist, create it.
            $term = Term::create([
              'vid' => $vocabulary->id(),
              'name' => $term_name,
            ]);
            $term->save();
            // Get the term ID after creating it.
            $term_id = $term->id();
          }
          // Store the term ID in the array.
          $return_term_ids[] = $term_id;
        }
        $return_term_id = $return_term_ids;
      }
      elseif ($field_value == 'NULL') {
          $return_term_id = '';
      }
      else {
          $return_term_id = $existing_term_value;
      }
      return $return_term_id;
    }
  }


  /**
   * Process and set a field with allowed values based on mapped data.
   */
  public function setFieldWithAllowedValues($mapped_data, $field_value, $allowed_values, $existing_value) {
    if (isset($mapped_data[$field_value])) {
      // $return_value = [];
      if ($mapped_data[$field_value] !== '' && $mapped_data[$field_value] !== 'NULL'){
        // Split the comma-separated values into an array.
        $values = explode(',', $mapped_data[$field_value]);
        // Filter the values to keep only the allowed ones.
        $filtered_values = array_intersect($values, $allowed_values);
        // If there are valid values, set the field.
        if (!empty($filtered_values)) {
          $return_value = $filtered_values;
        }
        else {
          $this->messenger->addWarning($this->t('Empty / Invalid value(s) for @field_value', ['@field_value' => $field_value]));
        }
      }
      elseif ($mapped_data[$field_value] == 'NULL'){
        $return_value = [];
      }
      else {
        $return_value = $existing_value;
      }
      return $return_value;
    }
  }

  /**
   * Attach a paragraph to a node's field and save it.
   */
  public function attachParagraphToNode($node, $paragraph, string $field_name) {
    $paragraph->save();
    // Attach the saved paragraph to the specified field.
    $node->{$field_name}[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
    $node->set($field_name, $paragraph);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Batch import completed successfully.'));
    }
    else {
      \Drupal::messenger()->addError(t('Batch import encountered errors. Check the logs for more information.'));
    }
  }

}
