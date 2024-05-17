<?php

namespace Drupal\contact_form_api\Plugin\rest\resource;

use Drupal\user\UserData;
use Psr\Log\LoggerInterface;
use Drupal\rest\ResourceResponse;
use Drupal\contact\Entity\Message;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\Component\Serialization\Json;
use Drupal\contact\MailHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a custom endpoint for contact form submission via API.
 *
 * @RestResource(
 *   id = "custom_contact_resource",
 *   label = @Translation("Custom Contact Form Resource"),
 *   uri_paths = {
 *     "create" = "/api/contact-user"
 *   }
 * )
 */

class ContactUserResource extends ResourceBase
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The contact mail handler service.
   *
   * @var \Drupal\contact\MailHandlerInterface
   */
  protected $mailHandler;

  /**
   * The currently authenticated user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The user data service.
   *
   * @var \Drupal\user\UserData
   */
  protected $userData;

  /**
   * Constructs a ContactUserResource instance.
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\contact\MailHandlerInterface $mail_handler
   *   The contact mail handler service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The currently authenticated user.
   * @param \Drupal\user\UserData $user_data
   *   The user data service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $serializer_formats, LoggerInterface $logger, EntityTypeManagerInterface $entity_type_manager, MailHandlerInterface $mail_handler, AccountInterface $current_user, UserData $user_data)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->entityTypeManager = $entity_type_manager;
    $this->mailHandler = $mail_handler;
    $this->currentUser = $current_user;
    $this->userData = $user_data;
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
      $container->get('entity_type.manager'),
      $container->get('contact.mail_handler'),
      $container->get('current_user'),
      $container->get('user.data')
    );
  }

  /**
   * Handles POST requests to create a contact form submission.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When user does not exist or data is missing.
   */
  public function post(Request $request)
  {
    $data = Json::decode($request->getContent());

    // Check if recipient, subject, and message are missing in the input data.
    if (empty($data['recipient']) || empty($data['subject']) || empty($data['message'])) {
      throw new BadRequestHttpException('Missing recipient, subject, or message.');
    }

    // Check if the recipient exists.
    $recipient = $this->entityTypeManager->getStorage('user')->load($data['recipient']);
    if (!$recipient) {
      // If the recipient does not exist, respond with a Bad Request error.
      throw new BadRequestHttpException('Please provide a valid user ID for the recipient.');
    }

    // Check if the recipient has enabled the option to be contacted.
    $contactEnabled = $this->userData->get('contact', $data['recipient'], 'enabled');
    if ($contactEnabled != '1') {
      throw new BadRequestHttpException('The recipient has disabled contact. Please contact the administrator.');
    }

    // Create the message entity using the contact form.
    $message = Message::create([
      'contact_form' => 'personal',
      'subject' => $data['subject'],
      'message' => $data['message'],
      'copy' => !empty($data['copy']) ? 1 : 0,
      'recipient' => $data['recipient'],
      'name' => $this->currentUser->getAccountName(),
      'mail' => $this->currentUser->getEmail(),
    ]);
    $message->save();

    try {
      // Send the email message.
      $this->mailHandler->sendMailMessages($message, $this->currentUser);
    } catch (\Exception $e) {
      // Log the error with recipient information.
      $this->logger->error($this->t('Failed to send email to "%recipient".', ['%recipient' => $data['recipient']]));
      // Respond with a 500 Internal Server Error and a more informative error message.
      throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
    }

    $response = ['message' => 'Contact form has been submitted successfully.'];
    return new ResourceResponse($response);
  }
}
