<?php

namespace Drupal\islandora_iiif\Plugin\views\style;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\islandora\IslandoraUtils;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\views\ResultRow;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Provide serializer format for IIIF Manifest.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "iiif_manifest",
 *   title = @Translation("IIIF Manifest"),
 *   help = @Translation("Display images as an IIIF Manifest."),
 *   display_types = {"data"}
 * )
 */
class IIIFManifest extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  /**
   * The allowed formats for this serializer. Default to only JSON.
   *
   * @var array
   */
  protected $formats = ['json'];

  /**
   * The serializer which serializes the views result.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The request service.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * This module's config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $iiifConfig;

  /**
   * The Drupal Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The enclosing entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * Islandora utils.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected $utils;

  /**
   * The Drupal Filesystem.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, SerializerInterface $serializer, Request $request, ImmutableConfig $iiif_config, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, Client $http_client, MessengerInterface $messenger, IslandoraUtils $islandora_utils) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->serializer = $serializer;
    $this->request = $request;
    $this->iiifConfig = $iiif_config;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
    $this->utils = $islandora_utils;
    $this->entity = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('serializer'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('config.factory')->get('islandora_iiif.settings'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('messenger'),
      $container->get('islandora.utils'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $metadata_mappings = [
      'Creator' => 'field_creator',
      'Library' => 'field_digital_library',
    ];
    $json = [];
    $iiif_address = $this->iiifConfig->get('iiif_server');
    if (!is_null($iiif_address) && !empty($iiif_address)) {
      // Get the current URL being requested.
      $request_host = $this->request->getSchemeAndHttpHost();
      $request_url = $this->request->getRequestUri();
      // Strip off the last URI component to get the base ID of the URL.
      // @todo assumming the view is a path like /node/1/manifest.json
      $url_components = explode('/', $request_url);
      array_pop($url_components);
      $content_path = implode('/', $url_components);
      $iiif_base_id = $request_host . $content_path;
      $this->entity = $this->getEntity($content_path);
      // Create metadata
      $metadata = [];
      foreach ($metadata_mappings as $mapping => $field) {
        if ($this->entity->hasField($field)) {
          $value = reset($this->entity->get($field)->getValue()[0]);
          $target = $this->entity->get($field)->target_id;
          if ($target) {
            $value = Term::load($target)->get('name')->value;
          }
          if ($value) {
            //$value = $value['value'];
            $metadata[] = [
              'label' => [
                'en' => [
                  0 => $mapping,
                ],
              ],
              'value' => [
                'en' => [
                  0 => $value,
                ],
              ],
            ];
          }
        }
      }


      // @see https://iiif.io/api/presentation/3/#manifest
      $json += [
        '@context' => 'http://iiif.io/api/presentation/3/context.json',
        'id' => $request_url,
        'type' => 'Manifest',
        // If the View has a title, set the View title as the manifest label.
        'label' => [
          'en' => [
            0 => $this->view->getTitle() ?: $this->getEntityTitle(),
          ],
        ],
        // @see https://iiif.io/api/presentation/3.0/#items
      ];
      $json += [
        'requiredStatement' => [
          'label' => [
            'en' => [
              0 => 'Attribution',
            ],
          ],
          'value' => [
            'en' => [
              0 => 'These materials are made available for research and educational purposes.  It is the responsibility of the researcher to determine the copyright status of materials in the Vassar College Digital Library',
            ],
          ],
        ],
      ];
      // Add metadata
      $json += [
        'metadata' => $metadata,
      ];
      // For each row in the View result.
      foreach ($this->view->result as $row) {
        // Add the IIIF URL to the image to print out as JSON.
        $items = $this->getTileSourceFromRow($row, $iiif_address, $iiif_base_id);
        foreach ($items as $tile_source) {
          $json['items'][] = $tile_source;
        }
      }
    }
    unset($this->view->row_index);

    $content_type = 'json';

    return $this->serializer->serialize($json, $content_type, ['views_style_plugin' => $this]);
  }

  /**
   * Render array from views result row.
   *
   * @param \Drupal\views\ResultRow $row
   *   Result row.
   * @param string $iiif_address
   *   The URL to the IIIF server endpoint.
   * @param string $iiif_base_id
   *   The URL for the request, minus the last part of the URL,
   *   which is likely "manifest".
   *
   * @return array
   *   List of IIIF URLs to display in the viewer.
   */
  protected function getTileSourceFromRow(ResultRow $row, $iiif_address, $iiif_base_id) {
    $items = [];
    foreach ($this->options['iiif_tile_field'] as $iiif_tile_field) {
      $viewsField = $this->view->field[$iiif_tile_field];
      $entity = $viewsField->getEntity($row);

      if (isset($entity->{$viewsField->definition['field_name']})) {

        /** @var \Drupal\Core\Field\FieldItemListInterface $images */
        $images = $entity->{$viewsField->definition['field_name']};
        foreach ($images as $image) {
          // Create the IIIF URL for this file
          // Visiting $iiif_url will resolve to the info.json for the image.
          $file_url = $image->entity->createFileUrl(FALSE);
          $mime_type = $image->entity->getMimeType();
          $iiif_url = rtrim($iiif_address, '/') . '/' . urlencode($file_url);

          // Create the necessary ID's for the item and annotation.
          $item_id = $iiif_base_id . '/item/' . $entity->id();

          // Try to fetch the IIIF metadata for the image.
          try {
            $info_json = $this->httpClient->get($iiif_url)->getBody();
            $resource = json_decode($info_json, TRUE);
            $width = $resource['width'];
            $height = $resource['height'];
          } catch (ClientException | ServerException | ConnectException $e) {
            // If we couldn't get the info.json from IIIF
            // try seeing if we can get it from Drupal.
            if (empty($width) || empty($height)) {
              // Get the image properties so we know the image width/height.
              $properties = $image->getProperties();
              $width = isset($properties['width']) ? $properties['width'] : 0;
              $height = isset($properties['height']) ? $properties['height'] : 0;

              // If this is a TIFF AND we don't know the width/height
              // see if we can get the image size via PHP's core function.
              if ($mime_type === 'image/tiff' && !$width || !$height) {
                $uri = $image->entity->getFileUri();
                $path = $this->fileSystem->realpath($uri);
                $image_size = getimagesize($path);
                if ($image_size) {
                  $width = $image_size[0];
                  $height = $image_size[1];
                }
              }
            }
          }

          $iiif_item = [
            'id' => $item_id,
            'type' => 'Canvas',
            'height' => $height,
            'width' => $width,
            'items' => [
              0 => [
                'id' => "$item_id/annopage-1",
                'type' => 'AnnotationPage',
                'items' => [
                  0 => [
                    'id' => "$item_id/annopage-1/anno-1",
                    'type' => 'Annotation',
                    'motivation' => 'painting',
                    'body' => [
                      'id' => $file_url,
                      'type' => 'Image',
                      'format' => $mime_type,
                      'height' => $height,
                      'width' => $width,
                    ],
                    'target' => $item_id,
                  ],
                ],
              ],
            ],
          ];
          $transcriptions = $this->getTranscripts($this->entity, $item_id);
          if ($transcriptions) {
            $iiif_item['annotations'] = [
              0 => [
                'id' => "$item_id/annopage-2",
                'type' => 'AnnotationPage',
                'items' => $this->getTranscripts($this->entity, $item_id),
              ],
            ];
          }
          $items[] = $iiif_item;
        }
      }
    }

    return $items;
  }
  public function getTranscripts($entity, $item_id) {
    $transcripts = NULL;
    $annotations = NULL;
    $media = $this->utils->getMedia($entity);
    foreach ($media as $medium){
      if ($medium->bundle() == "extracted_text") {
        $text = $medium->get('field_edited_text')->value;
        if ($text){
          $transcripts[] = $medium->get('field_edited_text')->value;
        }
      }
    }

    if ($transcripts) {
      foreach ($transcripts as &$transcript) {
        $annotations[] = [
          'id' => "$item_id/annopage-2/anno-1",
          'type' => 'Annotation',
          'motivation' => 'commenting',
          'body' => [
            'type' => 'TextualBody',
            'language' => 'en',
            'format' => 'text/plain',
            'value' => $transcript,
          ],
          'target' => $item_id,
        ];
      }
    }

    return $annotations;
  }


  public function getEntity(string $content_path) {
    $entity = NULL;
    try {
      $params = Url::fromUserInput($content_path)->getRouteParameters();
      if (isset($params['node'])) {
        $entity = $this->entityTypeManager->getStorage('node')
          ->load($params['node']);
      }
      elseif (isset($params['media'])) {
        $entity = $this->entityTypeManager->getStorage('media')
          ->load($params['media']);
      }
    } catch (\InvalidArgumentException $e) {

    }
    return $entity;
  }

  /**
   * Pull a title from the node or media passed to this view.
   *
   * @param string $content_path
   *   The path of the content being requested.
   *
   * @return string
   *   The entity's title.
   */
  public function getEntityTitle(): string {
    $entity_title = $this->t('IIIF Manifest');
    if ($this->entity) {
      $entity_title = $this->entity->label();
    }
    return $entity_title;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['iiif_tile_field'] = ['default' => ''];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $field_options = [];

    $fields = $this->displayHandler->getHandlers('field');
    $islandora_default_file_fields = [
      'field_media_file',
      'field_media_image',
    ];
    $file_views_field_formatters = [
      // Image formatters.
      'image',
      'image_url',
      // File formatters.
      'file_default',
      'file_url_plain',
    ];
    /** @var \Drupal\views\Plugin\views\field\FieldPluginBase[] $fields */
    foreach ($fields as $field_name => $field) {
      // If this is a known Islandora file/image field
      // OR it is another/custom field add it as an available option.
      // @todo find better way to identify file fields
      // Currently $field->options['type'] is storing the "formatter" of the
      // file/image so there are a lot of possibilities.
      // The default formatters are 'image' and 'file_default'
      // so this approach should catch most...
      if (in_array($field_name, $islandora_default_file_fields) ||
        (!empty($field->options['type']) && in_array($field->options['type'], $file_views_field_formatters))) {
        $field_options[$field_name] = $field->adminLabel();
      }
    }

    // If no fields to choose from, add an error message indicating such.
    if (count($field_options) == 0) {
      $this->messenger->addMessage($this->t('No image or file fields were found in the View.
        You will need to add a field to this View'), 'error');
    }

    $form['iiif_tile_field'] = [
      '#title' => $this->t('Tile source field(s)'),
      '#type' => 'checkboxes',
      '#default_value' => $this->options['iiif_tile_field'],
      '#description' => $this->t("The source of image for each entity."),
      '#options' => $field_options,
      // Only make the form element required if
      // we have more than one option to choose from
      // otherwise could lock up the form when setting up a View.
      '#required' => count($field_options) > 0,
    ];
  }

  /**
   * Returns an array of format options.
   *
   * @return string[]
   *   An array of the allowed serializer formats. In this case just JSON.
   */
  public function getFormats() {
    return ['json' => 'json'];
  }

}
