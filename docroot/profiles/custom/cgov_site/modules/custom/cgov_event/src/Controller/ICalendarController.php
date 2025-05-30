<?php

namespace Drupal\cgov_event\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Session\AccountProxy;
use Drupal\file\FileRepositoryInterface;
use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The iCalendar controller.
 */
class ICalendarController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  protected $entity;
  /**
   * {@inheritdoc}
   */
  protected $currentUser;
  /**
   * {@inheritdoc}
   */
  public $request;
  /**
   * {@inheritdoc}
   */
  protected $file;

  /**
   * The file repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * Constructs an ICalendar Controller object.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $entityStorage
   *   The node storage.
   * @param \Drupal\Core\Session\AccountProxy $currentUser
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   The request stack.
   * @param \Drupal\Core\File\FileSystem $fileStorage
   *   The file system.
   * @param \Drupal\file\FileRepositoryInterface $file_repository
   *   The file repository service.
   */
  public function __construct(EntityStorageInterface $entityStorage, AccountProxy $currentUser, RequestStack $request, FileSystem $fileStorage, FileRepositoryInterface $file_repository) {
    $this->entity = $entityStorage;
    $this->currentUser = $currentUser;
    $this->request = $request;
    $this->file = $fileStorage;
    $this->fileRepository = $file_repository;
  }

  /**
   * Create dependency injection.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('file_system'),
      $container->get('file.repository')
    );

  }

  /**
   * Borrowed from Simple ICS.
   *
   * Project https://www.drupal.org/project/simple_ics.
   */
  public function download($nid) {
    // Load event node.
    /** @var \Drupal\node\NodeInterface */
    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if ($node === NULL || $node->bundle() !== 'cgov_event') {
      // Throwing 404 if node ID doesn't exist.
      throw new NotFoundHttpException();
    }

    $start_date = NULL;
    $end_date = NULL;

    if ($node->hasField('field_event_start_date') && !empty($node->get('field_event_start_date')->first())) {
      // Convert the date to the Timezone of the user requesting.
      $start_date = $node->field_event_start_date->date->format(
        'Y-m-d H:i:s'
      );
    }

    if ($node->hasField('field_event_end_date') && !empty($node->get('field_event_end_date')->first())) {
      $end_date = $node->field_event_end_date->date->format(
        'Y-m-d H:i:s'
      );
    }

    // Get Host.
    $host = $this->request->getCurrentRequest()->getHost();

    // 1. Create a Calendar object.
    $vCalendar = new Calendar($host);

    // 2. Create an Event object.
    $vEvent = new Event();

    // 3. Add your information to the Event.
    $vEvent
      ->setDtStart(new \DateTime($start_date, new \DateTimeZone('UTC')))
      ->setDtEnd(new \DateTime($end_date, new \DateTimeZone('UTC')))
      ->setSummary($node->getTitle())
      ->setLocation($node->field_city_state->value)
      ->setDescription($node->field_page_description->value);

    // 4. Add Event to Calendar.
    $vCalendar->addComponent($vEvent);

    // 5. Send output.
    $filename = 'cal-' . $nid . '.ics';
    $uri = 'public://' . $filename;
    $content = $vCalendar->render();
    $file = $this->fileRepository->writeData($content, $uri, FileExists::Replace);
    $mimetype = 'text/calendar';
    $scheme = 'public';
    $parts = explode('://', $uri);
    $file_directory = $this->file->realpath(
      $scheme . "://"
    );
    $filepath = $file_directory . '/' . $parts[1];
    $filename = $file->getFilename();

    // File doesn't exist
    // This may occur if the download path is used outside of a formatter
    // and the file path is wrong or file is gone.
    if (!file_exists($filepath)) {
      throw new NotFoundHttpException();
    }

    $headers = [
      'Content-Type' => $mimetype,
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Content-Length' => $file->getSize(),
      'Content-Transfer-Encoding' => 'binary',
      'Pragma' => 'no-cache',
      'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
      'Expires' => '0',
      'Accept-Ranges' => 'bytes',
    ];

    // \Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()
    // sets response as not cacheable if the Cache-Control header is not
    // already modified. We pass in FALSE for non-private schemes for the
    // $public parameter to make sure we don't change the headers.
    return new BinaryFileResponse($uri, 200, $headers, $scheme !== 'private');
  }

}
